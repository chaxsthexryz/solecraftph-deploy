<?php
/**
 * Notifications API — the mobile half of notifications.php.
 *
 *   GET  /api/notifications.php            -> the signed-in user's notifications
 *   POST /api/notifications.php?action=read -> mark them all read
 *
 * Nothing new is written here. `order_update_status()` has been dropping a row
 * into `notifications` on every status change all along — including for orders
 * placed from the app — so this endpoint only exposes what already exists.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification_service.php';

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

function notifications_payload(int $userId): array
{
    $items = [];
    foreach (notification_list_for_user($userId, 100) as $row) {
        $items[] = [
            'id'         => (int) $row['id'],
            'title'      => (string) $row['title'],
            'message'    => (string) $row['message'],
            // MySQL hands back "0"/"1" for a tinyint; the app wants a real bool
            // so it can bind visibility straight to it.
            'is_read'    => (bool) ((int) $row['is_read']),
            'created_at' => (string) $row['created_at'],
        ];
    }
    return [
        'items'  => $items,
        'count'  => count($items),
        'unread' => notification_unread_count_for_user($userId),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(notifications_payload($userId));
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

if ($action === 'read') {
    notification_mark_all_read_for_user($userId);
    echo json_encode(notifications_payload($userId));
    exit;
}

http_response_code(422);
echo json_encode(['error' => 'Unknown action. Use ?action=read.']);
