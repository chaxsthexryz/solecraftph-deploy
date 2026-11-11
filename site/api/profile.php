<?php
/**
 * Profile API — the mobile half of profile.php.
 *
 *   GET  /api/profile.php                  -> the signed-in user's details
 *   POST /api/profile.php?action=update    {full_name, phone, address}
 *   POST /api/profile.php?action=password  {current_password, new_password}
 *   POST /api/profile.php?action=delete    deletes the account -> {ok, message}
 *
 * Bearer-authenticated throughout: a profile belongs to exactly one account and
 * there is no reason to reach it any other way.
 *
 * The rules mirror the website's profile.php deliberately — same 8-character
 * minimum, same current-password check — so the two can't drift into accepting
 * passwords the other would reject.
 */
require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/cors.php';
api_cors_origin();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$userId = auth_user_id_from_bearer_token();
if ($userId === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Valid Authorization Bearer token required.']);
    exit;
}

/** The account as the app shows it. Never includes the password hash. */
function profile_payload(int $userId): array
{
    $user = user_find($userId);
    if (!$user) {
        return [];
    }
    return [
        'id'        => (int) $user['id'],
        'username'  => $user['username'],
        'email'     => $user['email'],
        'full_name' => $user['full_name'] ?? '',
        'phone'     => $user['phone'] ?? '',
        'address'   => $user['address'] ?? '',
        'role'      => $user['role'] ?? 'customer',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(profile_payload($userId));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$action = $_GET['action'] ?? ($input['action'] ?? '');

if ($action === 'update') {
    // A blank field means "leave this alone", not "erase it". The app's form
    // starts empty — the DSL's TextField has no initial-value binding — so a
    // shopper fixing only their phone number must not lose their address.
    // Clearing a field outright is a website job; nothing in the app asks for it.
    $current = user_find($userId) ?? [];
    $keepOr = static function (string $sent, ?string $existing): string {
        $sent = trim($sent);
        return $sent !== '' ? $sent : (string) ($existing ?? '');
    };

    $fullName = $keepOr((string) ($input['full_name'] ?? ''), $current['full_name'] ?? '');
    $phone    = $keepOr((string) ($input['phone'] ?? ''), $current['phone'] ?? '');
    $address  = $keepOr((string) ($input['address'] ?? ''), $current['address'] ?? '');

    if ($fullName === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Please enter your full name.']);
        exit;
    }

    auth_update_profile($userId, [
        'full_name' => $fullName,
        'phone'     => $phone,
        'address'   => $address,
    ]);

    echo json_encode([
        'message' => 'Your profile has been updated.',
        'profile' => profile_payload($userId),
    ]);
    exit;
}

if ($action === 'password') {
    $current = (string) ($input['current_password'] ?? '');
    $new     = (string) ($input['new_password'] ?? '');

    if (!auth_verify_password($userId, $current)) {
        http_response_code(422);
        echo json_encode(['error' => 'Your current password is incorrect.']);
        exit;
    }
    if (strlen($new) < 8) {
        http_response_code(422);
        echo json_encode(['error' => 'New password must be at least 8 characters long.']);
        exit;
    }

    auth_change_password($userId, $new);

    // Every existing token was minted against the old password. Dropping them
    // means a stolen phone stops working the moment the password is changed —
    // the app signs in again with the new one.
    $stmt = db()->prepare('DELETE FROM api_tokens WHERE user_id = ?');
    $stmt->execute([$userId]);

    echo json_encode(['message' => 'Your password has been changed. Please sign in again.']);
    exit;
}

// The app asks "are you sure?" before calling this. Always a 200 with
// {ok, message}: the app only reads successful bodies.
if ($action === 'delete') {
    $ok = auth_delete_own_account($userId);
    echo json_encode([
        'ok'      => $ok,
        'message' => $ok
            ? 'Your account has been deleted.'
            : 'This account cannot be deleted here. Contact us from Help.',
    ]);
    exit;
}

http_response_code(422);
echo json_encode(['error' => 'Unknown action. Use ?action=update, ?action=password or ?action=delete.']);
