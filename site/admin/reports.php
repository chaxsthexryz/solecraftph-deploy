<?php
// Sales & Analytics now live on the dashboard, with the same range filter.
$r = ($_GET['days'] ?? '') === '90' ? '90d' : '30d';
header('Location: dashboard.php?range=' . $r);
exit;
