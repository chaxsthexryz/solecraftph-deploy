<?php
/**
 * Who built this. A flat list of the five of us, in the order the group uses.
 *
 * The names live here rather than in the database on purpose: this is not
 * content the shop owner edits between campaigns, it is a fact about the
 * project, and a cms_pages row would mean an admin screen and a migration for
 * something that changes once a term.
 */
require_once __DIR__ . '/config/app.php';

$groupName = 'Group 9';
$developers = [
    'Brian Ballano',
    'Erika Joan Del Rosario',
    'John Lawrence Balitos',
    'Keanu Florenz Licup',
    'Paul Francis Balboa',
];

/**
 * First initial and last initial, so "Erika Joan Del Rosario" reads ER and not
 * EJDR. Derived rather than typed out: a name and its monogram cannot drift
 * apart if only one of them exists.
 */
function developer_monogram(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    if (count($parts) < 2) {
        return strtoupper(substr($name, 0, 2));
    }
    return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
}

$pageTitle = 'The Developers — SoleCraftPH';
require __DIR__ . '/includes/header.php';
?>

<style>
  /* Page-specific, so it sits here rather than in style.css — the same call
     the homepage hero makes. Nothing else on the site uses a monogram tile. */
  .devs{
    display:grid;
    /* Still five across on a wide screen, and it reflows on its own down to
       one. 210px is the floor that keeps all five in a single row inside the
       1280 wrap — push it higher and a group of five breaks as four plus a
       stray. Everything inside the card scales with the viewport instead. */
    grid-template-columns:repeat(auto-fit,minmax(210px,1fr));
    gap:20px;
  }
  .dev{
    border:1.5px solid var(--stone);
    background:#fff;
    padding:clamp(28px,3vw,44px) 18px clamp(26px,2.6vw,38px);
    text-align:center;
  }
  .dev__mark{
    /* Sized off the viewport so the tile fills the card it is in rather than
       sitting as a token in the middle of it. */
    width:clamp(96px,10vw,148px);height:clamp(96px,10vw,148px);
    margin:0 auto clamp(20px,1.8vw,26px);
    border-radius:50%;
    background:var(--ink);color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-family:'Bebas Neue',sans-serif;
    font-size:clamp(34px,3.6vw,54px);letter-spacing:2px;
  }
  .dev__name{
    font-family:'Bebas Neue',sans-serif;
    font-size:clamp(21px,1.8vw,27px);line-height:1.12;letter-spacing:.5px;
    /* Long names wrap to two lines; without this the cards in a row end up
       different heights and the monograms stop lining up. */
    min-height:2.24em;
    display:flex;align-items:center;justify-content:center;
  }
  .dev__rule{
    width:36px;height:3px;margin:clamp(14px,1.3vw,18px) auto 0;
    background:var(--blaze);
  }
</style>

<header class="pagehead">
  <div class="wrap">
    <h1 class="display">The Developers</h1>
    <p><?= htmlspecialchars($groupName) ?> — the five of us who built SoleCraftPH, from the storefront and the admin panel to the Android app.</p>
  </div>
</header>

<section class="section">
  <div class="wrap">
    <div class="devs">
      <?php foreach ($developers as $name): ?>
        <div class="dev">
          <div class="dev__mark" aria-hidden="true"><?= htmlspecialchars(developer_monogram($name)) ?></div>
          <div class="dev__name"><?= htmlspecialchars($name) ?></div>
          <div class="dev__rule"></div>
        </div>
      <?php endforeach; ?>
    </div>

    <p class="mono" style="margin-top:34px;color:var(--gray);">
      <?= count($developers) ?> members &middot; <?= htmlspecialchars($groupName) ?>
    </p>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
