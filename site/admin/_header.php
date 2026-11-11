<?php
/**
 * The admin site's page head: no shop menu, cart or marquee. Pages then
 * require _nav.php for the sidebar (login and verify skip it).
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = $pageTitle ?? 'Admin — SoleCraftPH';
$__css = __DIR__ . '/../assets/css/style.css';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css?v=<?= @filemtime($__css) ?: time() ?>">
<meta name="theme-color" content="#111111">
</head>
<body class="adm-body">
<div class="adm">
