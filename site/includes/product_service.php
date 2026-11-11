<?php
/**
 * Product Service
 * Handles all product-related data access: listing, searching,
 * fetching a single product, and admin inventory management.
 */
require_once __DIR__ . '/../config/db.php';

/**
 * The store's fixed category taxonomy: 3 main categories, each with
 * 3 subcategories. Used to power admin dropdowns and the storefront
 * filter UI. Products aren't restricted to only these values (the
 * columns are still free text), but this is the intended structure.
 */
const PRODUCT_TAXONOMY = [
    'Athletic & Performance Footwear' => [
        'Road Running Shoes',
        'Trail Running Shoes',
        'Training & Gym Shoes',
    ],
    'Casual & Lifestyle Footwear' => [
        'Everyday Lifestyle Sneakers',
        'Slip-On & Canvas Shoes',
        'Retro & Heritage Sneakers',
    ],
    'Formal & Dress Footwear' => [
        'Oxfords & Derby Shoes',
        'Loafers & Dress Slip-On Shoes',
        'Dress Boots',
    ],
];

/**
 * How many products a catalog query returns at most.
 *
 * This was an unnamed 100 sitting in a default argument, with no offset, no
 * page parameter and nothing in the UI admitting a limit existed. Product 101
 * simply did not appear: the admin saw it saved, the shop did not show it, and
 * nothing anywhere reported a problem. The number was never the bug — the
 * silence was. Naming it lets a caller tell when it has been hit, and it is
 * raised to match product_list_all() and the dashboard, which already ask for
 * 500. Past that, this needs real paging rather than a bigger number.
 */
const PRODUCT_LIST_LIMIT = 500;

function product_list(?string $category = null, ?string $subcategory = null, int $limit = PRODUCT_LIST_LIMIT): array
{
    $db = db();
    $where = ['is_active = 1'];
    $params = [];

    if ($category && $category !== 'All') {
        $where[] = 'category = ?';
        $params[] = $category;
    }
    if ($subcategory && $subcategory !== 'All') {
        $where[] = 'subcategory = ?';
        $params[] = $subcategory;
    }

    $sql = 'SELECT * FROM products WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT ?';
    $stmt = $db->prepare($sql);
    $i = 1;
    foreach ($params as $p) {
        $stmt->bindValue($i++, $p, PDO::PARAM_STR);
    }
    $stmt->bindValue($i, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function product_search(string $term, int $limit = 50): array
{
    $db = db();
    $like = '%' . $term . '%';
    $stmt = $db->prepare(
        'SELECT * FROM products
         WHERE is_active = 1 AND (name LIKE ? OR category LIKE ? OR subcategory LIKE ? OR description LIKE ?)
         ORDER BY created_at DESC LIMIT ?'
    );
    $stmt->bindValue(1, $like, PDO::PARAM_STR);
    $stmt->bindValue(2, $like, PDO::PARAM_STR);
    $stmt->bindValue(3, $like, PDO::PARAM_STR);
    $stmt->bindValue(4, $like, PDO::PARAM_STR);
    $stmt->bindValue(5, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function product_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function product_categories(): array
{
    $stmt = db()->query('SELECT DISTINCT category FROM products WHERE is_active = 1 ORDER BY category');
    $found = array_column($stmt->fetchAll(), 'category');
    // Prefer the canonical taxonomy order; append any extra categories found in the DB.
    $ordered = array_values(array_intersect(array_keys(PRODUCT_TAXONOMY), $found));
    $extra = array_values(array_diff($found, $ordered));
    return array_merge($ordered, $extra);
}

/**
 * Subcategories, grouped by their parent category, limited to what's
 * actually in the DB (and active). Pass a $category to get only that
 * category's subcategory list (still keyed by category for convenience).
 */
function product_subcategory_map(?string $category = null): array
{
    $db = db();
    if ($category && $category !== 'All') {
        $stmt = $db->prepare(
            'SELECT DISTINCT category, subcategory FROM products
             WHERE is_active = 1 AND category = ? AND subcategory IS NOT NULL AND subcategory <> ""
             ORDER BY subcategory'
        );
        $stmt->execute([$category]);
    } else {
        $stmt = $db->query(
            'SELECT DISTINCT category, subcategory FROM products
             WHERE is_active = 1 AND subcategory IS NOT NULL AND subcategory <> ""
             ORDER BY category, subcategory'
        );
    }

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[$row['category']][] = $row['subcategory'];
    }

    // Sort each category's subcategories to match the canonical taxonomy order where possible.
    foreach ($map as $cat => $subs) {
        $canon = PRODUCT_TAXONOMY[$cat] ?? [];
        $ordered = array_values(array_intersect($canon, $subs));
        $extra = array_values(array_diff($subs, $ordered));
        $map[$cat] = array_merge($ordered, $extra);
    }

    return $map;
}

/** Admin: list every product (active AND deactivated) so nothing gets lost from the panel */
function product_list_all(int $limit = 500): array
{
    $stmt = db()->prepare('SELECT * FROM products ORDER BY created_at DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Inventory Management: active products at or below their own low-stock threshold */
function product_low_stock(): array
{
    $stmt = db()->query(
        'SELECT * FROM products WHERE is_active = 1 AND stock <= low_stock_threshold ORDER BY stock ASC'
    );
    return $stmt->fetchAll();
}

function product_update_low_stock_threshold(int $id, int $threshold): void
{
    $stmt = db()->prepare('UPDATE products SET low_stock_threshold = ? WHERE id = ?');
    $stmt->execute([max(0, $threshold), $id]);
}

/** Most recent products that have a photo — used for the homepage hero slideshow */
function product_hero_images(int $limit = 5): array
{
    $stmt = db()->prepare(
        "SELECT * FROM products WHERE is_active = 1 AND image IS NOT NULL AND image <> ''
         ORDER BY created_at DESC LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Handles a product photo upload from $_FILES['image'].
 * Returns ['name' => string|null, 'error' => string|null].
 * 'name' is null if no file was chosen (not an error) or if the upload failed (error set).
 */
function product_handle_image_upload(): array
{
    if (empty($_FILES['image']['name'])) {
        return ['name' => null, 'error' => null];
    }

    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        return ['name' => null, 'error' => 'The image upload failed (error code ' . $_FILES['image']['error'] . '). Please try again.'];
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return ['name' => null, 'error' => 'Image must be a JPG, PNG, or WEBP file.'];
    }

    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['name' => null, 'error' => 'The uploads folder could not be created. Check folder permissions.'];
    }
    if (!is_writable($dir)) {
        return ['name' => null, 'error' => 'The uploads folder is not writable. Check folder permissions.'];
    }

    $imageName = uniqid('prod_') . '.' . $ext;
    $dest = $dir . '/' . $imageName;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
        return ['name' => null, 'error' => 'Could not save the uploaded image to the server.'];
    }

    return ['name' => $imageName, 'error' => null];
}

/** Admin: add a product to inventory */
function product_create(array $data): int
{
    $stmt = db()->prepare(
        'INSERT INTO products (name, category, subcategory, description, price, sale_price, stock, badge, image, is_active)
         VALUES (:name, :category, :subcategory, :description, :price, :sale_price, :stock, :badge, :image, 1)'
    );
    $stmt->execute([
        ':name'        => $data['name'],
        ':category'    => $data['category'],
        ':subcategory' => $data['subcategory'] ?: null,
        ':description' => $data['description'],
        ':price'       => $data['price'],
        ':sale_price'  => $data['sale_price'] ?: null,
        ':stock'       => $data['stock'],
        ':badge'       => $data['badge'] ?: null,
        ':image'       => $data['image'] ?? null,
    ]);
    return (int) db()->lastInsertId();
}

function product_update_stock(int $productId, int $newStock): void
{
    $stmt = db()->prepare('UPDATE products SET stock = ? WHERE id = ?');
    $stmt->execute([$newStock, $productId]);
}

function product_decrement_stock(int $productId, int $qty): void
{
    $stmt = db()->prepare('UPDATE products SET stock = GREATEST(stock - ?, 0) WHERE id = ?');
    $stmt->execute([$qty, $productId]);
}

/** Admin: full edit of an existing product. Image is only replaced if a new filename is passed. */
function product_update(int $id, array $data): void
{
    $sql = 'UPDATE products SET
                name = :name,
                category = :category,
                subcategory = :subcategory,
                description = :description,
                price = :price,
                sale_price = :sale_price,
                stock = :stock,
                low_stock_threshold = :low_stock_threshold,
                badge = :badge,
                is_active = :is_active';

    $params = [
        ':id'          => $id,
        ':name'        => $data['name'],
        ':category'    => $data['category'],
        ':subcategory' => $data['subcategory'] ?: null,
        ':description' => $data['description'],
        ':price'       => $data['price'],
        ':sale_price'  => $data['sale_price'] ?: null,
        ':stock'       => $data['stock'],
        ':low_stock_threshold' => $data['low_stock_threshold'] ?? 5,
        ':badge'       => $data['badge'] ?: null,
        ':is_active'   => !empty($data['is_active']) ? 1 : 0,
    ];

    if (!empty($data['image'])) {
        $sql .= ', image = :image';
        $params[':image'] = $data['image'];
    }

    $sql .= ' WHERE id = :id';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
}

function product_reactivate(int $id): void
{
    $stmt = db()->prepare('UPDATE products SET is_active = 1 WHERE id = ?');
    $stmt->execute([$id]);
}

function product_delete(int $id): void
{
    $stmt = db()->prepare('UPDATE products SET is_active = 0 WHERE id = ?');
    $stmt->execute([$id]);
}

/** Resolves a product's uploaded photo to a browser URL, or null if it has none. */
function product_image_url(array $product): ?string
{
    if (empty($product['image'])) {
        return null;
    }
    if (preg_match('#^https?://#i', (string) $product['image'])) {
        return $product['image'];
    }
    $base = defined('BASE_PATH') ? BASE_PATH : '';
    return $base . '/uploads/' . rawurlencode($product['image']);
}

/**
 * The product's photos, main one first: the uploaded photo plus two close-ups
 * cut from it by product_view.php. Site-relative URLs; empty with no photo.
 */
function product_gallery(array $product): array
{
    $main = product_image_url($product);
    if ($main === null || preg_match('#^https?://#i', (string) $product['image'])) {
        return $main === null ? [] : [$product['image']];
    }
    $base = defined('BASE_PATH') ? BASE_PATH : '';
    $file = rawurlencode((string) $product['image']);
    return [
        $main,
        $base . '/product_view.php?file=' . $file . '&v=2',
        $base . '/product_view.php?file=' . $file . '&v=3',
    ];
}

/**
 * Approved-review average and count for many products in one query:
 * [product_id => ['avg' => 4.5, 'count' => 12]]. Products with no reviews
 * are absent.
 */
function product_ratings(array $productIds): array
{
    $ids = array_values(array_unique(array_map('intval', $productIds)));
    if (!$ids) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT product_id, AVG(rating) AS avg_rating, COUNT(*) AS cnt
         FROM product_reviews WHERE status = 'approved' AND product_id IN ($marks)
         GROUP BY product_id"
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['product_id']] = [
            'avg'   => round((float) $row['avg_rating'], 1),
            'count' => (int) $row['cnt'],
        ];
    }
    return $out;
}

/** Shop sort options, in menu order. */
const PRODUCT_SORTS = [
    'featured'   => 'Featured',
    'newest'     => 'Newest',
    'price_asc'  => 'Price: low to high',
    'price_desc' => 'Price: high to low',
    'rating'     => 'Top rated',
];
/** Shop price bands: key => [label, min, max]. */
const PRODUCT_PRICE_BANDS = [
    'under5' => ['Under ₱5k', 0, 4999.99],
    '5to8'   => ['₱5k–8k', 5000, 8000],
    'over8'  => ['Over ₱8k', 8000.01, PHP_FLOAT_MAX],
];

/**
 * The shop grid's sort and filter, applied to an already-fetched list.
 * $f: sort, size, price (a PRODUCT_PRICE_BANDS key), sale (bool), instock (bool).
 * The app does the same in its shopView custom function.
 */
function product_filter_sort(array $products, array $f, array $ratings): array
{
    $band = PRODUCT_PRICE_BANDS[$f['price'] ?? ''] ?? null;
    $size = (string) ($f['size'] ?? '');
    $out = array_values(array_filter($products, function (array $p) use ($f, $band, $size): bool {
        $price = product_display_price($p);
        if ($band && ($price < $band[1] || $price > $band[2])) {
            return false;
        }
        if (!empty($f['sale']) && $p['sale_price'] === null) {
            return false;
        }
        if (!empty($f['instock']) && (int) $p['stock'] < 1) {
            return false;
        }
        if ($size !== '' && !in_array($size, product_available_sizes((int) $p['id']), true)) {
            return false;
        }
        return true;
    }));

    $rating = fn (array $p) => $ratings[(int) $p['id']]['avg'] ?? 0;
    switch ($f['sort'] ?? 'featured') {
        case 'newest':
            usort($out, fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
            break;
        case 'price_asc':
            usort($out, fn ($a, $b) => product_display_price($a) <=> product_display_price($b));
            break;
        case 'price_desc':
            usort($out, fn ($a, $b) => product_display_price($b) <=> product_display_price($a));
            break;
        case 'rating':
            usort($out, fn ($a, $b) => $rating($b) <=> $rating($a));
            break;
    }
    return $out;
}

function product_display_price(array $product): float
{
    return $product['sale_price'] !== null ? (float) $product['sale_price'] : (float) $product['price'];
}

/* ---------------------------------------------------------------------------
 * Per-size stock.
 *
 * products.stock stays the single source of truth for a product's TOTAL — the
 * catalogue, the low-stock alerts and the admin list all read it. When size rows
 * exist it is kept equal to their sum, so nothing downstream had to change.
 *
 * A product with no size rows behaves exactly as before. That is what lets the
 * catalogue be converted gradually instead of every shoe reading out-of-stock
 * until all 45 have been counted.
 * ------------------------------------------------------------------------- */

/** The sizes the storefront offers, in display order. Matches includes/cart.php. */
const PRODUCT_SIZES = ['7', '8', '9', '10', '11', '12'];

/** ['7' => 3, '9' => 1, ...] for products that have per-size stock; [] if not. */
function product_sizes_for(int $productId): array
{
    $stmt = db()->prepare('SELECT size, stock FROM product_sizes WHERE product_id = ?');
    $stmt->execute([$productId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(string) $row['size']] = (int) $row['stock'];
    }
    return $out;
}

/** Whether this product is managed per size at all. */
function product_has_size_stock(int $productId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM product_sizes WHERE product_id = ? LIMIT 1');
    $stmt->execute([$productId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * How many of this product in this size can still be sold.
 *
 * Falls back to the product total when sizes are not being tracked, which keeps
 * every existing caller correct during the migration.
 */
function product_stock_for_size(int $productId, string $size): int
{
    $sizes = product_sizes_for($productId);
    if (!$sizes) {
        $p = product_find($productId);
        return $p ? (int) $p['stock'] : 0;
    }
    return (int) ($sizes[$size] ?? 0);
}

/** Which sizes a shopper can actually buy right now. */
function product_available_sizes(int $productId): array
{
    $sizes = product_sizes_for($productId);
    if (!$sizes) {
        // Not tracked per size: every offered size is available while the
        // product itself has stock.
        $p = product_find($productId);
        return ($p && (int) $p['stock'] > 0) ? PRODUCT_SIZES : [];
    }
    return array_values(array_filter(
        PRODUCT_SIZES,
        static fn(string $s): bool => ($sizes[$s] ?? 0) > 0
    ));
}

/**
 * Admin: replaces this product's size breakdown and re-derives products.stock
 * from the sum, so the total can never drift from the parts.
 *
 * Passing all zeros removes the rows entirely, returning the product to a single
 * stock number — an escape hatch if per-size turns out to be the wrong call for
 * some item.
 */
function product_sizes_save(int $productId, array $sizeStock): void
{
    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM product_sizes WHERE product_id = ?')->execute([$productId]);

        $total = 0;
        $ins = $db->prepare(
            'INSERT INTO product_sizes (product_id, size, stock) VALUES (?, ?, ?)'
        );
        foreach (PRODUCT_SIZES as $size) {
            $qty = max(0, (int) ($sizeStock[$size] ?? 0));
            if ($qty > 0) {
                $ins->execute([$productId, $size, $qty]);
                $total += $qty;
            }
        }

        if ($total > 0) {
            $db->prepare('UPDATE products SET stock = ? WHERE id = ?')
               ->execute([$total, $productId]);
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/** Takes stock off one size and off the product total together. */
function product_decrement_size_stock(int $productId, string $size, int $qty): void
{
    db()->prepare(
        'UPDATE product_sizes SET stock = GREATEST(stock - ?, 0)
         WHERE product_id = ? AND size = ?'
    )->execute([$qty, $productId, $size]);
}
