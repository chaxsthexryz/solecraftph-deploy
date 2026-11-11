<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/voucher_service.php';
require_once __DIR__ . '/../includes/audit_service.php';
auth_require_admin();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';
    if ($formAction === 'save') {
        $error = voucher_save($_POST) ?? '';
        if ($error === '') {
            audit_log('Voucher saved', voucher_normalize((string) ($_POST['code'] ?? '')));
        }
    } elseif ($formAction === 'toggle') {
        voucher_toggle((int) ($_POST['voucher_id'] ?? 0));
        audit_log('Voucher toggled', 'Voucher #' . ($_POST['voucher_id'] ?? ''));
    }
    if ($error === '') {
        header('Location: vouchers.php');
        exit;
    }
}

$vouchers = voucher_list();

$pageTitle = 'Vouchers — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
$active = 'vouchers';
require __DIR__ . '/_nav.php';
?>

<section class="section" style="padding-top:0;">
  <div class="wrap">
    <h1 class="display" style="margin-bottom:30px;">Voucher Codes</h1>
    <p class="mono" style="color:var(--gray);margin-bottom:30px;">
      Shoppers enter these at checkout on the website and in the app. Saving an existing code updates it.
    </p>

    <div class="panel" style="max-width:640px;margin-bottom:40px;">
      <h3 class="display" style="font-size:22px;margin-bottom:16px;">Add or Update Voucher</h3>
      <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="post" action="vouchers.php">
        <input type="hidden" name="form_action" value="save">
        <div class="form-grid">
          <div class="form-field"><label>Code *</label><input type="text" name="code" required placeholder="PAYDAY15" style="text-transform:uppercase;" value="<?= htmlspecialchars($_POST['code'] ?? '') ?>"></div>
          <div class="form-field"><label>Type *</label>
            <select name="type">
              <option value="percent">Percent off items</option>
              <option value="fixed">Pesos off items</option>
              <option value="shipping">Free shipping</option>
            </select>
          </div>
          <div class="form-field"><label>Value</label><input type="number" step="0.01" min="0" name="value" placeholder="15 (%) or 200 (₱)"></div>
          <div class="form-field"><label>Minimum spend (₱)</label><input type="number" step="0.01" min="0" name="min_spend" value="0"></div>
          <div class="form-field"><label>Usage limit</label><input type="number" min="1" name="max_uses" placeholder="Blank = unlimited"></div>
          <div class="form-field"><label>Last valid day</label><input type="date" name="expires_at"></div>
        </div>
        <button type="submit" class="btn btn--solid">Save Voucher</button>
      </form>
    </div>

    <?php if (empty($vouchers)): ?>
      <div class="empty-state">No vouchers yet.</div>
    <?php else: ?>
      <div class="table-scroll">
      <table class="admin-table">
        <thead><tr><th>Code</th><th>Discount</th><th>Min spend</th><th>Used</th><th>Expires</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($vouchers as $v): ?>
            <tr style="<?= $v['is_active'] ? '' : 'opacity:.55;' ?>">
              <td><span class="mono" style="font-weight:700;"><?= htmlspecialchars($v['code']) ?></span></td>
              <td><?= $v['type'] === 'percent' ? (float) $v['value'] . '% off' : ($v['type'] === 'fixed' ? '₱' . number_format((float) $v['value'], 2) . ' off' : 'Free shipping') ?></td>
              <td><?= (float) $v['min_spend'] > 0 ? '₱' . number_format((float) $v['min_spend'], 2) : '—' ?></td>
              <td><?= (int) $v['used_count'] ?><?= $v['max_uses'] !== null ? ' / ' . (int) $v['max_uses'] : '' ?></td>
              <td><?= $v['expires_at'] ? htmlspecialchars($v['expires_at']) : 'Never' ?></td>
              <td><span class="mono"><?= $v['is_active'] ? 'Active' : 'Off' ?></span></td>
              <td>
                <form method="post" action="vouchers.php"><input type="hidden" name="form_action" value="toggle"><input type="hidden" name="voucher_id" value="<?= (int) $v['id'] ?>"><button type="submit" class="mono" style="background:none;border:none;cursor:pointer;font-weight:700;"><?= $v['is_active'] ? 'Turn off' : 'Turn on' ?></button></form>
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
