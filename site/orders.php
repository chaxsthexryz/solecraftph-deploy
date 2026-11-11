<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/order_service.php';
require_once __DIR__ . '/includes/ui_helpers.php';
auth_require_login();

$orders = order_list_by_user((int) $_SESSION['user_id']);

$pageTitle = 'My Orders — SoleCraftPH';
require __DIR__ . '/includes/header.php';
?>

<header class="pagehead">
  <div class="wrap">
    <h1 class="display">Order History</h1>
    <p><?= count($orders) ?> order(s) placed with this account.</p>
  </div>
</header>

<section class="section" style="padding-top:0;">
  <div class="wrap">
    <nav class="subnav">
      <a href="<?= BASE_PATH ?>/profile.php">Profile</a>
      <a href="<?= BASE_PATH ?>/orders.php" class="active">Order History</a>
      <a href="<?= BASE_PATH ?>/wishlist.php">Wishlist</a>
      <a href="<?= BASE_PATH ?>/notifications.php">Notifications</a>
    </nav>

    <?php if (empty($orders)): ?>
      <div class="empty-state">
        You haven't placed an order yet.
        <div style="margin-top:20px;"><a href="<?= BASE_PATH ?>/index.php" class="btn btn--solid">Start Shopping</a></div>
      </div>
    <?php else: ?>
      <div class="table-scroll">
      <table class="admin-table">
        <thead><tr><th></th><th>Order #</th><th>Date</th><th>Total</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($orders as $o): ?>
            <tr>
              <td style="width:72px;"><?php $src = product_image_url(['image' => $o['thumb'] ?? '']); ?><?php if ($src): ?><img src="<?= htmlspecialchars($src) ?>" alt="" width="56" height="56" loading="lazy" style="object-fit:contain;background:#F4F3F1;border:1px solid #E3E0D9;"><?php endif; ?></td>
              <td>#<?= str_pad($o['id'], 6, '0', STR_PAD_LEFT) ?></td>
              <td><?= htmlspecialchars($o['created_at']) ?></td>
              <td>₱<?= number_format(order_amount_due($o), 2) ?></td>
              <td><?= render_status_badge($o['status']) ?></td>
              <td><a href="<?= BASE_PATH ?>/order_detail.php?id=<?= $o['id'] ?>" style="font-weight:700;">View &amp; Track</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
