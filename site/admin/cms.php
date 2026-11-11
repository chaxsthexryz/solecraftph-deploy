<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cms_service.php';
require_once __DIR__ . '/../includes/audit_service.php';
auth_require_admin();

$success = '';
$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId ? cms_page_find_by_id($editId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    if ($formAction === 'save') {
        $slug    = trim($_POST['slug'] ?? '');
        $title   = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $slug    = preg_replace('/[^a-z0-9\-]/', '', strtolower(str_replace(' ', '-', $slug)));

        if ($slug === '' || $title === '') {
            $success = '';
        } else {
            cms_page_save($slug, $title, $content);
            audit_log('CMS page saved', $slug);
            $success = 'Page "' . htmlspecialchars($title) . '" saved.';
            $editing = null;
        }
    }
}

$pages = cms_page_list();

$pageTitle = 'Content — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
$active = 'cms';
require __DIR__ . '/_nav.php';
?>

<section class="section" style="padding-top:0;">
  <div class="wrap">
    <h1 class="display" style="margin-bottom:30px;">Content Pages</h1>

    <?php if ($success): ?><div class="alert alert--success"><?= $success ?></div><?php endif; ?>

    <div class="panel" style="max-width:720px;margin-bottom:40px;">
      <h3 class="display" style="font-size:22px;margin-bottom:16px;"><?= $editing ? 'Edit Page' : 'New Page' ?></h3>
      <form method="post" action="cms.php">
        <input type="hidden" name="form_action" value="save">
        <div class="form-grid">
          <div class="form-field">
            <label>Slug (used in the page URL)</label>
            <input type="text" name="slug" required value="<?= htmlspecialchars($editing['slug'] ?? '') ?>" <?= $editing ? 'readonly' : '' ?> placeholder="about">
          </div>
          <div class="form-field">
            <label>Title</label>
            <input type="text" name="title" required value="<?= htmlspecialchars($editing['title'] ?? '') ?>">
          </div>
          <div class="form-field full">
            <label>Content</label>
            <textarea name="content" rows="8"><?= htmlspecialchars($editing['content'] ?? '') ?></textarea>
          </div>
        </div>
        <button type="submit" class="btn btn--solid">Save Page</button>
      </form>
    </div>

    <div class="table-scroll">
    <table class="admin-table">
      <thead><tr><th>Title</th><th>Slug</th><th>Updated</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($pages as $p): ?>
          <tr>
            <td><?= htmlspecialchars($p['title']) ?></td>
            <td class="mono">/page.php?slug=<?= htmlspecialchars($p['slug']) ?></td>
            <td><?= htmlspecialchars($p['updated_at']) ?></td>
            <td><a href="cms.php?edit=<?= $p['id'] ?>" style="font-weight:700;">Edit</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
