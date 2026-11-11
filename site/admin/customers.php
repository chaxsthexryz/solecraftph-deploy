<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order_service.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/audit_service.php';
auth_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'suspend') {
        user_set_status($id, 'suspended');
        audit_log('Customer suspended', 'User #' . $id);
    } elseif ($action === 'activate') {
        user_set_status($id, 'active');
        audit_log('Customer activated', 'User #' . $id);
    }
    header('Location: customers.php');
    exit;
}

$customers = user_list_customers();

$pageTitle = 'Customers — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
$active = 'customers';
require __DIR__ . '/_nav.php';
?>

<section class="section" style="padding-top:0;">
  <div class="wrap">
    <h1 class="display" style="margin-bottom:30px;">Customer Accounts</h1>

    <?php if (empty($customers)): ?>
      <div class="empty-state">No customer accounts yet.</div>
    <?php else: ?>
      <div class="table-scroll">
      <table class="admin-table">
        <thead><tr><th>Username</th><th>Name</th><th>Email</th><th>Phone</th><th>Orders</th><th>Joined</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach ($customers as $c): $orderCount = count(order_list_by_user((int) $c['id'], 1000)); ?>
            <tr style="<?= $c['status'] === 'suspended' ? 'opacity:.6;' : '' ?>">
              <td><?= htmlspecialchars($c['username']) ?></td>
              <td><?= htmlspecialchars($c['full_name'] ?: '—') ?></td>
              <td><?= htmlspecialchars($c['email']) ?></td>
              <td><?= htmlspecialchars($c['phone'] ?: '—') ?></td>
              <td><?= $orderCount ?></td>
              <td><?= htmlspecialchars($c['created_at']) ?></td>
              <td><?= render_status_badge($c['status']) ?></td>
              <td>
                <form method="post" action="customers.php">
                  <input type="hidden" name="user_id" value="<?= $c['id'] ?>">
                  <?php if ($c['status'] === 'active'): ?>
                    <input type="hidden" name="action" value="suspend">
                    <button type="submit" class="link-remove" style="background:none;border:none;cursor:pointer;">Suspend</button>
                  <?php else: ?>
                    <input type="hidden" name="action" value="activate">
                    <button type="submit" style="background:none;border:none;cursor:pointer;font-weight:700;font-size:11px;letter-spacing:.04em;text-transform:uppercase;">Activate</button>
                  <?php endif; ?>
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
