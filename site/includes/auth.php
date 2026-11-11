<?php
/**
 * Authentication (Basic) - session based, bcrypt password hashing.
 */
require_once __DIR__ . '/../config/db.php';

require_once __DIR__ . '/session.php';

/** The account these credentials open, or null. Starts no session. */
function auth_check_password(string $username, string $password): ?array
{
    $stmt = db()->prepare('SELECT id, username, email, password_hash, role, status FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if (!$user || ($user['status'] ?? 'active') === 'suspended' || !password_verify($password, $user['password_hash'])) {
        return null;
    }
    return $user;
}

/**
 * Customer sign-in. Admin accounts are refused here: they sign in on the admin
 * site, where a password alone is not enough (see admin/verify.php).
 */
function auth_attempt_login(string $username, string $password): bool
{
    $user = auth_check_password($username, $password);
    if (!$user || $user['role'] === 'admin') {
        return false;
    }
    auth_start_session_for($user);
    return true;
}

/** Signs $user in on the website. Shared by password and Google sign-in. */
function auth_start_session_for(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['role'];
    // Whatever they put in the bag while logged out follows them into the
    // account cart, so signing in never silently empties the cart.
    require_once __DIR__ . '/cart.php';
    cart_merge_session_into_account((int) $user['id']);
}

function auth_logout(): void
{
    $_SESSION = [];
    session_destroy();
}

/**
 * Whether the signed-in account has been suspended since it signed in.
 *
 * Suspension used to be checked in exactly one place — auth_attempt_login() —
 * which meant it only ever applied to the next login. A session that already
 * existed kept working, and sessions here have no fixed lifetime, so suspending
 * someone for fraud or chargebacks left them shopping until they chose to log
 * out, which might be never.
 *
 * Checked here rather than in each of the 23 files that include this one, and
 * remembered for the request because auth_is_logged_in() is called several
 * times on a page and this must not become several queries.
 *
 * The session keys are unset rather than the session destroyed on purpose: the
 * same session carries the guest cart, and destroying it would also empty the
 * bag of someone who is merely being signed out.
 */
function auth_session_suspended(): bool
{
    static $suspended = null;
    if ($suspended !== null) {
        return $suspended;
    }
    if (!isset($_SESSION['user_id'])) {
        return $suspended = false;
    }

    $stmt = db()->prepare('SELECT status FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $status = $stmt->fetchColumn();

    // A row that has gone is treated exactly like a suspended one: either way
    // the session names an account that must not be acting on the site.
    $suspended = ($status === false) || ($status === 'suspended');

    if ($suspended) {
        unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role']);
    }
    return $suspended;
}

function auth_is_logged_in(): bool
{
    return isset($_SESSION['user_id']) && !auth_session_suspended();
}

/** An admin who also passed the emailed code this session. */
function auth_is_admin(): bool
{
    return auth_is_logged_in() && ($_SESSION['role'] ?? '') === 'admin' && !empty($_SESSION['admin_verified']);
}

function auth_require_admin(): void
{
    if (!auth_is_admin()) {
        header('Location: login.php');
        exit;
    }
}

function auth_register_customer(string $username, string $email, string $password): array
{
    $db = db();

    $check = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
    $check->execute([$username, $email]);
    if ($check->fetch()) {
        return [false, 'Username or email is already registered.'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, "customer")');
    $stmt->execute([$username, $email, $hash]);

    return [true, 'Account created successfully.'];
}

/** Redirects to the login page (preserving where the user was headed) unless already logged in. */
/**
 * A Philippine mobile number with spaces and dashes taken out, or null when it
 * is not one: 09XXXXXXXXX or +639XXXXXXXXX. Checkout, the app API and the
 * profile form all use this one rule.
 */
function ph_mobile_normalize(string $phone): ?string
{
    $phone = preg_replace('/[\s\-]+/', '', $phone);
    return preg_match('/^(09\d{9}|\+639\d{9})$/', $phone) === 1 ? $phone : null;
}

function auth_require_login(): void
{
    if (!auth_is_logged_in()) {
        $base = defined('BASE_PATH') ? BASE_PATH : '';
        $redirect = $base . $_SERVER['REQUEST_URI'];
        header('Location: ' . $base . '/login.php?redirect=' . urlencode($redirect));
        exit;
    }
}

/**
 * The signed-in user's own record, or null.
 *
 * Suspension is no longer a login-time check: auth_is_logged_in() consults
 * auth_session_suspended() on every request, so a suspended account gets null
 * here on its very next page load rather than at its next sign-in.
 */
function auth_current_user(): ?array
{
    if (!auth_is_logged_in()) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, username, full_name, email, phone, address, role, status, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function auth_update_profile(int $userId, array $data): void
{
    $stmt = db()->prepare('UPDATE users SET full_name = ?, phone = ?, address = ? WHERE id = ?');
    $stmt->execute([$data['full_name'] ?? null, $data['phone'] ?? null, $data['address'] ?? null, $userId]);
}

function auth_change_password(int $userId, string $newPassword): void
{
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([$hash, $userId]);

    // Every mobile session for this account goes with the old password.
    // Someone who changes their password because they think they were
    // compromised expects that to end the intruder's access; without this the
    // intruder's bearer token keeps working for the rest of its 30 days, and
    // the one action the customer knows to take does nothing about it.
    db()->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$userId]);
}

/**
 * The stored form of an API bearer token.
 *
 * `api_tokens` holds this, never the token itself. A token is a credential
 * that bypasses the password outright, so storing it in the clear undoes the
 * bcrypt hashing two functions above: anything that can read one row of that
 * table owns every mobile account in it, with no cracking to do.
 *
 * SHA-256 rather than password_hash(): this value has 256 bits of entropy from
 * random_bytes(), so there is no dictionary to slow an attacker down with, and
 * a bcrypt verify cannot be used for the lookup anyway — the query has to find
 * the row by value, which needs a deterministic hash.
 *
 * The hex digest is 64 characters, exactly what the token was, so it still
 * fits api_tokens.token varchar(64) and no migration is needed.
 */
function auth_api_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function auth_verify_password(int $userId, string $password): bool
{
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $hash = $stmt->fetchColumn();
    return $hash && password_verify($password, $hash);
}

/** Admin: list customer accounts */
function user_list_customers(int $limit = 500): array
{
    $stmt = db()->prepare("SELECT * FROM users WHERE role = 'customer' ORDER BY created_at DESC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Admin: list admin accounts */
function user_list_admins(int $limit = 200): array
{
    $stmt = db()->prepare("SELECT * FROM users WHERE role = 'admin' ORDER BY created_at DESC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function user_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function user_set_status(int $id, string $status): void
{
    if (!in_array($status, ['active', 'suspended'], true)) {
        return;
    }
    $stmt = db()->prepare('UPDATE users SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
}

/** Admin: create another admin account */
function user_create_admin(string $username, string $email, string $password): array
{
    $db = db();
    $check = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
    $check->execute([$username, $email]);
    if ($check->fetch()) {
        return [false, 'Username or email is already registered.'];
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, "admin")');
    $stmt->execute([$username, $email, $hash]);
    return [true, 'Admin account created.'];
}

function user_delete(int $id): void
{
    $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * A customer deletes their own account (website profile and the app).
 *
 * Anonymised rather than removed: orders.user_id has no ON DELETE, and the
 * orders are the shop's sales records. Everything else personal goes — saved
 * addresses, bag, wishlist, notifications, phones — and the user row keeps no
 * name, email, phone or address, a password nobody knows, and 'suspended' so
 * it can never sign in. The email is freed, so the person can register again.
 */
function auth_delete_own_account(int $userId): bool
{
    $user = user_find($userId);
    if (!$user || ($user['role'] ?? '') === 'admin') {
        return false;
    }

    $db = db();
    $db->beginTransaction();
    try {
        foreach (['api_tokens', 'addresses', 'device_tokens', 'wishlists',
                  'cart_items', 'notifications', 'password_resets'] as $table) {
            $db->prepare("DELETE FROM {$table} WHERE user_id = ?")->execute([$userId]);
        }
        $db->prepare(
            "UPDATE users SET username = ?, email = ?, full_name = NULL, phone = NULL,
                    address = NULL, password_hash = ?, status = 'suspended'
             WHERE id = ?"
        )->execute([
            'deleted_' . $userId,
            'deleted_' . $userId . '@deleted.invalid',
            password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            $userId,
        ]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return true;
}

/* ---------------------------------------------------------------------------
 * Mobile app: bearer-token auth (additive — nothing above this line changed).
 *
 * The website keeps using PHP sessions. api/auth.php issues opaque tokens into
 * the existing `api_tokens` table, and these two helpers read them back so any
 * api/*.php endpoint can identify a mobile caller.
 * ------------------------------------------------------------------------- */

/**
 * Extracts the raw token from the Authorization header.
 *
 * Apache/CGI on shared hosting (Hostinger included) sometimes drops the
 * Authorization header before PHP sees it, so this checks the CGI-rewritten
 * variant and getallheaders() as fallbacks.
 */
function auth_bearer_token_value(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strcasecmp($key, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }

    if (!preg_match('/Bearer\s+(\S+)/i', (string) $header, $m)) {
        return null;
    }
    return $m[1];
}

/** Resolves a Bearer token from the Authorization header to a user_id, or null. */
function auth_user_id_from_bearer_token(): ?int
{
    $token = auth_bearer_token_value();
    if ($token === null) {
        return null;
    }

    // Joined to users so suspension applies to the app as well as the site.
    // The web session is checked in auth_session_suspended(); this is the other
    // way in, and a token issued before the suspension would otherwise keep
    // working for the rest of its life — which is the full token TTL, measured
    // in days. A suspended account now reads as "no valid token" and every
    // api/*.php endpoint 401s without needing to know suspension exists.
    $stmt = db()->prepare(
        'SELECT t.user_id
           FROM api_tokens t
           JOIN users u ON u.id = t.user_id
          WHERE t.token = ?
            AND t.expires_at > NOW()
            AND COALESCE(u.status, "active") <> "suspended"
          LIMIT 1'
    );
    // Matched against the stored hash, never the raw token.
    $stmt->execute([auth_api_token_hash($token)]);
    $userId = $stmt->fetchColumn();

    return $userId ? (int) $userId : null;
}