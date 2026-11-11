<?php
/**
 * Reviews API — the mobile half of the review block on product.php.
 *
 *   GET  /api/reviews.php?product_id=5      -> approved reviews + rating summary
 *   POST /api/reviews.php?action=create     {product_id, rating, comment}
 *
 * Reading is public: the website shows reviews to anyone, and so should the app.
 * Writing needs a bearer token and follows the website's rules exactly — one
 * review per product per person, and only for something they actually bought.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/review_service.php';
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

/**
 * Reviews plus the summary, and — when the caller is signed in — whether they
 * are allowed to write one. The app needs that flag to decide between showing
 * the form and explaining why it isn't there.
 */
function reviews_payload(int $productId, ?int $viewerId): array
{
    $summary = review_summary($productId);

    $items = [];
    foreach (review_list_for_product($productId) as $row) {
        $items[] = [
            'id'         => (int) $row['id'],
            'username'   => (string) $row['username'],
            'rating'     => (int) $row['rating'],
            'comment'    => (string) ($row['comment'] ?? ''),
            'created_at' => (string) $row['created_at'],
        ];
    }

    $hasPurchased = $viewerId !== null && review_user_purchased($productId, $viewerId);
    $hasReviewed  = $viewerId !== null && review_exists($productId, $viewerId);

    return [
        'items'       => $items,
        'count'       => $summary['count'],
        'average'     => $summary['average'],
        'can_review'  => $viewerId !== null && $hasPurchased && !$hasReviewed,
        'has_reviewed' => $hasReviewed,
        'has_purchased' => $hasPurchased,
    ];
}

$viewerId = auth_user_id_from_bearer_token();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $productId = (int) ($_GET['product_id'] ?? 0);
    if ($productId < 1) {
        http_response_code(422);
        echo json_encode(['error' => 'Missing product_id']);
        exit;
    }
    echo json_encode(reviews_payload($productId, $viewerId));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if ($viewerId === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Valid Authorization Bearer token required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$action    = $_GET['action'] ?? ($input['action'] ?? '');
$productId = (int) ($input['product_id'] ?? 0);

if ($action !== 'create') {
    http_response_code(422);
    echo json_encode(['error' => 'Unknown action. Use ?action=create.']);
    exit;
}

if ($productId < 1 || !product_find($productId)) {
    http_response_code(404);
    echo json_encode(['error' => 'Product not found']);
    exit;
}

$rating  = (int) ($input['rating'] ?? 0);
$comment = trim((string) ($input['comment'] ?? ''));

if ($rating < 1 || $rating > 5) {
    http_response_code(422);
    echo json_encode(['error' => 'Please choose a rating from 1 to 5.']);
    exit;
}
if (!review_user_purchased($productId, $viewerId)) {
    http_response_code(403);
    echo json_encode(['error' => 'You can only review shoes you have ordered.']);
    exit;
}
if (review_exists($productId, $viewerId)) {
    http_response_code(409);
    echo json_encode(['error' => 'You have already reviewed this shoe.']);
    exit;
}

review_create($productId, $viewerId, $rating, $comment);

echo json_encode(reviews_payload($productId, $viewerId));
