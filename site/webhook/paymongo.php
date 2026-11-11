<?php
/**
 * PayMongo webhook endpoint. Register this URL in the PayMongo dashboard
 * (Settings > Webhooks) for the checkout_session.payment.paid event:
 *
 *   https://yourdomain.com/webhook/paymongo.php
 *
 * Webhooks are MODE-SCOPED: a live-mode hook never receives test-mode events.
 * Test keys (sk_test_) need a hook created in test mode, with its own whsk_
 * secret. Getting this wrong looks exactly like a broken endpoint.
 *
 * This is the only place an order is actually marked "paid" — the customer's
 * browser redirect back to success_url is not trusted on its own, since they
 * could close the tab before PayMongo finishes.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/order_service.php';
require_once __DIR__ . '/../includes/paymongo_service.php';

$rawPayload = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

// Diagnostic log. Lives one level ABOVE public_html so it is never reachable
// over the web — it holds customer names and addresses. Delete it once
// payments are confirmed working.
// ponytail: unrotated append; fine for a handful of orders a day, swap for
// error_log() or logrotate if this ever gets real volume.
$logFile = __DIR__ . '/../../paymongo_webhook.log';
$logLine = function (string $message) use ($logFile): void {
    @file_put_contents(
        $logFile,
        gmdate('c') . ' ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
};

// Test-mode keys (sk_test_...) send the "te" signature; live keys send "li".
$isTestMode = str_starts_with(PAYMONGO_SECRET_KEY, 'sk_test_');

if (!paymongo_verify_webhook_signature($rawPayload, $signatureHeader, $isTestMode)) {
    $logLine('REJECTED bad signature (test_mode=' . ($isTestMode ? 'yes' : 'no') . ') body=' . $rawPayload);
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = json_decode($rawPayload, true) ?: [];

/*
 * PayMongo has shipped two payload shapes, and which one you get has moved
 * between API versions. Read both rather than betting on one:
 *
 *   nested (documented):  data.attributes.type + data.attributes.data
 *   flat   (v2 checkout): data.type           + data.data
 *
 * The nested form is checked first because in it `data.type` is the literal
 * string "event", which would otherwise silently match nothing.
 */
$eventType = $event['data']['attributes']['type']
    ?? $event['data']['type']
    ?? '';
$resource = $event['data']['attributes']['data']
    ?? $event['data']['data']
    ?? [];

$orderId = (int) ($resource['attributes']['reference_number'] ?? 0);

$logLine('RECEIVED type=' . $eventType . ' order=' . $orderId);

if ($eventType === 'checkout_session.payment.paid') {
    if ($orderId > 0 && order_find($orderId)) {
        /*
         * Redeliveries are normal here, not an error. PayMongo guarantees at
         * least once, so a timeout, a network blip or their own retry all send
         * an event that has already been handled.
         *
         * Everything that must happen exactly once now hangs off the return of
         * order_set_payment_status(), which is true only for the call that
         * actually flipped the row. Without this, every redelivery wrote
         * another order_status_log entry AND fired another push notification —
         * so one purchase told the customer "Order #000123 — Processing" three
         * times, which reads like being charged three times.
         */
        if (order_set_payment_status($orderId, 'paid')) {
            order_update_status($orderId, 'processing', 'Payment confirmed via PayMongo.');
            $logLine('MARKED PAID order=' . $orderId);
        } else {
            $logLine('ALREADY PAID, redelivery ignored order=' . $orderId);
        }
    } else {
        $logLine('NO MATCHING ORDER for reference_number=' . $orderId . ' body=' . $rawPayload);
    }
} else {
    $logLine('IGNORED event type=' . $eventType);
}

// Always 200 quickly — PayMongo retries on anything else, and slow/failed
// responses just create duplicate retries for events we already handled.
http_response_code(200);
echo json_encode(['received' => true]);
