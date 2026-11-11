<?php
/**
 * App home API (read-only, public)
 * GET /api/home.php -> the promo slides and the voucher strip at the top of
 *                      the app's Shop screen.
 *
 * Slides are the banners from Admin → Banners. With none active the shop
 * still gets slides built from the catalogue (newest pair, a sale pair, free
 * shipping), so the top of the app is never a blank box.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/product_service.php';
require_once __DIR__ . '/../includes/cms_service.php';
require_once __DIR__ . '/../includes/settings_service.php';
require_once __DIR__ . '/../includes/voucher_service.php';

require_once __DIR__ . '/../includes/cors.php';
api_cors_origin();
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$origin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_PATH;
$abs = fn (string $path): string => preg_match('#^https?://#i', $path) ? $path : $origin . '/' . ltrim($path, '/');
$photo = fn (array $p): ?string => empty($p['image']) ? null
    : (preg_match('#^https?://#i', $p['image']) ? $p['image'] : $origin . '/uploads/' . rawurlencode($p['image']));

$products = product_list(null, null, 40);
$price = fn (array $p): string => '₱' . number_format((float) $p['sale_price'] > 0 ? (float) $p['sale_price'] : (float) $p['price'], 2);
$slide = fn (string $title, string $subtitle, ?array $p, ?string $image = null, ?string $video = null, string $priceLabel = ''): array => [
    'title'      => $title,
    'subtitle'   => $subtitle,
    'image'      => $image,                       // full artwork, shown as-is
    'photo'      => $p ? $photo($p) : null,       // shoe shown beside the text
    'product_id' => $p ? (int) $p['id'] : 0,      // 0 = not tappable
    'video'      => $video,                       // promo video, plays in the app
    'price'      => $priceLabel,                  // the linked shoe's price, '' if none
];

$slides = [];
foreach (array_values(banner_list_active()) as $i => $b) {
    // A banner linking to product.php?id=N opens that shoe in the app.
    $linked = null;
    if (preg_match('/[?&]id=(\d+)/', (string) $b['link_url'], $m)) {
        foreach ($products as $p) {
            if ((int) $p['id'] === (int) $m[1]) {
                $linked = $p;
            }
        }
    }
    $slides[] = $slide(
        (string) $b['title'],
        (string) $b['subtitle'],
        $linked ?? ($products[$i % max(1, count($products))] ?? null),
        ($b['image_url'] ?? '') !== '' ? $abs($b['image_url']) : null,
        ($b['video_url'] ?? '') !== '' ? $abs($b['video_url']) : null,
        $linked ? $price($linked) : ''
    );
}

if (!$slides && $products) {
    $slides[] = $slide('New drops', 'Fresh pairs just landed. Be first in line.', $products[0]);
    $best = null;
    $bestOff = 0;
    foreach ($products as $p) {
        $off = (float) $p['sale_price'] > 0 ? (int) round((1 - (float) $p['sale_price'] / (float) $p['price']) * 100) : 0;
        if ($off > $bestOff) {
            [$best, $bestOff] = [$p, $off];
        }
    }
    if ($best) {
        $slides[] = $slide($bestOff . '% off ' . $best['name'], 'Sale price while sizes last.', $best);
    }
    $slides[] = $slide(
        'Free shipping',
        'On orders ₱' . number_format(settings_free_shipping_threshold()) . ' and up.',
        $products[min(1, count($products) - 1)]
    );
}

// The voucher strip shows the oldest code anyone can still use (WELCOME10).
$voucher = null;
$vouchers = voucher_list();
usort($vouchers, fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);
foreach ($vouchers as $v) {
    if (!(int) $v['is_active']
        || (!empty($v['expires_at']) && $v['expires_at'] < date('Y-m-d'))
        || ($v['max_uses'] !== null && (int) $v['used_count'] >= (int) $v['max_uses'])) {
        continue;
    }
    $label = $v['type'] === 'percent' ? rtrim(rtrim(number_format((float) $v['value'], 2), '0'), '.') . '% off your order'
        : ($v['type'] === 'fixed' ? '₱' . number_format((float) $v['value']) . ' off your order' : 'Free shipping on your order');
    if ((float) $v['min_spend'] > 0) {
        $label .= ' of ₱' . number_format((float) $v['min_spend']) . '+';
    }
    $voucher = ['code' => $v['code'], 'label' => $label];
    break;
}

// Admin → Banners → App welcome video: plays behind the app's Account header.
$welcome = (string) setting('app_welcome_video', '');

echo json_encode([
    'banners'       => $slides,
    'voucher'       => $voucher,
    'welcome_video' => $welcome !== '' ? $abs($welcome) : null,
]);
