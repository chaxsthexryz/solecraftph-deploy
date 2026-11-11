<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/product_service.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/wishlist_service.php';
require_once __DIR__ . '/includes/cms_service.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_wishlist' && auth_is_logged_in() && !auth_is_admin()) {
    wishlist_toggle((int) $_SESSION['user_id'], (int) ($_POST['product_id'] ?? 0));
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? (BASE_PATH . '/index.php')));
    exit;
}

$wishlistIds = (auth_is_logged_in() && !auth_is_admin()) ? wishlist_product_ids((int) $_SESSION['user_id']) : [];
// One banners table, two presentations. A row with an image is a full-width
// promo at the top of the page; a row without one is the thin text strip it
// has always been. Split here so an image banner does not appear as both.
$activeBanners = banner_list_active();
$promoBanners  = [];
foreach ($activeBanners as $i => $b) {
    // Null-coalesced because this has to keep rendering on a database where
    // upgrade_v10_banner_images.sql has not run yet.
    if (($b['image_url'] ?? '') !== '') {
        $promoBanners[] = $b;
        unset($activeBanners[$i]);
    }
}
$activeBanners = array_values($activeBanners);

$search      = trim($_GET['q'] ?? '');
$category    = trim($_GET['category'] ?? '');
$subcategory = trim($_GET['subcategory'] ?? '');

if ($search !== '') {
    $products = product_search($search);
} else {
    $products = product_list($category ?: null, $subcategory ?: null);
}
$ratings = product_ratings(array_column($products, 'id'));

// Sort and filter (the SORT / FILTER bar above the grid).
$shopFilter = [
    'sort'    => isset(PRODUCT_SORTS[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'featured',
    'size'    => preg_match('/^(7|8|9|10|11|12)$/', (string) ($_GET['size'] ?? '')) ? $_GET['size'] : '',
    'price'   => isset(PRODUCT_PRICE_BANDS[$_GET['price'] ?? '']) ? $_GET['price'] : '',
    'sale'    => !empty($_GET['sale']),
    'instock' => !empty($_GET['instock']),
];
$products = product_filter_sort($products, $shopFilter, $ratings);
$activeFilters = ($shopFilter['size'] !== '') + ($shopFilter['price'] !== '') + $shopFilter['sale'] + $shopFilter['instock'];
/** This page's URL with some sort/filter values changed; empty values drop out. */
$shopUrl = function (array $change) use ($search, $category, $subcategory, $shopFilter): string {
    $q = array_merge(
        ['q' => $search, 'category' => $category, 'subcategory' => $subcategory],
        $shopFilter,
        $change
    );
    if (($q['sort'] ?? '') === 'featured') {
        unset($q['sort']);
    }
    $q = array_filter($q, fn ($v) => $v !== '' && $v !== false && $v !== null);
    return BASE_PATH . '/index.php' . ($q ? '?' . http_build_query(array_map(fn ($v) => $v === true ? 1 : $v, $q)) : '');
};
$categories = product_categories();
$subcategoryMap = product_subcategory_map($category ?: null);
$subcategoriesForCategory = $category !== '' ? ($subcategoryMap[$category] ?? []) : [];
$pageTitle = 'SoleCraftPH — Footwear, Done Right';
require __DIR__ . '/includes/header.php';

// --- What can appear above the catalog, in priority order:
//  1) active banners carrying an image  -> promo band, managed in admin
//  2) nothing                           -> the type hero alone
//
// The assets/media/hero/ fallback is gone. Artwork dropped straight into a
// folder went on the homepage with no link, no ordering beyond the filename and
// no way to switch it off without a file manager — and a banner that cannot be
// switched off is the banner still advertising last month's sale. Admin →
// Banners is the one way in now. Nothing on the server was deleted: the files
// are still in assets/media/hero/ and can be uploaded there.

// Background footage for the type hero. Every .mp4 and .webm in
// assets/media/hero-video/ is played, in filename order, one after the next and
// then round again — so the order is set by naming the files 1-, 2-, 3-.
// With the folder empty no markup is emitted at all, so this is never an empty
// <video> waiting on a file that does not exist.
$heroVideoFiles = glob(__DIR__ . '/assets/media/hero-video/*.{mp4,webm}', GLOB_BRACE) ?: [];
natsort($heroVideoFiles);
$heroVideos = array_map(static function (string $path): string {
    return BASE_PATH . '/assets/media/hero-video/' . rawurlencode(basename($path));
}, array_values($heroVideoFiles));

// Promo banners get their own band above the page header rather than being
// used as wallpaper behind it.
//
// A promotional image already carries its own headline, discount and call to
// action. The hero treatment below would crop it to a tall box, drop a 72%
// black scrim over it, and then print our own heading, search box and category
// pills on top — burying the thing it is advertising under the site chrome.
// Shown whole instead, at its own aspect ratio, with the header underneath
// going back to plain text on paper.
// Banners uploaded in admin come first: they carry a title for the alt text,
// a link, an order and an on/off switch, and the shop owner can change them
// without touching the server.
$promoSlides = [];
foreach ($promoBanners as $b) {
    $promoSlides[] = [
        'url'  => BASE_PATH . '/' . $b['image_url'],
        'alt'  => $b['title'],
        'link' => $b['link_url'] ?: '',
    ];
}

// Behind the hero copy: footage if the folder has any, otherwise nothing.
//
// This is the third thing to go behind that headline, and it is not the second
// one again. The old slideshow-behind-text pulled whichever catalog photo
// happened to be newest, needed a 72% flat scrim over it to stay legible, and
// still advertised nothing in particular. The product-on-paper zone fixed the
// legibility by spending the best space on the site on a photo the visitor is
// about to scroll past. A video is chosen footage, not a catalog row, and the
// scrim is a left-heavy gradient rather than a blanket — so the words keep
// their contrast and the right-hand side of the frame is still visible.
//
// With no video the hero is exactly the ink field it was: same headline, same
// type sizes, one unused CSS rule.

// Fade timing serves the promo band alone.
$fadeCount   = count($promoSlides);
$heroSegment = 5;   // seconds each photo stays fully visible (incl. its own fade)
$heroFade    = 1.1; // seconds of crossfade
$heroTotal   = max($heroSegment, $fadeCount * $heroSegment);
if ($fadeCount > 1) {
    $heroFadeInPct = round($heroFade / $heroTotal * 100, 3);
    $heroHoldPct   = round(($heroSegment - $heroFade) / $heroTotal * 100, 3);
    $heroOutPct    = round($heroSegment / $heroTotal * 100, 3);
}
?>

<?php /* The shoe-linked banners drive the app's video hero; the website shows the hero alone. */ ?>

<?php if (!empty($promoSlides)): ?>
  <style>
    /* Only the homepage has this band, so it lives here rather than in
       style.css — one fewer file to keep in step. */
    /* The band and the type hero below it are both ink, and an ink border
       between two ink fields is invisible — the two ran together as one slab
       of black with no telling where the advert stopped and the shop started.
       A hairline in white at 14% separates them without spending the red,
       which the hero's own bottom rule already uses. */
    .promo{background:var(--ink);border-bottom:1.5px solid rgba(255,255,255,.14);}
    /* The first image sits in normal flow and sets the band's height; the rest
       are stacked over it. That is what makes this responsive without picking
       an aspect ratio: the band is exactly as tall as the artwork at whatever
       width the screen happens to be, so a promo is never cropped and never
       letterboxed. */
    .promo__stack{position:relative;}
    .promo__slide{display:block;position:relative;}
    .promo__slide img{display:block;width:100%;height:auto;}
    .promo__slide--over{position:absolute;inset:0;opacity:0;}
    .promo__slide--over img{height:100%;object-fit:cover;}
    @media (prefers-reduced-motion:reduce){
      /* Crossfading artwork under someone who asked for less motion is exactly
         the thing that setting is for. They get the first banner, held. */
      .promo__slide{animation:none!important;}
      .promo__slide--over{display:none;}
    }
  </style>
  <?php if (count($promoSlides) > 1): ?>
    <style>
      @keyframes heroFade{
        0%{opacity:0;}
        <?= $heroFadeInPct ?>%{opacity:1;}
        <?= $heroHoldPct ?>%{opacity:1;}
        <?= $heroOutPct ?>%{opacity:0;}
        100%{opacity:0;}
      }
    </style>
  <?php endif; ?>
  <section class="promo">
    <div class="promo__stack">
      <?php foreach ($promoSlides as $i => $slide): ?>
        <?php
          // A linked banner is an <a>, an unlinked one a <div> — same class, so
          // the stacking and the fade do not care which it is. The anchor wraps
          // the image rather than the image being the anchor, because the
          // first slide has to stay in normal flow to give the band its height.
          $tag   = $slide['link'] !== '' ? 'a' : 'div';
          $attrs = $slide['link'] !== '' ? ' href="' . htmlspecialchars($slide['link']) . '"' : '';
          if (count($promoSlides) > 1) {
              $attrs .= ' style="animation:heroFade ' . $heroTotal . 's ease-in-out infinite;'
                     . 'animation-delay:' . ($i * $heroSegment) . 's;"';
          }
        ?>
        <<?= $tag ?> class="promo__slide<?= $i > 0 ? ' promo__slide--over' : '' ?>"<?= $attrs ?>>
          <img src="<?= htmlspecialchars($slide['url']) ?>"
               alt="<?= htmlspecialchars($slide['alt']) ?>"<?= $i > 0 ? ' loading="lazy"' : '' ?>>
        </<?= $tag ?>>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<style>
  /* THE TYPE HERO
     One ink field, the headline carrying it alone. No photograph behind the
     words and no product beside them.

     Inline here rather than in style.css because .pagehead is shared with the
     cart and checkout headers, and only this page gets the treatment. Same
     reasoning as the promo band above.

     These selectors outrank their .pagehead counterparts by source order, not
     by weight: the stylesheet is linked in <head>, this block comes after. */
  .splithero{
    background:var(--ink);
    padding:0;                 /* .pagehead sets 50px 0 40px */
    overflow:hidden;
  }
  .splithero__copy{
    display:flex;
    flex-direction:column;
    justify-content:center;
    /* Height comes from a min, not an aspect-ratio: the chip rows grow when a
       category is open, and a fixed ratio would clip them. */
    min-height:clamp(420px,40vw,640px);
    /* Matches .wrap's 1280 + 40 so the headline starts on the same line as the
       catalog heading below it, while the ink field still runs edge to edge. */
    max-width:1280px;
    margin:0 auto;
    padding:clamp(30px,3.4vw,54px) 40px;
  }
  /* With nothing beside it the headline has the whole measure, so it can carry
     the weight the split hero borrowed from the photograph. */
  .splithero__copy h1{color:#fff;font-size:clamp(38px,6.4vw,88px);max-width:16ch;}
  .splithero__copy p{color:#B9B2A8;max-width:52ch;font-size:15px;}
  .splithero .searchbar{margin-top:18px;max-width:420px;border-color:#fff;}
  .splithero .searchbar input{color:#fff;}
  .splithero .searchbar input::placeholder{color:rgba(255,255,255,.65);}
  .splithero .searchbar button{background:#fff;color:var(--ink);border-left-color:#fff;}
  .splithero .searchbar button:hover{background:var(--blaze);color:#fff;}
  .splithero .filters{margin:20px 0 0;}
  .splithero .filters a{border-color:#fff;color:#fff;}
  .splithero .filters a.active,.splithero .filters a:hover{background:#fff;color:var(--ink);}
  .splithero .filters--sub{margin:8px 0 0;}
  .splithero .filters--sub a{border-color:rgba(255,255,255,.7);color:#fff;}
  .splithero .filters--sub a.active,.splithero .filters--sub a:hover{background:#fff;color:var(--ink);}

  /* The red the product zone carried now sits under the whole field, holding
     the accent without needing a picture to sit beside. */
  .splithero{border-bottom:3px solid var(--blaze);}

  /* THE BACKGROUND VIDEO, when the folder has one.
     The scrim is not decoration. White type over moving footage is legible
     until the first pale frame comes round, and unlike a still, a video cannot
     be checked once for contrast — every frame is a new background. Heaviest
     on the left where the words are, lightest on the right where the footage
     is actually allowed to show. */
  .splithero{position:relative;}
  .splithero__video{
    position:absolute;inset:0;width:100%;height:100%;
    object-fit:cover;z-index:0;
    /* Only the clip holding is-on is visible; the other element is downloading
       the next file behind it. The fade is what stops the cut between two
       unrelated pieces of footage reading as a glitch. */
    opacity:0;transition:opacity .6s linear;
  }
  .splithero__video.is-on{opacity:1;}
  .splithero__scrim{
    position:absolute;inset:0;z-index:1;
    background:linear-gradient(90deg,rgba(17,17,17,.92) 0%,rgba(17,17,17,.78) 45%,rgba(17,17,17,.45) 100%);
  }
  /* Above both — without this the search box and the category chips sit under
     the scrim and stop taking clicks. */
  .splithero__copy{position:relative;z-index:2;}
  @media (prefers-reduced-motion:reduce){
    /* Footage looping under someone who asked for less motion is the thing that
       setting exists to stop. They get the ink field. */
    .splithero__video{display:none;}
  }

  @media (max-width:860px){
    .splithero__copy{min-height:0;padding:26px 20px 30px;}
  }
</style>

<header class="pagehead splithero">
  <?php if (!empty($heroVideos)): ?>
    <?php /* muted + playsinline are what make autoplay legal on iOS and in
             Chrome; without both the browser refuses to start and the hero is
             a frozen first frame. aria-hidden because the footage says nothing
             the headline over it does not already say. */ ?>
    <video class="splithero__video is-on" src="<?= htmlspecialchars($heroVideos[0]) ?>"
           autoplay muted<?= count($heroVideos) === 1 ? ' loop' : '' ?> playsinline preload="auto"
           aria-hidden="true" tabindex="-1"
           <?php if (count($heroVideos) > 1): ?>data-playlist="<?= htmlspecialchars(json_encode($heroVideos), ENT_QUOTES) ?>"<?php endif; ?>></video>
    <?php if (count($heroVideos) > 1): ?>
      <?php /* The second element is the one loading the next clip. It starts
               empty and without autoplay: the script gives it a source once
               the first clip is actually playing, so the page does not open by
               downloading every video at once. */ ?>
      <video class="splithero__video" muted playsinline preload="auto"
             aria-hidden="true" tabindex="-1"></video>
    <?php endif; ?>
    <div class="splithero__scrim"></div>
    <?php if (count($heroVideos) > 1): ?>
      <script>
        /* Play the clips end to end, then round again.
           Two <video> elements rather than one: swapping .src on a single
           element means the next clip only starts downloading at the moment the
           previous one ends, and at these file sizes that is a hole in the hero
           for as long as the download takes. The idle element fetches the next
           clip while the visible one is still playing, and the two cross-fade.
           A single clip needs none of this and gets the loop attribute instead,
           so this script is not emitted at all. */
        (function () {
          var vids = document.querySelectorAll('.splithero__video');
          var list = JSON.parse(vids[0].dataset.playlist);
          var at = 0, showing = 0;

          function loadNext() {
            var idle = vids[1 - showing];
            idle.src = list[(at + 1) % list.length];
            idle.load();
          }

          function advance() {
            var idle = vids[1 - showing];
            at = (at + 1) % list.length;
            idle.classList.add('is-on');
            vids[showing].classList.remove('is-on');
            idle.play();
            showing = 1 - showing;
            /* Held back past the fade: giving the outgoing element a new source
               while it is still fading out swaps its last half second for the
               first frame of a clip that has not started yet. */
            setTimeout(loadNext, 900);
          }

          vids[0].addEventListener('ended', advance);
          vids[1].addEventListener('ended', advance);
          loadNext();
        })();
      </script>
    <?php endif; ?>
  <?php endif; ?>
  <div class="splithero__copy">
    <span class="mono" style="color:var(--blaze)">SS26 Collection</span>
    <h1 class="display" style="margin-top:14px;">Built for every step you take.</h1>
    <p>Authentic footwear, sourced right and priced fair — browse the full catalog below.</p>

    <form class="searchbar" method="get" action="<?= BASE_PATH ?>/index.php">
      <input type="text" name="q" placeholder="Search shoes, e.g. 'trail' or 'running'" value="<?= htmlspecialchars($search) ?>">
      <button type="submit">Search</button>
    </form>

    <div class="filters">
      <a href="<?= BASE_PATH ?>/index.php" class="<?= $category === '' && $search === '' ? 'active' : '' ?>">All</a>
      <?php foreach ($categories as $cat): ?>
        <a href="<?= BASE_PATH ?>/index.php?category=<?= urlencode($cat) ?>" class="<?= $category === $cat ? 'active' : '' ?>"><?= htmlspecialchars($cat) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($category !== '' && !empty($subcategoriesForCategory)): ?>
      <div class="filters filters--sub">
        <a href="<?= BASE_PATH ?>/index.php?category=<?= urlencode($category) ?>" class="<?= $subcategory === '' ? 'active' : '' ?>">All <?= htmlspecialchars($category) ?></a>
        <?php foreach ($subcategoriesForCategory as $sub): ?>
          <a href="<?= BASE_PATH ?>/index.php?category=<?= urlencode($category) ?>&subcategory=<?= urlencode($sub) ?>" class="<?= $subcategory === $sub ? 'active' : '' ?>"><?= htmlspecialchars($sub) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</header>

<section class="section">
  <div class="wrap">
    <div class="section-head">
      <div>
        <span class="mono">Catalog</span>
        <h2 class="display"><?= $search !== '' ? 'Results for "' . htmlspecialchars($search) . '"' : htmlspecialchars($subcategory ?: ($category ?: 'All Products')) ?></h2>
      </div>
      <p style="max-width:340px;font-size:14px;color:var(--gray);">
        <?= count($products) ?> item(s) found.
        <?php if ($search === '' && count($products) >= PRODUCT_LIST_LIMIT): ?>
          <?php /* The query stops at PRODUCT_LIST_LIMIT, and used to stop
                   silently — a catalog past the cap looked complete and was
                   not. This is not paging; it is the admission that the day
                   this line appears, paging is what is actually needed. */ ?>
          <br><strong>Showing the first <?= PRODUCT_LIST_LIMIT ?>.</strong>
          Narrow by category to see the rest.
        <?php endif; ?>
      </p>
    </div>

    <div class="shopbar">
      <details class="shopbar__menu">
        <summary>Sort <span aria-hidden="true">&#8964;</span></summary>
        <div class="shopbar__panel">
          <?php foreach (PRODUCT_SORTS as $key => $label): ?>
            <a href="<?= htmlspecialchars($shopUrl(['sort' => $key])) ?>" class="shopbar__opt<?= $shopFilter['sort'] === $key ? ' is-on' : '' ?>"><?= htmlspecialchars($label) ?></a>
          <?php endforeach; ?>
        </div>
      </details>
      <details class="shopbar__menu">
        <summary>Filter<?= $activeFilters ? ' (' . $activeFilters . ')' : '' ?>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></svg>
        </summary>
        <form class="shopbar__panel shopbar__panel--filter" method="get" action="<?= BASE_PATH ?>/index.php">
          <?php foreach (['q' => $search, 'category' => $category, 'subcategory' => $subcategory] as $k => $v): ?>
            <?php if ($v !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>"><?php endif; ?>
          <?php endforeach; ?>
          <?php if ($shopFilter['sort'] !== 'featured'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($shopFilter['sort']) ?>"><?php endif; ?>

          <p class="shopbar__label">Size (US)</p>
          <div class="shopbar__chips">
            <?php foreach (['' => 'Any', '7' => '7', '8' => '8', '9' => '9', '10' => '10', '11' => '11', '12' => '12'] as $val => $lbl): ?>
              <label class="shopbar__chip"><input type="radio" name="size" value="<?= $val ?>"<?= $shopFilter['size'] === (string) $val ? ' checked' : '' ?>><span><?= $lbl ?></span></label>
            <?php endforeach; ?>
          </div>

          <p class="shopbar__label">Price</p>
          <div class="shopbar__chips">
            <label class="shopbar__chip"><input type="radio" name="price" value=""<?= $shopFilter['price'] === '' ? ' checked' : '' ?>><span>Any</span></label>
            <?php foreach (PRODUCT_PRICE_BANDS as $val => $band): ?>
              <label class="shopbar__chip"><input type="radio" name="price" value="<?= $val ?>"<?= $shopFilter['price'] === $val ? ' checked' : '' ?>><span><?= htmlspecialchars($band[0]) ?></span></label>
            <?php endforeach; ?>
          </div>

          <p class="shopbar__label">Show</p>
          <div class="shopbar__chips">
            <label class="shopbar__chip"><input type="checkbox" name="sale" value="1"<?= $shopFilter['sale'] ? ' checked' : '' ?>><span>On sale</span></label>
            <label class="shopbar__chip"><input type="checkbox" name="instock" value="1"<?= $shopFilter['instock'] ? ' checked' : '' ?>><span>In stock only</span></label>
          </div>

          <div class="shopbar__actions">
            <a class="btn btn--sm" href="<?= htmlspecialchars($shopUrl(['size' => '', 'price' => '', 'sale' => false, 'instock' => false])) ?>">Clear</a>
            <button type="submit" class="btn btn--solid btn--sm">Show results</button>
          </div>
        </form>
      </details>
      <span class="shopbar__count"><?= count($products) ?> item<?= count($products) === 1 ? '' : 's' ?></span>
    </div>

    <?php if (empty($products)): ?>
      <div class="empty-state">No products matched your search.</div>
    <?php else: ?>
      <div class="products">
        <?php foreach ($products as $p): $price = product_display_price($p); $img = product_image_url($p); ?>
          <article class="card">
            <a class="card__frame" href="<?= BASE_PATH ?>/product.php?id=<?= $p['id'] ?>" style="--accent:#111111;color:#111111;" aria-label="View <?= htmlspecialchars($p['name']) ?>">
              <?php if ($p['badge']): ?><span class="card__badge <?= $p['sale_price'] ? 'card__badge--sale' : '' ?>"><?= htmlspecialchars($p['badge']) ?></span><?php endif; ?>
              <?php if ($p['stock'] < 1): ?><span class="card__badge card__badge--out" style="left:auto;right:14px;">Sold Out</span><?php endif; ?>
              <?php if ($img): ?>
                <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($p['name']) ?>" class="card__photo" loading="lazy">
              <?php else: ?>
                <svg viewBox="0 0 300 160"><use href="#shoe"/></svg>
              <?php endif; ?>
            </a>
            <?php if (auth_is_logged_in() && !auth_is_admin()): ?>
              <form method="post" action="<?= BASE_PATH ?>/index.php">
                <input type="hidden" name="action" value="toggle_wishlist">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                <button type="submit" class="card__wishlist <?= in_array($p['id'], $wishlistIds, true) ? 'is-active' : '' ?>" aria-label="Toggle wishlist" title="Toggle wishlist">
                  <?= in_array($p['id'], $wishlistIds, true) ? '♥' : '♡' ?>
                </button>
              </form>
            <?php endif; ?>
            <div class="card__body">
              <div class="card__cat mono"><?= htmlspecialchars($p['category']) ?><?= !empty($p['subcategory']) ? ' · ' . htmlspecialchars($p['subcategory']) : '' ?></div>
              <div class="card__name"><a href="<?= BASE_PATH ?>/product.php?id=<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></a></div>
              <?php $r = $ratings[(int) $p['id']] ?? null; ?>
              <div class="card__rating"><?php if ($r): ?><span class="card__stars" aria-hidden="true">★</span> <?= number_format($r['avg'], 1) ?> <span class="card__reviews">(<?= $r['count'] ?>)</span><span class="sr-only"> stars from <?= $r['count'] ?> review<?= $r['count'] === 1 ? '' : 's' ?></span><?php else: ?><span class="card__reviews">No reviews yet</span><?php endif; ?></div>
              <div class="card__row">
                <span class="card__price">
                  <?php if ($p['sale_price']): ?><s>₱<?= number_format($p['price'], 2) ?></s><?php endif; ?>
                  ₱<?= number_format($price, 2) ?>
                </span>
                <?php // A grid card has nowhere to pick a size, and the cart now
                      // needs one — so send shoppers to the product page instead
                      // of posting an add that would only be rejected. ?>
                <?php if ($p['stock'] < 1): ?>
                  <button type="button" class="card__add" disabled>Unavailable</button>
                <?php else: ?>
                  <a class="card__add" style="display:inline-block;text-align:center;text-decoration:none;"
                     href="<?= BASE_PATH ?>/product.php?id=<?= $p['id'] ?>">Choose Size</a>
                <?php endif; ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
        <?php
          // The grid's hairlines are drawn by the container's dark background
          // showing through the gap — an incomplete last row leaves an empty
          // cell with nothing covering that background, which reads as a
          // solid black tile. Pad the row with blank filler cards instead.
          $fillers = (3 - (count($products) % 3)) % 3;
          for ($i = 0; $i < $fillers; $i++): ?>
          <div class="card" aria-hidden="true"></div>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
