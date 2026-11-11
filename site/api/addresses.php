<?php
/**
 * Saved Addresses API
 *   GET  /api/addresses.php                 -> this customer's saved addresses
 *   POST /api/addresses.php?action=save     -> {id?, label, recipient_name, phone, address, latitude?, longitude?}
 *   POST /api/addresses.php?action=default  -> {id}
 *   POST /api/addresses.php?action=delete   -> {id}
 *
 * Bearer auth only, and every operation is scoped to the token's own user —
 * passing someone else's address id gets a 404, not their address.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/address_service.php';

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

/** The whole list, which every write returns so the app never has to re-fetch. */
function addresses_payload(int $userId): array
{
    $items = array_map('address_public', address_list($userId));
    return ['count' => count($items), 'items' => $items];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(addresses_payload($userId));
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
$input = is_array($input) ? $input : $_POST;
$action = $_GET['action'] ?? 'save';

if ($action === 'save') {
    $id = address_save($userId, $input);
    if ($id === null) {
        http_response_code(422);
        echo json_encode([
            'error' => 'Could not save that address. It needs at least an address line, '
                . 'and you can keep up to ' . ADDRESS_MAX_PER_USER . '.',
        ]);
        exit;
    }
    echo json_encode(['saved' => $id] + addresses_payload($userId));
    exit;
}

$id = (int) ($input['id'] ?? 0);

if ($action === 'default') {
    if (!address_set_default($userId, $id)) {
        http_response_code(404);
        echo json_encode(['error' => 'No such address.']);
        exit;
    }
    echo json_encode(addresses_payload($userId));
    exit;
}

if ($action === 'delete') {
    if (!address_delete($userId, $id)) {
        http_response_code(404);
        echo json_encode(['error' => 'No such address.']);
        exit;
    }
    echo json_encode(addresses_payload($userId));
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action. Use ?action=save, ?action=default or ?action=delete.']);
