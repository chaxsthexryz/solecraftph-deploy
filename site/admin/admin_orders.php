<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order_service.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
auth_require_admin();

$status = trim($_GET['status'] ?? 'all');
$orders = order_list_filtered($status, 300);

$pageTitle = 'Orders — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
$active = 'orders';
require __DIR__ . '/_nav.php';
?>

<section class="section" style="padding-top:0;">
  <div class="wrap">
    <div class="section-head">
      <div><span class="mono">Manage</span><h2 class="display" style="font-size:36px;">Orders</h2></div>
    </div>

    <div class="filters" style="margin-top:0;">
      <a href="orders.php?status=all" class="<?= $status === 'all' ? 'active' : '' ?>">All</a>
      <?php foreach (ORDER_STATUSES as $s): ?>
        <a href="orders.php?status=<?= $s ?>" class="<?= $status === $s ? 'active' : '' ?>"><?= ucfirst($s) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($orders)): ?>
      <div class="empty-state">No orders found.</div>
    <?php else: ?>
      <div class="table-scroll">
      <table class="admin-table">
        <thead><tr><th>Order #</th><th>Customer</th><th>Email</th><th>Payment</th><th>Total</th><th>Status</th><th>Date</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($orders as $o): ?>
            <tr>
              <td>#<?= str_pad($o['id'], 6, '0', STR_PAD_LEFT) ?></td>
              <td><?= htmlspecialchars($o['customer_name']) ?></td>
              <td><?= htmlspecialchars($o['customer_email']) ?></td>
              <td>
                <?= htmlspecialchars($o['payment_method']) ?>
                <?php if ($o['payment_method'] !== 'COD'): ?>
                  <span class="badge-status badge-status--<?= $o['payment_status'] === 'paid' ? 'ok' : ($o['payment_status'] === 'failed' ? 'bad' : 'muted') ?>" style="margin-left:6px;padding:2px 6px;font-size:9px;">
                    <?= $o['payment_status'] === 'paid' ? 'Paid' : ($o['payment_status'] === 'failed' ? 'Failed' : 'Pending') ?>
                  </span>
                <?php endif; ?>
              </td>
              <td>₱<?= number_format($o['total_amount'], 2) ?></td>
              <td><?= render_status_badge($o['status']) ?></td>
              <td><?= htmlspecialchars($o['created_at']) ?></td>
              <td><a href="order_detail.php?id=<?= $o['id'] ?>" style="font-weight:700;">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
