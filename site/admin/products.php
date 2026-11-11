<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/product_service.php';
require_once __DIR__ . '/../includes/audit_service.php';
auth_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['product_id'] ?? 0);

    if ($action === 'delete') {
        product_delete($id);
        audit_log('Product deactivated', 'Product #' . $id);
    } elseif ($action === 'reactivate') {
        product_reactivate($id);
        audit_log('Product reactivated', 'Product #' . $id);
    } elseif ($action === 'restock') {
        product_update_stock($id, (int) ($_POST['stock'] ?? 0));
        audit_log('Stock updated', 'Product #' . $id . ' -> ' . (int) ($_POST['stock'] ?? 0));
    }
    header('Location: products.php');
    exit;
}

$products = product_list_all(500);

$pageTitle = 'Inventory — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
$active = 'products';
require __DIR__ . '/_nav.php';
?>

<section class="section" style="padding-top:0;">
  <div class="wrap">
    <div class="section-head">
      <div><span class="mono">Manage</span><h2 class="display" style="font-size:36px;">Inventory</h2></div>
      <a href="add_product.php" class="btn btn--solid">+ Add Product</a>
    </div>

    <?php if (empty($products)): ?>
      <div class="empty-state">No products in inventory yet.</div>
    <?php else: ?>
      <div class="table-scroll">
      <table class="admin-table">
        <thead>
          <tr><th></th><th>Name</th><th>Category</th><th>Subcategory</th><th>Price</th><th>Stock</th><th>Badge</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($products as $p): $img = product_image_url($p); $low = $p['is_active'] && $p['stock'] <= $p['low_stock_threshold']; ?>
            <tr style="<?= $p['is_active'] ? '' : 'opacity:.55;' ?><?= $low ? 'background:#fbeceb;' : '' ?>">
              <td style="width:52px;">
                <div style="width:44px;height:44px;background:var(--stone);border:1px solid var(--ink);display:flex;align-items:center;justify-content:center;overflow:hidden;">
                  <?php if ($img): ?>
                    <img src="<?= htmlspecialchars($img) ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                  <?php else: ?>
                    <svg viewBox="0 0 300 160" style="width:80%;color:#111"><use href="#shoe"/></svg>
                  <?php endif; ?>
                </div>
              </td>
              <td><a href="edit_product.php?id=<?= $p['id'] ?>" style="font-weight:700;"><?= htmlspecialchars($p['name']) ?></a></td>
              <td><?= htmlspecialchars($p['category']) ?></td>
              <td><?= htmlspecialchars($p['subcategory'] ?? '—') ?></td>
              <td>
                <?php if ($p['sale_price']): ?><s>₱<?= number_format($p['price'], 2) ?></s> <?php endif; ?>
                ₱<?= number_format(product_display_price($p), 2) ?>
              </td>
              <td>
                <form method="post" action="products.php" style="display:flex;gap:6px;">
                  <input type="hidden" name="action" value="restock">
                  <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                  <input class="qty-input" type="number" name="stock" value="<?= (int) $p['stock'] ?>" min="0" style="width:70px;">
                  <button type="submit" class="btn btn--sm">Save</button>
                </form>
              </td>
              <td><?= htmlspecialchars($p['badge'] ?? '—') ?></td>
              <td><span class="mono" style="color:<?= $p['is_active'] ? 'var(--ink)' : 'var(--blaze)' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span></td>
              <td>
                <div style="display:flex;gap:14px;align-items:center;">
                  <a href="edit_product.php?id=<?= $p['id'] ?>" class="mono" style="font-weight:700;">Edit</a>
                  <?php if ($p['is_active']): ?>
                    <form method="post" action="products.php" onsubmit="return confirm('Remove this product from the storefront?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                      <button type="submit" class="link-remove" style="background:none;border:none;cursor:pointer;">Deactivate</button>
                    </form>
                  <?php else: ?>
                    <form method="post" action="products.php">
                      <input type="hidden" name="action" value="reactivate">
                      <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                      <button type="submit" style="background:none;border:none;cursor:pointer;color:var(--ink);font-weight:700;font-size:11px;letter-spacing:.04em;text-transform:uppercase;">Reactivate</button>
                    </form>
                  <?php endif; ?>
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
