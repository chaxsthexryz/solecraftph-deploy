<?php
/**
 * Notification Service.
 * Customer notifications (order status, etc.) are tied to a user_id.
 * Admin notifications (e.g. "low stock", "new order") are broadcast
 * to every admin (user_id = NULL, audience = 'admin').
 */
require_once __DIR__ . '/../config/db.php';

function notification_create(?int $userId, string $audience, string $title, string $message): void
{
    $stmt = db()->prepare(
        'INSERT INTO notifications (user_id, audience, title, message) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $audience, $title, $message]);
}

function notification_notify_customer(int $userId, string $title, string $message): void
{
    notification_create($userId, 'customer', $title, $message);

    // Every customer notification in the app already flows through here, so
    // this is the one place a push has to be sent from. It is deliberately
    // after the insert and in its own try/catch: a phone that cannot be
    // reached must never cost the customer the in-app notification, and must
    // never break the admin's status update.
    try {
        require_once __DIR__ . '/push_service.php';
        push_send_to_user($userId, $title, $message);
    } catch (Throwable $e) {
        error_log('push failed for user ' . $userId . ': ' . $e->getMessage());
    }
}

function notification_notify_admins(string $title, string $message): void
{
    notification_create(null, 'admin', $title, $message);
}

function notification_list_for_user(int $userId, int $limit = 50): array
{
    $stmt = db()->prepare(
        "SELECT * FROM notifications WHERE user_id = ? AND audience = 'customer' ORDER BY created_at DESC LIMIT ?"
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function notification_list_for_admins(int $limit = 50): array
{
    $stmt = db()->prepare(
        "SELECT * FROM notifications WHERE audience = 'admin' ORDER BY created_at DESC LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function notification_unread_count_for_user(int $userId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND audience = 'customer' AND is_read = 0");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function notification_unread_count_for_admins(): int
{
    $stmt = db()->query("SELECT COUNT(*) FROM notifications WHERE audience = 'admin' AND is_read = 0");
    return (int) $stmt->fetchColumn();
}

function notification_mark_all_read_for_user(int $userId): void
{
    $stmt = db()->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND audience = 'customer'");
    $stmt->execute([$userId]);
}

function notification_mark_all_read_for_admins(): void
{
    db()->exec("UPDATE notifications SET is_read = 1 WHERE audience = 'admin'");
}
