<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/order_service.php';
require_once __DIR__ . '/includes/return_service.php';
require_once __DIR__ . '/includes/ui_helpers.php';
auth_require_login();

$userId = (int) $_SESSION['user_id'];
$orderId = (int) ($_GET['id'] ?? 0);
$order = order_find($orderId);

// Customers may only view their own orders.
if (!$order || (int) $order['user_id'] !== $userId) {
    http_response_code(404);
    $pageTitle = 'Order not found — SoleCraftPH';
    require __DIR__ . '/includes/header.php';
    echo '<section class="section"><div class="wrap"><div class="empty-state">Order not found.</div></div></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$error = '';
$success = '';
$canReturn = in_array($order['status'], ['shipped', 'completed'], true) && !return_has_existing($orderId);

// Same rule as the app's Cancel button (order_cancel_by_customer): pending and
// not yet paid.
$canCancel = $order['status'] === 'pending' && ($order['payment_status'] ?? '') !== 'paid';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_order') {
    $cancelError = order_cancel_by_customer($orderId, $userId);
    if ($cancelError !== null) {
        $error = $cancelError;
    } else {
        $success = 'Your order is cancelled. The shoes are back in stock.';
        $order = order_find($orderId);
        $canCancel = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_return') {
    $returnError = return_request_by_customer(
        $orderId,
        (int) $userId,
        trim($_POST['reason'] ?? ''),
        trim($_POST['details'] ?? '')
    );
    if ($returnError !== null) {
        $error = $returnError;
    } else {
        $success = 'Your return request has been submitted. Our team will review it shortly.';
        $canReturn = false;
    }
}

$history = order_status_history($orderId);
$pageTitle = 'Order #' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT) . ' — SoleCraftPH';
require __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap">
    <div class="section-head">
      <div><span class="mono">Order</span><h2 class="display" style="font-size:36px;">#<?= str_pad($orderId, 6, '0', STR_PAD_LEFT) ?></h2></div>
      <div style="display:flex;gap:12px;align-items:center;">
        <?= render_status_badge($order['status']) ?>
        <?php if ($order['payment_method'] !== 'COD'): ?>
          <span class="badge-status badge-status--<?= $order['payment_status'] === 'paid' ? 'ok' : ($order['payment_status'] === 'failed' ? 'bad' : 'muted') ?>">
            <?= $order['payment_status'] === 'paid' ? 'Paid' : ($order['payment_status'] === 'failed' ? 'Payment Failed' : 'Payment Pending') ?>
          </span>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert--success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <?php if ($canCancel): ?>
      <form method="post" action="<?= BASE_PATH ?>/order_detail.php?id=<?= $orderId ?>"
            onsubmit="return confirm('Cancel order #<?= str_pad((string) $orderId, 6, '0', STR_PAD_LEFT) ?>? This cannot be undone.');"
            style="margin-bottom:24px;">
        <input type="hidden" name="action" value="cancel_order">
        <button type="submit" class="btn">Cancel order</button>
        <span class="mono" style="color:var(--gray);margin-left:10px;">You can cancel until we start preparing it.</span>
      </form>
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

    <div class="cart-summary" style="justify-content:space-between;flex-wrap:wrap;gap:30px;">
      <div style="max-width:400px;">
        <h3 class="display" style="font-size:22px;margin-bottom:12px;">Tracking</h3>
        <?php if (empty($history)): ?>
          <p class="mono" style="color:var(--gray);">No tracking updates yet.</p>
        <?php else: ?>
          <div class="timeline">
            <?php foreach ($history as $h): ?>
              <div class="timeline__item">
                <h5><?= htmlspecialchars(ucfirst($h['status'])) ?></h5>
                <p><?= htmlspecialchars($h['created_at']) ?><?= $h['note'] ? ' — ' . htmlspecialchars($h['note']) : '' ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($order['tracking_number'])): ?>
          <p class="mono">Tracking #: <?= htmlspecialchars($order['tracking_number']) ?></p>
        <?php endif; ?>
      </div>

      <div class="cart-summary__box">
        <div class="cart-summary__row"><span>Payment Method</span><span><?= htmlspecialchars($order['payment_method']) ?></span></div>
        <div class="cart-summary__row"><span>Delivery Address</span><span style="text-align:right;max-width:200px;"><?= nl2br(htmlspecialchars($order['customer_address'])) ?></span></div>
        <?php $ship = settings_shipping_for((float) $order['total_amount']); ?><div class="cart-summary__row"><span>Shipping</span><span><?= $ship == 0 ? 'Free' : '₱' . number_format($ship, 2) ?></span></div><?php if ((float) ($order['discount_amount'] ?? 0) > 0): ?><div class="cart-summary__row"><span>Voucher <?= htmlspecialchars((string) $order['voucher_code']) ?></span><span>−₱<?= number_format((float) $order['discount_amount'], 2) ?></span></div><?php endif; ?><div class="cart-summary__row total"><span>Total</span><span>₱<?= number_format(order_amount_due($order), 2) ?></span></div>
      </div>
    </div>

    <div class="panel" style="margin-top:40px;max-width:560px;">
      <h3 class="display" style="font-size:22px;margin-bottom:14px;">Request a Return / Refund</h3>
      <?php if (!$canReturn && $order['status'] !== 'cancelled'): ?>
        <p class="mono" style="color:var(--gray);">
          <?= return_has_existing($orderId) ? 'A return request already exists for this order.' : 'This order is not yet eligible for a return.' ?>
        </p>
      <?php elseif ($canReturn): ?>
        <form method="post" action="<?= BASE_PATH ?>/order_detail.php?id=<?= $orderId ?>">
          <input type="hidden" name="action" value="request_return">
          <div class="form-field">
            <label>Reason *</label>
            <select name="reason" required>
              <option value="">Select a reason…</option>
              <option>Wrong size</option>
              <option>Item damaged / defective</option>
              <option>Not as described</option>
              <option>Changed my mind</option>
              <option>Other</option>
            </select>
          </div>
          <div class="form-field">
            <label>Additional Details</label>
            <textarea name="details" placeholder="Optional — tell us more"></textarea>
          </div>
          <button type="submit" class="btn btn--solid">Submit Request</button>
        </form>
      <?php endif; ?>
    </div>

    <div style="margin-top:30px;"><a href="<?= BASE_PATH ?>/orders.php" class="btn">&larr; Back to Order History</a></div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
