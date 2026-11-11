<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notification_service.php';
auth_require_login();

$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    notification_mark_all_read_for_user($userId);
    header('Location: ' . BASE_PATH . '/notifications.php');
    exit;
}

$items = notification_list_for_user($userId, 100);

$pageTitle = 'Notifications — SoleCraftPH';
require __DIR__ . '/includes/header.php';
?>

<header class="pagehead">
  <div class="wrap">
    <h1 class="display">Notifications</h1>
    <p>Order updates and announcements land here.</p>
  </div>
</header>

<section class="section" style="padding-top:0;">
  <div class="wrap">
    <nav class="subnav">
      <a href="<?= BASE_PATH ?>/profile.php">Profile</a>
      <a href="<?= BASE_PATH ?>/orders.php">Order History</a>
      <a href="<?= BASE_PATH ?>/wishlist.php">Wishlist</a>
      <a href="<?= BASE_PATH ?>/notifications.php" class="active">Notifications</a>
    </nav>

    <?php if (empty($items)): ?>
      <div class="empty-state">No notifications yet.</div>
    <?php else: ?>
      <div class="section-head">
        <div></div>
        <form method="post" action="<?= BASE_PATH ?>/notifications.php">
          <input type="hidden" name="action" value="mark_read">
          <button type="submit" class="btn btn--sm">Mark All Read</button>
        </form>
      </div>
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

<?php require __DIR__ . '/includes/footer.php'; ?>
