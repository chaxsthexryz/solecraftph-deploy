<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/product_service.php';
require_once __DIR__ . '/../includes/audit_service.php';
auth_require_admin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$product = product_find($id);

if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product not found — Admin — SoleCraftPH';
    require __DIR__ . '/_header.php';
    $active = 'products';
    require __DIR__ . '/_nav.php';
    echo '<section class="section" style="padding-top:0;"><div class="wrap"><div class="empty-state">Product not found. <a href="products.php" class="btn" style="margin-left:10px;">Back to Inventory</a></div></div></section>';
    require __DIR__ . '/_footer.php';
    exit;
}

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
    $lowStockThreshold = (int) ($_POST['low_stock_threshold'] ?? 5);
    $badge       = trim($_POST['badge'] ?? '');
    $isActive    = isset($_POST['is_active']);

    if ($name === '' || $category === '' || $price <= 0) {
        $error = 'Please provide at least a name, category, and a valid price.';
    } else {
        $upload = product_handle_image_upload();
        if ($upload['error']) {
            $error = $upload['error'];
        } else {
            product_update($id, [
                'name'        => $name,
                'category'    => $category,
                'subcategory' => $subcategory,
                'description' => $description,
                'price'       => $price,
                'sale_price'  => $salePrice,
                'stock'       => $stock,
                'low_stock_threshold' => $lowStockThreshold,
                'badge'       => $badge,
                'image'       => $upload['name'], // null = keep existing photo
                'is_active'   => $isActive,
            ]);

            // Per-size stock. Saved after product_update so its recalculated
            // total wins over whatever was typed in the single Stock box —
            // otherwise the two disagree the moment someone edits both.
            if (isset($_POST['sizes']) && is_array($_POST['sizes'])) {
                product_sizes_save($id, $_POST['sizes']);
            }

            $success = 'Product "' . htmlspecialchars($name) . '" was updated.';
            audit_log('Product updated', $name);
            $product = product_find($id); // refresh with saved values
        }
    }
}

$pageTitle = 'Edit Product — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
$active = 'products';
require __DIR__ . '/_nav.php';
$img = product_image_url($product);
?>

<section class="section" style="padding-top:0;">
  <div class="wrap" style="max-width:760px;">
    <div class="section-head" style="margin-bottom:24px;">
      <div><span class="mono">Manage</span><h1 class="display" style="font-size:36px;">Edit Product</h1></div>
      <a href="products.php" class="btn">&larr; Back to Inventory</a>
    </div>

    <div class="panel">
      <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="alert alert--success"><?= $success ?></div><?php endif; ?>

      <form method="post" action="edit_product.php?id=<?= $product['id'] ?>" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= $product['id'] ?>">

        <div class="form-field full" style="display:flex;gap:20px;align-items:center;">
          <div style="width:120px;height:120px;flex-shrink:0;background:var(--stone);border:1.5px solid var(--ink);display:flex;align-items:center;justify-content:center;overflow:hidden;">
            <?php if ($img): ?>
              <img src="<?= htmlspecialchars($img) ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
            <?php else: ?>
              <svg viewBox="0 0 300 160" style="width:80%;color:#111"><use href="#shoe"/></svg>
            <?php endif; ?>
          </div>
          <div style="flex:1;">
            <label>Replace Photo (optional)</label>
            <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
            <p style="font-size:12px;color:var(--gray);margin-top:6px;">Leave empty to keep the current photo.</p>
          </div>
        </div>

        <div class="form-grid" style="margin-top:18px;">
          <div class="form-field full">
            <label>Product Name *</label>
            <input type="text" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? $product['name']) ?>">
          </div>
          <div class="form-field">
            <label>Category *</label>
            <select name="category" id="category-select" required>
              <option value="">Select a category…</option>
              <?php $curCat = $_POST['category'] ?? $product['category']; ?>
              <?php foreach (PRODUCT_TAXONOMY as $cat => $subs): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= $curCat === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
              <?php endforeach; ?>
              <?php if ($curCat !== '' && !array_key_exists($curCat, PRODUCT_TAXONOMY)): ?>
                <option value="<?= htmlspecialchars($curCat) ?>" selected><?= htmlspecialchars($curCat) ?> (legacy)</option>
              <?php endif; ?>
            </select>
          </div>
          <div class="form-field">
            <label>Subcategory *</label>
            <select name="subcategory" id="subcategory-select" required>
              <option value="">Select a category first…</option>
              <?php $curSub = $_POST['subcategory'] ?? ($product['subcategory'] ?? ''); ?>
              <?php if ($curSub !== '' && !in_array($curSub, PRODUCT_TAXONOMY[$curCat] ?? [], true)): ?>
                <option value="<?= htmlspecialchars($curSub) ?>" selected><?= htmlspecialchars($curSub) ?> (legacy)</option>
              <?php endif; ?>
            </select>
          </div>
          <div class="form-field">
            <label>Badge (optional)</label>
            <input type="text" name="badge" value="<?= htmlspecialchars($_POST['badge'] ?? ($product['badge'] ?? '')) ?>" placeholder="New, Best Seller, Trail...">
          </div>
          <div class="form-field">
            <label>Price (₱) *</label>
            <input type="number" step="0.01" min="0" name="price" required value="<?= htmlspecialchars($_POST['price'] ?? $product['price']) ?>">
          </div>
          <div class="form-field">
            <label>Sale Price (₱, optional)</label>
            <input type="number" step="0.01" min="0" name="sale_price" value="<?= htmlspecialchars($_POST['sale_price'] ?? ($product['sale_price'] ?? '')) ?>">
          </div>
          <div class="form-field">
            <label>Stock Quantity *</label>
            <input type="number" min="0" name="stock" required value="<?= htmlspecialchars($_POST['stock'] ?? $product['stock']) ?>">
            <?php if (product_has_size_stock($id)): ?>
              <small style="color:var(--gray);">Calculated from the sizes below — edit those instead.</small>
            <?php endif; ?>
          </div>

          <?php $sizeStock = product_sizes_for($id); ?>
          <div class="form-field full">
            <label>Stock by size (US)</label>
            <small style="display:block;color:var(--gray);margin-bottom:10px;">
              <?= $sizeStock
                  ? 'This shoe is sold by size. Customers can only buy sizes with stock left.'
                  : 'Leave every box empty to keep using the single Stock Quantity above. Enter numbers to start tracking this shoe by size.' ?>
            </small>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
              <?php foreach (PRODUCT_SIZES as $s): ?>
                <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;">
                  <span class="mono">US <?= htmlspecialchars($s) ?></span>
                  <input type="number" min="0" style="width:74px;"
                         name="sizes[<?= htmlspecialchars($s) ?>]"
                         value="<?= htmlspecialchars((string) ($_POST['sizes'][$s] ?? $sizeStock[$s] ?? '')) ?>"
                         placeholder="0">
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-field">
            <label>Low Stock Alert Threshold</label>
            <input type="number" min="0" name="low_stock_threshold" value="<?= htmlspecialchars($_POST['low_stock_threshold'] ?? $product['low_stock_threshold']) ?>">
          </div>
          <div class="form-field">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox" name="is_active" value="1" style="width:auto;" <?= (isset($_POST['is_active']) || (!isset($_POST['name']) && $product['is_active'])) ? 'checked' : '' ?>>
              Active (visible in store)
            </label>
          </div>
          <div class="form-field full">
            <label>Description</label>
            <textarea name="description"><?= htmlspecialchars($_POST['description'] ?? ($product['description'] ?? '')) ?></textarea>
          </div>
        </div>
        <button type="submit" class="btn btn--solid">Save Changes</button>
      </form>
    </div>
  </div>
</section>

<script>
const PRODUCT_TAXONOMY = <?= json_encode(PRODUCT_TAXONOMY) ?>;
const SELECTED_SUBCATEGORY = <?= json_encode($curSub) ?>;
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
// Only auto-rebuild the subcategory list on load if the current category is
// part of the known taxonomy — otherwise keep the "(legacy)" option PHP rendered.
if (PRODUCT_TAXONOMY[catSelect.value]) {
  populateSubcategories(SELECTED_SUBCATEGORY);
}
</script>

<?php require __DIR__ . '/_footer.php'; ?>
