<?php
/**
 * Sales report as a CSV download, for the same ranges as the dashboard.
 * One row per order, then a totals row, so it opens straight in Excel.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/voucher_service.php';
auth_require_admin();

$ranges = ['today' => 1, '30d' => 30, '90d' => 90];
$range  = isset($ranges[$_GET['range'] ?? '']) ? $_GET['range'] : '30d';
$db     = db();
$today  = $db->query('SELECT CURDATE()')->fetchColumn();
$start  = date('Y-m-d', strtotime($today . ' -' . ($ranges[$range] - 1) . ' days'));

$stmt = $db->prepare(
    "SELECT o.*, (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = o.id) AS pairs
     FROM orders o WHERE o.created_at >= ? ORDER BY o.created_at"
);
$stmt->execute([$start]);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="solecraft-sales-' . $range . '-' . $today . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // so Excel reads ₱ and names correctly
fputcsv($out, ['SoleCraftPH sales report', $start . ' to ' . $today]);
fputcsv($out, []);
fputcsv($out, ['Order #', 'Date', 'Customer', 'Email', 'Payment', 'Payment status', 'Status', 'Pairs', 'Items', 'Shipping', 'Voucher', 'Amount due']);

$sum = ['orders' => 0, 'pairs' => 0, 'due' => 0.0];
foreach ($stmt->fetchAll() as $o) {
    $items    = (float) $o['total_amount'];
    $discount = (float) ($o['discount_amount'] ?? 0);
    $due      = order_amount_due($o);
    // A leading = + - @ would run as a formula in Excel; the quote keeps it text.
    $name = preg_replace('/^([=+\-@])/', "'$1", (string) $o['customer_name']);
    fputcsv($out, [
        str_pad($o['id'], 6, '0', STR_PAD_LEFT), $o['created_at'], $name, $o['customer_email'],
        $o['payment_method'], $o['payment_status'] ?? '', $o['status'], (int) $o['pairs'],
        number_format($items, 2, '.', ''), number_format($due - $items + $discount, 2, '.', ''),
        number_format($discount, 2, '.', ''), number_format($due, 2, '.', ''),
    ]);
    if ($o['status'] !== 'cancelled') {
        $sum['orders']++;
        $sum['pairs'] += (int) $o['pairs'];
        $sum['due']   += $due;
    }
}
fputcsv($out, []);
fputcsv($out, ['Totals (cancelled excluded)', '', '', '', '', '', $sum['orders'] . ' orders', $sum['pairs'], '', '', '', number_format($sum['due'], 2, '.', '')]);
fclose($out);
