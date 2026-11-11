<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order_service.php';
require_once __DIR__ . '/../config/app.php';
auth_require_admin();

$orderId = (int) ($_GET['id'] ?? 0);
$order = order_find($orderId);
if (!$order) {
    http_response_code(404);
    echo 'Order not found.';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice #<?= str_pad($orderId, 6, '0', STR_PAD_LEFT) ?> — SoleCraftPH</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<style>
  :root{--ink:#111111;--paper:#FAF9F6;--gray:#8A8578;}
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Inter',sans-serif;color:var(--ink);background:#fff;padding:50px;max-width:760px;margin:0 auto;}
  .brand{font-family:'Bebas Neue',sans-serif;font-size:32px;}
  .brand span{color:#E2412A;}
  .mono{font-family:'IBM Plex Mono',monospace;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--gray);}
  h1{font-family:'Bebas Neue',sans-serif;font-size:40px;margin:6px 0 26px;}
  .row{display:flex;justify-content:space-between;margin-bottom:34px;}
  .col p{margin-bottom:3px;font-size:13.5px;}
  table{width:100%;border-collapse:collapse;margin-bottom:24px;}
  th,td{text-align:left;padding:10px 8px;font-size:13.5px;border-bottom:1px solid #ddd;}
  th{font-family:'IBM Plex Mono',monospace;font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--gray);border-bottom:1.5px solid var(--ink);}
  .totals{width:280px;margin-left:auto;}
  .totals div{display:flex;justify-content:space-between;padding:6px 0;font-size:14px;}
  .totals .grand{font-family:'Bebas Neue',sans-serif;font-size:22px;border-top:1.5px solid var(--ink);margin-top:6px;padding-top:12px;}
  .print-btn{margin-top:30px;padding:12px 22px;border:1.5px solid var(--ink);background:var(--ink);color:#fff;font-weight:700;font-size:13px;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;}
  @media print{.print-btn{display:none;}body{padding:0;}}
</style>
</head>
<body>
  <div class="row">
    <div class="brand">SOLE<span>CRAFT</span>PH</div>
    <div class="mono" style="text-align:right;">Invoice / Packing Slip<br><?= htmlspecialchars($order['created_at']) ?></div>
  </div>

  <h1>#<?= str_pad($orderId, 6, '0', STR_PAD_LEFT) ?></h1>

  <div class="row">
    <div class="col">
      <p class="mono">Bill To</p>
      <p><strong><?= htmlspecialchars($order['customer_name']) ?></strong></p>
      <p><?= htmlspecialchars($order['customer_email']) ?></p>
      <p><?= htmlspecialchars($order['customer_phone']) ?></p>
      <p><?= nl2br(htmlspecialchars($order['customer_address'])) ?></p>
    </div>
    <div class="col" style="text-align:right;">
      <p class="mono">Payment Method</p>
      <p><?= htmlspecialchars($order['payment_method']) ?></p>
      <p class="mono" style="margin-top:12px;">Status</p>
      <p><?= htmlspecialchars(ucfirst($order['status'])) ?></p>
      <?php if (!empty($order['tracking_number'])): ?>
        <p class="mono" style="margin-top:12px;">Tracking #</p>
        <p><?= htmlspecialchars($order['tracking_number']) ?></p>
      <?php endif; ?>
    </div>
  </div>

  <table>
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

  <div class="totals">
    <?php $ship = settings_shipping_for((float) $order['total_amount']); ?><div ><span>Shipping</span><span><?= $ship == 0 ? 'Free' : '₱' . number_format($ship, 2) ?></span></div><?php if ((float) ($order['discount_amount'] ?? 0) > 0): ?><div ><span>Voucher <?= htmlspecialchars((string) $order['voucher_code']) ?></span><span>−₱<?= number_format((float) $order['discount_amount'], 2) ?></span></div><?php endif; ?><div class="grand"><span>Total</span><span>₱<?= number_format(order_amount_due($order), 2) ?></span></div>
  </div>

  <button class="print-btn" onclick="window.print()">Print</button>
</body>
</html>
