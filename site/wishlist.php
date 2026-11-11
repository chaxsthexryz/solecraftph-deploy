<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wishlist_service.php';
require_once __DIR__ . '/includes/product_service.php';
auth_require_login();

$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int) ($_POST['product_id'] ?? 0);
    if ($productId > 0) {
        wishlist_remove($userId, $productId);
    }
    header('Location: ' . BASE_PATH . '/wishlist.php');
    exit;
}

$products = wishlist_products($userId);

$pageTitle = 'My Wishlist — SoleCraftPH';
require __DIR__ . '/includes/header.php';
?>

<header class="pagehead">
  <div class="wrap">
    <h1 class="display">My Wishlist</h1>
    <p><?= count($products) ?> saved item(s).</p>
  </div>
</header>

<section class="section" style="padding-top:0;">
  <div class="wrap">
    <nav class="subnav">
      <a href="<?= BASE_PATH ?>/profile.php">Profile</a>
      <a href="<?= BASE_PATH ?>/orders.php">Order History</a>
      <a href="<?= BASE_PATH ?>/wishlist.php" class="active">Wishlist</a>
      <a href="<?= BASE_PATH ?>/notifications.php">Notifications</a>
    </nav>

    <?php if (empty($products)): ?>
      <div class="empty-state">
        Nothing saved yet.
        <div style="margin-top:20px;"><a href="<?= BASE_PATH ?>/index.php" class="btn btn--solid">Browse Products</a></div>
      </div>
    <?php else: ?>
      <div class="products">
        <?php foreach ($products as $p): $price = product_display_price($p); $img = product_image_url($p); ?>
          <article class="card">
            <a class="card__frame" href="<?= BASE_PATH ?>/product.php?id=<?= $p['id'] ?>" style="--accent:#111111;color:#111111;">
              <?php if ($p['badge']): ?><span class="card__badge <?= $p['sale_price'] ? 'card__badge--sale' : '' ?>"><?= htmlspecialchars($p['badge']) ?></span><?php endif; ?>
              <?php if ($img): ?>
                <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($p['name']) ?>" class="card__photo" loading="lazy">
              <?php else: ?>
                <svg viewBox="0 0 300 160"><use href="#shoe"/></svg>
              <?php endif; ?>
            </a>
            <div class="card__body">
              <div class="card__cat mono"><?= htmlspecialchars($p['category']) ?></div>
              <div class="card__name"><a href="<?= BASE_PATH ?>/product.php?id=<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></a></div>
              <div class="card__row">
                <span class="card__price">₱<?= number_format($price, 2) ?></span>
                <form method="post" action="<?= BASE_PATH ?>/wishlist.php">
                  <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                  <button type="submit" class="card__add">Remove</button>
                </form>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
        <?php $fillers = (3 - (count($products) % 3)) % 3; for ($i = 0; $i < $fillers; $i++): ?>
          <div class="card" aria-hidden="true"></div>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
