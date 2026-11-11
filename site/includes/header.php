<?php
/**
 * Shared header. Expects optional $pageTitle to already be set.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notification_service.php';
$pageTitle = $pageTitle ?? 'SoleCraftPH — Footwear, Done Right';
$__navUnread = (auth_is_logged_in() && !auth_is_admin()) ? notification_unread_count_for_user((int) $_SESSION['user_id']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: time() ?>">

<!-- PWA: installable app manifest + icons -->
<link rel="manifest" href="<?= BASE_PATH ?>/manifest.php">
<meta name="theme-color" content="#111111">
<link rel="apple-touch-icon" href="<?= BASE_PATH ?>/assets/icons/icon-192.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="SoleCraft">
</head>
<body>

<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('<?= BASE_PATH ?>/sw.php').catch(function (err) {
      console.warn('SW registration failed:', err);
    });
  });
}
</script>

<svg width="0" height="0" style="position:absolute">
  <symbol id="shoe" viewBox="0 0 300 160">
    <path d="M12 118 C 12 100, 30 92, 46 90 C 58 88, 62 78, 72 66 C 84 52, 100 40, 122 34 C 146 27, 172 26, 196 32 C 214 36, 226 44, 236 54 C 244 62, 250 66, 262 68 C 276 70, 288 78, 290 92 L 290 122 C 290 130, 284 134, 276 134 L 24 134 C 16 134, 12 128, 12 118 Z" fill="none" stroke="currentColor" stroke-width="4.5" stroke-linejoin="round"/>
    <path d="M70 68 C 92 54, 118 44, 150 40" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity="0.55"/>
    <path d="M84 60 L 100 76 M100 54 L 118 72 M116 48 L 134 68 M134 44 L 152 64" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity="0.85"/>
    <path d="M12 118 C 60 108, 130 106, 200 108 C 232 109, 262 112, 290 118" fill="none" stroke="currentColor" stroke-width="4.5"/>
    <path d="M20 134 L 280 134 L 288 122 C 288 116, 282 112, 274 112 L 30 112 C 20 112, 12 118, 12 126 C 12 130, 15 134, 20 134 Z" fill="var(--accent, #E2412A)" opacity="0.9"/>
    <circle cx="252" cy="58" r="5.5" fill="currentColor"/>
  </symbol>
</svg>

<nav class="nav">
  <div class="wrap nav__row">
    <a href="<?= BASE_PATH ?>/index.php" class="nav__logo">SOLE<span>CRAFT</span>PH</a>
    <ul class="nav__links" id="navLinks">
      <li><a href="<?= BASE_PATH ?>/index.php">Shop</a></li>
      <li><a href="<?= BASE_PATH ?>/index.php?category=<?= urlencode('Athletic & Performance Footwear') ?>">Performance</a></li>
      <li><a href="<?= BASE_PATH ?>/index.php?category=<?= urlencode('Casual & Lifestyle Footwear') ?>">Casual</a></li>
      <li><a href="<?= BASE_PATH ?>/index.php?category=<?= urlencode('Formal & Dress Footwear') ?>">Formal</a></li>
      <?php if (auth_is_logged_in() && !auth_is_admin()): ?>
        <li><a href="<?= BASE_PATH ?>/wishlist.php">Wishlist</a></li>
        <li><a href="<?= BASE_PATH ?>/orders.php">My Orders</a></li>
        <li><a href="<?= BASE_PATH ?>/notifications.php">Notifications<?= $__navUnread > 0 ? ' <span class="notif-dot"></span>' : '' ?></a></li>
        <li><a href="<?= BASE_PATH ?>/profile.php">Profile</a></li>
      <?php endif; ?>
      <li><a href="<?= BASE_PATH ?>/contact.php">Contact</a></li>
      <?php if (auth_is_logged_in()): ?>
        <li><a href="<?= BASE_PATH ?>/logout.php">Log Out (<?= htmlspecialchars($_SESSION['username']) ?>)</a></li>
      <?php else: ?>
        <li><a href="<?= BASE_PATH ?>/login.php">Log In</a></li>
        <li><a href="<?= BASE_PATH ?>/register.php">Sign Up</a></li>
      <?php endif; ?>
    </ul>
    <div class="nav__right">
      <a class="nav__icon" href="<?= BASE_PATH ?>/cart.php">Cart (<span id="navCartCount"><?= (int) cart_count() ?></span>)</a>
      <button type="button" class="nav__toggle" id="navToggle" aria-label="Toggle menu" aria-expanded="false" aria-controls="navLinks">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
    </div>
  </div>
  <div class="nav__overlay" id="navOverlay"></div>
  <button type="button" class="nav__close" id="navClose" aria-label="Close menu">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="4" x2="20" y2="20"/><line x1="20" y1="4" x2="4" y2="20"/></svg>
  </button>
</nav>
<script>
(function(){
  var btn = document.getElementById('navToggle');
  var closeBtn = document.getElementById('navClose');
  var overlay = document.getElementById('navOverlay');
  var links = document.getElementById('navLinks');
  if (!btn || !links || !overlay || !closeBtn) return;

  function setOpen(open) {
    links.classList.toggle('is-open', open);
    overlay.classList.toggle('is-open', open);
    closeBtn.classList.toggle('is-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.style.overflow = open ? 'hidden' : '';
  }

  btn.addEventListener('click', function () { setOpen(!links.classList.contains('is-open')); });
  closeBtn.addEventListener('click', function () { setOpen(false); });
  overlay.addEventListener('click', function () { setOpen(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') setOpen(false);
  });
})();
</script>

<div class="marquee">
  <div class="marquee__track">
    <span>FREE SHIPPING OVER ₱2,000</span>
    <span>100% AUTHENTIC, ALWAYS</span>
    <span>NEW DROPS EVERY FRIDAY</span>
    <span>COD AVAILABLE NATIONWIDE</span>
    <span>FREE SHIPPING OVER ₱2,000</span>
    <span>100% AUTHENTIC, ALWAYS</span>
    <span>NEW DROPS EVERY FRIDAY</span>
    <span>COD AVAILABLE NATIONWIDE</span>
  </div>
</div>