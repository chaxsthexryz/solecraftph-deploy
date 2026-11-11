<?php
/**
 * Signed-in order history
 * GET /api/my_orders.php  -> header: Authorization: Bearer <token>
 *
 * 200 -> { "count": N, "orders": [ { ...order row... }, ... ] }
 * 401 -> { "error": "..." }
 *
 * Reuses order_list_by_user() from includes/order_service.php as-is.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order_service.php';

/* ---------------------------------------------------------------------------
 * CORS + JSON headers. Same block as api/products.php.
 * ------------------------------------------------------------------------- */
require_once __DIR__ . '/../includes/cors.php';
api_cors_origin();
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // preflight — no body
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Website sessions work here too, so the same endpoint serves both clients.
$userId = $_SESSION['user_id'] ?? auth_user_id_from_bearer_token();

if ($userId === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Valid Authorization Bearer token required.']);
    exit;
}

$orders = order_list_by_user((int) $userId);

$orders = array_map('order_for_app', $orders);
echo json_encode(['count' => count($orders), 'orders' => $orders]);
