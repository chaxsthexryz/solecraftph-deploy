<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings_service.php';
require_once __DIR__ . '/../includes/audit_service.php';
auth_require_admin();

$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    settings_update([
        'store_name'               => trim($_POST['store_name'] ?? ''),
        'currency_symbol'          => trim($_POST['currency_symbol'] ?? '₱'),
        'shipping_fee'             => (string) (float) ($_POST['shipping_fee'] ?? 0),
        'free_shipping_threshold'  => (string) (float) ($_POST['free_shipping_threshold'] ?? 0),
        'payment_cod_enabled'      => isset($_POST['payment_cod_enabled']) ? '1' : '0',
        'payment_gcash_enabled'    => isset($_POST['payment_gcash_enabled']) ? '1' : '0',
        'payment_card_enabled'     => isset($_POST['payment_card_enabled']) ? '1' : '0',
        'support_email'            => trim($_POST['support_email'] ?? ''),
        'support_phone'            => trim($_POST['support_phone'] ?? ''),
        'low_stock_default_threshold' => (string) (int) ($_POST['low_stock_default_threshold'] ?? 5),
    ]);
    audit_log('Settings updated');
    $success = 'Settings saved.';
}

$pageTitle = 'Settings — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
$active = 'settings';
require __DIR__ . '/_nav.php';
?>

<section class="section" style="padding-top:0;">
  <div class="wrap" style="max-width:640px;">
    <h1 class="display" style="margin-bottom:30px;">Settings &amp; Configuration</h1>

    <?php if ($success): ?><div class="alert alert--success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="panel">
      <form method="post" action="settings.php">
        <div class="form-grid">
          <div class="form-field"><label>Store Name</label><input type="text" name="store_name" value="<?= htmlspecialchars(setting('store_name', 'SoleCraftPH')) ?>"></div>
          <div class="form-field"><label>Currency Symbol</label><input type="text" name="currency_symbol" value="<?= htmlspecialchars(setting('currency_symbol', '₱')) ?>"></div>
          <div class="form-field"><label>Shipping Fee</label><input type="number" step="0.01" name="shipping_fee" value="<?= htmlspecialchars(setting('shipping_fee', '150')) ?>"></div>
          <div class="form-field"><label>Free Shipping Threshold</label><input type="number" step="0.01" name="free_shipping_threshold" value="<?= htmlspecialchars(setting('free_shipping_threshold', '2000')) ?>"></div>
          <div class="form-field"><label>Support Email</label><input type="email" name="support_email" value="<?= htmlspecialchars(setting('support_email', '')) ?>"></div>
          <div class="form-field"><label>Support Phone</label><input type="text" name="support_phone" value="<?= htmlspecialchars(setting('support_phone', '')) ?>"></div>
          <div class="form-field"><label>Default Low Stock Threshold</label><input type="number" name="low_stock_default_threshold" value="<?= htmlspecialchars(setting('low_stock_default_threshold', '5')) ?>"></div>
        </div>

        <div class="form-field">
          <label>Payment Methods</label>
          <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:6px;">
            <label style="display:flex;align-items:center;gap:8px;text-transform:none;font-weight:500;letter-spacing:0;font-size:14px;"><input type="checkbox" name="payment_cod_enabled" <?= setting('payment_cod_enabled', '1') === '1' ? 'checked' : '' ?>> Cash on Delivery</label>
            <label style="display:flex;align-items:center;gap:8px;text-transform:none;font-weight:500;letter-spacing:0;font-size:14px;"><input type="checkbox" name="payment_gcash_enabled" <?= setting('payment_gcash_enabled', '1') === '1' ? 'checked' : '' ?>> GCash</label>
            <label style="display:flex;align-items:center;gap:8px;text-transform:none;font-weight:500;letter-spacing:0;font-size:14px;"><input type="checkbox" name="payment_card_enabled" <?= setting('payment_card_enabled', '1') === '1' ? 'checked' : '' ?>> Credit / Debit Card</label>
          </div>
        </div>

        <button type="submit" class="btn btn--solid" style="margin-top:10px;">Save Settings</button>
      </form>
    </div>
  </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
