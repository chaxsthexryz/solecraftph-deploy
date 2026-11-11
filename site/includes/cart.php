<?php
/**
 * Shopping cart — size-aware, and account-backed once you're signed in.
 *
 * Signed in  -> rows in `cart_items`, keyed to user_id. The website and the
 *               mobile app both read this table, so a bag filled on the phone
 *               is the same bag the browser shows.
 * Guest      -> the old $_SESSION['cart'], merged into the account cart on login.
 *
 * A "line" is one product at one size, so the same shoe in 9 and in 10 are two
 * separate lines. Line key = "<product_id>|<size>".
 *
 * Name/price/image are NOT stored on the line. They're read from `products`
 * every time the cart is listed, so a price change or a rename shows up in an
 * open cart instead of going stale — and a client can't dictate its own price.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/product_service.php';

/** The sizes the storefront offers. Matches the mobile app's size buttons. */
const CART_SIZES = ['7', '8', '9', '10', '11', '12'];

/**
 * Whose cart are we touching? The website has a PHP session; the mobile app
 * sends a bearer token. Either resolves to a user_id, and no user_id means
 * "guest" — session cart.
 */
function cart_user_id(): ?int
{
    if (!empty($_SESSION['user_id'])) {
        return (int) $_SESSION['user_id'];
    }
    if (function_exists('auth_user_id_from_bearer_token')) {
        return auth_user_id_from_bearer_token();
    }
    return null;
}

function cart_line_key(int $productId, string $size): string
{
    return $productId . '|' . $size;
}

/** Keeps a submitted size to one of the offered ones; anything else becomes ''. */
function cart_normalize_size(?string $size): string
{
    $size = trim((string) $size);
    return in_array($size, CART_SIZES, true) ? $size : '';
}

function cart_add(int $productId, int $qty = 1, string $size = ''): void
{
    $product = product_find($productId);
    if (!$product || $product['stock'] < 1) {
        return;
    }
    $size = cart_normalize_size($size);
    $qty  = max(1, $qty);
    $userId = cart_user_id();

    if ($userId === null) {
        $key = cart_line_key($productId, $size);
        $existing = (int) ($_SESSION['cart'][$key]['qty'] ?? 0);
        // ponytail: stock is per product, not per size, so both sizes of one shoe
        // clamp against the same pool. Per-size stock needs a product_sizes table.
        $_SESSION['cart'][$key] = [
            'product_id' => $productId,
            'size'       => $size,
            'qty'        => min($existing + $qty, (int) $product['stock']),
        ];
        return;
    }

    $stmt = db()->prepare(
        'INSERT INTO cart_items (user_id, product_id, size, qty) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE qty = LEAST(qty + VALUES(qty), ?)'
    );
    $stmt->execute([$userId, $productId, $size, min($qty, (int) $product['stock']), (int) $product['stock']]);
}

function cart_update(int $productId, int $qty, string $size = ''): void
{
    if ($qty <= 0) {
        cart_remove($productId, $size);
        return;
    }
    $product = product_find($productId);
    if (!$product) {
        return;
    }
    $qty    = min($qty, (int) $product['stock']);
    $size   = cart_normalize_size($size);
    $userId = cart_user_id();

    if ($userId === null) {
        $key = cart_line_key($productId, $size);
        if (isset($_SESSION['cart'][$key])) {
            $_SESSION['cart'][$key]['qty'] = $qty;
        }
        return;
    }
    db()->prepare('UPDATE cart_items SET qty = ? WHERE user_id = ? AND product_id = ? AND size = ?')
        ->execute([$qty, $userId, $productId, $size]);
}

function cart_remove(int $productId, string $size = ''): void
{
    $size   = cart_normalize_size($size);
    $userId = cart_user_id();

    if ($userId === null) {
        unset($_SESSION['cart'][cart_line_key($productId, $size)]);
        return;
    }
    db()->prepare('DELETE FROM cart_items WHERE user_id = ? AND product_id = ? AND size = ?')
        ->execute([$userId, $productId, $size]);
}

/**
 * @return array line key => ['product_id','size','name','price','image','qty']
 */
function cart_items(): array
{
    $userId = cart_user_id();
    $rows   = [];

    if ($userId === null) {
        foreach ($_SESSION['cart'] ?? [] as $key => $line) {
            // Carts saved before sizes existed were keyed by bare product id.
            $rows[] = [
                'product_id' => (int) ($line['product_id'] ?? $key),
                'size'       => (string) ($line['size'] ?? ''),
                'qty'        => (int) $line['qty'],
            ];
        }
    } else {
        $stmt = db()->prepare('SELECT product_id, size, qty FROM cart_items WHERE user_id = ? ORDER BY id ASC');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
    }

    $items = [];
    foreach ($rows as $row) {
        $product = product_find((int) $row['product_id']);
        if (!$product) {
            continue; // product deleted out from under an open cart
        }
        $items[cart_line_key((int) $row['product_id'], (string) $row['size'])] = [
            'product_id' => (int) $row['product_id'],
            'size'       => (string) $row['size'],
            'name'       => $product['name'],
            'price'      => product_display_price($product),
            'image'      => $product['image'],
            'qty'        => (int) $row['qty'],
            'stock'      => (int) $product['stock'],
        ];
    }
    return $items;
}

/** Subtotal of [$items] (cart_items() rows), or of the whole cart. */
function cart_total(?array $items = null): float
{
    $total = 0.0;
    foreach ($items ?? cart_items() as $item) {
        $total += $item['price'] * $item['qty'];
    }
    return $total;
}

function cart_count(): int
{
    $count = 0;
    foreach (cart_items() as $item) {
        $count += $item['qty'];
    }
    return $count;
}

/**
 * The lines ticked on cart.php for this checkout; the whole cart when nothing
 * was picked. A pick that no longer matches anything (lines removed in another
 * tab) falls back to the whole cart, which checkout then lists in full.
 */
function cart_checkout_items(): array
{
    $items = cart_items();
    $keys  = $_SESSION['checkout_keys'] ?? null;
    if (!is_array($keys)) {
        return $items;
    }
    $picked = array_intersect_key($items, array_flip($keys));
    return $picked ?: $items;
}

/** Takes an order's lines out of the cart and leaves everything else in it. */
function cart_remove_lines(array $lines): void
{
    foreach ($lines as $line) {
        cart_remove((int) $line['product_id'], (string) ($line['size'] ?? ''));
    }
    unset($_SESSION['checkout_keys']);
}

function cart_clear(): void
{
    $userId = cart_user_id();
    unset($_SESSION['cart']);
    if ($userId !== null) {
        db()->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$userId]);
    }
}

/**
 * Folds [$lines] into [$userId]'s cart, adding quantities rather than replacing
 * them. Used at the two moments a bag that was built anonymously has to survive
 * becoming an account's: the website's login, and the app's sign-in / register.
 *
 * Unlike cart_replace(), nothing already in the account cart is removed — this
 * is a union, so a cart filled on another device is still there afterwards.
 */
function cart_merge_lines(int $userId, array $lines): void
{
    if (!$lines) {
        return;
    }
    $stmt = db()->prepare(
        'INSERT INTO cart_items (user_id, product_id, size, qty) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE qty = LEAST(qty + VALUES(qty), ?)'
    );
    foreach ($lines as $key => $line) {
        // Session carts are keyed "<product_id>|<size>"; posted lines carry the
        // id on the line itself. Older session carts used a bare product id.
        $productId = (int) ($line['product_id'] ?? $line['id'] ?? explode('|', (string) $key)[0]);
        $product   = $productId > 0 ? product_find($productId) : null;
        if (!$product || $product['stock'] < 1) {
            continue;
        }
        $qty = min(max(1, (int) ($line['qty'] ?? 1)), (int) $product['stock']);
        $stmt->execute([
            $userId,
            $productId,
            cart_normalize_size((string) ($line['size'] ?? '')),
            $qty,
            (int) $product['stock'],
        ]);
    }
}

/**
 * Called right after a successful website login: whatever the visitor put in
 * their bag while logged out is folded into their account cart, then the
 * session copy is dropped.
 */
function cart_merge_session_into_account(int $userId): void
{
    $sessionCart = $_SESSION['cart'] ?? [];
    unset($_SESSION['cart']);
    cart_merge_lines($userId, $sessionCart);
}

/**
 * Replaces the whole cart with [$lines] — each ['product_id','size','qty'].
 *
 * This is what the mobile app pushes after every change to its on-device bag:
 * one call, whole list, so the phone and the server can't drift into a
 * half-applied state the way per-item calls can. Anything not in [$lines] is
 * gone afterwards — that is how a removal on the phone reaches the website.
 */
function cart_replace(array $lines): void
{
    $userId = cart_user_id();
    if ($userId === null) {
        unset($_SESSION['cart']);
        foreach ($lines as $line) {
            cart_add((int) ($line['product_id'] ?? $line['id'] ?? 0), (int) ($line['qty'] ?? 1), (string) ($line['size'] ?? ''));
        }
        return;
    }

    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$userId]);
        $stmt = $db->prepare(
            'INSERT INTO cart_items (user_id, product_id, size, qty) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)'
        );
        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? $line['id'] ?? 0);
            $product   = $productId > 0 ? product_find($productId) : null;
            if (!$product) {
                continue;
            }
            $qty = min(max(1, (int) ($line['qty'] ?? 1)), (int) $product['stock']);
            if ($qty < 1) {
                continue;
            }
            $stmt->execute([$userId, $productId, cart_normalize_size((string) ($line['size'] ?? '')), $qty]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
