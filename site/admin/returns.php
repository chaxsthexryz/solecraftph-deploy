<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/return_service.php';
require_once __DIR__ . '/../includes/order_service.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/audit_service.php';
auth_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['return_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $notes = trim($_POST['admin_notes'] ?? '');
    return_update_status($id, $status, $notes);
    if ($status === 'refunded') {
        $ret = return_find($id);
        if ($ret) {
            order_update_status((int) $ret['order_id'], 'cancelled', 'Refunded per return #' . $id . '.');
        }
    }
    audit_log('Return status updated', 'Return #' . $id . ' -> ' . $status);
    header('Location: returns.php');
    exit;
}

$returns = return_list_all();

$pageTitle = 'Returns & Refunds — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
$active = 'returns';
require __DIR__ . '/_nav.php';
?>

<section class="section" style="padding-top:0;">
  <div class="wrap">
    <h1 class="display" style="margin-bottom:30px;">Returns &amp; Refunds</h1>

    <?php if (empty($returns)): ?>
      <div class="empty-state">No return requests yet.</div>
    <?php else: ?>
      <div class="table-scroll">
      <table class="admin-table">
        <thead><tr><th>Order</th><th>Customer</th><th>Reason</th><th>Details</th><th>Status</th><th>Date</th><th>Update</th></tr></thead>
        <tbody>
          <?php foreach ($returns as $r): ?>
            <tr>
              <td><a href="order_detail.php?id=<?= $r['order_id'] ?>">#<?= str_pad($r['order_id'], 6, '0', STR_PAD_LEFT) ?></a></td>
              <td><?= htmlspecialchars($r['customer_name']) ?></td>
              <td><?= htmlspecialchars($r['reason']) ?></td>
              <td style="max-width:220px;"><?= htmlspecialchars($r['details'] ?: '—') ?></td>
              <td><?= render_status_badge($r['status']) ?></td>
              <td><?= htmlspecialchars($r['created_at']) ?></td>
              <td>
                <form method="post" action="returns.php" style="display:flex;gap:6px;">
                  <input type="hidden" name="return_id" value="<?= $r['id'] ?>">
                  <select name="status" style="padding:6px;border:1.5px solid var(--ink);font-size:12px;">
                    <option value="requested" <?= $r['status'] === 'requested' ? 'selected' : '' ?>>Requested</option>
                    <option value="approved" <?= $r['status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $r['status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="refunded" <?= $r['status'] === 'refunded' ? 'selected' : '' ?>>Refunded</option>
                  </select>
                  <input type="hidden" name="admin_notes" value="<?= htmlspecialchars($r['admin_notes'] ?? '') ?>">
                  <button type="submit" class="btn btn--sm">Save</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
