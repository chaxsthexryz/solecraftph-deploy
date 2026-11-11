<?php
/**
 * Admin Audit Trail Service.
 * Call audit_log() after any meaningful admin write action.
 */
require_once __DIR__ . '/../config/db.php';

function audit_log(string $action, string $details = ''): void
{
    $stmt = db()->prepare(
        'INSERT INTO audit_log (admin_id, admin_username, action, details) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
        $_SESSION['username'] ?? 'system',
        $action,
        $details,
    ]);
}

function audit_list(int $limit = 300): array
{
    $stmt = db()->prepare('SELECT * FROM audit_log ORDER BY created_at DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
