<?php
/**
 * Mobile Auth API (token based)
 * POST /api/auth.php?action=register  -> body: {username, email, password, full_name}
 * POST /api/auth.php?action=login     -> body: {username, password}
 * POST /api/auth.php?action=forgot    -> body: {email}  (emails a reset link)
 * POST /api/auth.php?action=google    -> body: {id_token}  (Google sign-in)
 * GET  /api/auth.php?action=me        -> header: Authorization: Bearer <token>
 * POST /api/auth.php?action=logout    -> header: Authorization: Bearer <token>
 *
 * Issues opaque 64-char tokens stored in the existing `api_tokens` table. The
 * session-based web login (login.php / register.php / logout.php at the site
 * root) is untouched and keeps working exactly as before.
 */
require_once __DIR__ . '/../includes/auth.php';

/* ---------------------------------------------------------------------------
 * CORS + JSON headers. Same block as api/products.php.
 * ------------------------------------------------------------------------- */
require_once __DIR__ . '/../includes/cors.php';
api_cors_origin();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // preflight — no body
    exit;
}

/** How long a freshly issued token stays valid. */
const API_TOKEN_TTL_DAYS = 30;

/* ---------------------------------------------------------------------------
 * Helpers (local to this endpoint — includes/auth.php stays session-only apart
 * from the one shared bearer-token resolver it now exposes).
 * ------------------------------------------------------------------------- */

/** Decoded JSON request body, falling back to form-encoded POST. */
function auth_api_body(): array
{
    $json = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($json) ? $json : $_POST;
}

/** Sends {"error": "..."} with $status and stops. */
function auth_api_error(string $message, int $status): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

/**
 * Creates a new token for $userId and returns the raw value.
 *
 * The raw token is handed to the caller here and never stored — only its
 * SHA-256 digest goes into the table (see auth_api_token_hash). This return is
 * the one and only time the server can produce it; a lost token is replaced by
 * signing in again, not recovered.
 *
 * The digest is 64 hex chars, the same width the raw token was, so it still
 * fits api_tokens.token varchar(64) and no migration is needed.
 */
function auth_api_issue_token(int $userId): string
{
    $token   = bin2hex(random_bytes(32));
    $expires = (new DateTimeImmutable('+' . API_TOKEN_TTL_DAYS . ' days'))->format('Y-m-d H:i:s');

    $stmt = db()->prepare('INSERT INTO api_tokens (user_id, token, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([$userId, auth_api_token_hash($token), $expires]);

    // Cheap housekeeping so the table doesn't grow without bound.
    db()->prepare('DELETE FROM api_tokens WHERE expires_at < NOW()')->execute();

    return $token;
}

/** The public shape of a user, as returned by login / register / me. */
function auth_api_public_user(array $u): array
{
    return [
        'id'        => (int) $u['id'],
        'username'  => $u['username'],
        'full_name' => $u['full_name'],
        'email'     => $u['email'],
        'phone'     => $u['phone'],
        'address'   => $u['address'],
        'role'      => $u['role'],
        // Convenience flag for the app; saves it string-comparing `role`.
        'is_admin'  => ($u['role'] ?? '') === 'admin',
    ];
}

/** Loads the full user row for $userId, or null. */
function auth_api_find_user(int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, username, full_name, email, phone, address, role, status
           FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

/* ---------------------------------------------------------------------------
 * POST ?action=register
 * ------------------------------------------------------------------------- */
if ($action === 'register' && $method === 'POST') {
    // Nothing stopped one address creating accounts in a loop. Counted per IP
    // by the same table that throttles sign-in; a real customer registers once
    // and never sees this.
    require_once __DIR__ . '/../includes/login_throttle.php';
    $wait = throttle_delay('register');
    if ($wait > 0) {
        header('Retry-After: ' . $wait);
        auth_api_error(login_throttle_message($wait), 429);
    }
    throttle_record('register');

    $input = auth_api_body();

    $username = trim((string) ($input['username'] ?? ''));
    $email    = trim((string) ($input['email'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $fullName = trim((string) ($input['full_name'] ?? ''));

    if ($username === '' || $email === '' || $password === '') {
        auth_api_error('Username, email, and password are required.', 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        auth_api_error('Please enter a valid email address.', 422);
    }
    if (strlen($password) < 8) {
        auth_api_error('Password must be at least 8 characters.', 422);
    }

    // Reuses the web registration helper: uniqueness check + password_hash().
    [$ok, $message] = auth_register_customer($username, $email, $password);
    if (!$ok) {
        auth_api_error($message, 422);
    }

    $stmt = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $userId = (int) $stmt->fetchColumn();

    // auth_register_customer() doesn't take a display name, so store it after.
    if ($fullName !== '') {
        $nameStmt = db()->prepare('UPDATE users SET full_name = ? WHERE id = ?');
        $nameStmt->execute([$fullName, $userId]);
    }

    // Log them straight in so the app doesn't need a second round trip.
    $token = auth_api_issue_token($userId);

    http_response_code(201);
    echo json_encode([
        'token' => $token,
        'user'  => auth_api_public_user(auth_api_find_user($userId)),
    ]);
    exit;
}

/* ---------------------------------------------------------------------------
 * POST ?action=login
 * ------------------------------------------------------------------------- */
if ($action === 'login' && $method === 'POST') {
    $input = auth_api_body();

    // `username` is the documented field; an email in the same box also works.
    $username = trim((string) ($input['username'] ?? $input['login'] ?? $input['email'] ?? ''));
    $password = (string) ($input['password'] ?? '');

    if ($username === '' || $password === '') {
        auth_api_error('Username and password are required.', 422);
    }

    // Checked before the password is looked at, so a throttled attempt cannot
    // be used to probe whether an account exists. 429 is the honest status.
    require_once __DIR__ . '/../includes/login_throttle.php';
    $wait = login_throttle_delay($username);
    if ($wait > 0) {
        header('Retry-After: ' . $wait);
        auth_api_error(login_throttle_message($wait), 429);
    }

    $stmt = db()->prepare(
        'SELECT id, username, full_name, email, phone, address, password_hash, role, status
           FROM users WHERE username = ? OR email = ? LIMIT 1'
    );
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    // Same message for "no such user" and "wrong password" — don't leak which.
    if (!$user || !password_verify($password, $user['password_hash'])) {
        login_attempt_record($username, false);
        auth_api_error('Invalid username or password.', 401);
    }
    if (($user['status'] ?? 'active') === 'suspended') {
        login_attempt_record($username, false);
        auth_api_error('This account has been suspended.', 403);
    }

    // A phone token is a password-only sign-in; admins must pass the emailed
    // code on the admin site instead.
    if (($user['role'] ?? '') === 'admin') {
        auth_api_error('Admin accounts sign in on the admin website.', 403);
    }

    login_attempt_record($username, true);
    $token = auth_api_issue_token((int) $user['id']);

    echo json_encode([
        'token' => $token,
        'user'  => auth_api_public_user($user),
    ]);
    exit;
}

/* ---------------------------------------------------------------------------
 * POST ?action=google  -> body: {id_token}
 *
 * The app signs in with Google on the phone and sends the ID token here. Same
 * {token, user} as ?action=login on success. Answers 200 with ok:false and a
 * message on refusal: the app can only read the body of a successful call.
 * ------------------------------------------------------------------------- */
if ($action === 'google' && $method === 'POST') {
    require_once __DIR__ . '/../includes/google_auth.php';

    $input  = auth_api_body();
    $claims = google_verify_id_token((string) ($input['id_token'] ?? ''));
    [$user, $error] = $claims
        ? google_user_for($claims)
        : [null, 'Google sign-in could not be verified. Please try again.'];

    if ($user === null) {
        echo json_encode(['ok' => false, 'message' => $error, 'token' => '', 'user' => null]);
        exit;
    }

    echo json_encode([
        'ok'      => true,
        'message' => 'Signed in',
        'token'   => auth_api_issue_token((int) $user['id']),
        'user'    => auth_api_public_user($user),
    ]);
    exit;
}

/* ---------------------------------------------------------------------------
 * GET ?action=me
 * ------------------------------------------------------------------------- */
if ($action === 'me' && $method === 'GET') {
    $userId = auth_user_id_from_bearer_token();
    if ($userId === null) {
        auth_api_error('Valid Authorization Bearer token required.', 401);
    }

    $user = auth_api_find_user($userId);
    if (!$user || ($user['status'] ?? 'active') === 'suspended') {
        auth_api_error('Valid Authorization Bearer token required.', 401);
    }

    echo json_encode(['user' => auth_api_public_user($user)]);
    exit;
}

/* ---------------------------------------------------------------------------
 * GET ?action=session — is this token still good?
 *
 * Answers 200 either way, with {"valid": true|false}. That looks redundant
 * next to ?action=me, which already 401s on a dead token, but the mobile app
 * cannot see an HTTP status or read the body of a failed call — only a parsed
 * success body. Without this it cannot tell an expired token apart from an
 * unreachable server, and would sign customers out over a dropped connection.
 * ------------------------------------------------------------------------- */
if ($action === 'session' && $method === 'GET') {
    $userId = auth_user_id_from_bearer_token();
    $user   = $userId === null ? null : auth_api_find_user($userId);

    echo json_encode([
        'valid' => $user !== null && ($user['status'] ?? 'active') !== 'suspended',
    ]);
    exit;
}

/* ---------------------------------------------------------------------------
 * POST ?action=logout — idempotent.
 * ------------------------------------------------------------------------- */
if ($action === 'logout' && $method === 'POST') {
    $token = auth_bearer_token_value();
    if ($token !== null) {
        $stmt = db()->prepare('DELETE FROM api_tokens WHERE token = ?');
        // The row is keyed by the digest, so logging out has to hash first.
        // Without this the DELETE matches nothing and logout silently leaves
        // the session alive — the worst possible outcome for this endpoint.
        $stmt->execute([auth_api_token_hash($token)]);
    }

    echo json_encode(['message' => 'Logged out']);
    exit;
}

/* ---------------------------------------------------------------------------
 * POST ?action=forgot  -> body: {email}
 *
 * Emails a reset link. Answers identically whether or not the address has an
 * account — otherwise this endpoint becomes a way to enumerate your customers.
 * The link lands on the website; the app only asks for the email, because a
 * password reset should be reachable by someone who cannot get into the app.
 * Always a 200 with {ok, message} — the app only reads successful bodies.
 * ------------------------------------------------------------------------- */
if ($action === 'forgot' && $method === 'POST') {
    require_once __DIR__ . '/../includes/password_reset_service.php';

    $input = auth_api_body();
    $email = trim((string) ($input['email'] ?? ''));

    // A 200 with ok:false rather than a 422: the app only reads successful
    // bodies, and "that is not an email" is an answer to show.
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'message' => 'Please enter a valid email address.']);
        exit;
    }

    // Throttled inside password_reset_request, shared with the website form.
    password_reset_request($email);

    echo json_encode([
        'ok'      => true,
        'message' => 'If that email has an account, a reset link is on its way.',
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown or unsupported action. Use ?action=register, ?action=login, ?action=forgot, ?action=me, ?action=session, or ?action=logout']);
