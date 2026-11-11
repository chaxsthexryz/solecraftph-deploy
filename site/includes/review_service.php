<?php
/**
 * Product Review & Rating Service.
 */
require_once __DIR__ . '/../config/db.php';

function review_create(int $productId, int $userId, int $rating, string $comment): void
{
    $rating = max(1, min(5, $rating));
    $stmt = db()->prepare(
        'INSERT INTO product_reviews (product_id, user_id, rating, comment, status)
         VALUES (?, ?, ?, ?, "approved")'
    );
    $stmt->execute([$productId, $userId, $rating, $comment]);
}

/** Whether this user already reviewed this product (one review per product per user) */
function review_exists(int $productId, int $userId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM product_reviews WHERE product_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$productId, $userId]);
    return (bool) $stmt->fetchColumn();
}

/** Whether this user has a completed order containing this product ("verified purchase") */
function review_user_purchased(int $productId, int $userId): bool
{
    $stmt = db()->prepare(
        "SELECT 1 FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE oi.product_id = ? AND o.user_id = ? LIMIT 1"
    );
    $stmt->execute([$productId, $userId]);
    return (bool) $stmt->fetchColumn();
}

function review_list_for_product(int $productId): array
{
    $stmt = db()->prepare(
        "SELECT r.*, u.username FROM product_reviews r
         JOIN users u ON u.id = r.user_id
         WHERE r.product_id = ? AND r.status = 'approved'
         ORDER BY r.created_at DESC"
    );
    $stmt->execute([$productId]);
    return $stmt->fetchAll();
}

function review_summary(int $productId): array
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(AVG(rating),0) AS avg_rating
         FROM product_reviews WHERE product_id = ? AND status = 'approved'"
    );
    $stmt->execute([$productId]);
    $row = $stmt->fetch();
    return ['count' => (int) $row['cnt'], 'average' => round((float) $row['avg_rating'], 1)];
}

/** Admin: every review across all products, for moderation */
function review_list_all(int $limit = 300): array
{
    $stmt = db()->prepare(
        "SELECT r.*, u.username, p.name AS product_name FROM product_reviews r
         JOIN users u ON u.id = r.user_id
         JOIN products p ON p.id = r.product_id
         ORDER BY r.created_at DESC LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function review_set_status(int $id, string $status): void
{
    if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
        return;
    }
    $stmt = db()->prepare('UPDATE product_reviews SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
}

function review_delete(int $id): void
{
    $stmt = db()->prepare('DELETE FROM product_reviews WHERE id = ?');
    $stmt->execute([$id]);
}
