<?php
/**
 * Storefront Settings API (read-only, public)
 * GET /api/settings.php -> which payment methods are on, and which product
 *                          categories currently have something to sell.
 *
 * The mobile app hardcoded all of this. Turning GCash off in admin took it
 * off the website and left it on the app, so a customer could pick a method
 * the shop had disabled and only find out when the order failed.
 */
require_once __DIR__ . '/../includes/settings_service.php';
require_once __DIR__ . '/../includes/product_service.php';

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


// The category > subcategory pairs the shop actually has stock in, flattened
// to a list because a nested map is awkward for a typed mobile client to bind.
// Served here rather than added to the main response because the app's
// StoreSettings struct cannot gain a field without being recreated.
if (($_GET['action'] ?? '') === 'taxonomy') {
    $items = [];
    foreach (product_subcategory_map() as $category => $subcategories) {
        foreach ($subcategories as $subcategory) {
            $items[] = ['category' => $category, 'subcategory' => $subcategory];
        }
    }
    echo json_encode(['count' => count($items), 'items' => $items]);
    exit;
}
$methods = settings_enabled_payment_methods();

// Flat booleans rather than a list: the app binds each payment button's
// visibility to one of these, and a list would need a repeating widget for
// three fixed buttons that already exist.
echo json_encode([
    'cod'        => isset($methods['COD']),
    'gcash'      => isset($methods['GCASH']),
    'card'       => isset($methods['CARD']),
    // Only categories with something active in them, so the app stops
    // offering a filter that can only ever come back empty.
    'categories' => product_categories(),
]);
