<?php
/**
 * Password reset tokens.
 *
 * Design notes, because the details here are the security:
 *
 * - The token is 64 hex chars from random_bytes(). Only its SHA-256 is stored,
 *   so someone who reads the database still cannot reset anybody's password.
 * - Requesting a reset never reveals whether an email is registered. The web
 *   and API both answer the same way either way — otherwise the form becomes a
 *   way to enumerate your customers.
 * - Tokens expire after an hour, are single-use, and every outstanding token
 *   for that user is invalidated the moment one is used.
 * - Using a token also drops the user's API tokens: if the reset was triggered
 *   because an account was compromised, the attacker's phone stops working.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail_service.php';

const PASSWORD_RESET_TTL_MINUTES = 60;

/**
 * Issues a reset for whoever owns [$email] and emails them the link.
 *
 * Always returns void — the caller must show the same message whether or not
 * the address existed.
 */
function password_reset_request(string $email): void
{
    // Unthrottled, this is a way to flood any inbox with mail from our own SMTP
    // account. Counted here so the website form and the API share one limit.
    // A throttled request returns like any other, so it still reveals nothing.
    require_once __DIR__ . '/login_throttle.php';
    if (throttle_delay('forgot') > 0) {
        mail_log('RESET   throttled for ' . login_client_ip());
        return;
    }
    throttle_record('forgot');

    $stmt = db()->prepare(
        "SELECT id, username, email FROM users
         WHERE email = ? AND status = 'active' LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        mail_log('RESET   no account for ' . $email);
        return;
    }

    $token   = bin2hex(random_bytes(32));
    $hash    = hash('sha256', $token);
    $expires = (new DateTimeImmutable('+' . PASSWORD_RESET_TTL_MINUTES . ' minutes'))
        ->format('Y-m-d H:i:s');

    // One live token per person: asking again replaces the last one.
    db()->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')
        ->execute([(int) $user['id']]);

    db()->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
    )->execute([(int) $user['id'], $hash, $expires]);

    // Housekeeping, cheap and rare enough to do inline.
    db()->exec('DELETE FROM password_resets WHERE expires_at < NOW() OR used_at IS NOT NULL');

    $link = mail_site_url() . '/reset-password.php?token=' . $token;

    mail_send(
        $user['email'],
        'Reset your SoleCraftPH password',
        '<h1 style="font-size:22px;margin:0 0 12px;">Reset your password</h1>'
        . '<p style="margin:0;">Hi ' . htmlspecialchars($user['username']) . ', '
        . 'someone asked to reset the password on your SoleCraftPH account. '
        . 'This link works once and expires in an hour.</p>'
        . mail_button('Choose a new password', $link)
        . '<p style="margin:0;font-size:13px;color:#8A8578;">If that was not you, '
        . 'ignore this email — nothing has changed.</p>'
    );
}

/** Resolves a raw token to its user id, or null when it is invalid or spent. */
function password_reset_user_for_token(string $token): ?int
{
    if ($token === '') {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT user_id FROM password_resets
         WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    return $row ? (int) $row['user_id'] : null;
}

/**
 * Consumes [$token] and sets the new password. Returns an error string, or null
 * on success.
 */
function password_reset_complete(string $token, string $newPassword): ?string
{
    $userId = password_reset_user_for_token($token);
    if ($userId === null) {
        return 'That reset link has expired or has already been used. Please request another.';
    }
    if (strlen($newPassword) < 8) {
        return 'New password must be at least 8 characters long.';
    }

    auth_change_password($userId, $newPassword);

    db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE token_hash = ?')
        ->execute([hash('sha256', $token)]);
    // Any other outstanding request for this account dies with it.
    db()->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')
        ->execute([$userId]);
    // And so does every signed-in phone.
    db()->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$userId]);

    mail_log('RESET   completed for user ' . $userId);
    return null;
}
