<?php
/**
 * POST /api/assistant.php  body: {"message": "running shoes under 3000"}
 * -> {reply, items, links, chips}. See includes/assistant_service.php.
 *
 * Website only: order answers use the browser session, which a cross-site POST
 * does not carry (SameSite=Lax), so no other site can read someone's orders.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/assistant_service.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST a message.']);
    exit;
}

$input   = json_decode(file_get_contents('php://input') ?: '', true);
$message = mb_substr(trim((string) ($input['message'] ?? '')), 0, 300);
$userId  = auth_is_logged_in() ? (int) $_SESSION['user_id'] : null;

echo json_encode(assistant_reply($message, $userId));
