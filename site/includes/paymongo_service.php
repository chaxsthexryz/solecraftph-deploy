<?php
/**
 * PayMongo Hosted Checkout (v2) — one API call gets a checkout_url that
 * handles GCash and Card entirely on PayMongo's own page, so this site
 * never touches card numbers or GCash credentials directly.
 */
require_once __DIR__ . '/../config/paymongo.php';

/**
 * @param int    $orderId       Used as the reference_number so the webhook
 *                               can look the order back up.
 * @param array  $cartItems     ['product_id' => ['name'=>.., 'price'=>.., 'qty'=>..], ...]
 * @param float  $shippingFee
 * @param array  $paymentMethodTypes  e.g. ['gcash'] or ['card']
 * @param string $successUrl
 * @param string $cancelUrl
 * @return array ['checkout_url' => string, 'session_id' => string]
 * @throws RuntimeException on any API or network failure
 */
function paymongo_create_checkout_session(
    int $orderId,
    array $cartItems,
    float $shippingFee,
    array $paymentMethodTypes,
    string $successUrl,
    string $cancelUrl,
    float $discount = 0.0
): array {
    if (PAYMONGO_SECRET_KEY === '') {
        throw new RuntimeException('PayMongo is not configured (PAYMONGO_SECRET_KEY is empty).');
    }

    // PayMongo line items cannot be negative, so a voucher order is billed as
    // one line for the discounted total.
    if ($discount > 0) {
        $due = $shippingFee - $discount;
        foreach ($cartItems as $item) {
            $due += $item['price'] * $item['qty'];
        }
        $cartItems   = [['name' => 'SoleCraftPH order #' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT), 'price' => max(0, $due), 'qty' => 1]];
        $shippingFee = 0.0;
    }

    $lineItems = [];
    foreach ($cartItems as $item) {
        $lineItems[] = [
            'name'     => $item['name'],
            // PayMongo amounts are in centavos (smallest currency unit).
            'amount'   => (int) round($item['price'] * 100),
            'currency' => 'PHP',
            'quantity' => (int) $item['qty'],
        ];
    }
    if ($shippingFee > 0) {
        $lineItems[] = [
            'name'     => 'Shipping',
            'amount'   => (int) round($shippingFee * 100),
            'currency' => 'PHP',
            'quantity' => 1,
        ];
    }

    $payload = [
        'data' => [
            'attributes' => [
                'line_items'           => $lineItems,
                'payment_method_types' => $paymentMethodTypes,
                'reference_number'     => (string) $orderId,
                'success_url'          => $successUrl,
                'cancel_url'           => $cancelUrl,
            ],
        ],
    ];

    $ch = curl_init('https://api.paymongo.com/v2/checkout_sessions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => PAYMONGO_SECRET_KEY . ':',
        CURLOPT_CONNECTTIMEOUT => 8,  // bounds the connect/DNS phase specifically
        CURLOPT_TIMEOUT        => 15, // bounds the whole request
        CURLOPT_NOSIGNAL       => true, // required for the above timeouts to be honored under PHP-FPM
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Could not reach PayMongo: ' . $curlError);
    }

    $decoded = json_decode($response, true);
    if ($httpCode >= 300 || !isset($decoded['data']['attributes']['checkout_url'])) {
        $message = $decoded['errors'][0]['detail'] ?? ('Unexpected PayMongo response (HTTP ' . $httpCode . ').');
        throw new RuntimeException($message);
    }

    return [
        'checkout_url' => $decoded['data']['attributes']['checkout_url'],
        'session_id'   => $decoded['data']['id'],
    ];
}

/* ---------------------------------------------------------------------------
 * Payment Intents — the app's native checkout (api/payments.php).
 *
 * The phone collects card details in its own form and turns them into a
 * payment method with the PUBLIC key, straight to PayMongo; the card number
 * never reaches this server. Here, with the secret key, we only create the
 * intent and attach that method to it. GCash methods are created here too,
 * since they carry no secret data — just the customer's billing details.
 * ------------------------------------------------------------------------- */

/**
 * One PayMongo v1 call with the secret key. Returns [httpCode, decoded body].
 * @throws RuntimeException when PayMongo cannot be reached at all
 */
function paymongo_request(string $method, string $path, ?array $attributes = null): array
{
    if (PAYMONGO_SECRET_KEY === '') {
        throw new RuntimeException('PayMongo is not configured (PAYMONGO_SECRET_KEY is empty).');
    }
    $ch = curl_init('https://api.paymongo.com/v1/' . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => PAYMONGO_SECRET_KEY . ':',
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_NOSIGNAL       => true,
    ];
    if ($attributes !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode(['data' => ['attributes' => $attributes]]);
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Could not reach PayMongo: ' . $curlError);
    }
    return [$httpCode, json_decode($response, true) ?: []];
}

/** PayMongo's own error sentence, or a generic one. */
function paymongo_error_detail(array $body): string
{
    return (string) ($body['errors'][0]['detail'] ?? 'The payment could not be processed.');
}

/**
 * What the customer owes for an order, in pesos: the same figure the website's
 * Hosted Checkout bills (items + shipping − voucher). Needs settings_service.php.
 */
function paymongo_amount_due(array $order): float
{
    $items = (float) $order['total_amount'];
    return max(0.0, $items + settings_shipping_for($items) - (float) ($order['discount_amount'] ?? 0));
}

/**
 * Creates a payment intent for an order and returns its id.
 * @throws RuntimeException on any PayMongo refusal
 */
function paymongo_create_intent(array $order): string
{
    [$code, $body] = paymongo_request('POST', 'payment_intents', [
        // centavos
        'amount'                 => (int) round(paymongo_amount_due($order) * 100),
        'currency'               => 'PHP',
        'payment_method_allowed' => ['card', 'gcash'],
        'capture_type'           => 'automatic',
        'description'            => 'SoleCraftPH order #' . str_pad((string) $order['id'], 6, '0', STR_PAD_LEFT),
        'statement_descriptor'   => 'SoleCraftPH',
        'metadata'               => ['order_id' => (string) $order['id']],
    ]);
    if ($code >= 300 || empty($body['data']['id'])) {
        throw new RuntimeException(paymongo_error_detail($body));
    }
    return $body['data']['id'];
}

/**
 * A GCash payment method carrying the order's billing details.
 * @throws RuntimeException on any PayMongo refusal
 */
function paymongo_create_gcash_method(array $order): string
{
    [$code, $body] = paymongo_request('POST', 'payment_methods', [
        'type'    => 'gcash',
        'billing' => array_filter([
            'name'  => (string) $order['customer_name'],
            'email' => (string) $order['customer_email'],
            'phone' => (string) $order['customer_phone'],
        ]),
    ]);
    if ($code >= 300 || empty($body['data']['id'])) {
        throw new RuntimeException(paymongo_error_detail($body));
    }
    return $body['data']['id'];
}

/**
 * Attaches a payment method to an intent and reports where that left it:
 * ['status' => 'paid'|'action'|'failed', 'redirect_url' => string, 'message' => string]
 * 'action' means the customer must approve on the URL (GCash, or a card bank's
 * 3-D Secure check) before the intent can succeed.
 */
function paymongo_attach(string $intentId, string $methodId, string $returnUrl): array
{
    [$code, $body] = paymongo_request('POST', 'payment_intents/' . rawurlencode($intentId) . '/attach', [
        'payment_method' => $methodId,
        'return_url'     => $returnUrl,
    ]);
    if ($code >= 300) {
        return ['status' => 'failed', 'redirect_url' => '', 'message' => paymongo_error_detail($body)];
    }
    return paymongo_intent_outcome($body['data']['attributes'] ?? []);
}

/** The same ['status','redirect_url','message'] for an intent fetched fresh. */
function paymongo_intent_status(string $intentId): array
{
    [$code, $body] = paymongo_request('GET', 'payment_intents/' . rawurlencode($intentId));
    if ($code >= 300) {
        return ['status' => 'failed', 'redirect_url' => '', 'message' => paymongo_error_detail($body)];
    }
    return paymongo_intent_outcome($body['data']['attributes'] ?? []);
}

function paymongo_intent_outcome(array $a): array
{
    switch ($a['status'] ?? '') {
        case 'succeeded':
            return ['status' => 'paid', 'redirect_url' => '', 'message' => 'Payment received.'];
        case 'awaiting_next_action':
            return ['status' => 'action', 'redirect_url' => (string) ($a['next_action']['redirect']['url'] ?? ''), 'message' => 'Approve the payment to finish.'];
        case 'processing':
            return ['status' => 'action', 'redirect_url' => '', 'message' => 'Your payment is processing.'];
        default:
            // awaiting_payment_method after an attempt = the attempt failed.
            $err = $a['last_payment_error'] ?? null;
            $msg = is_array($err) ? ($err['failed_message'] ?? $err['message'] ?? '') : (string) $err;
            return ['status' => 'failed', 'redirect_url' => '', 'message' => $msg !== '' ? $msg : 'The payment did not go through. Please try again.'];
    }
}

/**
 * Verifies the Paymongo-Signature header on an incoming webhook request.
 * Header shape: t=<timestamp>,te=<test-mode signature>,li=<live-mode signature>
 *
 * @param string $rawPayload  The exact, unparsed request body (php://input) —
 *                              re-encoding the JSON before verifying breaks the check.
 */
function paymongo_verify_webhook_signature(string $rawPayload, string $signatureHeader, bool $testMode = true): bool
{
    if (PAYMONGO_WEBHOOK_SECRET === '' || $signatureHeader === '') {
        return false;
    }

    $parts = [];
    foreach (explode(',', $signatureHeader) as $piece) {
        [$key, $value] = array_pad(explode('=', $piece, 2), 2, null);
        if ($key !== null && $value !== null) {
            $parts[trim($key)] = trim($value);
        }
    }

    $timestamp = $parts['t'] ?? null;
    $expectedSignature = $testMode ? ($parts['te'] ?? null) : ($parts['li'] ?? null);
    if ($timestamp === null || $expectedSignature === null) {
        return false;
    }

    $computed = hash_hmac('sha256', $timestamp . '.' . $rawPayload, PAYMONGO_WEBHOOK_SECRET);
    return hash_equals($expectedSignature, $computed);
}