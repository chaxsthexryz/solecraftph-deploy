<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification_service.php';
auth_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    notification_mark_all_read_for_admins();
    header('Location: notifications.php');
    exit;
}

$items = notification_list_for_admins(100);

$pageTitle = 'Notifications — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
$active = 'notifications';
require __DIR__ . '/_nav.php';
?>

<section class="section" style="padding-top:0;">
  <div class="wrap">
    <div class="section-head">
      <div><span class="mono">System</span><h2 class="display" style="font-size:36px;">Notifications</h2></div>
      <form method="post" action="notifications.php">
        <input type="hidden" name="action" value="mark_read">
        <button type="submit" class="btn btn--sm">Mark All Read</button>
      </form>
    </div>

    <?php if (empty($items)): ?>
      <div class="empty-state">No notifications — new orders and low-stock alerts will show up here.</div>
    <?php else: ?>
      <div class="notif-list">
        <?php foreach ($items as $n): ?>
          <div class="notif-item <?= $n['is_read'] ? '' : 'notif-item--unread' ?>">
            <div>
              <h4><?= htmlspecialchars($n['title']) ?></h4>
              <p><?= htmlspecialchars($n['message']) ?></p>
            </div>
            <time><?= htmlspecialchars($n['created_at']) ?></time>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
