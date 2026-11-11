<?php
/**
 * Wishlist / Favorites Service.
 * Requires a logged-in customer (wishlist is tied to a user account).
 */
require_once __DIR__ . '/../config/db.php';

function wishlist_add(int $userId, int $productId): void
{
    $stmt = db()->prepare('INSERT IGNORE INTO wishlists (user_id, product_id) VALUES (?, ?)');
    $stmt->execute([$userId, $productId]);
}

function wishlist_remove(int $userId, int $productId): void
{
    $stmt = db()->prepare('DELETE FROM wishlists WHERE user_id = ? AND product_id = ?');
    $stmt->execute([$userId, $productId]);
}

function wishlist_toggle(int $userId, int $productId): bool
{
    if (wishlist_has($userId, $productId)) {
        wishlist_remove($userId, $productId);
        return false;
    }
    wishlist_add($userId, $productId);
    return true;
}

function wishlist_has(int $userId, int $productId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM wishlists WHERE user_id = ? AND product_id = ? LIMIT 1');
    $stmt->execute([$userId, $productId]);
    return (bool) $stmt->fetchColumn();
}

/** Product IDs the user has wishlisted, for quick lookups when rendering a grid */
function wishlist_product_ids(int $userId): array
{
    $stmt = db()->prepare('SELECT product_id FROM wishlists WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'product_id'));
}

/** Full product rows in a user's wishlist (active products only) */
function wishlist_products(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT p.*, w.created_at AS wishlisted_at FROM wishlists w
         JOIN products p ON p.id = w.product_id
         WHERE w.user_id = ? AND p.is_active = 1
         ORDER BY w.created_at DESC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function wishlist_count(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM wishlists WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}
