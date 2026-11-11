<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/cart.php';
require_once __DIR__ . '/includes/order_service.php';
require_once __DIR__ . '/includes/settings_service.php';
require_once __DIR__ . '/includes/notification_service.php';
// Orders belong to an account. A guest's cart survives the trip: login merges
// it into the account cart, then sends them back here.
auth_require_login();

// Only the lines ticked on cart.php; the rest stay in the cart.
$items = cart_checkout_items();
$error = '';

// Prefill from the logged-in account so a returning customer doesn't
// retype what's already on their profile. $_POST wins on a failed
// resubmit so we don't clobber what they just typed.
$currentUser = auth_is_logged_in() ? auth_current_user() : null;

// Saved addresses, and whichever one this checkout is using. A customer who
// has saved "Work" should not have to retype it, and picking it must not cost
// them the pin their phone attached to it.
require_once __DIR__ . '/includes/address_service.php';
$savedAddresses = $currentUser ? address_list((int) $currentUser['id']) : [];
$chosenId = (int) ($_POST['address_id'] ?? $_GET['address'] ?? 0);
$chosen = null;
foreach ($savedAddresses as $sa) {
    if ($chosenId > 0 && (int) $sa['id'] === $chosenId) { $chosen = $sa; break; }
}
// No explicit pick means the default, which address_list() sorts first.
if ($chosen === null && $chosenId === 0 && $savedAddresses) {
    $chosen = $savedAddresses[0];
}

$prefill = [
    'name'    => $_POST['name']    ?? (!empty($chosen['recipient_name']) ? $chosen['recipient_name'] : ($currentUser['full_name'] ?? '')),
    'email'   => $_POST['email']   ?? ($currentUser['email'] ?? ''),
    'phone'   => $_POST['phone']   ?? (!empty($chosen['phone']) ? $chosen['phone'] : ($currentUser['phone'] ?? '')),
    'address' => $_POST['address'] ?? ($chosen['address'] ?? ($currentUser['address'] ?? '')),
];

// Voucher: Apply / Remove post here on their own and keep the code in the
// session; the summary re-checks it on every load, and order_create checks it
// once more when the order is placed.
$voucherMessage = '';
if (($_POST['voucher_action'] ?? '') === 'apply') {
    $q = voucher_quote((string) ($_POST['voucher_code'] ?? ''), cart_total($items));
    $voucherMessage = $q['message'];
    if ($q['ok']) {
        $_SESSION['voucher_code'] = $q['code'];
    }
} elseif (($_POST['voucher_action'] ?? '') === 'remove') {
    unset($_SESSION['voucher_code']);
}
$voucher = null;
if (!empty($_SESSION['voucher_code']) && $items) {
    $voucher = voucher_quote($_SESSION['voucher_code'], cart_total($items));
    if (!$voucher['ok']) {
        $voucherMessage = $voucher['message'];
        $voucher = null;
        unset($_SESSION['voucher_code']);
    }
}
$discount = $voucher ? $voucher['discount'] : 0.0;

// A pin dropped on this page wins over the saved address's pin; both halves
// or neither, and only real coordinates.
$pinLat = trim((string) ($_POST['latitude'] ?? ''));
$pinLng = trim((string) ($_POST['longitude'] ?? ''));
$pinOk  = is_numeric($pinLat) && is_numeric($pinLng)
    && abs((float) $pinLat) <= 90 && abs((float) $pinLng) <= 180;
if (!$pinOk) {
    $pinLat = (string) ($chosen['latitude'] ?? '');
    $pinLng = (string) ($chosen['longitude'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['voucher_action'])) {
    if (empty($items)) {
        $error = 'Your cart is empty.';
    } else {
        $name    = trim($_POST['name'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $payment = $_POST['payment_method'] ?? 'COD';
        $allowedPayments = array_keys(settings_enabled_payment_methods());
        if (!in_array($payment, $allowedPayments, true)) {
            $payment = $allowedPayments[0] ?? 'COD';
        }

        if ($name === '' || $email === '' || $phone === '' || $address === '') {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (($phone = ph_mobile_normalize($phone)) === null) {
            $error = 'Enter a valid mobile number (e.g. 09171234567).';
        } else {
            try {
                // The pin, when this order is going to a saved address that
                // has one. order_create stores both halves or neither.
                $orderId = order_create([
                    'user_id'        => $_SESSION['user_id'] ?? null,
                    'name'           => $name,
                    'email'          => $email,
                    'phone'          => $phone,
                    'address'        => $address,
                    'latitude'       => $pinLat,
                    'longitude'      => $pinLng,
                    'payment_method' => $payment,
                    'voucher_code'   => $voucher['code'] ?? '',
                ], $items);
                unset($_SESSION['voucher_code']);

                order_update_status($orderId, 'pending', 'Order placed.');
                notification_notify_admins('New Order', 'Order #' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT) . ' placed by ' . $name . '.');
                if (!empty($_SESSION['user_id'])) {
                    notification_notify_customer((int) $_SESSION['user_id'], 'Order Placed', 'Your order #' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT) . ' has been received.');
                }

                if ($payment === 'GCASH' || $payment === 'CARD') {
                    // Online methods: don't clear the cart or call the order
                    // "placed" for the customer yet — the order stays
                    // payment_status='unpaid' until PayMongo's webhook
                    // confirms it. If PayMongo itself can't be reached,
                    // cancel the order rather than leave a live cart order
                    // with no way to ever get paid.
                    require_once __DIR__ . '/includes/paymongo_service.php';
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $origin = $scheme . '://' . $_SERVER['HTTP_HOST'];
                    try {
                        $session = paymongo_create_checkout_session(
                            $orderId,
                            $items,
                            settings_shipping_for(cart_total($items)),
                            [strtolower($payment)],
                            $origin . BASE_PATH . '/order_success.php?id=' . $orderId,
                            $origin . BASE_PATH . '/checkout.php',
                            $discount
                        );
                        order_set_checkout_session($orderId, $session['session_id']);
                        cart_remove_lines($items);
                        header('Location: ' . $session['checkout_url']);
                        exit;
                    } catch (Throwable $paymentError) {
                        order_update_status($orderId, 'cancelled', 'Could not start PayMongo checkout: ' . $paymentError->getMessage());
                        $error = 'We couldn\'t start the payment step. Please try again, or choose Cash on Delivery.';
                    }
                } else {
                    cart_remove_lines($items);
                    header('Location: ' . BASE_PATH . '/order_success.php?id=' . $orderId);
                    exit;
                }
            } catch (Throwable $e) {
                $error = 'Could not place your order: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Checkout — SoleCraftPH';
require __DIR__ . '/includes/header.php';
?>

<header class="pagehead">
  <div class="wrap">
    <h1 class="display">Checkout</h1>
    <p>Fill in your delivery details to complete your order.</p>
  </div>
</header>

<section class="section">
  <div class="wrap checkout-layout" style="display:grid;grid-template-columns:1.4fr 1fr;gap:40px;align-items:start;">

    <div class="panel">
      <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if (empty($items)): ?>
        <p>Your cart is empty. <a href="<?= BASE_PATH ?>/index.php" class="btn" style="margin-left:10px;">Shop Now</a></p>
      <?php else: ?>
        <?php if ($savedAddresses): ?>
          <div style="margin-bottom:20px;">
            <label class="mono" style="display:block;margin-bottom:8px;">Deliver to</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <?php foreach ($savedAddresses as $sa): ?>
                <?php $isOn = $chosen && (int) $chosen['id'] === (int) $sa['id']; ?>
                <a href="<?= BASE_PATH ?>/checkout.php?address=<?= (int) $sa['id'] ?>"
                   class="btn btn--sm<?= $isOn ? ' btn--solid' : '' ?>">
                  <?= htmlspecialchars($sa['label']) ?>
                </a>
              <?php endforeach; ?>
              <a href="<?= BASE_PATH ?>/addresses.php" class="btn btn--sm">Manage&hellip;</a>
            </div>
          </div>
        <?php endif; ?>

        <form method="post" action="<?= BASE_PATH ?>/checkout.php">
          <input type="hidden" name="address_id" value="<?= (int) ($chosen['id'] ?? 0) ?>">
          <div class="form-grid">
            <div class="form-field"><label>Full Name *</label><input type="text" name="name" required value="<?= htmlspecialchars($prefill['name']) ?>"></div>
            <div class="form-field"><label>Email *</label><input type="email" name="email" required value="<?= htmlspecialchars($prefill['email']) ?>"></div>
            <div class="form-field"><label>Phone *</label><input type="tel" name="phone" required inputmode="tel" pattern="(\+639|09)[0-9 \-]{9,13}" title="09171234567 or +639171234567" value="<?= htmlspecialchars($prefill['phone']) ?>"></div>
            <div class="form-field"><label>Payment Method</label>
              <select name="payment_method">
                <?php foreach (settings_enabled_payment_methods() as $code => $label): ?>
                  <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-field full"><label>Delivery Address *</label><textarea name="address" id="coAddress" required><?= htmlspecialchars($prefill['address']) ?></textarea></div>
            <div class="form-field full">
              <button type="button" class="btn btn--sm" id="pinBtn">Pin on map</button>
              <span id="pinNote" class="mono" style="margin-left:10px;font-size:12px;color:var(--gray);"><?= $pinLat !== '' ? 'Location pinned for this delivery.' : '' ?></span>
              <input type="hidden" name="latitude" id="pinLat" value="<?= htmlspecialchars($pinLat) ?>">
              <input type="hidden" name="longitude" id="pinLng" value="<?= htmlspecialchars($pinLng) ?>">
              <div id="pinPanel" hidden style="margin-top:12px;border:1.5px solid var(--ink);">
                <div style="position:relative;height:320px;">
                  <div id="pinMap" style="position:absolute;inset:0;"></div>
                  <!-- The pin stays in the middle; the map moves under it, as in the app. -->
                  <svg width="34" height="44" viewBox="0 0 34 44" aria-hidden="true" style="position:absolute;left:50%;top:50%;transform:translate(-50%,-100%);z-index:500;pointer-events:none;">
                    <path d="M17 0C7.6 0 0 7.6 0 17c0 12.7 17 27 17 27s17-14.3 17-27C34 7.6 26.4 0 17 0z" fill="#C8341F"/>
                    <circle cx="17" cy="17" r="6.5" fill="#fff"/>
                  </svg>
                </div>
                <div style="display:flex;gap:8px;padding:10px;flex-wrap:wrap;align-items:center;">
                  <button type="button" class="btn btn--sm" id="pinMe">Use my location</button>
                  <span id="pinText" style="flex:1;min-width:180px;font-size:13px;"></span>
                  <button type="button" class="btn btn--sm btn--solid" id="pinUse">Use this location</button>
                </div>
              </div>
            </div>
          </div>
          <button type="submit" class="btn btn--solid" style="width:100%;justify-content:center;">Place Order</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="cart-summary__box">
      <h3 class="display" style="font-size:24px;margin-bottom:16px;">Order Summary</h3>
      <?php foreach ($items as $item): ?>
        <div class="cart-summary__row" style="align-items:center;"><span style="display:flex;align-items:center;gap:10px;"><?php $src = product_image_url($item); ?><?php if ($src): ?><img src="<?= htmlspecialchars($src) ?>" alt="" width="44" height="44" loading="lazy" style="object-fit:contain;background:#F4F3F1;border:1px solid #E3E0D9;flex:none;"><?php endif; ?><span><?= htmlspecialchars($item['name']) ?> &times; <?= (int) $item['qty'] ?></span></span><span>₱<?= number_format($item['price'] * $item['qty'], 2) ?></span></div>
      <?php endforeach; ?>
      <div class="cart-summary__row"><span>Shipping</span><span><?= settings_shipping_for(cart_total($items)) == 0 ? 'Free' : '₱' . number_format(settings_shipping_for(cart_total($items)), 2) ?></span></div>
      <?php if ($voucher): ?>
        <div class="cart-summary__row"><span>Voucher <?= htmlspecialchars($voucher['code']) ?></span><span>−₱<?= number_format($discount, 2) ?></span></div>
      <?php endif; ?>
      <div class="cart-summary__row total"><span>Total</span><span>₱<?= number_format(max(0, cart_total($items) + ($items ? settings_shipping_for(cart_total($items)) : 0) - $discount), 2) ?></span></div>

      <?php if ($items): ?>
      <form method="post" action="<?= BASE_PATH ?>/checkout.php<?= $chosen ? '?address=' . (int) $chosen['id'] : '' ?>" style="margin-top:18px;">
        <?php if ($voucher): ?>
          <input type="hidden" name="voucher_action" value="remove">
          <button type="submit" class="btn btn--sm">Remove voucher</button>
        <?php else: ?>
          <input type="hidden" name="voucher_action" value="apply">
          <label class="mono" for="voucher_code" style="display:block;margin-bottom:6px;">Voucher code</label>
          <div style="display:flex;gap:8px;">
            <input type="text" id="voucher_code" name="voucher_code" placeholder="WELCOME10" autocomplete="off" style="flex:1;text-transform:uppercase;">
            <button type="submit" class="btn btn--sm">Apply</button>
          </div>
        <?php endif; ?>
        <?php if ($voucherMessage && !$voucher): ?><p style="margin-top:8px;font-size:13px;color:var(--blaze);"><?= htmlspecialchars($voucherMessage) ?></p><?php endif; ?>
      </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<script>
/* Pin on map, as in the app: OpenStreetMap tiles, a fixed centre pin, the
   address looked up from the pin, and the coordinates sent with the order. */
(function () {
  var btn = document.getElementById('pinBtn');
  if (!btn) return;
  var panel = document.getElementById('pinPanel'), text = document.getElementById('pinText');
  var latIn = document.getElementById('pinLat'), lngIn = document.getElementById('pinLng');
  var map = null, seq = 0, found = '';
  var start = [parseFloat(latIn.value) || 14.5995, parseFloat(lngIn.value) || 120.9842];

  function load(done) {
    if (window.L) return done();
    var css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    document.head.appendChild(css);
    var js = document.createElement('script');
    js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    js.onload = done;
    js.onerror = function () { text.textContent = 'The map could not load. Type your address instead.'; };
    document.head.appendChild(js);
  }

  function lookup() {
    var c = map.getCenter(), mine = ++seq;
    found = '';
    text.textContent = 'Finding the address…';
    fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=18&lat=' + c.lat + '&lon=' + c.lng,
          { headers: { 'Accept-Language': 'en' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (mine !== seq) return;  // an older lookup finishing late
        found = d && d.display_name ? d.display_name : '';
        text.textContent = found || 'Pinned here (no street address found).';
      })
      .catch(function () { if (mine === seq) text.textContent = 'Pinned here (address lookup failed).'; });
  }

  btn.addEventListener('click', function () {
    panel.hidden = !panel.hidden;
    if (panel.hidden) return;
    load(function () {
      if (map) { map.invalidateSize(); return; }
      map = L.map('pinMap').setView(start, 16);
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
      }).addTo(map);
      map.on('moveend', lookup);
      lookup();
    });
  });

  document.getElementById('pinMe').addEventListener('click', function () {
    if (!map || !navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(
      function (p) { map.setView([p.coords.latitude, p.coords.longitude], 17); },
      function () { text.textContent = 'Location permission was denied. Move the map instead.'; });
  });

  document.getElementById('pinUse').addEventListener('click', function () {
    if (!map) return;
    var c = map.getCenter();
    latIn.value = c.lat.toFixed(7);
    lngIn.value = c.lng.toFixed(7);
    if (found) document.getElementById('coAddress').value = found;
    document.getElementById('pinNote').textContent = 'Location pinned for this delivery.';
    panel.hidden = true;
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
