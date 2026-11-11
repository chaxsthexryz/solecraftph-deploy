<?php
/**
 * Where the mobile app's in-app PayMongo checkout lands when it finishes.
 *
 * The website's own checkout keeps using order_success.php; this exists purely
 * so a phone shopper isn't dropped onto a full desktop storefront page inside
 * the payment WebView.
 *
 * Deliberately reads NOTHING from the database. It is reachable by anyone who
 * can guess an order id, so it shows only the number already in the URL and
 * never the customer, the amount, or whether payment actually landed. The app's
 * Confirmed screen reads the real status through the authenticated API.
 */
require_once __DIR__ . '/config/app.php';

$orderId = (int) ($_GET['id'] ?? 0);
$cancelled = ($_GET['state'] ?? '') === 'cancelled';
$reference = $orderId > 0 ? '#' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT) : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $cancelled ? 'Payment cancelled' : 'Payment received' ?> — SoleCraftPH</title>
<style>
  :root{
    --ink:#111111; --paper:#FAF9F6; --stone:#EDEAE3; --gray:#8A8578; --blaze:#E2412A;
  }
  *{box-sizing:border-box}
  html,body{margin:0;height:100%}
  body{
    background:var(--paper); color:var(--ink);
    font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
    display:flex; align-items:center; justify-content:center;
    padding:32px 24px; text-align:center; line-height:1.6;
  }
  .mark{
    width:64px;height:64px;border-radius:50%;margin:0 auto 24px;
    display:flex;align-items:center;justify-content:center;
    background:<?= $cancelled ? 'rgba(226,65,42,.10)' : 'rgba(17,17,17,.06)' ?>;
    color:<?= $cancelled ? 'var(--blaze)' : 'var(--ink)' ?>;
    font-size:30px;line-height:1;
  }
  h1{font-size:24px;font-weight:600;margin:0 0 10px;letter-spacing:-.01em}
  p{margin:0;color:var(--gray);max-width:34ch}
  .ref{
    font-family:'IBM Plex Mono',ui-monospace,SFMono-Regular,Consolas,monospace;
    font-size:13px;letter-spacing:.08em;color:var(--ink);
    background:var(--stone);padding:6px 12px;border-radius:8px;
    display:inline-block;margin-top:20px;
  }
  .hint{margin-top:28px;font-size:13px;color:var(--gray)}
</style>
</head>
<body>
  <main>
    <div class="mark" aria-hidden="true"><?= $cancelled ? '&times;' : '&check;' ?></div>
    <?php if ($cancelled): ?>
      <h1>Payment cancelled</h1>
      <p>Nothing was charged. Your order is still waiting — you can try again from the app.</p>
    <?php else: ?>
      <h1>Payment received</h1>
      <p>Thanks! We're confirming it with PayMongo now. Your order updates on its own once that clears.</p>
    <?php endif; ?>
    <?php if ($reference !== ''): ?>
      <div class="ref">Order <?= htmlspecialchars($reference) ?></div>
    <?php endif; ?>
    <p class="hint">Tap <strong>Done</strong> below to go back to your order.</p>
  </main>
</body>
</html>
