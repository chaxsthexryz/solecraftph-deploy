<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_service.php';
auth_require_admin();

$logs = audit_list();

$pageTitle = 'Audit Trail — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
$active = 'audit';
require __DIR__ . '/_nav.php';
?>

<section class="section" style="padding-top:0;">
  <div class="wrap">
    <h1 class="display" style="margin-bottom:30px;">Audit Trail</h1>

    <?php if (empty($logs)): ?>
      <div class="empty-state">No admin actions logged yet.</div>
    <?php else: ?>
      <div class="table-scroll">
      <table class="admin-table">
        <thead><tr><th>Date</th><th>Admin</th><th>Action</th><th>Details</th></tr></thead>
        <tbody>
          <?php foreach ($logs as $l): ?>
            <tr>
              <td><?= htmlspecialchars($l['created_at']) ?></td>
              <td><?= htmlspecialchars($l['admin_username'] ?? 'system') ?></td>
              <td><?= htmlspecialchars($l['action']) ?></td>
              <td><?= htmlspecialchars($l['details'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
