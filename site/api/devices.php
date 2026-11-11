<?php
/**
 * Device Registration API
 * POST /api/devices.php?action=register   -> body: {token, platform}
 * POST /api/devices.php?action=unregister -> body: {token}
 *
 * The app hands over its FCM token so order updates can reach it when it is
 * closed. Bearer auth only — a device belongs to whoever is signed in on it.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/push_service.php';

require_once __DIR__ . '/../includes/cors.php';
api_cors_origin();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$userId = auth_user_id_from_bearer_token();
if ($userId === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Valid Authorization Bearer token required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
$input = is_array($input) ? $input : $_POST;

$token = trim((string) ($input['token'] ?? ''));
if ($token === '') {
    http_response_code(422);
    echo json_encode(['error' => 'A device token is required.']);
    exit;
}

$action = $_GET['action'] ?? 'register';

if ($action === 'unregister') {
    push_forget_token($token);
    echo json_encode(['registered' => false]);
    exit;
}

$platform = in_array($input['platform'] ?? '', ['android', 'ios', 'web'], true)
    ? $input['platform']
    : 'android';

push_register_token($userId, $token, $platform);
echo json_encode(['registered' => true]);
