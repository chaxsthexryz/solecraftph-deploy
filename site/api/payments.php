<?php
/**
 * In-app payments — the app's native PayMongo checkout. No hosted page, no browser.
 *
 *   GET  /api/payments.php?action=config               -> {public_key}
 *   POST /api/payments.php?action=pay    {order_id, method: "card"|"gcash", payment_method_id?}
 *   GET  /api/payments.php?action=status&order_id=56
 *   GET  /api/payments.php?action=quote&order_id=56     -> {ok, amount, paid}
 *
 * pay and status answer {ok, status, redirect_url, message}:
 *   status "paid"   -> money received; the order is now paid + processing
 *   status "action" -> the customer must approve on redirect_url (GCash, or a
 *                      card's 3-D Secure check). The app shows it in its own
 *                      sheet and closes the sheet when PayMongo sends it on to
 *                      payment_return.php, then asks for status.
 *   status "failed" -> message says why, in PayMongo's words; the customer can
 *                      fix the card and pay the same order again.
 *
 * Card: the app turns the card into a payment method itself, with the public
 * key, so the number goes from the phone to PayMongo and never through here.
 *
 * Business refusals are 200 {ok:false}: the app can only read the body of a
 * successful call. Only a missing or bad token is a 401.
 *
 * "paid" is only ever decided from PayMongo's own answer to a secret-key call
 * made here, never from anything the phone says.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order_service.php';
require_once __DIR__ . '/../includes/settings_service.php';
require_once __DIR__ . '/../includes/paymongo_service.php';

require_once __DIR__ . '/../includes/cors.php';
api_cors_origin();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function payments_answer(bool $ok, string $status, string $message, string $redirectUrl = ''): void
{
    echo json_encode(['ok' => $ok, 'status' => $status, 'redirect_url' => $redirectUrl, 'message' => $message]);
    exit;
}

/** Flips the order to paid + processing once, exactly as the webhook does. */
function payments_mark_paid(int $orderId): void
{
    if (order_set_payment_status($orderId, 'paid')) {
        order_update_status($orderId, 'processing', 'Payment confirmed via PayMongo (in-app).');
    }
}

// The public key is meant to ship in apps; serving it saves pinning it in a build.
if ($action === 'config' && $method === 'GET') {
    echo json_encode(['public_key' => PAYMONGO_PUBLIC_KEY]);
    exit;
}

$viewerId = auth_user_id_from_bearer_token();
if ($viewerId === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Valid Authorization Bearer token required.']);
    exit;
}

$input   = json_decode(file_get_contents('php://input') ?: '', true);
$input   = is_array($input) ? $input : [];
$orderId = (int) ($input['order_id'] ?? $_GET['order_id'] ?? 0);
$order   = order_find($orderId);

// Someone else's order reads exactly like a missing one.
if (!$order || (int) ($order['user_id'] ?? 0) !== $viewerId) {
    payments_answer(false, 'failed', 'Order not found.');
}

// What the payment screen shows as the total — the server's own figure, so the
// app never does money arithmetic of its own.
if ($action === 'quote' && $method === 'GET') {
    echo json_encode([
        'ok'     => true,
        'amount' => round(paymongo_amount_due($order), 2),
        'paid'   => ($order['payment_status'] ?? '') === 'paid',
    ]);
    exit;
}

if (($order['payment_status'] ?? '') === 'paid') {
    payments_answer(true, 'paid', 'This order is already paid.');
}

try {
    if ($action === 'status' && $method === 'GET') {
        $intentId = (string) ($order['checkout_session_id'] ?? '');
        if (!str_starts_with($intentId, 'pi_')) {
            payments_answer(false, 'failed', 'No payment has been started for this order.');
        }
        $outcome = paymongo_intent_status($intentId);
        if ($outcome['status'] === 'paid') {
            payments_mark_paid($orderId);
        }
        payments_answer($outcome['status'] !== 'failed', $outcome['status'], $outcome['message'], $outcome['redirect_url']);
    }

    if ($action === 'pay' && $method === 'POST') {
        if (($order['status'] ?? '') === 'cancelled') {
            payments_answer(false, 'failed', 'This order was cancelled.');
        }
        $kind = strtolower((string) ($input['method'] ?? ''));
        if ($kind !== 'card' && $kind !== 'gcash') {
            payments_answer(false, 'failed', 'Choose GCash or card.');
        }
        $methodId = $kind === 'gcash'
            ? paymongo_create_gcash_method($order)
            : (string) ($input['payment_method_id'] ?? '');
        if (!preg_match('/^pm_[A-Za-z0-9]+$/', $methodId)) {
            payments_answer(false, 'failed', 'Card details are missing. Please enter them again.');
        }

        // A fresh intent per attempt: a failed one cannot always take a new
        // method, and the newest id is what status checks read.
        $intentId = paymongo_create_intent($order);
        order_set_checkout_session($orderId, $intentId);
        // The customer may switch between GCash and card on retry.
        db()->prepare('UPDATE orders SET payment_method = ? WHERE id = ?')->execute([strtoupper($kind), $orderId]);

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $returnUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . BASE_PATH . '/payment_return.php?id=' . $orderId;

        $outcome = paymongo_attach($intentId, $methodId, $returnUrl);
        if ($outcome['status'] === 'paid') {
            payments_mark_paid($orderId);
        }
        payments_answer($outcome['status'] !== 'failed', $outcome['status'], $outcome['message'], $outcome['redirect_url']);
    }
} catch (Throwable $e) {
    payments_answer(false, 'failed', 'Could not reach PayMongo. Please try again.');
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
