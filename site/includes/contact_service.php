<?php
/**
 * Contact / Customer Support Service.
 */
require_once __DIR__ . '/../config/db.php';

function contact_create(string $name, string $email, string $subject, string $message): void
{
    $stmt = db()->prepare(
        'INSERT INTO contact_messages (name, email, subject, message, status) VALUES (?, ?, ?, ?, "new")'
    );
    $stmt->execute([$name, $email, $subject, $message]);
}

function contact_list(int $limit = 300): array
{
    $stmt = db()->prepare('SELECT * FROM contact_messages ORDER BY created_at DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function contact_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM contact_messages WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function contact_set_status(int $id, string $status): void
{
    if (!in_array($status, ['new', 'read', 'resolved'], true)) {
        return;
    }
    $stmt = db()->prepare('UPDATE contact_messages SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
}

function contact_unread_count(): int
{
    $stmt = db()->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'");
    return (int) $stmt->fetchColumn();
}
