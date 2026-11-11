<?php
/**
 * Login throttling.
 *
 * Five bad passwords in a row used to cost an attacker nothing — no delay, no
 * lockout — on the customer login, the API and the admin panel alike. The admin
 * panel is the serious one: a public URL guarding the whole catalogue, customer
 * list and order history behind a single password.
 *
 * Two counters, deliberately:
 *
 *   by IP       stops one machine grinding through many usernames.
 *   by username stops a distributed attempt on one account.
 *
 * Counting only the username would let an attacker rotate usernames freely;
 * counting only the IP would let anyone lock a victim out from elsewhere. A
 * successful login clears that pair, so a legitimate person who mistypes twice
 * and then gets in is never punished for it.
 */
require_once __DIR__ . '/../config/db.php';

/** Failures inside this window count toward the limits below. */
const LOGIN_WINDOW_MINUTES = 15;
/** Past this many failures, each further attempt waits. */
const LOGIN_SOFT_LIMIT = 5;
/** Past this many, the pair is locked out for the rest of the window. */
const LOGIN_HARD_LIMIT = 10;

function login_client_ip(): string
{
    // Hostinger fronts PHP with a proxy, so REMOTE_ADDR is theirs, not the
    // visitor's. Prefer the forwarded chain's first hop when present.
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $first = trim(explode(',', $forwarded)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Recent failures for this IP and this username. */
function login_recent_failures(string $username): array
{
    $stmt = db()->prepare(
        'SELECT
             SUM(ip = ?)       AS by_ip,
             SUM(username = ?) AS by_user
         FROM login_attempts
         WHERE succeeded = 0
           AND attempted_at > (NOW() - INTERVAL ? MINUTE)
           AND (ip = ? OR username = ?)'
    );
    $stmt->execute([
        login_client_ip(), $username, LOGIN_WINDOW_MINUTES,
        login_client_ip(), $username,
    ]);
    $row = $stmt->fetch() ?: [];
    return [
        'ip'   => (int) ($row['by_ip'] ?? 0),
        'user' => (int) ($row['by_user'] ?? 0),
    ];
}

/**
 * How many seconds this attempt must wait, or 0 when it may proceed.
 *
 * Call before checking the password. Returning a number means "refuse without
 * even looking" — a check that never runs cannot leak whether the account
 * exists.
 */
function login_throttle_delay(string $username): int
{
    $fails = login_recent_failures($username);
    $worst = max(intdiv($fails['ip'], 10), $fails['user']);

    if ($worst >= LOGIN_HARD_LIMIT) {
        return LOGIN_WINDOW_MINUTES * 60;
    }
    if ($worst >= LOGIN_SOFT_LIMIT) {
        // Grows with each failure past the soft limit: 30s, 60s, 120s…
        return min(300, 30 * (2 ** ($worst - LOGIN_SOFT_LIMIT)));
    }
    return 0;
}

/** Human wording for a refusal, so three call sites cannot word it differently. */
function login_throttle_message(int $seconds): string
{
    $minutes = (int) ceil($seconds / 60);
    return $minutes > 1
        ? 'Too many failed attempts. Please try again in about ' . $minutes . ' minutes.'
        : 'Too many failed attempts. Please wait a minute and try again.';
}

/**
 * Records the outcome. A success wipes this pair's failures so the next honest
 * attempt starts clean.
 */
function login_attempt_record(string $username, bool $succeeded): void
{
    $ip = login_client_ip();

    db()->prepare(
        'INSERT INTO login_attempts (ip, username, succeeded) VALUES (?, ?, ?)'
    )->execute([$ip, $username, $succeeded ? 1 : 0]);

    if ($succeeded) {
        db()->prepare(
            'DELETE FROM login_attempts
             WHERE succeeded = 0 AND (ip = ? OR username = ?)'
        )->execute([$ip, $username]);
    }

    // Cheap housekeeping — the table only needs the current window.
    if (random_int(1, 20) === 1) {
        db()->prepare(
            'DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL ? MINUTE)'
        )->execute([LOGIN_WINDOW_MINUTES * 4]);
    }
}

/* ---------------------------------------------------------------------------
 * The same counter, for things that are not logins but are just as abusable:
 * creating accounts in bulk, or firing reset emails at someone's inbox using
 * our SMTP. login_attempts already counts per IP, so this reuses it under a
 * synthetic name that no real username can collide with.
 * ------------------------------------------------------------------------- */

/** Seconds this action must wait, or 0 to proceed. */
function throttle_delay(string $action): int
{
    return login_throttle_delay('@' . $action);
}

/** Records one attempt. Every attempt counts — there is no "success" here. */
function throttle_record(string $action): void
{
    login_attempt_record('@' . $action, false);
}
