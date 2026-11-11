<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/product_service.php';
require_once __DIR__ . '/../includes/order_service.php';
require_once __DIR__ . '/../includes/voucher_service.php';
require_once __DIR__ . '/../includes/return_service.php';
require_once __DIR__ . '/../includes/contact_service.php';
auth_require_admin();

$ranges = ['today' => 1, '30d' => 30, '90d' => 90];
$range  = isset($ranges[$_GET['range'] ?? '']) ? $_GET['range'] : '30d';
$days   = $ranges[$range];

// Dates come from MySQL so they match created_at's clock, whatever PHP's is.
$db    = db();
$today = $db->query('SELECT CURDATE()')->fetchColumn();
$start = date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' days'));
$prev  = date('Y-m-d', strtotime($start . ' -' . $days . ' days'));

// This period ('now') and the one before it ('was'), for the change on each card.
$stmt = $db->prepare('SELECT id, status, payment_method, total_amount, discount_amount, created_at FROM orders WHERE created_at >= ? ORDER BY created_at');
$stmt->execute([$prev]);
$tot        = ['now' => ['revenue' => 0.0, 'orders' => 0, 'cancelled' => 0]];
$tot['was'] = $tot['now'];
$byBucket   = [];
$byStatus   = [];
$byPay      = [];
foreach ($stmt->fetchAll() as $o) {
    $cur = substr($o['created_at'], 0, 10) >= $start;
    $ok  = $o['status'] !== 'cancelled';
    $amt = $ok ? order_amount_due($o) : 0.0;
    $p   = $cur ? 'now' : 'was';
    $tot[$p]['orders']++;
    $tot[$p]['revenue']   += $amt;
    $tot[$p]['cancelled'] += $ok ? 0 : 1;
    if (!$cur) {
        continue;
    }
    $byStatus[$o['status']] = ($byStatus[$o['status']] ?? 0) + 1;
    if ($ok) {
        $k = $days === 1 ? (int) substr($o['created_at'], 11, 2) : substr($o['created_at'], 0, 10);
        $byBucket[$k]['rev'] = ($byBucket[$k]['rev'] ?? 0) + $amt;
        $byBucket[$k]['n']   = ($byBucket[$k]['n'] ?? 0) + 1;
        $byPay[$o['payment_method']] = ($byPay[$o['payment_method']] ?? 0) + $amt;
    }
}
[$now, $was] = [$tot['now'], $tot['was']];

// Every hour (today) or day (30d/90d) gets a point, so quiet days show as zero.
$labels = $revSeries = $ordSeries = [];
for ($i = 0; $i < ($days === 1 ? 24 : $days); $i++) {
    $k = $days === 1 ? $i : date('Y-m-d', strtotime($start . " +$i days"));
    $labels[]    = $days === 1 ? sprintf('%02d:00', $i) : date('M j', strtotime($k));
    $revSeries[] = round($byBucket[$k]['rev'] ?? 0, 2);
    $ordSeries[] = $byBucket[$k]['n'] ?? 0;
}

$stmt = $db->prepare(
    "SELECT oi.product_name, SUM(oi.quantity) AS units, SUM(oi.subtotal) AS revenue
     FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE o.status <> 'cancelled' AND o.created_at >= ?
     GROUP BY oi.product_name ORDER BY units DESC LIMIT 8"
);
$stmt->execute([$start]);
$topProducts = $stmt->fetchAll();

$stmt = $db->prepare(
    "SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE o.status <> 'cancelled' AND o.created_at >= ?"
);
$stmt->execute([$start]);
$unitsSold = (int) $stmt->fetchColumn();

$stmt = $db->prepare(
    "SELECT customer_name, customer_email, COUNT(*) AS n, SUM(total_amount) AS spent
     FROM orders WHERE status <> 'cancelled' AND created_at >= ?
     GROUP BY customer_email, customer_name ORDER BY spent DESC LIMIT 5"
);
$stmt->execute([$start]);
$topCustomers = $stmt->fetchAll();

$stmt = $db->prepare(
    "SELECT COALESCE(SUM(created_at >= ?), 0) AS now_n, COALESCE(SUM(created_at < ?), 0) AS was_n
     FROM users WHERE role = 'customer' AND created_at >= ?"
);
$stmt->execute([$start, $start, $prev]);
$newCust = $stmt->fetch();

$pending     = (int) $db->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$processing  = (int) $db->query("SELECT COUNT(*) FROM orders WHERE status = 'processing'")->fetchColumn();
$lowStock    = product_low_stock();
$openReturns = count(array_filter(return_list_all(), fn($r) => $r['status'] === 'requested'));
$newMessages = contact_unread_count();
$recent      = order_list_recent(8);

$paid   = $now['orders'] - $now['cancelled'];
$wasPd  = $was['orders'] - $was['cancelled'];
$aov    = $paid > 0 ? $now['revenue'] / $paid : 0.0;
$wasAov = $wasPd > 0 ? $was['revenue'] / $wasPd : 0.0;

/** "▲ 12%" against the period before; blank when there is nothing to compare. */
function dash_delta(float $now, float $was): string
{
    if ($was <= 0) {
        return $now > 0 ? '<em class="up">new</em>' : '';
    }
    $pct = (int) round(($now - $was) / $was * 100);
    return '<em class="' . ($pct >= 0 ? 'up' : 'down') . '">' . ($pct >= 0 ? '▲ ' : '▼ ') . abs($pct) . '%</em>';
}

$rangeName = ['today' => 'Today', '30d' => 'Last 30 days', '90d' => 'Last 90 days'][$range];
$vsName    = ['today' => 'yesterday', '30d' => 'the previous 30 days', '90d' => 'the previous 90 days'][$range];

$pageTitle = 'Dashboard — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
$active = 'dashboard';
require __DIR__ . '/_nav.php';
?>

<section class="section" style="padding-top:0;">
  <div class="wrap">
    <div class="adm-head">
      <div><span class="mono">Hi <?= htmlspecialchars($_SESSION['username'] ?? '') ?> · <?= $rangeName ?></span><h1 class="display">Dashboard</h1></div>
      <div class="adm-tools">
        <nav class="filters" style="margin:0;" aria-label="Date range">
          <?php foreach (['today' => 'Today', '30d' => '30D', '90d' => '90D'] as $k => $label): ?>
            <a href="dashboard.php?range=<?= $k ?>"<?= $range === $k ? ' class="active" aria-current="true"' : '' ?>><?= $label ?></a>
          <?php endforeach; ?>
        </nav>
        <a href="report_export.php?range=<?= $range ?>" class="btn btn--sm">Download report (CSV)</a>
        <button type="button" class="btn btn--sm" onclick="window.print()">Print report</button>
      </div>
    </div>

    <?php if ($pending || $processing || $lowStock || $openReturns || $newMessages): ?>
      <div class="adm-todo">
        <span class="mono">Needs attention</span>
        <?php if ($pending): ?><a href="orders.php?status=pending"><b><?= $pending ?></b> pending order<?= $pending > 1 ? 's' : '' ?></a><?php endif; ?>
        <?php if ($processing): ?><a href="orders.php?status=processing"><b><?= $processing ?></b> to ship</a><?php endif; ?>
        <?php if ($lowStock): ?><a href="products.php"><b><?= count($lowStock) ?></b> low stock</a><?php endif; ?>
        <?php if ($openReturns): ?><a href="returns.php"><b><?= $openReturns ?></b> return request<?= $openReturns > 1 ? 's' : '' ?></a><?php endif; ?>
        <?php if ($newMessages): ?><a href="messages.php"><b><?= $newMessages ?></b> new message<?= $newMessages > 1 ? 's' : '' ?></a><?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="adm-kpis">
      <div><span>Revenue</span><b>₱<?= number_format($now['revenue'], 0) ?></b><?= dash_delta($now['revenue'], $was['revenue']) ?></div>
      <div><span>Orders</span><b><?= $now['orders'] ?></b><?= dash_delta($now['orders'], $was['orders']) ?></div>
      <div><span>Avg order</span><b>₱<?= number_format($aov, 0) ?></b><?= dash_delta($aov, $wasAov) ?></div>
      <div><span>Pairs sold</span><b><?= $unitsSold ?></b></div>
      <div><span>New customers</span><b><?= (int) $newCust['now_n'] ?></b><?= dash_delta((float) $newCust['now_n'], (float) $newCust['was_n']) ?></div>
      <div><span>Cancelled</span><b><?= $now['cancelled'] ?></b></div>
    </div>
    <p class="mono adm-note">Arrows compare with <?= $vsName ?>. Revenue is what customers pay (items + shipping − vouchers), cancelled orders left out.</p>

    <div class="adm-card">
      <h2 class="display">Revenue &amp; orders <small><?= $days === 1 ? 'by hour' : 'by day' ?></small></h2>
      <div class="adm-chart" style="height:300px;"><canvas id="chRevenue" role="img" aria-label="Revenue and orders over time"></canvas></div>
    </div>

    <div class="adm-grid">
      <div class="adm-card">
        <h2 class="display">Orders by status</h2>
        <?php if ($byStatus): ?><div class="adm-chart" style="height:240px;"><canvas id="chStatus" role="img" aria-label="Orders by status"></canvas></div>
        <?php else: ?><p class="adm-empty">No orders in this period.</p><?php endif; ?>
      </div>
      <div class="adm-card">
        <h2 class="display">Revenue by payment</h2>
        <?php if ($byPay): ?><div class="adm-chart" style="height:240px;"><canvas id="chPay" role="img" aria-label="Revenue by payment method"></canvas></div>
        <?php else: ?><p class="adm-empty">No sales in this period.</p><?php endif; ?>
      </div>
    </div>

    <div class="adm-grid">
      <div class="adm-card">
        <h2 class="display">Top products</h2>
        <?php if ($topProducts): ?><div class="adm-chart" style="height:<?= 50 + 34 * count($topProducts) ?>px;"><canvas id="chTop" role="img" aria-label="Top products by pairs sold"></canvas></div>
        <?php else: ?><p class="adm-empty">No sales in this period.</p><?php endif; ?>
      </div>
      <div class="adm-card">
        <h2 class="display">Top customers</h2>
        <?php if ($topCustomers): ?>
          <div class="table-scroll"><table class="admin-table">
            <thead><tr><th>Customer</th><th>Orders</th><th>Items total</th></tr></thead>
            <tbody><?php foreach ($topCustomers as $c): ?>
              <tr><td><?= htmlspecialchars($c['customer_name']) ?><br><span class="mono" style="color:var(--gray);"><?= htmlspecialchars($c['customer_email']) ?></span></td>
                  <td><?= (int) $c['n'] ?></td><td>₱<?= number_format((float) $c['spent'], 0) ?></td></tr>
            <?php endforeach; ?></tbody>
          </table></div>
        <?php else: ?><p class="adm-empty">No customers in this period.</p><?php endif; ?>
      </div>
    </div>

    <div class="adm-grid">
      <div class="adm-card">
        <h2 class="display">Latest orders <a href="orders.php" class="btn btn--sm">All orders</a></h2>
        <?php if ($recent): ?>
          <div class="table-scroll"><table class="admin-table">
            <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($recent as $o): ?>
              <tr><td><a href="order_detail.php?id=<?= (int) $o['id'] ?>">#<?= str_pad($o['id'], 6, '0', STR_PAD_LEFT) ?></a><br><span class="mono" style="color:var(--gray);"><?= htmlspecialchars(date('M j, g:i A', strtotime($o['created_at']))) ?></span></td>
                  <td><?= htmlspecialchars($o['customer_name']) ?></td>
                  <td>₱<?= number_format(order_amount_due($o), 2) ?></td>
                  <td><span class="adm-status adm-status--<?= htmlspecialchars($o['status']) ?>"><?= htmlspecialchars(ucfirst($o['status'])) ?></span></td></tr>
            <?php endforeach; ?></tbody>
          </table></div>
        <?php else: ?><p class="adm-empty">No orders yet.</p><?php endif; ?>
      </div>
      <div class="adm-card">
        <h2 class="display">Low stock <a href="products.php" class="btn btn--sm">Inventory</a></h2>
        <?php if ($lowStock): ?>
          <table class="admin-table">
            <thead><tr><th>Product</th><th>Left</th></tr></thead>
            <tbody><?php foreach (array_slice($lowStock, 0, 8) as $p): ?>
              <tr><td><a href="edit_product.php?id=<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></a></td><td><b style="color:var(--blaze);"><?= (int) $p['stock'] ?></b></td></tr>
            <?php endforeach; ?></tbody>
          </table>
        <?php else: ?><p class="adm-empty">Everything is well stocked.</p><?php endif; ?>
      </div>
    </div>
  </div>
</section>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function () {
  if (!window.Chart) return;
  var ink = '#111111', blaze = '#E2412A', gray = '#8A8578', line = '#E3E0D9';
  var peso = function (v) { return '₱' + Number(v).toLocaleString('en-PH', { maximumFractionDigits: 0 }); };
  var el = function (id) { return document.getElementById(id); };
  Chart.defaults.font.family = 'Inter, sans-serif';
  Chart.defaults.color = gray;
  Chart.defaults.plugins.legend.labels.boxWidth = 12;

  new Chart(el('chRevenue'), {
    data: {
      labels: <?= json_encode($labels) ?>,
      datasets: [
        { type: 'line', label: 'Revenue', data: <?= json_encode($revSeries) ?>, borderColor: ink, backgroundColor: 'rgba(17,17,17,.08)', fill: true, borderWidth: 2.5, pointRadius: 2, pointHoverRadius: 5, tension: .3, yAxisID: 'y' },
        { type: 'line', label: 'Orders', data: <?= json_encode($ordSeries) ?>, borderColor: blaze, backgroundColor: blaze, borderWidth: 1.5, borderDash: [5, 4], pointRadius: 2, tension: .3, yAxisID: 'y1' }
      ]
    },
    options: {
      maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
      scales: {
        x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } },
        y: { beginAtZero: true, grid: { color: line }, ticks: { callback: peso } },
        y1: { beginAtZero: true, position: 'right', grid: { display: false }, ticks: { precision: 0 } }
      },
      plugins: { tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + (c.dataset.yAxisID === 'y' ? peso(c.raw) : c.raw); } } } }
    }
  });

  var statusColors = { pending: '#E8A33D', processing: '#3D7BE8', shipped: '#7A5CE0', completed: '#2E9E5B', cancelled: '#B8B3A7' };
  var st = <?= json_encode((object) $byStatus) ?>;
  if (el('chStatus')) new Chart(el('chStatus'), {
    type: 'doughnut',
    data: { labels: Object.keys(st).map(function (s) { return s[0].toUpperCase() + s.slice(1); }),
            datasets: [{ data: Object.values(st), backgroundColor: Object.keys(st).map(function (s) { return statusColors[s] || gray; }), borderWidth: 0 }] },
    options: { maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'right' } } }
  });

  var pay = <?= json_encode((object) $byPay) ?>;
  if (el('chPay')) new Chart(el('chPay'), {
    type: 'doughnut',
    data: { labels: Object.keys(pay), datasets: [{ data: Object.values(pay), backgroundColor: [ink, blaze, '#3D7BE8'], borderWidth: 0 }] },
    options: { maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'right' }, tooltip: { callbacks: { label: function (c) { return c.label + ': ' + peso(c.raw); } } } } }
  });

  if (el('chTop')) new Chart(el('chTop'), {
    type: 'bar',
    data: { labels: <?= json_encode(array_column($topProducts, 'product_name')) ?>,
            datasets: [{ label: 'Pairs sold', data: <?= json_encode(array_map('intval', array_column($topProducts, 'units'))) ?>, backgroundColor: blaze }] },
    options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } },
               scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: line } }, y: { grid: { display: false } } } }
  });
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>
