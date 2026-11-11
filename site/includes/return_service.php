<?php
/**
 * Returns / Refunds Service.
 */
require_once __DIR__ . '/../config/db.php';

function return_create(int $orderId, ?int $userId, string $reason, string $details): int
{
    $stmt = db()->prepare(
        'INSERT INTO returns (order_id, user_id, reason, details, status) VALUES (?, ?, ?, ?, "requested")'
    );
    $stmt->execute([$orderId, $userId, $reason, $details]);
    return (int) db()->lastInsertId();
}

function return_list_for_user(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT r.*, o.total_amount FROM returns r JOIN orders o ON o.id = r.order_id
         WHERE r.user_id = ? ORDER BY r.created_at DESC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function return_list_all(int $limit = 300): array
{
    $stmt = db()->prepare(
        'SELECT r.*, o.customer_name, o.customer_email, o.total_amount FROM returns r
         JOIN orders o ON o.id = r.order_id
         ORDER BY r.created_at DESC LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function return_find(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT r.*, o.customer_name, o.customer_email, o.total_amount FROM returns r
         JOIN orders o ON o.id = r.order_id WHERE r.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function return_update_status(int $id, string $status, string $adminNotes = ''): void
{
    if (!in_array($status, ['requested', 'approved', 'rejected', 'refunded'], true)) {
        return;
    }
    $stmt = db()->prepare('UPDATE returns SET status = ?, admin_notes = ? WHERE id = ?');
    $stmt->execute([$status, $adminNotes, $id]);

    // Tell the customer — the app's order screen shows the status too.
    $ret = return_find($id);
    if ($ret && !empty($ret['user_id']) && $status !== 'requested') {
        require_once __DIR__ . '/notification_service.php';
        notification_notify_customer(
            (int) $ret['user_id'],
            'Return for order #' . str_pad((string) $ret['order_id'], 6, '0', STR_PAD_LEFT) . ' — ' . ucfirst($status),
            'Your return request is now ' . $status . '.' . ($adminNotes !== '' ? ' Note: ' . $adminNotes : '')
        );
    }
}

/** What a customer may give as the reason; the website's dropdown and the app's. */
const RETURN_REASONS = [
    'Wrong size',
    'Item damaged / defective',
    'Not as described',
    'Changed my mind',
    'Other',
];

/** The order's return status ('requested', 'approved', …), or '' when none. */
function return_status_for_order(int $orderId): string
{
    $stmt = db()->prepare('SELECT status FROM returns WHERE order_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$orderId]);
    return (string) ($stmt->fetchColumn() ?: '');
}

/**
 * A customer asks to return their order. Returns an error to show, or null.
 * One rule for order_detail.php and the app: their own order, shipped or
 * delivered, and only one request per order.
 */
function return_request_by_customer(int $orderId, int $userId, string $reason, string $details): ?string
{
    require_once __DIR__ . '/order_service.php';
    $order = order_find($orderId);
    if ($order === null || (int) ($order['user_id'] ?? 0) !== $userId) {
        return 'Order not found.';
    }
    if (!in_array($reason, RETURN_REASONS, true)) {
        return 'Please choose a reason for your return.';
    }
    if (!in_array($order['status'] ?? '', ['shipped', 'completed'], true)) {
        return 'This order is not yet eligible for a return.';
    }
    if (return_has_existing($orderId)) {
        return 'A return request already exists for this order.';
    }

    return_create($orderId, $userId, $reason, mb_substr($details, 0, 1000));

    require_once __DIR__ . '/notification_service.php';
    notification_notify_admins(
        'Return requested',
        'Order #' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT) . ': ' . $reason
    );
    return null;
}

function return_has_existing(int $orderId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM returns WHERE order_id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    return (bool) $stmt->fetchColumn();
}
