<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/product_service.php';
require_once __DIR__ . '/../includes/audit_service.php';
auth_require_admin();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name'] ?? '');
    $category    = trim($_POST['category'] ?? '');
    $subcategory = trim($_POST['subcategory'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price       = (float) ($_POST['price'] ?? 0);
    $salePrice   = $_POST['sale_price'] !== '' ? (float) $_POST['sale_price'] : null;
    $stock       = (int) ($_POST['stock'] ?? 0);
    $badge       = trim($_POST['badge'] ?? '');

    if ($name === '' || $category === '' || $price <= 0) {
        $error = 'Please provide at least a name, category, and a valid price.';
    } else {
        $upload = product_handle_image_upload();
        if ($upload['error']) {
            $error = $upload['error'];
        } else {
            product_create([
                'name'        => $name,
                'category'    => $category,
                'subcategory' => $subcategory,
                'description' => $description,
                'price'       => $price,
                'sale_price'  => $salePrice,
                'stock'       => $stock,
                'badge'       => $badge,
                'image'       => $upload['name'],
            ]);

            $success = 'Product "' . htmlspecialchars($name) . '" was added to inventory.';
            audit_log('Product created', $name);
        }
    }
}

$pageTitle = 'Add Product — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
$active = 'add_product';
require __DIR__ . '/_nav.php';
?>

<section class="section" style="padding-top:0;">
  <div class="wrap" style="max-width:760px;">
    <h1 class="display" style="margin-bottom:24px;">Add Product to Inventory</h1>

    <div class="panel">
      <?php if ($error): ?><div class="alert alert--error"><?= $error ?></div><?php endif; ?>
      <?php if ($success): ?><div class="alert alert--success"><?= $success ?></div><?php endif; ?>

      <form method="post" action="add_product.php" enctype="multipart/form-data">
        <div class="form-grid">
          <div class="form-field full">
            <label>Product Name *</label>
            <input type="text" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
          </div>
          <div class="form-field">
            <label>Category *</label>
            <select name="category" id="category-select" required>
              <option value="">Select a category…</option>
              <?php foreach (PRODUCT_TAXONOMY as $cat => $subs): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= ($_POST['category'] ?? '') === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-field">
            <label>Subcategory *</label>
            <select name="subcategory" id="subcategory-select" required>
              <option value="">Select a category first…</option>
            </select>
          </div>
          <div class="form-field">
            <label>Badge (optional)</label>
            <input type="text" name="badge" value="<?= htmlspecialchars($_POST['badge'] ?? '') ?>" placeholder="New, Best Seller, Trail...">
          </div>
          <div class="form-field">
            <label>Price (₱) *</label>
            <input type="number" step="0.01" min="0" name="price" required value="<?= htmlspecialchars($_POST['price'] ?? '') ?>">
          </div>
          <div class="form-field">
            <label>Sale Price (₱, optional)</label>
            <input type="number" step="0.01" min="0" name="sale_price" value="<?= htmlspecialchars($_POST['sale_price'] ?? '') ?>">
          </div>
          <div class="form-field">
            <label>Stock Quantity *</label>
            <input type="number" min="0" name="stock" required value="<?= htmlspecialchars($_POST['stock'] ?? '0') ?>">
          </div>
          <div class="form-field">
            <label>Product Image</label>
            <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
          </div>
          <div class="form-field full">
            <label>Description</label>
            <textarea name="description"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
          </div>
        </div>
        <button type="submit" class="btn btn--solid">Add to Inventory</button>
      </form>
    </div>
  </div>
</section>

<script>
const PRODUCT_TAXONOMY = <?= json_encode(PRODUCT_TAXONOMY) ?>;
const SELECTED_SUBCATEGORY = <?= json_encode($_POST['subcategory'] ?? '') ?>;
const catSelect = document.getElementById('category-select');
const subSelect = document.getElementById('subcategory-select');

function populateSubcategories(selectedSub) {
  const cat = catSelect.value;
  subSelect.innerHTML = '';
  if (!cat || !PRODUCT_TAXONOMY[cat]) {
    subSelect.innerHTML = '<option value="">Select a category first…</option>';
    return;
  }
  subSelect.innerHTML = '<option value="">Select a subcategory…</option>';
  PRODUCT_TAXONOMY[cat].forEach(sub => {
    const opt = document.createElement('option');
    opt.value = sub;
    opt.textContent = sub;
    if (sub === selectedSub) opt.selected = true;
    subSelect.appendChild(opt);
  });
}

catSelect.addEventListener('change', () => populateSubcategories(''));
populateSubcategories(SELECTED_SUBCATEGORY);
</script>

<?php require __DIR__ . '/_footer.php'; ?>
