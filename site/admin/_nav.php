<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin(); // also when this file is requested on its own
require_once __DIR__ . '/../includes/notification_service.php';
require_once __DIR__ . '/../includes/contact_service.php';
$__adminUnread = notification_unread_count_for_admins();
$__msgUnread   = contact_unread_count();
$__admNav      = true;
$__groups = [
    'Overview'  => ['dashboard' => ['dashboard.php', 'Dashboard &amp; Analytics']],
    'Sales'     => ['orders' => ['orders.php', 'Orders'], 'returns' => ['returns.php', 'Returns'],
                    'reviews' => ['reviews.php', 'Reviews'], 'vouchers' => ['vouchers.php', 'Vouchers']],
    'Catalog'   => ['products' => ['products.php', 'Inventory'], 'add_product' => ['add_product.php', 'Add Product'],
                    'banners' => ['banners.php', 'Banners']],
    'Customers' => ['customers' => ['customers.php', 'Customers'],
                    'messages' => ['messages.php', 'Messages' . ($__msgUnread > 0 ? ' <span class="adm-count">' . (int) $__msgUnread . '</span>' : '')]],
    'Store'     => ['cms' => ['cms.php', 'Content'], 'settings' => ['settings.php', 'Settings'],
                    'admins' => ['admins.php', 'Admins'],
                    'notifications' => ['notifications.php', 'Notifications' . ($__adminUnread > 0 ? ' <span class="adm-count">' . (int) $__adminUnread . '</span>' : '')],
                    'audit' => ['audit_log.php', 'Audit Trail']],
];
?>
<aside class="adm-side" id="admSide">
  <div class="adm-side__top">
    <a href="dashboard.php" class="adm-logo">SOLE<span>CRAFT</span>PH <small>Admin</small></a>
    <button type="button" class="adm-menu" aria-expanded="false" aria-controls="admLinks"
            onclick="var o=document.getElementById('admSide').classList.toggle('open');this.setAttribute('aria-expanded',o)">Menu</button>
  </div>
  <nav id="admLinks" aria-label="Admin">
    <?php foreach ($__groups as $__g => $__items): ?>
      <p class="adm-group"><?= $__g ?></p>
      <?php foreach ($__items as $__key => [$__href, $__label]): ?>
        <a href="<?= $__href ?>"<?= $active === $__key ? ' class="active" aria-current="page"' : '' ?>><?= $__label ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <div class="adm-side__foot">
      <a href="<?= BASE_PATH ?>/index.php" target="_blank" rel="noopener">View store ↗</a>
      <a href="logout.php">Log out (<?= htmlspecialchars($_SESSION['username'] ?? '') ?>)</a>
    </div>
  </nav>
</aside>
<main class="adm-main">
