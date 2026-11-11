<?php
/**
 * Product Page + Shared Product API
 * ---------------------------------------------------------------------
 * GET  /product.php?id=5   -> HTML product detail page for browsers
 *                             (the web storefront links here from
 *                             index.php / wishlist.php)
 * GET  /product.php?id=5   -> JSON for the mobile app / any client that
 *                             sends Accept: application/json, a Bearer
 *                             token, or ?format=json (same response
 *                             shape as before, nothing changed there)
 * GET  /product.php        -> JSON product list (unchanged)
 * POST /product.php        -> JSON create product, admin session (unchanged)
 * POST /product.php?id=5   -> action=submit_review -> saves a review,
 *                             redirects back to the HTML page
 *
 * Which response a GET ?id= request gets is decided purely by how the
 * request identifies itself (Accept header / bearer token / ?format=json)
 * — the URL mobile app developers already use keeps returning JSON with
 * zero changes needed on their end.
 * ---------------------------------------------------------------------
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/product_service.php';
require_once __DIR__ . '/includes/review_service.php';
require_once __DIR__ . '/includes/wishlist_service.php';
require_once __DIR__ . '/includes/cart.php'; // CART_SIZES for the size picker

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(204); // preflight — no body
    exit;
}

/* ---------------------------------------------------------------------------
 * Resolve a product's `image` column to an absolute, ready-to-use URL.
 *   - already a full http(s) URL  -> returned as-is
 *   - a stored filename           -> https://<this-host>/uploads/<file>
 *   - empty / null                -> null
 * Adds `image_url` and also overwrites `image` with the resolved value so
 * every client gets a usable link with no extra logic.
 * ------------------------------------------------------------------------- */
function api_with_image_url(array $product): array
{
    $raw = $product['image'] ?? null;

    if ($raw === null || $raw === '') {
        $url = null;
    } elseif (preg_match('#^https?://#i', $raw)) {
        $url = $raw;
    } else {
        // IMPORTANT: do not derive the host from $_SERVER['HTTP_HOST'] — on this
        // hosting setup it does not reliably match the real public domain, which
        // was producing broken image URLs (wrong domain -> "cannot be decoded"
        // errors in FlutterFlow). Use the known-good public domain instead.
        // Replace the value below with YOUR exact working domain if it differs.
        $publicBase = 'https://snow-jellyfish-553645.hostingersite.com';
        $base       = defined('BASE_PATH') ? BASE_PATH : '';
        // Served through serve_image.php instead of a direct /uploads/ link so a
        // guaranteed Access-Control-Allow-Origin header is sent via PHP's
        // header() call — this works even when the host ignores .htaccess
        // Header directives (which is what was happening here).
        $url        = $publicBase . $base . '/serve_image.php?file=' . rawurlencode($raw);
    }

    $product['image_url'] = $url;
    $product['image']     = $url; // keep both fields in sync for older clients
    return $product;
}

$method = $_SERVER['REQUEST_METHOD'];
$id     = $_GET['id'] ?? null;

/* ---------------------------------------------------------------------------
 * Decide who's asking: a browser wanting the product page, or an API
 * caller (mobile app, admin panel, curl, etc.) wanting JSON.
 * ------------------------------------------------------------------------- */
$accept      = $_SERVER['HTTP_ACCEPT'] ?? '';
$forcesJson  = (isset($_GET['format']) && $_GET['format'] === 'json') || auth_bearer_token_value() !== null;
$isReviewPost = $method === 'POST' && ($_POST['action'] ?? '') === 'submit_review';
$wantsPage   = $id !== null && !$forcesJson && ($isReviewPost || ($method === 'GET' && stripos($accept, 'text/html') !== false));

if (!$wantsPage) {
    /* -----------------------------------------------------------------
     * JSON API branch — mobile app / programmatic clients. Unchanged
     * from before.
     * ----------------------------------------------------------------- */
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json');

    if ($method === 'GET') {
        if ($id !== null) {
            $product = product_find((int) $id);
            if (!$product) {
                http_response_code(404);
                echo json_encode(['error' => 'Product not found']);
                exit;
            }
            echo json_encode(api_with_image_url($product));
            exit;
        }

        $q           = trim($_GET['q'] ?? '');
        $category    = trim($_GET['category'] ?? '');
        $subcategory = trim($_GET['subcategory'] ?? '');

        $products = $q !== '' ? product_search($q) : product_list($category ?: null, $subcategory ?: null);
        $products = array_map('api_with_image_url', $products);
        echo json_encode(['count' => count($products), 'products' => $products]);
        exit;
    }

    if ($method === 'POST') {
        if (!auth_is_admin()) {
            http_response_code(401);
            echo json_encode(['error' => 'Admin authentication required']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $required = ['name', 'category', 'price', 'stock'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || $input[$field] === '') {
                http_response_code(422);
                echo json_encode(['error' => "Missing required field: $field"]);
                exit;
            }
        }

        $id = product_create([
            'name'        => $input['name'],
            'category'    => $input['category'],
            'subcategory' => $input['subcategory'] ?? null,
            'description' => $input['description'] ?? '',
            'price'       => (float) $input['price'],
            'sale_price'  => isset($input['sale_price']) && $input['sale_price'] !== '' ? (float) $input['sale_price'] : null,
            'stock'       => (int) $input['stock'],
            'badge'       => $input['badge'] ?? null,
            'image'       => $input['image'] ?? null,
        ]);

        http_response_code(201);
        echo json_encode(['id' => $id, 'message' => 'Product created']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

/* ---------------------------------------------------------------------------
 * HTML product detail page — the web storefront.
 * ------------------------------------------------------------------------- */
$productId = (int) $id;

// A logged-in customer submitting the review form. Redirect back
// (PRG pattern) so refreshing the page never resubmits the review.
if ($isReviewPost) {
    if (auth_is_logged_in() && !auth_is_admin()) {
        $uid = (int) $_SESSION['user_id'];
        if (review_user_purchased($productId, $uid) && !review_exists($productId, $uid)) {
            $rating  = max(1, min(5, (int) ($_POST['rating'] ?? 5)));
            $comment = trim($_POST['comment'] ?? '');
            if ($comment !== '') {
                review_create($productId, $uid, $rating, $comment);
            }
        }
    }
    header('Location: ' . BASE_PATH . '/product.php?id=' . $productId);
    exit;
}

$product = product_find($productId);

if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product Not Found — SoleCraftPH';
    require __DIR__ . '/includes/header.php';
    ?>
    <section class="section">
      <div class="wrap">
        <div class="empty-state">
          This product doesn't exist or is no longer available.
          <div style="margin-top:20px;"><a href="<?= BASE_PATH ?>/index.php" class="btn btn--solid">Back to Shop</a></div>
        </div>
      </div>
    </section>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$img           = product_image_url($product);
$price         = product_display_price($product);
$reviews       = review_list_for_product($productId);
$reviewSummary = review_summary($productId);
$userId        = auth_is_logged_in() ? (int) $_SESSION['user_id'] : null;
$isCustomer    = auth_is_logged_in() && !auth_is_admin();
$canReview     = $isCustomer && review_user_purchased($productId, $userId) && !review_exists($productId, $userId);
$isWishlisted  = $isCustomer && in_array($productId, wishlist_product_ids($userId), true);

$related = array_values(array_filter(
    product_list($product['category'], null, 8),
    static function (array $p) use ($productId) { return (int) $p['id'] !== $productId; }
));
$related = array_slice($related, 0, 3);

$pageTitle = $product['name'] . ' — SoleCraftPH';
require __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap product-layout" style="display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:start;">
    <?php $gallery = product_gallery($product); ?>
    <?php if ($gallery): ?>
    <div class="gallery">
      <div class="card__frame gallery__main">
        <button type="button" class="gallery__open" aria-label="Zoom photo">
          <img id="galleryMain" src="<?= htmlspecialchars($gallery[0]) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
        </button>
        <?php if (count($gallery) > 1): ?>
          <button type="button" class="gallery__nav gallery__nav--prev" data-step="-1" aria-label="Previous photo">&lsaquo;</button>
          <button type="button" class="gallery__nav gallery__nav--next" data-step="1" aria-label="Next photo">&rsaquo;</button>
        <?php endif; ?>
      </div>
      <?php if (count($gallery) > 1): ?>
      <div class="gallery__thumbs">
        <?php foreach ($gallery as $i => $g): ?>
          <button type="button" class="gallery__thumb<?= $i === 0 ? ' is-active' : '' ?>" data-index="<?= $i ?>" aria-label="Photo <?= $i + 1 ?>">
            <img src="<?= htmlspecialchars($g) ?>" alt="" loading="lazy">
          </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="lightbox" id="lightbox" hidden role="dialog" aria-modal="true" aria-label="<?= htmlspecialchars($product['name']) ?> photos">
      <button type="button" class="lightbox__close" aria-label="Close">&times;</button>
      <button type="button" class="lightbox__nav lightbox__nav--prev" data-step="-1" aria-label="Previous photo">&lsaquo;</button>
      <div class="lightbox__stage"><img id="lightboxImg" src="" alt="<?= htmlspecialchars($product['name']) ?>"></div>
      <button type="button" class="lightbox__nav lightbox__nav--next" data-step="1" aria-label="Next photo">&rsaquo;</button>
      <p class="lightbox__hint mono">Click the photo to zoom · Esc to close</p>
    </div>

    <script>
    (function () {
      var photos = <?= json_encode($gallery, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
      var at = 0;
      var main = document.getElementById('galleryMain');
      var box = document.getElementById('lightbox');
      var big = document.getElementById('lightboxImg');
      var thumbs = document.querySelectorAll('.gallery__thumb');

      function show(i) {
        at = (i + photos.length) % photos.length;
        main.src = photos[at];
        big.src = photos[at];
        big.classList.remove('is-zoomed');
        thumbs.forEach(function (t, n) { t.classList.toggle('is-active', n === at); });
      }
      function open() { box.hidden = false; big.src = photos[at]; document.body.style.overflow = 'hidden'; }
      function close() { box.hidden = true; big.classList.remove('is-zoomed'); document.body.style.overflow = ''; }

      document.querySelectorAll('[data-step]').forEach(function (b) {
        b.addEventListener('click', function (e) { e.stopPropagation(); show(at + Number(b.dataset.step)); });
      });
      thumbs.forEach(function (t) { t.addEventListener('click', function () { show(Number(t.dataset.index)); }); });
      document.querySelector('.gallery__open').addEventListener('click', open);
      box.querySelector('.lightbox__close').addEventListener('click', close);
      box.addEventListener('click', function (e) { if (e.target === box) close(); });

      // Click to zoom where you clicked; move the mouse to look around.
      function aim(e) {
        var r = big.getBoundingClientRect();
        big.style.transformOrigin = ((e.clientX - r.left) / r.width * 100) + '% ' + ((e.clientY - r.top) / r.height * 100) + '%';
      }
      big.addEventListener('click', function (e) { aim(e); big.classList.toggle('is-zoomed'); });
      big.addEventListener('mousemove', function (e) { if (big.classList.contains('is-zoomed')) aim(e); });

      document.addEventListener('keydown', function (e) {
        if (box.hidden) return;
        if (e.key === 'Escape') close();
        if (e.key === 'ArrowLeft') show(at - 1);
        if (e.key === 'ArrowRight') show(at + 1);
      });

      // Swipe on phones, on the page photo and in the lightbox.
      [main.parentNode.parentNode, box].forEach(function (el) {
        var x0 = null;
        el.addEventListener('touchstart', function (e) { x0 = e.touches.length === 1 ? e.touches[0].clientX : null; }, { passive: true });
        el.addEventListener('touchend', function (e) {
          if (x0 === null || big.classList.contains('is-zoomed')) return;
          var dx = e.changedTouches[0].clientX - x0;
          if (Math.abs(dx) > 40) show(at + (dx < 0 ? 1 : -1));
          x0 = null;
        });
      });
    })();
    </script>
    <?php else: ?>
    <div class="card__frame" style="cursor:default;">
      <svg viewBox="0 0 300 160"><use href="#shoe"/></svg>
    </div>
    <?php endif; ?>

    <div>
      <div class="mono" style="color:var(--blaze);">
        <?= htmlspecialchars($product['category']) ?><?= !empty($product['subcategory']) ? ' · ' . htmlspecialchars($product['subcategory']) : '' ?>
      </div>
      <h1 class="display" style="margin:10px 0 6px;font-size:clamp(28px,4vw,44px);"><?= htmlspecialchars($product['name']) ?></h1>

      <?php if ($reviewSummary['count'] > 0): ?>
        <div class="rating-line">
          <span class="stars"><?= str_repeat('★', (int) round($reviewSummary['average'])) . str_repeat('☆', 5 - (int) round($reviewSummary['average'])) ?></span>
          <span class="mono"><?= $reviewSummary['average'] ?> (<?= $reviewSummary['count'] ?> review<?= $reviewSummary['count'] === 1 ? '' : 's' ?>)</span>
        </div>
      <?php endif; ?>

      <div class="card__price" style="font-size:32px;margin:14px 0;">
        <?php if ($product['sale_price']): ?><s style="font-size:18px;color:var(--gray);margin-right:10px;">₱<?= number_format($product['price'], 2) ?></s><?php endif; ?>
        ₱<?= number_format($price, 2) ?>
      </div>

      <?php if ($product['badge']): ?>
        <span class="card__badge <?= $product['sale_price'] ? 'card__badge--sale' : '' ?>" style="position:static;display:inline-block;margin-bottom:16px;"><?= htmlspecialchars($product['badge']) ?></span>
      <?php endif; ?>

      <p style="font-size:15px;line-height:1.7;color:#3d3b36;margin-bottom:24px;"><?= nl2br(htmlspecialchars($product['description'] ?? '')) ?></p>

      <p class="mono" style="margin-bottom:20px;color:<?= $product['stock'] > 0 ? 'var(--gray)' : 'var(--blaze)' ?>;">
        <?php if ($product['stock'] < 1): ?>
          Out of stock
        <?php elseif ($product['stock'] <= $product['low_stock_threshold']): ?>
          Only <?= (int) $product['stock'] ?> left in stock
        <?php else: ?>
          In stock
        <?php endif; ?>
      </p>

      <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
        <form method="post" action="<?= BASE_PATH ?>/cart.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
          <label for="sizeSelect" class="mono" style="font-size:13px;">Size (US)</label>
          <select id="sizeSelect" class="qty-input" name="size" required style="width:auto;min-width:96px;" <?= $product['stock'] < 1 ? 'disabled' : '' ?>>
            <option value="">Choose</option>
            <?php
              // A size the shopper cannot buy is shown, not hidden — so they
              // know the shoe comes in it — but cannot be picked. Same list
              // the app gets as available_sizes.
              $sellable = product_available_sizes((int) $product['id']);
            ?>
            <?php foreach (CART_SIZES as $sizeOption): ?>
              <?php $soldOut = !in_array((string) $sizeOption, $sellable, true); ?>
              <option value="<?= htmlspecialchars($sizeOption) ?>" <?= $soldOut ? 'disabled' : '' ?>>
                US <?= htmlspecialchars($sizeOption) ?><?= $soldOut ? ' — sold out' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <input class="qty-input" type="number" name="qty" value="1" min="1" <?= $product['stock'] < 1 ? 'disabled' : '' ?>>
          <button type="submit" class="btn btn--solid" <?= $product['stock'] < 1 ? 'disabled' : '' ?>>
            <?= $product['stock'] < 1 ? 'Unavailable' : 'Add to Cart' ?>
          </button>
        </form>

        <?php if ($isCustomer): ?>
          <form method="post" action="<?= BASE_PATH ?>/index.php">
            <input type="hidden" name="action" value="toggle_wishlist">
            <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
            <button type="submit" class="btn" title="Toggle wishlist"><?= $isWishlisted ? '♥ Wishlisted' : '♡ Add to Wishlist' ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0;">
  <div class="wrap" style="max-width:720px;">
    <div class="section-head">
      <div>
        <span class="mono">Reviews</span>
        <h2 class="display" style="font-size:clamp(26px,3.4vw,38px);">What Buyers Say</h2>
      </div>
    </div>

    <?php if ($canReview): ?>
      <form method="post" action="<?= BASE_PATH ?>/product.php?id=<?= $product['id'] ?>" class="panel" style="margin-bottom:30px;">
        <input type="hidden" name="action" value="submit_review">
        <div class="form-field">
          <label>Your Rating</label>
          <div class="star-select">
            <?php for ($i = 5; $i >= 1; $i--): ?>
              <input type="radio" name="rating" id="star<?= $i ?>" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?>>
              <label for="star<?= $i ?>">★</label>
            <?php endfor; ?>
          </div>
        </div>
        <div class="form-field">
          <label>Your Review</label>
          <textarea name="comment" required placeholder="How did these hold up?"></textarea>
        </div>
        <button type="submit" class="btn btn--solid">Submit Review</button>
      </form>
    <?php elseif ($isCustomer && review_exists($productId, $userId)): ?>
      <div class="alert" style="margin-bottom:30px;">You've already reviewed this product — thanks!</div>
    <?php endif; ?>

    <?php if (empty($reviews)): ?>
      <div class="empty-state">No reviews yet<?= $canReview ? '' : ' — be the first to buy and review this product.' ?></div>
    <?php else: ?>
      <?php foreach ($reviews as $r): ?>
        <div class="review-card">
          <div class="review-card__head">
            <span class="review-card__user"><?= htmlspecialchars($r['username']) ?></span>
            <time><?= date('M j, Y', strtotime($r['created_at'])) ?></time>
          </div>
          <span class="stars stars--sm"><?= str_repeat('★', (int) $r['rating']) . str_repeat('☆', 5 - (int) $r['rating']) ?></span>
          <p><?= nl2br(htmlspecialchars($r['comment'])) ?></p>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<?php if (!empty($related)): ?>
<section class="section" style="padding-top:0;">
  <div class="wrap">
    <div class="section-head">
      <div>
        <span class="mono">You May Also Like</span>
        <h2 class="display" style="font-size:clamp(26px,3.4vw,38px);">More <?= htmlspecialchars($product['category']) ?></h2>
      </div>
    </div>
    <div class="products">
      <?php foreach ($related as $rp): $rImg = product_image_url($rp); $rPrice = product_display_price($rp); ?>
        <article class="card">
          <a class="card__frame" href="<?= BASE_PATH ?>/product.php?id=<?= $rp['id'] ?>" style="--accent:#111111;color:#111111;">
            <?php if ($rImg): ?>
              <img src="<?= htmlspecialchars($rImg) ?>" alt="<?= htmlspecialchars($rp['name']) ?>" class="card__photo" loading="lazy">
            <?php else: ?>
              <svg viewBox="0 0 300 160"><use href="#shoe"/></svg>
            <?php endif; ?>
          </a>
          <div class="card__body">
            <div class="card__cat mono"><?= htmlspecialchars($rp['category']) ?></div>
            <div class="card__name"><a href="<?= BASE_PATH ?>/product.php?id=<?= $rp['id'] ?>"><?= htmlspecialchars($rp['name']) ?></a></div>
            <div class="card__row"><span class="card__price">₱<?= number_format($rPrice, 2) ?></span></div>
          </div>
        </article>
      <?php endforeach; ?>
      <?php $fillers = (3 - (count($related) % 3)) % 3; for ($i = 0; $i < $fillers; $i++): ?>
        <div class="card" aria-hidden="true"></div>
      <?php endfor; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
