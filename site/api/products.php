<?php
/**
 * Shared Product API
 * GET  /api/products.php            -> list active products (optional ?category=, ?q=)
 * GET  /api/products.php?id=5       -> single product
 * POST /api/products.php            -> create product (admin session required)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/product_service.php';

/* ---------------------------------------------------------------------------
 * CORS + JSON headers. Lets the mobile app and browser-based clients
 * (FlutterFlow web preview, etc.) call this endpoint cross-origin.
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

/* ---------------------------------------------------------------------------
 * Resolve a product's `image` column to an absolute, ready-to-use URL.
 *   - already a full http(s) URL  -> returned as-is
 *   - a stored filename           -> https://<this-host>/uploads/<file>
 *   - empty / null                -> null
 * Adds `image_url` and also overwrites `image` with the resolved value so
 * every client gets a usable link with no extra logic.
 * ------------------------------------------------------------------------- */
function api_with_image_url(array $product): array
{
    $raw = $product['image'] ?? null;

    if ($raw === null || $raw === '') {
        $url = null;
    } elseif (preg_match('#^https?://#i', $raw)) {
        $url = $raw;
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base   = defined('BASE_PATH') ? BASE_PATH : '';
        $url    = $scheme . '://' . $host . $base . '/uploads/' . rawurlencode($raw);
    }

    $product['image_url'] = $url;
    $product['image']     = $url; // keep both fields in sync for older clients

    // Which sizes can actually be bought. For a product not yet converted to
    // per-size stock this is every offered size while the product has stock, so
    // the app behaves exactly as before until the catalogue is counted.
    $product['available_sizes'] = product_available_sizes((int) $product['id']);

    // Every photo, absolute: the gallery on Shoe details swipes through these.
    $origin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $product['images'] = array_map(
        fn ($u) => preg_match('#^https?://#i', $u) ? $u : $origin . $u,
        product_gallery(array_merge($product, ['image' => $raw]))
    );

    return $product;
}

/** Adds avg_rating and review_count (0 when unreviewed) to each product. */
function api_with_ratings(array $products): array
{
    $ratings = product_ratings(array_column($products, 'id'));
    foreach ($products as &$p) {
        $r = $ratings[(int) $p['id']] ?? ['avg' => 0, 'count' => 0];
        $p['avg_rating']   = $r['avg'];
        $p['review_count'] = $r['count'];
    }
    return $products;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $id = $_GET['id'] ?? null;
    if ($id !== null) {
        $product = product_find((int) $id);
        if (!$product) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            exit;
        }
        echo json_encode(api_with_ratings([api_with_image_url($product)])[0]);
        exit;
    }

    $q           = trim($_GET['q'] ?? '');
    $category    = trim($_GET['category'] ?? '');
    $subcategory = trim($_GET['subcategory'] ?? '');

    $products = $q !== '' ? product_search($q) : product_list($category ?: null, $subcategory ?: null);
    $products = api_with_ratings(array_map('api_with_image_url', $products));
    echo json_encode(['count' => count($products), 'products' => $products]);
    exit;
}

if ($method === 'POST') {
    if (!auth_is_admin()) {
        http_response_code(401);
        echo json_encode(['error' => 'Admin authentication required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $required = ['name', 'category', 'price', 'stock'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            http_response_code(422);
            echo json_encode(['error' => "Missing required field: $field"]);
            exit;
        }
    }

    $id = product_create([
        'name'        => $input['name'],
        'category'    => $input['category'],
        'subcategory' => $input['subcategory'] ?? null,
        'description' => $input['description'] ?? '',
        'price'       => (float) $input['price'],
        'sale_price'  => isset($input['sale_price']) && $input['sale_price'] !== '' ? (float) $input['sale_price'] : null,
        'stock'       => (int) $input['stock'],
        'badge'       => $input['badge'] ?? null,
        'image'       => $input['image'] ?? null,
    ]);

    http_response_code(201);
    echo json_encode(['id' => $id, 'message' => 'Product created']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);