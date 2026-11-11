<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cms_service.php';
require_once __DIR__ . '/../includes/audit_service.php';
require_once __DIR__ . '/../includes/settings_service.php';
require_once __DIR__ . '/../includes/product_service.php';
auth_require_admin();
banner_ensure_video_column();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';
    if ($formAction === 'create') {
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $link = trim($_POST['link_url'] ?? '');
        $sort = (int) ($_POST['sort_order'] ?? 0);

        // Picking a shoe names the slide after it and links it to that shoe.
        $shoe = product_find((int) ($_POST['product_id'] ?? 0));
        if ($shoe) {
            $title = $title !== '' ? $title : (string) $shoe['name'];
            $link = $link !== '' ? $link : 'product.php?id=' . (int) $shoe['id'];
        }

        $upload = banner_handle_image_upload();
        $video = !empty($_FILES['video']['name'])
            ? video_handle_upload('video', 'bannervid')
            : ['url' => '', 'error' => null];
        if ($upload['error'] || $video['error']) {
            // Shown rather than swallowed: without this, a rejected file
            // quietly created a text banner and the picture simply never
            // appeared, with nothing on screen saying why.
            $error = $upload['error'] ?: $video['error'];
        } elseif ($title === '') {
            $error = 'Give the banner a title or pick a shoe.';
        } else {
            $id = banner_create($title, $subtitle, $link, $sort, $upload['url']);
            if ($video['url'] !== '') {
                banner_set_video($id, $video['url']);
            }
            audit_log('Banner created', $title . ($video['url'] !== '' ? ' (with video)' : ($upload['url'] !== '' ? ' (with image)' : '')));
        }
    } elseif ($formAction === 'video') {
        $id = (int) ($_POST['banner_id'] ?? 0);
        $upload = video_handle_upload('video', 'bannervid');
        if ($upload['error']) {
            $error = $upload['error'];
        } else {
            banner_set_video($id, $upload['url']);
            audit_log('Banner video set', 'Banner #' . $id);
        }
    } elseif ($formAction === 'video_clear') {
        banner_set_video((int) ($_POST['banner_id'] ?? 0), '');
        audit_log('Banner video removed', 'Banner #' . ($_POST['banner_id'] ?? ''));
    } elseif ($formAction === 'welcome_video') {
        $upload = video_handle_upload('video', 'welcomevid');
        if ($upload['error']) {
            $error = $upload['error'];
        } else {
            settings_update(['app_welcome_video' => $upload['url']]);
            audit_log('App welcome video set', $upload['url']);
        }
    } elseif ($formAction === 'welcome_clear') {
        settings_update(['app_welcome_video' => '']);
        audit_log('App welcome video removed', '');
    } elseif ($formAction === 'toggle') {
        banner_toggle((int) ($_POST['banner_id'] ?? 0));
        audit_log('Banner toggled', 'Banner #' . ($_POST['banner_id'] ?? ''));
    } elseif ($formAction === 'delete') {
        banner_delete((int) ($_POST['banner_id'] ?? 0));
        audit_log('Banner deleted', 'Banner #' . ($_POST['banner_id'] ?? ''));
    }

    // Redirect only on success, so a rejected upload can say so instead of
    // being lost to the redirect.
    if ($error === '') {
        header('Location: banners.php');
        exit;
    }
}

$banners = banner_list_all();
$shoes = product_list(null, null, 200);
$welcomeVideo = (string) setting('app_welcome_video', '');

$pageTitle = 'Banners — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
$active = 'banners';
require __DIR__ . '/_nav.php';
?>

<section class="section" style="padding-top:0;">
  <div class="wrap">
    <h1 class="display" style="margin-bottom:30px;">Homepage Banners &amp; Promotions</h1>
    <p class="mono" style="color:var(--gray);margin-bottom:30px;">
      With an image, a banner fills the top of the homepage as a promo. Without one, it appears as a thin text strip.
    </p>

    <div class="panel" style="max-width:640px;margin-bottom:40px;">
      <h3 class="display" style="font-size:22px;margin-bottom:16px;">Add Banner</h3>
      <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="post" action="banners.php" enctype="multipart/form-data">
        <input type="hidden" name="form_action" value="create">
        <div class="form-grid">
          <div class="form-field full">
            <label>Shoe</label>
            <select name="product_id">
              <option value="0">— None (text or image banner) —</option>
              <?php foreach ($shoes as $s): ?>
                <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="mono" style="color:var(--gray);display:block;margin-top:6px;">The app slide shows this shoe's name and price and opens it when tapped.</span>
          </div>
          <div class="form-field full"><label>Title</label><input type="text" name="title" placeholder="Optional with a shoe — uses the shoe's name"></div>
          <div class="form-field full">
            <label>App video</label>
            <input type="file" name="video" accept="video/mp4,video/quicktime">
            <span class="mono" style="color:var(--gray);display:block;margin-top:6px;">Optional. MP4 or MOV, plays muted in the app's Shop slides. Max <?= htmlspecialchars(ini_get('upload_max_filesize')) ?>.</span>
          </div>
          <div class="form-field full">
            <label>Banner image</label>
            <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
            <span class="mono" style="color:var(--gray);display:block;margin-top:6px;">
              Optional. Wide artwork, around 1920&times;600. Use the same size for every promo banner — the first one sets the height and the rest are matched to it.
            </span>
          </div>
          <div class="form-field full"><label>Subtitle</label><input type="text" name="subtitle" placeholder="Optional — shown on text banners only"></div>
          <div class="form-field"><label>Link URL</label><input type="text" name="link_url" placeholder="Optional — /index.php?category=..."></div>
          <div class="form-field"><label>Sort Order</label><input type="number" name="sort_order" value="0"></div>
        </div>
        <button type="submit" class="btn btn--solid">Add Banner</button>
      </form>
    </div>

    <div class="panel" style="max-width:640px;margin-bottom:40px;">
      <h3 class="display" style="font-size:22px;margin-bottom:8px;">App Welcome Video</h3>
      <p class="mono" style="color:var(--gray);margin-bottom:16px;">
        Plays behind "Welcome back" on the app's Account page. MP4 or MOV, short and silent works best (it plays muted, on a loop). Max <?= htmlspecialchars(ini_get('upload_max_filesize')) ?>.
      </p>
      <?php if ($welcomeVideo !== ''): ?>
        <video src="<?= BASE_PATH ?>/<?= htmlspecialchars($welcomeVideo) ?>" muted loop autoplay playsinline style="width:240px;display:block;margin-bottom:12px;border:1px solid var(--stone);"></video>
        <form method="post" action="banners.php" style="margin-bottom:12px;"><input type="hidden" name="form_action" value="welcome_clear"><button type="submit" class="link-remove" style="background:none;border:none;cursor:pointer;">Remove video</button></form>
      <?php endif; ?>
      <form method="post" action="banners.php" enctype="multipart/form-data" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="form_action" value="welcome_video">
        <input type="file" name="video" accept="video/mp4,video/quicktime" required>
        <button type="submit" class="btn btn--solid"><?= $welcomeVideo !== '' ? 'Replace video' : 'Upload video' ?></button>
      </form>
    </div>

    <?php if (empty($banners)): ?>
      <div class="empty-state">No banners yet.</div>
    <?php else: ?>
      <div class="table-scroll">
      <table class="admin-table">
        <thead><tr><th>Image</th><th>App video</th><th>Title</th><th>Subtitle</th><th>Links to</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($banners as $b): ?>
            <?php $img = $b['image_url'] ?? ''; ?>
            <tr style="<?= $b['is_active'] ? '' : 'opacity:.55;' ?>">
              <td>
                <?php if ($img !== ''): ?>
                  <img src="<?= BASE_PATH ?>/<?= htmlspecialchars($img) ?>" alt="" style="width:120px;height:auto;display:block;border:1px solid var(--stone);">
                <?php else: ?>
                  <span class="mono" style="color:var(--gray);">Text only</span>
                <?php endif; ?>
              </td>
              <td>
                <?php $vid = $b['video_url'] ?? ''; ?>
                <?php if ($vid !== ''): ?>
                  <video src="<?= BASE_PATH ?>/<?= htmlspecialchars($vid) ?>" muted loop autoplay playsinline style="width:120px;display:block;border:1px solid var(--stone);"></video>
                  <form method="post" action="banners.php"><input type="hidden" name="form_action" value="video_clear"><input type="hidden" name="banner_id" value="<?= $b['id'] ?>"><button type="submit" class="link-remove" style="background:none;border:none;cursor:pointer;">Remove video</button></form>
                <?php endif; ?>
                <form method="post" action="banners.php" enctype="multipart/form-data">
                  <input type="hidden" name="form_action" value="video">
                  <input type="hidden" name="banner_id" value="<?= $b['id'] ?>">
                  <input type="file" name="video" accept="video/mp4,video/quicktime" required style="max-width:170px;">
                  <button type="submit" class="mono" style="background:none;border:none;cursor:pointer;font-weight:700;"><?= $vid !== '' ? 'Replace' : 'Upload' ?></button>
                </form>
              </td>
              <td><?= htmlspecialchars($b['title']) ?></td>
              <td><?= htmlspecialchars($b['subtitle'] ?: '—') ?></td>
              <td><span class="mono"><?= htmlspecialchars($b['link_url'] ?: '—') ?></span></td>
              <td><?= (int) $b['sort_order'] ?></td>
              <td><span class="mono"><?= $b['is_active'] ? 'Active' : 'Inactive' ?></span></td>
              <td>
                <div style="display:flex;gap:14px;">
                  <form method="post" action="banners.php"><input type="hidden" name="form_action" value="toggle"><input type="hidden" name="banner_id" value="<?= $b['id'] ?>"><button type="submit" class="mono" style="background:none;border:none;cursor:pointer;font-weight:700;"><?= $b['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
                  <form method="post" action="banners.php" onsubmit="return confirm('Delete this banner?');"><input type="hidden" name="form_action" value="delete"><input type="hidden" name="banner_id" value="<?= $b['id'] ?>"><button type="submit" class="link-remove" style="background:none;border:none;cursor:pointer;">Delete</button></form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
