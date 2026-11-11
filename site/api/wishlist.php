<?php
/**
 * Wishlist API — the mobile half of wishlist.php.
 *
 *   GET  /api/wishlist.php               -> the signed-in user's saved products
 *   POST /api/wishlist.php?action=toggle {product_id}
 *
 * Both return the same payload, so the app can write the response straight back
 * over its state and never has to reconcile a toggle against a stale list.
 *
 * Rows come out of `wishlist_products()` as full product rows — the same shape
 * api/products.php returns — so the app's existing Shoe struct and ShoeCard
 * component read them without translation.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/wishlist_service.php';
require_once __DIR__ . '/../includes/product_service.php'; // product_find()

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

function wishlist_payload(int $userId): array
{
    $items = wishlist_products($userId);
    return [
        'items' => $items,
        'count' => count($items),
        // Ids alone, so a product page can ask "is this one saved?" without
        // walking the whole list.
        'product_ids' => wishlist_product_ids($userId),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // ?product_id= answers the one question a product page has: is this saved?
    // Kept here rather than bolted onto products.php so the whole wishlist
    // feature lives in one file.
    $askAbout = (int) ($_GET['product_id'] ?? 0);
    if ($askAbout > 0) {
        echo json_encode(['wishlisted' => wishlist_has($userId, $askAbout)]);
        exit;
    }
    echo json_encode(wishlist_payload($userId));
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

if ($action === 'toggle') {
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

    $nowWishlisted = wishlist_toggle($userId, $productId);

    echo json_encode(
        ['wishlisted' => $nowWishlisted] + wishlist_payload($userId)
    );
    exit;
}

http_response_code(422);
echo json_encode(['error' => 'Unknown action. Use ?action=toggle.']);
