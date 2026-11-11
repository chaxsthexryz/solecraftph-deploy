<?php
/**
 * Shared Cart API — the mobile app's bag, same rows the website reads.
 *
 *   GET    /api/cart.php                      -> list the signed-in user's cart
 *   POST   /api/cart.php?action=add           {product_id, size, qty}
 *   POST   /api/cart.php?action=update        {product_id, size, qty}   qty<=0 removes
 *   POST   /api/cart.php?action=remove        {product_id, size}
 *   POST   /api/cart.php?action=replace       {items:[{product_id,size,qty}, ...]}
 *   POST   /api/cart.php?action=merge         {items:[...]}  (union, used at app sign-in)
 *   POST   /api/cart.php?action=clear
 *
 * Always Bearer-authenticated: a cart that isn't tied to an account is exactly
 * the thing this endpoint exists to replace. Guests keep using the on-device bag.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cart.php';
require_once __DIR__ . '/../includes/settings_service.php';

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

/** The cart as the app wants it: a flat list, plus the totals it shows. */
function cart_api_payload(): array
{
    $items = [];
    foreach (cart_items() as $line) {
        $items[] = [
            // 'id' mirrors 'product_id' so a cart line drops straight into the
            // app's existing BagItem struct with no translation step.
            'id'         => $line['product_id'],
            'product_id' => $line['product_id'],
            'name'       => $line['name'],
            'size'       => $line['size'],
            'qty'        => $line['qty'],
            'price'      => (float) $line['price'],
            'image'      => $line['image'],
            'subtotal'   => round($line['price'] * $line['qty'], 2),
        ];
    }
    $subtotal = cart_total();
    $shipping = function_exists('settings_shipping_for') ? (float) settings_shipping_for($subtotal) : 0.0;
    return [
        'items'    => $items,
        'count'    => cart_count(),
        'subtotal' => round($subtotal, 2),
        'shipping' => round($shipping, 2),
        'total'    => round($subtotal + $shipping, 2),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(cart_api_payload());
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
$action    = $_GET['action'] ?? ($input['action'] ?? '');
$productId = (int) ($input['product_id'] ?? 0);
$size      = (string) ($input['size'] ?? '');
$qty       = (int) ($input['qty'] ?? 1);

switch ($action) {
    case 'add':
        if ($productId < 1) {
            http_response_code(422);
            echo json_encode(['error' => 'Missing product_id']);
            exit;
        }
        if (!product_find($productId)) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            exit;
        }
        cart_add($productId, max(1, $qty), $size);
        break;

    case 'update':
        if ($productId < 1) {
            http_response_code(422);
            echo json_encode(['error' => 'Missing product_id']);
            exit;
        }
        cart_update($productId, $qty, $size);
        break;

    case 'remove':
        if ($productId < 1) {
            http_response_code(422);
            echo json_encode(['error' => 'Missing product_id']);
            exit;
        }
        cart_remove($productId, $size);
        break;

    case 'replace':
        // The app's whole bag in one shot. `items` is authoritative: whatever
        // isn't in it is gone from the cart afterwards.
        if (!isset($input['items']) || !is_array($input['items'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Missing items array']);
            exit;
        }
        cart_replace($input['items']);
        break;

    case 'merge':
        // Called once, right after the app signs in: the bag built while signed
        // out is added to whatever the account already had. A union, not a
        // replace — a cart filled on the website survives.
        if (!isset($input['items']) || !is_array($input['items'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Missing items array']);
            exit;
        }
        cart_merge_lines($userId, $input['items']);
        break;

    case 'clear':
        cart_clear();
        break;

    default:
        http_response_code(422);
        echo json_encode(['error' => 'Unknown action. Use ?action=add, update, remove, replace, merge, or clear.']);
        exit;
}

echo json_encode(cart_api_payload());
