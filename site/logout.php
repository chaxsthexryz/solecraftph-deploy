<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
auth_logout();
header('Location: ' . BASE_PATH . '/index.php');
exit;
