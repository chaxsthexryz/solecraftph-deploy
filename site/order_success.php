<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/order_service.php';
auth_require_login();

$orderId = (int) ($_GET['id'] ?? 0);
$order = order_find($orderId);

// Customers may only see their own orders; same rule as order_detail.php.
if ($order && (int) $order['user_id'] !== (int) $_SESSION['user_id']) {
    $order = null;
}
if (!$order) {
    http_response_code(404);
}

$pageTitle = 'Order Confirmed — SoleCraftPH';
require __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap">
    <?php if (!$order): ?>
      <div class="empty-state">Order not found.</div>
    <?php else: ?>
      <div class="alert alert--success">
        Thank you, <?= htmlspecialchars($order['customer_name']) ?>! Your order
        <strong>#<?= str_pad($order['id'], 6, '0', STR_PAD_LEFT) ?></strong> has been placed.
      </div>

      <?php if ($order['payment_method'] !== 'COD' && $order['payment_status'] !== 'paid'): ?>
        <div class="alert" style="border-color:var(--gray);color:var(--gray);">
          Confirming your <?= htmlspecialchars($order['payment_method']) ?> payment — this
          updates automatically once PayMongo confirms it, usually within a few seconds.
          Refresh this page if it still says pending after a minute.
        </div>
      <?php endif; ?>

      <div class="table-scroll">
      <table class="cart-table">
        <thead><tr><th>Product</th><th>Price</th><th>Qty</th><th>Subtotal</th></tr></thead>
        <tbody>
          <?php foreach ($order['items'] as $item): ?>
            <tr>
              <td><?= htmlspecialchars($item['product_name']) ?></td>
              <td>₱<?= number_format($item['unit_price'], 2) ?></td>
              <td><?= (int) $item['quantity'] ?></td>
              <td>₱<?= number_format($item['subtotal'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <div class="cart-summary">
        <div class="cart-summary__box">
          <div class="cart-summary__row"><span>Payment Method</span><span><?= htmlspecialchars($order['payment_method']) ?></span></div>
          <?php if ($order['payment_method'] !== 'COD'): ?>
            <div class="cart-summary__row"><span>Payment Status</span><span><?= $order['payment_status'] === 'paid' ? 'Paid' : ($order['payment_status'] === 'failed' ? 'Failed' : 'Pending') ?></span></div>
          <?php endif; ?>
          <div class="cart-summary__row"><span>Status</span><span><?= htmlspecialchars(ucfirst($order['status'])) ?></span></div>
          <?php $ship = settings_shipping_for((float) $order['total_amount']); ?><div class="cart-summary__row"><span>Shipping</span><span><?= $ship == 0 ? 'Free' : '₱' . number_format($ship, 2) ?></span></div><?php if ((float) ($order['discount_amount'] ?? 0) > 0): ?><div class="cart-summary__row"><span>Voucher <?= htmlspecialchars((string) $order['voucher_code']) ?></span><span>−₱<?= number_format((float) $order['discount_amount'], 2) ?></span></div><?php endif; ?><div class="cart-summary__row total"><span>Total</span><span>₱<?= number_format(order_amount_due($order), 2) ?></span></div>
        </div>
      </div>

      <div style="margin-top:30px;"><a href="<?= BASE_PATH ?>/index.php" class="btn btn--solid">Continue Shopping</a></div>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
