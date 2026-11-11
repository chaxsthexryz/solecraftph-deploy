<?php
/**
 * Shared Order API
 * GET  /api/orders.php?id=1  -> fetch a single order + items
 * POST /api/orders.php       -> create an order
 * POST /api/orders.php?action=cancel -> customer cancels a pending order ({id})
 * POST /api/orders.php?action=return -> customer requests a return ({id, reason, details})
 * POST /api/orders.php?action=voucher -> checks a voucher code ({code, items})
 *      An order's customer may carry "voucher_code"; order_create applies it.
 *      body: { "customer": {name,email,phone,address,payment_method},
 *               "items": { "<product_id>|<size>": {"product_id":1,"size":"9","qty":1}, ... } }
 *
 * A GCASH or CARD order comes back with a non-empty "checkout_url" — the same
 * PayMongo Hosted Checkout page the website redirects to. The app opens it in
 * the browser; the order stays payment_status='unpaid' until PayMongo's webhook
 * (/webhook/paymongo.php) confirms it, exactly as on the web. COD orders come
 * back with an empty "checkout_url" and nothing more to do.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order_service.php';
require_once __DIR__ . '/../includes/return_service.php';
require_once __DIR__ . '/../includes/cart.php';
require_once __DIR__ . '/../includes/settings_service.php';

/* ---------------------------------------------------------------------------
 * CORS + JSON headers. Lets the mobile app and browser-based clients
 * (FlutterFlow web preview, etc.) call this endpoint cross-origin.
 * ------------------------------------------------------------------------- */
require_once __DIR__ . '/../includes/cors.php';
api_cors_origin();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // preflight — no body
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);

    // An order row carries the customer's name, email, phone and delivery
    // address, and order ids are sequential — so this must never answer on the
    // id alone. The website supplies a session; the app supplies a bearer token.
    $viewerId = $_SESSION['user_id'] ?? auth_user_id_from_bearer_token();
    if ($viewerId === null) {
        http_response_code(401);
        echo json_encode(['error' => 'Valid Authorization Bearer token required.']);
        exit;
    }

    // Only a website admin who passed the emailed code sees any order; a bearer
    // token is password-only, so it never counts as admin.
    $isAdmin = auth_is_admin();
    $order   = order_find($id);

    // 404 rather than 403 for someone else's order: a 403 confirms the order
    // exists, which is half of what we are trying not to leak.
    if (!$order || (!$isAdmin && (int) ($order['user_id'] ?? 0) !== (int) $viewerId)) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
        exit;
    }

    // The tracking history the website shows on order_detail.php. Cheap enough
    // to always include — an order has a handful of status rows, not hundreds.
    $order['status_history'] = order_status_history($id);
    $order['return_status']  = return_status_for_order($id);

    echo json_encode(order_for_app($order));
    exit;
}

/* ---------------------------------------------------------------------------
 * POST /api/orders.php?action=return  body: {"id": 56, "reason": "...", "details": "..."}
 *
 * The customer asking to return a shipped or delivered order. Bearer only and
 * always a 200 with {ok, message, order}, like cancel below.
 * ------------------------------------------------------------------------- */
if ($method === 'POST' && ($_GET['action'] ?? '') === 'return') {
    $viewerId = auth_user_id_from_bearer_token();
    $input    = json_decode(file_get_contents('php://input') ?: '', true);
    $input    = is_array($input) ? $input : [];
    $id       = (int) ($input['id'] ?? 0);

    $error = $viewerId === null
        ? 'Please sign in again.'
        : return_request_by_customer(
            $id,
            (int) $viewerId,
            trim((string) ($input['reason'] ?? '')),
            trim((string) ($input['details'] ?? ''))
        );

    $order = $error === null ? order_find($id) : null;
    if ($order !== null) {
        $order['status_history'] = order_status_history($id);
        $order['return_status']  = return_status_for_order($id);
    }

    echo json_encode([
        'ok'      => $error === null,
        'message' => $error ?? 'Return requested. We will let you know once it is reviewed.',
        'order'   => $order === null ? null : order_for_app($order),
    ]);
    exit;
}

/* ---------------------------------------------------------------------------
 * POST /api/orders.php?action=cancel  body: {"id": 56}
 *
 * The customer cancelling their own pending order from the app. Bearer token
 * only — no session — so a page on another site cannot fire it through a
 * signed-in visitor's cookie. Answers 200 with {ok, message, order} either
 * way: the app can only read the body of a successful call, and a refusal
 * ("already being prepared") is something to show, not a transport error.
 * ------------------------------------------------------------------------- */
if ($method === 'POST' && ($_GET['action'] ?? '') === 'cancel') {
    $viewerId = auth_user_id_from_bearer_token();
    $input    = json_decode(file_get_contents('php://input') ?: '', true);
    $id       = (int) (is_array($input) ? ($input['id'] ?? 0) : 0);

    $error = $viewerId === null
        ? 'Please sign in again.'
        : order_cancel_by_customer($id, (int) $viewerId);

    $order = $error === null ? order_find($id) : null;
    if ($order !== null) {
        $order['status_history'] = order_status_history($id);
    }

    echo json_encode([
        'ok'      => $error === null,
        'message' => $error ?? 'Order cancelled. The shoes are back in stock.',
        'order'   => $order === null ? null : order_for_app($order),
    ]);
    exit;
}

/* ---------------------------------------------------------------------------
 * POST /api/orders.php?action=voucher  body: {"code": "WELCOME10", "items": {...}}
 *
 * Checkout's Apply button. Prices the bag from the products table, like
 * order_create does, and answers 200 {ok, message, code, discount}. Only a
 * preview: the order re-checks the voucher when it is placed.
 * ------------------------------------------------------------------------- */
if ($method === 'POST' && ($_GET['action'] ?? '') === 'voucher') {
    $input    = json_decode(file_get_contents('php://input') ?: '', true);
    $input    = is_array($input) ? $input : [];
    $subtotal = 0.0;
    foreach ((array) ($input['items'] ?? []) as $key => $item) {
        $product = product_find((int) ($item['product_id'] ?? explode('|', (string) $key)[0]));
        if ($product) {
            $subtotal += product_display_price($product) * max(1, (int) ($item['qty'] ?? 1));
        }
    }
    echo json_encode(voucher_quote((string) ($input['code'] ?? ''), $subtotal));
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['customer']) || empty($input['items'])) {
        http_response_code(422);
        echo json_encode(['error' => 'Missing customer or items']);
        exit;
    }

    $customer = $input['customer'];
    // Website checkout supplies the session; the mobile app supplies a bearer
    // token. Orders belong to an account, so a request with neither is refused.
    $customer['user_id'] = $_SESSION['user_id'] ?? auth_user_id_from_bearer_token();
    if (empty($customer['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Sign in to place an order.']);
        exit;
    }

    foreach (['name', 'email', 'phone', 'address'] as $field) {
        if (empty($customer[$field])) {
            http_response_code(422);
            echo json_encode(['error' => "Missing customer field: $field"]);
            exit;
        }
    }
    $customer['phone'] = ph_mobile_normalize((string) $customer['phone']);
    if ($customer['phone'] === null) {
        http_response_code(422);
        echo json_encode(['error' => 'Enter a valid mobile number (e.g. 09171234567).']);
        exit;
    }

    try {
        $orderId = order_create($customer, $input['items']);
        order_update_status($orderId, 'pending', 'Order placed from the mobile app.');

        // GCASH / CARD go through PayMongo Hosted Checkout — the same service
        // call the website's checkout.php makes, so both clients produce the
        // same kind of session and the one webhook settles either of them.
        $payment     = strtoupper((string) ($customer['payment_method'] ?? 'COD'));
        $checkoutUrl = '';
        // The Launch Edition app pays natively through api/payments.php, so it
        // asks for no hosted session. Older builds and the website omit "inapp"
        // and keep getting checkout_url exactly as before.
        $inApp = !empty($input['inapp']);

        if (($payment === 'GCASH' || $payment === 'CARD') && !$inApp) {
            require_once __DIR__ . '/../includes/paymongo_service.php';

            $order     = order_find($orderId);
            $lineItems = [];
            foreach ($order['items'] as $row) {
                $lineItems[] = [
                    // Size belongs on the PayMongo receipt too — it is the only
                    // thing distinguishing two otherwise identical lines.
                    'name'  => $row['product_name'] . (!empty($row['size']) ? ' (US ' . $row['size'] . ')' : ''),
                    'price' => (float) $row['unit_price'],
                    'qty'   => (int) $row['quantity'],
                ];
            }

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $origin = $scheme . '://' . $_SERVER['HTTP_HOST'];

            try {
                $session = paymongo_create_checkout_session(
                    $orderId,
                    $lineItems,
                    settings_shipping_for((float) $order['total_amount']),
                    [strtolower($payment)],
                    // A phone-sized landing page rather than the full desktop
                    // storefront — this renders inside the app's payment WebView.
                    $origin . BASE_PATH . '/payment_return.php?id=' . $orderId,
                    $origin . BASE_PATH . '/payment_return.php?id=' . $orderId . '&state=cancelled',
                    (float) ($order['discount_amount'] ?? 0)
                );
                order_set_checkout_session($orderId, $session['session_id']);
                $checkoutUrl = $session['checkout_url'];
            } catch (Throwable $paymentError) {
                // Same call the website makes: cancel rather than leave an order
                // that can never be paid, and keep the cart so they can retry.
                order_update_status($orderId, 'cancelled', 'Could not start PayMongo checkout: ' . $paymentError->getMessage());
                http_response_code(502);
                echo json_encode([
                    'error' => 'Could not start the payment step. Please try again, or choose Cash on Delivery.',
                ]);
                exit;
            }
        }

        // The ordered lines leave the shared cart; lines the customer did not
        // pick stay, on the website and in the app alike.
        cart_remove_lines(array_values($input['items']));
        http_response_code(201);
        echo json_encode([
            'id'           => $orderId,
            'message'      => 'Order created',
            'checkout_url' => $checkoutUrl,
            // true = the app should now collect payment with api/payments.php
            'pay_in_app'   => $inApp && ($payment === 'GCASH' || $payment === 'CARD'),
        ]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
