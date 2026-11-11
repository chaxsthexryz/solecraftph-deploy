<?php
/**
 * Order Service
 * Handles checkout / order creation and order lookups.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/product_service.php';
require_once __DIR__ . '/voucher_service.php';

/**
 * @param array $customer  ['name','email','phone','address','payment_method','user_id']
 * @param array $cartItems Cart lines. Accepts either shape:
 *                         - keyed "<product_id>|<size>" => ['product_id','size','qty', ...]
 *                         - legacy: keyed <product_id>   => ['name','price','qty']
 *                         The same shoe in two sizes is two lines.
 * @return int  the new order id
 */
function order_create(array $customer, array $cartItems): int
{
    if (empty($cartItems)) {
        throw new InvalidArgumentException('Cannot place an order with an empty cart.');
    }

    // Normalize and re-price every line from the products table before touching
    // the database. The mobile app posts its own name/price fields, and those
    // must never be what we bill — only the id, size and quantity are the
    // client's to decide.
    $lines = [];
    foreach ($cartItems as $key => $item) {
        $productId = (int) ($item['product_id'] ?? explode('|', (string) $key)[0]);
        $product   = product_find($productId);
        if (!$product) {
            throw new InvalidArgumentException('Product #' . $productId . ' is no longer available.');
        }
        $lines[] = [
            'product_id' => $productId,
            'name'       => $product['name'],
            'size'       => (string) ($item['size'] ?? ''),
            'price'      => product_display_price($product),
            'qty'        => max(1, (int) ($item['qty'] ?? 1)),
        ];
    }

    $db = db();
    $db->beginTransaction();
    try {
        /*
         * Lock the product rows and check stock BEFORE writing anything.
         *
         * Without this, two people buying the last pair at the same moment both
         * succeed: each reads stock=1, each decrements, and GREATEST(...,0)
         * quietly floors the result at zero so nothing ever looks wrong. One of
         * them gets a confirmation for a shoe that does not exist.
         *
         * SELECT ... FOR UPDATE makes the second transaction wait until the
         * first commits, so it sees stock=0 and is refused.
         *
         * Quantities are summed per product first — the same shoe in two sizes
         * is two lines but one pool of stock — and the rows are locked in
         * ascending id order so two concurrent orders containing the same
         * products in different sequences cannot deadlock against each other.
         */
        $needed = [];
        $neededBySize = [];
        foreach ($lines as $line) {
            $needed[$line['product_id']] = ($needed[$line['product_id']] ?? 0) + $line['qty'];
            $sizeKey = $line['product_id'] . '|' . $line['size'];
            $neededBySize[$sizeKey] = ($neededBySize[$sizeKey] ?? 0) + $line['qty'];
        }
        ksort($needed);
        ksort($neededBySize);

        $lockStmt = $db->prepare('SELECT name, stock FROM products WHERE id = ? FOR UPDATE');
        $sizedLock = $db->prepare(
            'SELECT stock FROM product_sizes WHERE product_id = ? AND size = ? FOR UPDATE'
        );

        foreach ($needed as $productId => $wanted) {
            $lockStmt->execute([$productId]);
            $row = $lockStmt->fetch();

            if (!$row) {
                throw new InvalidArgumentException('Product #' . $productId . ' is no longer available.');
            }
            if ((int) $row['stock'] < $wanted) {
                throw new RuntimeException(sprintf(
                    '%s: only %d left, and %d were requested. Please adjust your bag.',
                    $row['name'],
                    (int) $row['stock'],
                    $wanted
                ));
            }

            // Products converted to per-size stock get the real check: having
            // fifteen pairs in total is no use if none of them are a US 9. The
            // product row above is still locked first, which keeps lock ordering
            // consistent and stops the total drifting from the parts.
            if (product_has_size_stock((int) $productId)) {
                foreach ($neededBySize as $key => $qty) {
                    [$pid, $size] = array_pad(explode('|', (string) $key, 2), 2, '');
                    if ((int) $pid !== (int) $productId) {
                        continue;
                    }
                    $sizedLock->execute([$productId, $size]);
                    $have = $sizedLock->fetchColumn();

                    if ($have === false || (int) $have < $qty) {
                        throw new RuntimeException(sprintf(
                            '%s in US %s: only %d left, and %d were requested. Please adjust your bag.',
                            $row['name'],
                            $size !== '' ? $size : '—',
                            (int) ($have === false ? 0 : $have),
                            $qty
                        ));
                    }
                }
            }
        }

        $total = 0.0;
        foreach ($lines as $line) {
            $total += $line['price'] * $line['qty'];
        }

        // The map pin, when the customer chose a saved address that had one.
        // Copied onto the order rather than looked up from the address book
        // later: that book is theirs to edit or delete, and an order has to
        // keep saying where it actually went. Both halves or neither — half a
        // coordinate points at the Gulf of Guinea.
        $lat = isset($customer['latitude']) && $customer['latitude'] !== ''
            ? (float) $customer['latitude'] : null;
        $lng = isset($customer['longitude']) && $customer['longitude'] !== ''
            ? (float) $customer['longitude'] : null;
        if ($lat === null || $lng === null) {
            $lat = null;
            $lng = null;
        }

        // The voucher, checked again here against the real total: what Apply
        // showed was a preview, this is the answer. Counted inside the same
        // transaction, so a failed order never uses one up.
        $voucherCode = voucher_normalize((string) ($customer['voucher_code'] ?? ''));
        $discount = 0.0;
        if ($voucherCode !== '') {
            $quote = voucher_quote($voucherCode, $total);
            if (!$quote['ok']) {
                throw new RuntimeException($quote['message']);
            }
            if (!voucher_redeem($voucherCode)) {
                throw new RuntimeException('That voucher has been fully used.');
            }
            $discount = $quote['discount'];
        }

        $stmt = $db->prepare(
            'INSERT INTO orders (user_id, customer_name, customer_email, customer_phone, customer_address, latitude, longitude, payment_method, total_amount, voucher_code, discount_amount, status)
             VALUES (:user_id, :name, :email, :phone, :address, :latitude, :longitude, :payment_method, :total, :voucher, :discount, "pending")'
        );
        $stmt->execute([
            ':user_id'        => $customer['user_id'] ?? null,
            ':name'           => $customer['name'],
            ':email'          => $customer['email'],
            ':phone'          => $customer['phone'],
            ':address'        => $customer['address'],
            ':latitude'       => $lat,
            ':longitude'      => $lng,
            ':payment_method' => $customer['payment_method'] ?? 'COD',
            ':total'          => $total,
            ':voucher'        => $voucherCode !== '' ? $voucherCode : null,
            ':discount'       => $discount,
        ]);
        $orderId = (int) $db->lastInsertId();

        $itemStmt = $db->prepare(
            'INSERT INTO order_items (order_id, product_id, product_name, size, unit_price, quantity, subtotal)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $line) {
            $subtotal = $line['price'] * $line['qty'];
            $itemStmt->execute([$orderId, $line['product_id'], $line['name'], $line['size'], $line['price'], $line['qty'], $subtotal]);
            product_decrement_stock($line['product_id'], $line['qty']);
            // Both sides move together, inside the same transaction, so the
            // total and the per-size rows cannot disagree.
            if ($line['size'] !== '' && product_has_size_stock($line['product_id'])) {
                product_decrement_size_stock($line['product_id'], $line['size'], $line['qty']);
            }
        }

        $db->commit();

        // Confirmation email. After the commit and wrapped in its own try, so a
        // mail failure can never roll back or fail an order that is already paid
        // for — the customer's money matters more than their receipt.
        try {
            order_send_confirmation_email($orderId, $customer, $lines, $total, $discount);
        } catch (Throwable $mailError) {
            require_once __DIR__ . '/mail_service.php';
            mail_log('ORDER   confirmation failed for #' . $orderId . ': ' . $mailError->getMessage());
        }

        // Inventory Management: low-stock alerts, fired after the transaction commits.
        require_once __DIR__ . '/notification_service.php';
        foreach (array_unique(array_column($lines, 'product_id')) as $productId) {
            $p = product_find((int) $productId);
            if ($p && $p['is_active'] && $p['stock'] <= $p['low_stock_threshold']) {
                notification_notify_admins(
                    'Low Stock',
                    $p['name'] . ' is down to ' . $p['stock'] . ' unit(s) (threshold: ' . $p['low_stock_threshold'] . ').'
                );
            }
        }

        return $orderId;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * An order as the app shows it: total_amount is what the customer pays
 * (items + delivery - voucher), the figure the website labels Total. The
 * items-only sum stays available as items_total.
 */
function order_for_app(array $order): array
{
    $order['items_total']  = $order['total_amount'];
    $order['total_amount'] = sprintf('%.2f', order_amount_due($order));
    return $order;
}

function order_find(int $orderId): ?array
{
    $stmt = db()->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        return null;
    }
    // The product photo rides along so the app can show what was bought.
    $itemStmt = db()->prepare(
        'SELECT oi.*, p.image FROM order_items oi
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = ?'
    );
    $itemStmt->execute([$orderId]);
    $order['items'] = $itemStmt->fetchAll();
    return $order;
}

function order_list_recent(int $limit = 50): array
{
    $stmt = db()->prepare('SELECT * FROM orders ORDER BY created_at DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Admin: orders list, optionally filtered by status */
function order_list_filtered(?string $status = null, int $limit = 300): array
{
    if ($status && $status !== 'all') {
        $stmt = db()->prepare('SELECT * FROM orders WHERE status = ? ORDER BY created_at DESC LIMIT ?');
        $stmt->bindValue(1, $status, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    return order_list_recent($limit);
}

/** Customer: order history for their account */
function order_list_by_user(int $userId, int $limit = 100): array
{
    $stmt = db()->prepare(
        'SELECT o.*, (SELECT p.image FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id
                      WHERE oi.order_id = o.id ORDER BY oi.id LIMIT 1) AS thumb
         FROM orders o WHERE o.user_id = ? ORDER BY o.created_at DESC LIMIT ?'
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

const ORDER_STATUSES = ['pending', 'processing', 'shipped', 'completed', 'cancelled'];

/** The happy path, in order. 'cancelled' sits outside it and ends things early. */
const ORDER_FLOW = ['pending', 'processing', 'shipped', 'completed'];

/** Nothing follows these two. */
const ORDER_TERMINAL = ['completed', 'cancelled'];

/**
 * Whether $to is allowed to follow $from.
 *
 * Until now any status could follow any other, because the only check was that
 * the new value appeared in ORDER_STATUSES. A real order on this site reads
 * pending -> processing -> completed -> cancelled, and every one of those steps
 * pushed a notification to the customer. Being told an order you have already
 * received is now cancelled is the kind of message that produces a support
 * ticket and a refund request for goods already delivered.
 *
 * Forward along the flow is fine, and so is standing still — checkout logs its
 * own 'pending' the moment an order is created, which is a pending -> pending
 * move. Cancelling is allowed from anywhere that is not already finished.
 * Nothing at all leaves a terminal state: an order that is completed or
 * cancelled has told the customer its final word, and the fix for a mis-click
 * is a deliberate correction, not another push notification.
 */
function order_status_may_follow(string $from, string $to): bool
{
    if (in_array($from, ORDER_TERMINAL, true)) {
        return false;
    }
    if ($to === 'cancelled') {
        return true;
    }

    $at = array_search($from, ORDER_FLOW, true);
    $to_ = array_search($to, ORDER_FLOW, true);
    if ($at === false || $to_ === false) {
        return false;
    }
    return $to_ >= $at;
}

/**
 * Admin: update order status, write the tracking history log, notify the
 * customer.
 *
 * Returns false and changes nothing when the move is not allowed — see
 * order_status_may_follow. Callers that show the result to a human should say
 * so rather than swallowing it; a button that silently does nothing is worse
 * than one that refuses out loud.
 */
function order_update_status(int $orderId, string $status, string $note = '', ?string $trackingNumber = null): bool
{
    if (!in_array($status, ORDER_STATUSES, true)) {
        return false;
    }

    // Read once and reuse for the notification below, rather than querying the
    // same row twice.
    $order = order_find($orderId);
    if ($order === null) {
        return false;
    }
    if (!order_status_may_follow((string) ($order['status'] ?? ''), $status)) {
        return false;
    }

    $db = db();
    $from = (string) ($order['status'] ?? '');
    $sql = 'UPDATE orders SET status = ?';
    $params = [$status];
    if ($trackingNumber !== null) {
        $sql .= ', tracking_number = ?';
        $params[] = $trackingNumber;
    }
    // Conditional on the status just checked, so two cancels racing each other
    // (the customer's button and the admin's dropdown) cannot both win and put
    // the same shoes back on the shelf twice.
    $sql .= ' WHERE id = ? AND status = ?';
    $params[] = $orderId;
    $params[] = $from;

    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        $update = $db->prepare($sql);
        $update->execute($params);
        if ($update->rowCount() === 0 && $from !== $status) {
            // Someone else moved it first; their change stands.
            if ($ownTransaction) {
                $db->rollBack();
            }
            return false;
        }

        $db->prepare('INSERT INTO order_status_log (order_id, status, note) VALUES (?, ?, ?)')
           ->execute([$orderId, $status, $note ?: null]);

        if ($status === 'cancelled' && $from !== 'cancelled') {
            order_restock($orderId);
        }

        if ($ownTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction) {
            $db->rollBack();
        }
        throw $e;
    }

    if (!empty($order['user_id'])) {
        require_once __DIR__ . '/notification_service.php';
        $label = ucfirst($status);
        notification_notify_customer(
            (int) $order['user_id'],
            'Order #' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT) . ' — ' . $label,
            'Your order status changed to "' . $label . '".' . ($note ? ' Note: ' . $note : '') .
                ($trackingNumber ? ' Tracking #: ' . $trackingNumber : '')
        );
    }

    // Email too: push never reaches a sideloaded iPhone, and guests have no
    // app at all. 'pending' is skipped — order placement sends its own email.
    // Same rule as the confirmation: a mail failure never undoes the change.
    if ($status !== $from && $status !== 'pending') {
        try {
            order_send_status_email($order, $status, $note, $trackingNumber);
        } catch (Throwable $mailError) {
            require_once __DIR__ . '/mail_service.php';
            mail_log('ORDER   status email failed for #' . $orderId . ': ' . $mailError->getMessage());
        }
    }
    return true;
}

/** "Your order shipped" and friends, one email per status change. */
function order_send_status_email(array $order, string $status, string $note, ?string $trackingNumber): void
{
    require_once __DIR__ . '/mail_service.php';

    $email = trim((string) ($order['customer_email'] ?? ''));
    if ($email === '') {
        return;
    }
    $reference = '#' . str_pad((string) $order['id'], 6, '0', STR_PAD_LEFT);

    $copy = [
        'processing' => ['is being prepared', 'We are packing your order',
            'Your order is confirmed and we are getting it ready to ship.'],
        'shipped'    => ['is on its way', 'Your order is on its way',
            'Your shoes have left our store and are heading to you.'],
        'completed'  => ['was delivered', 'Delivered — enjoy your shoes',
            'Your order is marked as delivered. Loving them? Leave a review on the product page.'],
        'cancelled'  => ['was cancelled', 'Your order was cancelled',
            'This order has been cancelled. If you paid online, the refund goes back to the same account.'],
    ];
    if (!isset($copy[$status])) {
        return;
    }
    [$subject, $heading, $body] = $copy[$status];

    $tracking = $trackingNumber ?: (string) ($order['tracking_number'] ?? '');

    mail_send(
        $email,
        'Your SoleCraftPH order ' . $reference . ' ' . $subject,
        '<h1 style="font-size:22px;margin:0 0 12px;">' . $heading . '</h1>'
        . '<p style="margin:0;">Order ' . $reference . '. ' . $body . '</p>'
        . ($status === 'shipped' && $tracking !== ''
            ? '<p style="margin:12px 0 0;">Tracking number: <b>' . htmlspecialchars($tracking) . '</b></p>'
            : '')
        . ($note !== '' ? '<p style="margin:12px 0 0;color:#8A8578;">Note: ' . htmlspecialchars($note) . '</p>' : '')
        . mail_button('View your order', mail_site_url() . '/order_detail.php?id=' . (int) $order['id'])
    );
}

/**
 * Puts a cancelled order's shoes back on the shelf: the product total and, for
 * products counted per size, the size row — the exact reverse of what
 * order_create took off.
 *
 * Until this existed, cancelling never restocked, so every cancelled order
 * quietly shrank the catalogue for good (including every GCash/Card order that
 * failed to reach PayMongo, which order creation cancels itself).
 */
function order_restock(int $orderId): void
{
    $db = db();
    $items = $db->prepare('SELECT product_id, size, quantity FROM order_items WHERE order_id = ?');
    $items->execute([$orderId]);

    $total = $db->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
    $sized = $db->prepare(
        'INSERT INTO product_sizes (product_id, size, stock) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE stock = stock + VALUES(stock)'
    );

    foreach ($items->fetchAll() as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty       = (int) $item['quantity'];
        $size      = (string) ($item['size'] ?? '');
        if ($productId === 0 || $qty < 1) {
            continue; // product since deleted: nothing to put back
        }
        $total->execute([$qty, $productId]);
        if ($size !== '' && product_has_size_stock($productId)) {
            $sized->execute([$productId, $size, $qty]);
        }
    }
}

/**
 * A customer cancelling their own order. Returns null on success, or the
 * sentence to show them when it cannot be done.
 *
 * Only while it is still pending: once the store has started on it, stopping it
 * is a conversation, not a button. A paid order is refused too — cancelling it
 * here would leave money with no order and nobody told to refund it.
 */
function order_cancel_by_customer(int $orderId, int $userId): ?string
{
    $order = order_find($orderId);
    if ($order === null || (int) ($order['user_id'] ?? 0) !== $userId) {
        return 'Order not found.';
    }
    if (($order['status'] ?? '') !== 'pending') {
        return 'This order is already being prepared, so it can no longer be cancelled here. Contact us and we will help.';
    }
    if (($order['payment_status'] ?? '') === 'paid') {
        return 'This order is already paid. Contact us to cancel it and arrange a refund.';
    }
    return order_update_status($orderId, 'cancelled', 'Cancelled by the customer.')
        ? null
        : 'This order could not be cancelled. Please refresh and try again.';
}

/** Stores the PayMongo Checkout Session id against the order so the webhook can be traced back. */
function order_set_checkout_session(int $orderId, string $sessionId): void
{
    db()->prepare('UPDATE orders SET checkout_session_id = ? WHERE id = ?')->execute([$sessionId, $orderId]);
}

/**
 * Marks an order's payment as paid/failed once PayMongo's webhook confirms it.
 *
 * Returns true only for the call that actually changed the value, so a caller
 * can tell a real payment from a webhook redelivery of one already handled.
 * Callers that ignore the return value behave exactly as they did before.
 *
 * The guard is in the WHERE clause rather than a read-then-write, because
 * PayMongo can deliver the same event twice close enough together that both
 * requests read "unpaid" before either writes. One statement settles it inside
 * the database: whichever arrives second matches no rows and gets false.
 */
function order_set_payment_status(int $orderId, string $paymentStatus): bool
{
    if (!in_array($paymentStatus, ['unpaid', 'paid', 'failed'], true)) {
        return false;
    }
    $stmt = db()->prepare(
        'UPDATE orders SET payment_status = ? WHERE id = ? AND payment_status <> ?'
    );
    $stmt->execute([$paymentStatus, $orderId, $paymentStatus]);

    return $stmt->rowCount() > 0;
}

function order_status_history(int $orderId): array
{
    $stmt = db()->prepare('SELECT * FROM order_status_log WHERE order_id = ? ORDER BY created_at ASC');
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}

function order_set_admin_notes(int $orderId, string $notes): void
{
    db()->prepare('UPDATE orders SET admin_notes = ? WHERE id = ?')->execute([$notes, $orderId]);
}

/** Sales & Analytics: revenue + order counts, grouped by day, over the last N days */
function order_sales_by_day(int $days = 30): array
{
    $stmt = db()->prepare(
        "SELECT DATE(created_at) AS day, COUNT(*) AS orders_count, SUM(total_amount) AS revenue
         FROM orders WHERE status <> 'cancelled' AND created_at >= (NOW() - INTERVAL ? DAY)
         GROUP BY DATE(created_at) ORDER BY day ASC"
    );
    $stmt->bindValue(1, $days, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Sales & Analytics: best-selling products by units sold / revenue */
function order_top_products(int $limit = 10): array
{
    $stmt = db()->prepare(
        "SELECT oi.product_name, SUM(oi.quantity) AS units_sold, SUM(oi.subtotal) AS revenue
         FROM order_items oi JOIN orders o ON o.id = oi.order_id
         WHERE o.status <> 'cancelled'
         GROUP BY oi.product_name ORDER BY units_sold DESC LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Sales & Analytics: totals for the dashboard/reports summary cards */
function order_sales_summary(): array
{
    $row = db()->query(
        "SELECT COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total_amount ELSE 0 END),0) AS total_revenue,
                COALESCE(AVG(CASE WHEN status <> 'cancelled' THEN total_amount END),0) AS avg_order_value,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count
         FROM orders"
    )->fetch();
    return $row ?: [];
}

/** Customer insights: top customers by spend */
function order_top_customers(int $limit = 10): array
{
    $stmt = db()->prepare(
        "SELECT customer_name, customer_email, COUNT(*) AS orders_count, SUM(total_amount) AS total_spent
         FROM orders WHERE status <> 'cancelled'
         GROUP BY customer_email, customer_name
         ORDER BY total_spent DESC LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * The "we've got your order" email. Called once, straight after order_create()
 * commits — see the note there about why failures are swallowed.
 *
 * Deliberately does not claim payment has been received: a GCash or Card order
 * is still unpaid at this point and only PayMongo's webhook can say otherwise.
 */
function order_send_confirmation_email(
    int $orderId,
    array $customer,
    array $lines,
    float $total,
    float $discount = 0.0
): void {
    require_once __DIR__ . '/mail_service.php';
    require_once __DIR__ . '/settings_service.php';

    $email = trim((string) ($customer['email'] ?? ''));
    if ($email === '') {
        return;
    }

    $reference = '#' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
    $payment   = strtoupper((string) ($customer['payment_method'] ?? 'COD'));
    $shipping  = settings_shipping_for($total);

    $rows = '';
    foreach ($lines as $line) {
        $label = htmlspecialchars($line['name'])
            . ($line['size'] !== '' ? ' <span style="color:#8A8578;">(US ' . htmlspecialchars($line['size']) . ')</span>' : '')
            . ' &times; ' . (int) $line['qty'];
        $rows .= '<tr>'
            . '<td style="padding:6px 0;border-bottom:1px solid #EDEAE3;">' . $label . '</td>'
            . '<td style="padding:6px 0;border-bottom:1px solid #EDEAE3;text-align:right;white-space:nowrap;">₱'
            . number_format($line['price'] * $line['qty'], 2) . '</td>'
            . '</tr>';
    }

    $note = ($payment === 'COD')
        ? 'Pay in cash when your order arrives.'
        : 'We are confirming your ' . ($payment === 'GCASH' ? 'GCash' : 'card')
          . ' payment with PayMongo. Your order updates automatically once it clears.';

    mail_send(
        $email,
        'Your SoleCraftPH order ' . $reference,
        '<h1 style="font-size:22px;margin:0 0 12px;">Thanks, we have your order</h1>'
        . '<p style="margin:0 0 4px;">Order <strong>' . $reference . '</strong></p>'
        . '<p style="margin:0 0 20px;color:#8A8578;">' . htmlspecialchars($note) . '</p>'
        . '<table style="width:100%;border-collapse:collapse;font-size:14px;">' . $rows
        . '<tr><td style="padding:6px 0;">Shipping</td>'
        . '<td style="padding:6px 0;text-align:right;">'
        . ($shipping == 0 ? 'Free' : '₱' . number_format($shipping, 2)) . '</td></tr>'
        . ($discount > 0
            ? '<tr><td style="padding:6px 0;">Voucher ' . htmlspecialchars(voucher_normalize((string) ($customer['voucher_code'] ?? ''))) . '</td>'
              . '<td style="padding:6px 0;text-align:right;">−₱' . number_format($discount, 2) . '</td></tr>'
            : '')
        . '<tr><td style="padding:10px 0 0;font-weight:600;">Total</td>'
        . '<td style="padding:10px 0 0;text-align:right;font-weight:600;">₱'
        . number_format(max(0, $total + $shipping - $discount), 2) . '</td></tr>'
        . '</table>'
        . '<p style="margin:20px 0 0;">Delivering to<br><span style="color:#8A8578;">'
        . nl2br(htmlspecialchars((string) ($customer['address'] ?? ''))) . '</span></p>'
        . mail_button('View your order', mail_site_url() . '/order_detail.php?id=' . $orderId)
    );
}

/**
 * A Google Maps link for an order's pin, or empty when it has none.
 * Used by the admin order page and the packing slip — a rider looking for
 * "blk 12 lot 4, corner of the sari-sari store" wants the pin, not the prose.
 */
function order_map_url(array $order): string
{
    if (empty($order['latitude']) || empty($order['longitude'])) {
        return '';
    }
    return 'https://www.google.com/maps/search/?api=1&query='
        . rawurlencode($order['latitude'] . ',' . $order['longitude']);
}
