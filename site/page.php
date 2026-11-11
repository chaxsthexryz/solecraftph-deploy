<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/cms_service.php';

$slug = trim($_GET['slug'] ?? '');
$page = $slug !== '' ? cms_page_find($slug) : null;

if (!$page) {
    http_response_code(404);
    $pageTitle = 'Page not found — SoleCraftPH';
    require __DIR__ . '/includes/header.php';
    echo '<section class="section"><div class="wrap"><div class="empty-state">That page could not be found.</div></div></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle = $page['title'] . ' — SoleCraftPH';
require __DIR__ . '/includes/header.php';
?>

<header class="pagehead">
  <div class="wrap">
    <h1 class="display"><?= htmlspecialchars($page['title']) ?></h1>
  </div>
</header>

<section class="section">
  <div class="wrap">
    <div class="cms-content"><?= nl2br(htmlspecialchars($page['content'])) ?></div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
