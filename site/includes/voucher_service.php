<?php
/**
 * Voucher codes at checkout (website and app).
 *
 * Three kinds: 'percent' (value % off the items), 'fixed' (₱value off the
 * items) and 'shipping' (the shipping fee waived). Checked twice: once when the
 * shopper taps Apply, to show the discount, and again inside order_create(),
 * which is the only answer that counts.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/settings_service.php';

const VOUCHER_TYPES = ['percent', 'fixed', 'shipping'];

function voucher_normalize(string $code): string
{
    return strtoupper(trim($code));
}

function voucher_find(string $code): ?array
{
    $stmt = db()->prepare('SELECT * FROM vouchers WHERE code = ? LIMIT 1');
    $stmt->execute([voucher_normalize($code)]);
    return $stmt->fetch() ?: null;
}

/**
 * What [$code] takes off an order of [$subtotal] (items only, before shipping).
 * ['ok' => bool, 'message' => string, 'code' => string, 'discount' => float]
 */
function voucher_quote(string $code, float $subtotal): array
{
    $code = voucher_normalize($code);
    $no = function (string $why) use ($code): array {
        return ['ok' => false, 'message' => $why, 'code' => $code, 'discount' => 0.0];
    };

    if ($code === '') {
        return $no('Enter a voucher code.');
    }
    $v = voucher_find($code);
    if (!$v || !(int) $v['is_active']) {
        return $no('That voucher code is not valid.');
    }
    if (!empty($v['expires_at']) && $v['expires_at'] < date('Y-m-d')) {
        return $no('That voucher has expired.');
    }
    if ($v['max_uses'] !== null && (int) $v['used_count'] >= (int) $v['max_uses']) {
        return $no('That voucher has been fully used.');
    }
    if ($subtotal < (float) $v['min_spend']) {
        return $no('Spend at least ₱' . number_format((float) $v['min_spend'], 2) . ' to use ' . $code . '.');
    }

    if ($v['type'] === 'percent') {
        $discount = round($subtotal * (float) $v['value'] / 100, 2);
    } elseif ($v['type'] === 'fixed') {
        $discount = min((float) $v['value'], $subtotal);
    } else {
        $discount = settings_shipping_for($subtotal);
    }
    if ($discount <= 0) {
        return $no($v['type'] === 'shipping'
            ? 'Shipping is already free on this order.'
            : 'That voucher does not lower this order.');
    }

    return [
        'ok'       => true,
        'message'  => $code . ' applied: −₱' . number_format($discount, 2),
        'code'     => $code,
        'discount' => $discount,
    ];
}

/** Counts one use. False when the last use was taken in the meantime. */
function voucher_redeem(string $code): bool
{
    $stmt = db()->prepare(
        'UPDATE vouchers SET used_count = used_count + 1
         WHERE code = ? AND (max_uses IS NULL OR used_count < max_uses)'
    );
    $stmt->execute([voucher_normalize($code)]);
    return $stmt->rowCount() === 1;
}

/** What the customer pays: items + shipping − voucher. */
function order_amount_due(array $order): float
{
    $items = (float) $order['total_amount'];
    return max(0.0, $items + settings_shipping_for($items) - (float) ($order['discount_amount'] ?? 0));
}

/* ---- Admin -------------------------------------------------------------- */

function voucher_list(): array
{
    return db()->query('SELECT * FROM vouchers ORDER BY created_at DESC')->fetchAll();
}

/** Creates or updates by code. Returns an error to show, or null. */
function voucher_save(array $in): ?string
{
    $code = voucher_normalize((string) ($in['code'] ?? ''));
    $type = (string) ($in['type'] ?? '');
    if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
        return 'Codes are 3–40 letters, numbers, dashes or underscores.';
    }
    if (!in_array($type, VOUCHER_TYPES, true)) {
        return 'Choose a voucher type.';
    }
    $value = (float) ($in['value'] ?? 0);
    if ($type === 'percent' && ($value <= 0 || $value > 100)) {
        return 'A percent voucher takes 1 to 100.';
    }
    if ($type === 'fixed' && $value <= 0) {
        return 'Enter how many pesos the voucher takes off.';
    }
    $maxUses = trim((string) ($in['max_uses'] ?? ''));
    $expires = trim((string) ($in['expires_at'] ?? ''));

    db()->prepare(
        'INSERT INTO vouchers (code, type, value, min_spend, max_uses, expires_at, is_active)
         VALUES (?, ?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE type = VALUES(type), value = VALUES(value),
           min_spend = VALUES(min_spend), max_uses = VALUES(max_uses),
           expires_at = VALUES(expires_at)'
    )->execute([
        $code,
        $type,
        $type === 'shipping' ? 0 : $value,
        max(0, (float) ($in['min_spend'] ?? 0)),
        $maxUses === '' ? null : max(1, (int) $maxUses),
        $expires === '' ? null : $expires,
    ]);
    return null;
}

function voucher_toggle(int $id): void
{
    db()->prepare('UPDATE vouchers SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
}
