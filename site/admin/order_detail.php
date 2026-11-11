<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order_service.php';
require_once __DIR__ . '/../includes/return_service.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/audit_service.php';
auth_require_admin();

$orderId = (int) ($_GET['id'] ?? 0);
$order = order_find($orderId);

if (!$order) {
    http_response_code(404);
    $pageTitle = 'Order not found — Admin — SoleCraftPH';
    require __DIR__ . '/_header.php';
    $active = 'orders';
    require __DIR__ . '/_nav.php';
    echo '<section class="section" style="padding-top:0;"><div class="wrap"><div class="empty-state">Order not found.</div></div></section>';
    require __DIR__ . '/_footer.php';
    exit;
}

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    if ($formAction === 'update_status') {
        $status = $_POST['status'] ?? '';
        $note = trim($_POST['note'] ?? '');
        $tracking = trim($_POST['tracking_number'] ?? '');
        if (order_update_status($orderId, $status, $note, $tracking !== '' ? $tracking : null)) {
            audit_log('Order status updated', 'Order #' . $orderId . ' -> ' . $status);
            $success = 'Order status updated.';
            $order = order_find($orderId);
        } else {
            // order_update_status refuses a move that would contradict what the
            // customer has already been told — anything leaving Completed or
            // Cancelled, or a step backwards. Saying so matters: this branch
            // used to be empty, so the button appeared to do nothing at all.
            $error = 'That change was refused. An order that is already '
                . ($order['status'] ?? 'finished')
                . ' cannot move to ' . $status
                . ', because the customer has already been told the outcome.';
        }
    } elseif ($formAction === 'save_notes') {
        order_set_admin_notes($orderId, trim($_POST['admin_notes'] ?? ''));
        audit_log('Order notes updated', 'Order #' . $orderId);
        $success = 'Notes saved.';
        $order = order_find($orderId);
    }
}

$history = order_status_history($orderId);

$pageTitle = 'Order #' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT) . ' — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
$active = 'orders';
require __DIR__ . '/_nav.php';
?>

<section class="section" style="padding-top:0;">
  <div class="wrap">
    <div class="section-head">
      <div><span class="mono">Order</span><h2 class="display" style="font-size:36px;">#<?= str_pad($orderId, 6, '0', STR_PAD_LEFT) ?></h2></div>
      <div style="display:flex;gap:12px;align-items:center;">
        <?= render_status_badge($order['status']) ?>
        <a href="invoice.php?id=<?= $orderId ?>" class="btn btn--sm" target="_blank">Print Invoice</a>
      </div>
    </div>

    <?php if ($success): ?><div class="alert alert--success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="form-grid">
      <div class="panel">
        <h3 class="display" style="font-size:22px;margin-bottom:14px;">Customer</h3>
        <p><strong><?= htmlspecialchars($order['customer_name']) ?></strong></p>
        <p><?= htmlspecialchars($order['customer_email']) ?></p>
        <p><?= htmlspecialchars($order['customer_phone']) ?></p>
        <p style="margin-top:10px;color:var(--gray);"><?= nl2br(htmlspecialchars($order['customer_address'])) ?></p>
        <?php $mapUrl = order_map_url($order); ?>
        <?php if ($mapUrl): ?>
          <p style="margin-top:10px;">
            <a href="<?= htmlspecialchars($mapUrl) ?>" target="_blank" rel="noopener" class="btn btn--sm">
              Open delivery pin in Maps
            </a>
          </p>
        <?php endif; ?>
        <p class="mono" style="margin-top:14px;">Payment: <?= htmlspecialchars($order['payment_method']) ?></p>
      </div>

      <div class="panel">
        <h3 class="display" style="font-size:22px;margin-bottom:14px;">Update Status</h3>
        <form method="post" action="order_detail.php?id=<?= $orderId ?>">
          <input type="hidden" name="form_action" value="update_status">
          <div class="form-field">
            <label>Status</label>
            <select name="status">
              <?php foreach (ORDER_STATUSES as $s): ?>
                <option value="<?= $s ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-field">
            <label>Tracking Number</label>
            <input type="text" name="tracking_number" value="<?= htmlspecialchars($order['tracking_number'] ?? '') ?>" placeholder="Optional">
          </div>
          <div class="form-field">
            <label>Note (shown in the customer's tracking timeline)</label>
            <input type="text" name="note" placeholder="e.g. Out for delivery">
          </div>
          <button type="submit" class="btn btn--solid">Update Order</button>
        </form>
      </div>
    </div>

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
    <div class="cart-summary"><div class="cart-summary__box"><?php $ship = settings_shipping_for((float) $order['total_amount']); ?><div class="cart-summary__row"><span>Shipping</span><span><?= $ship == 0 ? 'Free' : '₱' . number_format($ship, 2) ?></span></div><?php if ((float) ($order['discount_amount'] ?? 0) > 0): ?><div class="cart-summary__row"><span>Voucher <?= htmlspecialchars((string) $order['voucher_code']) ?></span><span>−₱<?= number_format((float) $order['discount_amount'], 2) ?></span></div><?php endif; ?><div class="cart-summary__row total"><span>Total</span><span>₱<?= number_format(order_amount_due($order), 2) ?></span></div></div></div>

    <div class="form-grid" style="margin-top:10px;">
      <div class="panel">
        <h3 class="display" style="font-size:22px;margin-bottom:14px;">Status History</h3>
        <?php if (empty($history)): ?>
          <p class="mono" style="color:var(--gray);">No history yet.</p>
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
      </div>

      <div class="panel">
        <h3 class="display" style="font-size:22px;margin-bottom:14px;">Internal Notes</h3>
        <form method="post" action="order_detail.php?id=<?= $orderId ?>">
          <input type="hidden" name="form_action" value="save_notes">
          <div class="form-field">
            <textarea name="admin_notes" rows="5" placeholder="Notes visible to admins only"><?= htmlspecialchars($order['admin_notes'] ?? '') ?></textarea>
          </div>
          <button type="submit" class="btn btn--sm">Save Notes</button>
        </form>
      </div>
    </div>

    <div style="margin-top:30px;"><a href="orders.php" class="btn">&larr; Back to Orders</a></div>
  </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
