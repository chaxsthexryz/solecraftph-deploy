<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/cart.php';
require_once __DIR__ . '/includes/settings_service.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $productId = (int) ($_POST['product_id'] ?? 0);
    $size      = (string) ($_POST['size'] ?? '');

    // JS sends this header so it can add-to-cart without leaving the
    // current page. Anyone posting here without it (no JS, old
    // clients) keeps getting the normal redirect-to-cart-page behavior.
    $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    $ok      = true;
    $message = '';

    if ($action === 'add') {
        $qty     = max(1, (int) ($_POST['qty'] ?? 1));
        $product = product_find($productId);
        if (!$product) {
            $ok = false;
            $message = 'Product not found.';
        } elseif ($product['stock'] < 1) {
            $ok = false;
            $message = 'Sorry, that item is out of stock.';
        } elseif (cart_normalize_size($size) === '') {
            $ok = false;
            $message = 'Please choose a size first.';
        } else {
            cart_add($productId, $qty, $size);
            $message = htmlspecialchars($product['name']) . ' (US ' . htmlspecialchars($size) . ') added to cart.';
        }
    } elseif ($action === 'update') {
        $qty     = (int) ($_POST['qty'] ?? 1);
        $product = product_find($productId);
        cart_update($productId, $qty, $size);
        $message = ($product && $qty > (int) $product['stock'])
            ? 'Only ' . (int) $product['stock'] . ' left in stock.'
            : 'Cart updated.';
    } elseif ($action === 'remove') {
        cart_remove($productId, $size);
        $message = 'Item removed from cart.';
    } elseif ($action === 'checkout') {
        $picked = array_values(array_intersect(
            array_map('strval', (array) ($_POST['sel'] ?? [])),
            array_keys(cart_items())
        ));
        if ($picked) {
            $_SESSION['checkout_keys'] = $picked;
            header('Location: ' . BASE_PATH . '/checkout.php');
            exit;
        }
        $ok = false;
        $message = 'Select at least one item to check out.';
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok'         => $ok,
            'message'    => $message,
            'cart_count' => cart_count(),
            'cart_total' => cart_total(),
        ]);
        exit;
    }
    $_SESSION['cart_flash'] = ['ok' => $ok, 'message' => $message];
    header('Location: ' . BASE_PATH . '/cart.php');
    exit;
}

$items = cart_items();
// A fresh look at the cart starts a fresh pick; Proceed sets it again.
unset($_SESSION['checkout_keys']);
$flash = $_SESSION['cart_flash'] ?? null;
unset($_SESSION['cart_flash']);
$pageTitle = 'Your Cart — SoleCraftPH';
require __DIR__ . '/includes/header.php';
?>
<header class="pagehead">
  <div class="wrap">
    <h1 class="display">Your Cart</h1>
    <p><?= cart_count() ?> item(s) in your bag.</p>
    <?php if (!auth_is_logged_in()): ?>
      <p style="opacity:.75;font-size:14px;">
        <a href="<?= BASE_PATH ?>/login.php">Log in</a> to keep this bag on your account and see it in the app.
      </p>
    <?php endif; ?>
  </div>
</header>

<section class="section">
  <div class="wrap">
    <?php if ($flash && $flash['message'] !== ''): ?>
      <div class="alert <?= $flash['ok'] ? 'alert--success' : 'alert--error' ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>
    <?php if (empty($items)): ?>
      <div class="empty-state">
        Your cart is empty.
        <div style="margin-top:20px;"><a href="<?= BASE_PATH ?>/index.php" class="btn btn--solid">Continue Shopping</a></div>
      </div>
    <?php else: ?>
      <div class="table-scroll">
      <table class="cart-table">
        <thead>
          <tr>
            <th><input type="checkbox" id="selAll" checked aria-label="Select all items"></th>
            <th>Product</th>
            <th>Size</th>
            <th>Price</th>
            <th>Quantity</th>
            <th>Subtotal</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td>
                <input type="checkbox" class="cart-sel" name="sel[]" form="checkoutForm" checked
                       value="<?= htmlspecialchars(cart_line_key((int) $item['product_id'], $item['size'])) ?>"
                       data-sub="<?= htmlspecialchars((string) ($item['price'] * $item['qty'])) ?>"
                       aria-label="Check out <?= htmlspecialchars($item['name']) ?>">
              </td>
              <td><div style="display:flex;align-items:center;gap:12px;"><?php $src = product_image_url($item); ?><?php if ($src): ?><img src="<?= htmlspecialchars($src) ?>" alt="" width="56" height="56" loading="lazy" style="object-fit:contain;background:#F4F3F1;border:1px solid #E3E0D9;flex:none;"><?php endif; ?><span><?= htmlspecialchars($item['name']) ?></span></div></td>
              <td><?= $item['size'] === '' ? '—' : 'US ' . htmlspecialchars($item['size']) ?></td>
              <td>₱<?= number_format($item['price'], 2) ?></td>
              <td>
                <form method="post" action="<?= BASE_PATH ?>/cart.php" style="display:flex;gap:8px;align-items:center;">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="product_id" value="<?= (int) $item['product_id'] ?>">
                  <input type="hidden" name="size" value="<?= htmlspecialchars($item['size']) ?>">
                  <input class="qty-input" type="number" name="qty" value="<?= (int) $item['qty'] ?>" min="1" max="<?= max(1, (int) $item['stock']) ?>">
                  <button type="submit" class="btn btn--sm">Update</button>
                </form>
              </td>
              <td>₱<?= number_format($item['price'] * $item['qty'], 2) ?></td>
              <td>
                <form method="post" action="<?= BASE_PATH ?>/cart.php">
                  <input type="hidden" name="action" value="remove">
                  <input type="hidden" name="product_id" value="<?= (int) $item['product_id'] ?>">
                  <input type="hidden" name="size" value="<?= htmlspecialchars($item['size']) ?>">
                  <button type="submit" class="link-remove" style="background:none;border:none;cursor:pointer;">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <div class="cart-summary">
        <div class="cart-summary__box" id="cartSummary"
             data-free-at="<?= htmlspecialchars((string) settings_free_shipping_threshold()) ?>"
             data-fee="<?= htmlspecialchars((string) settings_shipping_fee()) ?>">
          <div class="cart-summary__row"><span>Subtotal</span><span id="sumSub">₱<?= number_format(cart_total(), 2) ?></span></div>
          <div class="cart-summary__row"><span>Shipping</span><span id="sumShip"><?= settings_shipping_for(cart_total()) == 0 ? 'Free' : '₱' . number_format(settings_shipping_for(cart_total()), 2) ?></span></div>
          <div class="cart-summary__row total"><span>Total</span><span id="sumTotal">₱<?= number_format(cart_total() + settings_shipping_for(cart_total()), 2) ?></span></div>
          <form method="post" action="<?= BASE_PATH ?>/cart.php" id="checkoutForm">
            <input type="hidden" name="action" value="checkout">
            <button type="submit" id="checkoutBtn" class="btn btn--solid" style="width:100%;justify-content:center;margin-top:18px;">Proceed to Checkout</button>
          </form>
        </div>
      </div>
      <script>
      /* Totals follow the ticked rows; the server re-checks the pick on Proceed. */
      (function () {
        var boxes = Array.prototype.slice.call(document.querySelectorAll('.cart-sel'));
        var all = document.getElementById('selAll');
        var box = document.getElementById('cartSummary');
        var freeAt = parseFloat(box.dataset.freeAt), fee = parseFloat(box.dataset.fee);
        function peso(n) {
          return '\u20b1' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function refresh() {
          var sub = 0, n = 0;
          boxes.forEach(function (b) { if (b.checked) { sub += parseFloat(b.dataset.sub); n++; } });
          var ship = (sub <= 0 || sub >= freeAt) ? 0 : fee;
          document.getElementById('sumSub').textContent = peso(sub);
          document.getElementById('sumShip').textContent = ship === 0 ? 'Free' : peso(ship);
          document.getElementById('sumTotal').textContent = peso(sub + ship);
          document.getElementById('checkoutBtn').disabled = n === 0;
          all.checked = n === boxes.length;
          all.indeterminate = n > 0 && n < boxes.length;
        }
        boxes.forEach(function (b) { b.addEventListener('change', refresh); });
        all.addEventListener('change', function () {
          boxes.forEach(function (b) { b.checked = all.checked; });
          refresh();
        });
        refresh();
      })();
      </script>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
