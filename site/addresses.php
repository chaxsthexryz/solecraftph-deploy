<?php
/**
 * Saved delivery addresses, for the website.
 *
 * The app has had these since the address book went in; the site still knew
 * about exactly one address per customer. Same table and same service, so a
 * shopper who saves "Work" on their phone sees it here, and the other way
 * round.
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/address_service.php';

if (!auth_is_logged_in()) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

$userId  = (int) $_SESSION['user_id'];
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['form_action'] ?? 'save';

    if ($action === 'save') {
        $saved = address_save($userId, [
            'id'             => (int) ($_POST['id'] ?? 0),
            'label'          => $_POST['label'] ?? '',
            'recipient_name' => $_POST['recipient_name'] ?? '',
            'phone'          => $_POST['phone'] ?? '',
            'address'        => $_POST['address'] ?? '',
            // There is no "use my location" button here on purpose: a desktop
            // browser's idea of where you are is usually the wrong city. Any
            // pin was set from the phone, and is carried through the form so
            // that editing an address here does not silently drop it.
            'latitude'       => $_POST['latitude'] ?? '',
            'longitude'      => $_POST['longitude'] ?? '',
        ]);
        if ($saved === null) {
            $error = 'Could not save that address. It needs at least an address line, and you can keep up to '
                . ADDRESS_MAX_PER_USER . '.';
        } else {
            $success = 'Address saved.';
        }
    } elseif ($action === 'default') {
        address_set_default($userId, (int) ($_POST['id'] ?? 0));
        $success = 'Default address updated.';
    } elseif ($action === 'delete') {
        address_delete($userId, (int) ($_POST['id'] ?? 0));
        $success = 'Address removed.';
    }
}

$addresses = address_list($userId);

// Editing reuses this same form rather than a second page — it is the same
// five fields, and a separate edit screen would be the same code twice.
$editing = isset($_GET['edit']) ? address_find($userId, (int) $_GET['edit']) : null;

$pageTitle = 'Delivery Addresses — SoleCraftPH';
require __DIR__ . '/includes/header.php';
?>

<header class="pagehead">
  <div class="wrap">
    <h1 class="display">Delivery Addresses</h1>
    <p>Save the places you order to, so checkout fills itself in.</p>
  </div>
</header>

<section class="section">
  <div class="wrap" style="display:grid;grid-template-columns:1.3fr 1fr;gap:40px;align-items:start;">

    <div>
      <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="alert alert--success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

      <?php if (!$addresses): ?>
        <div class="empty-state">
          No saved addresses yet. Add one on the right and your next checkout will be filled in for you.
        </div>
      <?php endif; ?>

      <?php foreach ($addresses as $a): ?>
        <?php $map = address_public($a)['map_url']; ?>
        <div class="panel" style="margin-bottom:16px;">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
            <h3 class="display" style="font-size:22px;"><?= htmlspecialchars($a['label']) ?></h3>
            <?php if ((int) $a['is_default'] === 1): ?>
              <span class="mono" style="color:var(--blaze);">Default</span>
            <?php endif; ?>
          </div>
          <?php if ($a['recipient_name'] !== ''): ?>
            <p><?= htmlspecialchars($a['recipient_name']) ?></p>
          <?php endif; ?>
          <p style="color:var(--gray);"><?= nl2br(htmlspecialchars($a['address'])) ?></p>
          <?php if ($a['phone'] !== ''): ?>
            <p class="mono" style="font-size:13px;"><?= htmlspecialchars($a['phone']) ?></p>
          <?php endif; ?>
          <?php if ($map): ?>
            <p style="margin-top:8px;">
              <a href="<?= htmlspecialchars($map) ?>" target="_blank" rel="noopener" class="mono" style="font-size:13px;">
                Pinned on the map &rarr;
              </a>
            </p>
          <?php endif; ?>

          <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap;">
            <a href="<?= BASE_PATH ?>/addresses.php?edit=<?= (int) $a['id'] ?>" class="btn btn--sm">Edit</a>
            <?php if ((int) $a['is_default'] !== 1): ?>
              <form method="post" action="<?= BASE_PATH ?>/addresses.php" style="display:inline;">
                <input type="hidden" name="form_action" value="default">
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <button type="submit" class="btn btn--sm">Make default</button>
              </form>
            <?php endif; ?>
            <form method="post" action="<?= BASE_PATH ?>/addresses.php" style="display:inline;"
                  onsubmit="return confirm('Remove this address?');">
              <input type="hidden" name="form_action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
              <button type="submit" class="btn btn--sm">Delete</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="panel">
      <h3 class="display" style="font-size:24px;margin-bottom:16px;">
        <?= $editing ? 'Edit address' : 'Add an address' ?>
      </h3>
      <form method="post" action="<?= BASE_PATH ?>/addresses.php">
        <input type="hidden" name="form_action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
        <input type="hidden" name="latitude" value="<?= htmlspecialchars((string) ($editing['latitude'] ?? '')) ?>">
        <input type="hidden" name="longitude" value="<?= htmlspecialchars((string) ($editing['longitude'] ?? '')) ?>">

        <div class="form-field">
          <label>Label</label>
          <input type="text" name="label" placeholder="Home, Work, Mum's"
                 value="<?= htmlspecialchars((string) ($editing['label'] ?? '')) ?>">
        </div>
        <div class="form-field">
          <label>Who receives it</label>
          <input type="text" name="recipient_name"
                 value="<?= htmlspecialchars((string) ($editing['recipient_name'] ?? '')) ?>">
        </div>
        <div class="form-field">
          <label>Contact number</label>
          <input type="text" name="phone"
                 value="<?= htmlspecialchars((string) ($editing['phone'] ?? '')) ?>">
        </div>
        <div class="form-field">
          <label>Address *</label>
          <textarea name="address" required
                    placeholder="House / street / barangay / city"><?= htmlspecialchars((string) ($editing['address'] ?? '')) ?></textarea>
        </div>
        <button type="submit" class="btn btn--solid" style="width:100%;justify-content:center;">
          <?= $editing ? 'Save changes' : 'Add address' ?>
        </button>
        <?php if ($editing): ?>
          <p style="margin-top:10px;text-align:center;">
            <a href="<?= BASE_PATH ?>/addresses.php" class="mono" style="font-size:13px;">Cancel</a>
          </p>
        <?php endif; ?>
      </form>

      <?php if ($editing && ($editing['latitude'] ?? null) !== null): ?>
        <p class="mono" style="font-size:13px;color:var(--gray);margin-top:14px;">
          This address has a map pin saved from the app. Editing it here keeps the pin.
        </p>
      <?php endif; ?>
    </div>

  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
