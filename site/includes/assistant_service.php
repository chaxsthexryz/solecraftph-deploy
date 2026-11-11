<?php
/**
 * The shop's help assistant: a keyword bot, no AI service and no API key.
 *
 * It answers from live store data — the catalog, settings and the signed-in
 * customer's own orders — so its answers never drift from the site.
 *
 * shortcut: keyword matching, not language understanding; questions it was not
 * built for get the help menu. Swap assistant_reply() for an LLM call if the
 * store ever wants free-form answers.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/product_service.php';
require_once __DIR__ . '/settings_service.php';
require_once __DIR__ . '/order_service.php';

const ASSISTANT_CHIPS = ['Shoes under ₱3,000', 'Running shoes size 9', 'Track my order', 'Shipping fee', 'Size help', 'Talk to a person'];

/**
 * ['reply' => text, 'items' => [shoe cards], 'links' => [[label, url]], 'chips' => [suggestions]]
 */
function assistant_reply(string $message, ?int $userId): array
{
    $m = mb_strtolower(trim($message));
    $has = fn(string $re): bool => (bool) preg_match('/' . $re . '/u', $m);
    $peso = fn(float $v): string => settings_currency() . number_format($v, 0);
    $out = fn(string $reply, array $links = [], array $items = [], array $chips = []): array
        => ['reply' => $reply, 'items' => $items, 'links' => $links, 'chips' => $chips];
    $base = defined('BASE_PATH') ? BASE_PATH : '';

    if ($m === '') {
        return $out('Ask me about shoes, sizes, shipping, payment, returns or your orders.', [], [], ASSISTANT_CHIPS);
    }

    if ($has('\b(return|returns|refund|exchange|replace|defect|defective|damaged|sira)\b')) {
        return $out('Once an order is shipped or delivered, open it in My Orders and request a return. We review every request and update you there.',
            [['Returns & Refunds policy', $base . '/page.php?slug=returns'], ['My Orders', $base . '/orders.php']]);
    }

    if ($has('\b(track|tracking|parcel|package)\b|\bmy orders?\b|order status|\bstatus\b|nasaan|where.{0,20}\border\b|#\d+')) {
        if (!$userId) {
            return $out('Sign in and I can tell you where your orders are. You can also see them in My Orders.',
                [['Log in', $base . '/login.php?redirect=' . rawurlencode($base . '/orders.php')]]);
        }
        $orders = order_list_by_user($userId, 5);
        if (preg_match('/#\s*0*(\d{1,7})\b|order\s*#?\s*0*(\d{1,7})\b/u', $m, $hit)) {
            $want = (int) ($hit[1] !== '' ? $hit[1] : $hit[2]);
            $orders = array_values(array_filter($orders, fn($o) => (int) $o['id'] === $want)) ?: $orders;
        }
        if (!$orders) {
            return $out("You don't have any orders yet. Want help finding a pair?", [], [], ['Shoes under ₱3,000', 'Casual shoes']);
        }
        $lines = $links = [];
        foreach (array_slice($orders, 0, 3) as $o) {
            $no = '#' . str_pad($o['id'], 6, '0', STR_PAD_LEFT);
            $lines[] = $no . ' — ' . ucfirst($o['status']) . ' (' . date('M j', strtotime($o['created_at'])) . ')';
            $links[] = ['Order ' . $no, $base . '/order_detail.php?id=' . (int) $o['id']];
        }
        return $out("Your latest orders:\n" . implode("\n", $lines), $links);
    }

    if ($has('\b(ship|shipping|delivery|deliver|courier|shipped|arrive|lbc)\b|how long')) {
        $free = settings_free_shipping_threshold();
        return $out('Shipping is ' . $peso(settings_shipping_fee()) . ' per order, and free on orders of '
            . $peso($free) . ' or more. Delivery time depends on your address; every status change shows in My Orders.',
            [['FAQs', $base . '/page.php?slug=faq']], [], ['Payment options', 'Track my order']);
    }

    if ($has('\b(pay|payment|gcash|cod|cash|card|credit|debit|bayad)\b')) {
        return $out('You can pay with ' . implode(', ', settings_enabled_payment_methods())
            . '. GCash and card payments go through PayMongo, so we never see your card details.', [], [], ['Shipping fee', 'Vouchers']);
    }

    if ($has('\b(voucher|vouchers|promo code|coupon|discount code|code|codes)\b')) {
        $rows = db()->query("SELECT code, type, value, min_spend FROM vouchers
            WHERE is_active = 1 AND (expires_at IS NULL OR expires_at >= CURDATE())
              AND (max_uses IS NULL OR used_count < max_uses) ORDER BY created_at DESC LIMIT 4")->fetchAll();
        if (!$rows) {
            return $out('There are no voucher codes running right now. Check back soon.');
        }
        $lines = array_map(fn($v) => $v['code'] . ' — ' . ($v['type'] === 'percent' ? (float) $v['value'] . '% off'
            : ($v['type'] === 'fixed' ? $peso((float) $v['value']) . ' off' : 'free shipping'))
            . ((float) $v['min_spend'] > 0 ? ' on ' . $peso((float) $v['min_spend']) . '+' : ''), $rows);
        return $out("Codes you can use at checkout:\n" . implode("\n", $lines), [], [], ['Shoes under ₱3,000']);
    }

    if ($has('\b(sizing|sukat|fit|fits|true to size|half size|between sizes|wide|narrow|size chart|what size|size help)\b')) {
        return $out("We sell US sizes 7–12. Tips:\n• Measure your foot in the afternoon, heel to longest toe.\n"
            . "• Between sizes? Go half a size up, more so for running shoes.\n• Wide feet: casual and lifestyle pairs usually feel roomier.\n"
            . 'Each product page shows which sizes are in stock.', [], [], ['Running shoes size 9', 'Casual shoes']);
    }

    if ($has('\b(contact|support|email|phone|call|human|person|agent|talk|tao)\b')) {
        $phone = setting('support_phone');
        return $out('You can reach our team at ' . setting('support_email', 'our support email')
            . ($phone ? ' or ' . $phone : '') . ', or send us a message and we will email you back.',
            [['Contact Support', $base . '/contact.php']]);
    }

    if ($has('\b(authentic|original|legit|fake|genuine|orig)\b')) {
        return $out('Every pair we sell is 100% authentic. If anything looks wrong when it arrives, request a return from My Orders.');
    }

    if ($has('^(hi|hello|hey|yo|good (morning|afternoon|evening)|kumusta|musta|helo)\b')) {
        return $out("Hi! I'm the SoleCraft assistant. I can find shoes for you, help with sizing, or check your orders.", [], [], ASSISTANT_CHIPS);
    }

    if ($has('\b(thanks|thank you|salamat|ty)\b')) {
        return $out('Happy to help! Anything else?', [], [], ASSISTANT_CHIPS);
    }

    return assistant_find_shoes($m, $out, $peso, $base);
}

/** The shoe finder: price, size, category, sale and free-text keywords. */
function assistant_find_shoes(string $m, callable $out, callable $peso, string $base): array
{
    $num = fn(string $n, string $k): float => (float) str_replace(',', '', $n) * ($k !== '' ? 1000 : 1);
    $max = preg_match('/(under|below|less than|max|budget|up to|hanggang|<)\s*(?:php|₱|p)?\s*([\d,]+(?:\.\d+)?)\s*(k?)/u', $m, $x) ? $num($x[2], $x[3]) : null;
    $min = preg_match('/(over|above|more than|at least|>)\s*(?:php|₱|p)?\s*([\d,]+(?:\.\d+)?)\s*(k?)/u', $m, $x) ? $num($x[2], $x[3]) : null;
    $size = preg_match('/\b(?:size|us|sz)\s*(\d{1,2})\b/u', $m, $x) ? $x[1] : null;
    $sale = (bool) preg_match('/\b(sale|on sale|discounted)\b/u', $m);
    $cheap = (bool) preg_match('/\b(cheap|cheapest|affordable|mura)\b/u', $m);

    $cats = [
        'Athletic & Performance Footwear' => '\b(running|run|jog|jogging|gym|training|basketball|sport|sports|athletic|performance|marathon|trail)\b',
        'Casual & Lifestyle Footwear'     => '\b(casual|lifestyle|everyday|sneaker|sneakers|streetwear|slip-?on)\b',
        'Formal & Dress Footwear'         => '\b(formal|dress|office|leather|oxford|loafer|loafers|derby|work|school|wedding)\b',
    ];
    $cat = null;
    foreach ($cats as $name => $re) {
        if (preg_match('/' . $re . '/u', $m)) {
            $cat = $name;
            break;
        }
    }

    // Whatever is left after the filter words is matched against the catalog text.
    $stop = 'i|im|a|an|the|some|any|show|me|find|get|want|need|looking|look|for|recommend|suggest|please|pls|do|you|have|is|are|what|which|can|with|and|or|of|in|to|my|good|nice|best|new|shoe|shoes|pair|pairs|under|below|less|than|over|above|more|max|budget|up|size|us|php|cheap|cheapest|affordable|sale|on|discounted|po|ako|ko|gusto|ng|mga|sa|na|yung|mura|hanggang|k|sz|at|least';
    $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $m),
        fn($w) => mb_strlen($w) > 1 && !is_numeric($w) && !preg_match('/^(' . $stop . ')$/u', $w)));

    $scored = [];
    foreach (product_list($cat, null, 500) as $p) {
        $price = product_display_price($p);
        if (($max !== null && $price > $max) || ($min !== null && $price < $min) || ($sale && $p['sale_price'] === null) || (int) $p['stock'] <= 0) {
            continue;
        }
        $name = mb_strtolower($p['name']);
        $text = mb_strtolower($p['name'] . ' ' . $p['category'] . ' ' . $p['subcategory'] . ' ' . $p['description']);
        $score = 0;
        foreach ($words as $w) {
            $score += mb_strpos($name, $w) !== false ? 3 : (mb_strpos($text, $w) !== false ? 1 : 0);
        }
        $scored[] = [$score, $price, $p];
    }

    $filtered = $max !== null || $min !== null || $size !== null || $sale || $cheap || $cat !== null;
    if ($words) {
        $hits = array_values(array_filter($scored, fn($s) => $s[0] > 0));
        // Unknown words alone mean we did not understand; with a filter, the filter still answers.
        if ($hits || !$filtered) {
            $scored = $hits;
        }
    }
    if (!$scored && !$filtered) {
        return $out("I didn't catch that. I can find shoes, help with sizes, explain shipping, payment and returns, or track your orders:", [], [], ASSISTANT_CHIPS);
    }

    usort($scored, fn($a, $b) => $cheap ? $a[1] <=> $b[1] : ($b[0] <=> $a[0] ?: $a[1] <=> $b[1]));
    $items = [];
    foreach ($scored as [, $price, $p]) {
        if ($size !== null && !in_array($size, product_available_sizes((int) $p['id']), true)) {
            continue;
        }
        $items[] = [
            'name'  => $p['name'],
            'price' => $peso($price) . ($p['sale_price'] !== null ? ' · sale' : ''),
            'url'   => $base . '/product.php?id=' . (int) $p['id'],
            'image' => product_image_url($p),
        ];
        if (count($items) === 4) {
            break;
        }
    }

    if (!$items) {
        return $out("I couldn't find a pair in stock that matches that. Try a higher budget, another size, or a different style.",
            [['Browse all shoes', $base . '/index.php']], [], ['Shoes under ₱5,000', 'Casual shoes', 'Running shoes']);
    }
    $what = trim(($sale ? 'on-sale ' : '') . ($cat ? strtolower(strtok($cat, ' ')) . ' ' : '') . 'pairs'
        . ($size ? ' in size ' . $size : '') . ($max !== null ? ' under ' . $peso($max) : '') . ($min !== null ? ' over ' . $peso($min) : ''));
    return $out('Here are ' . $what . ' you might like:', [['See all shoes', $base . '/index.php']], $items);
}
