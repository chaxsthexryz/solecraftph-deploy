# SoleCraftPH — PHP / MySQL E-commerce App

A full footwear storefront covering the customer site, the admin backend,
and the shared services behind both.

**Customer Site**
- Home / discovery, search, category + subcategory filters (`index.php`)
- Registration / login / logout (`register.php`, `login.php`, `logout.php`)
- Profile: edit info, change password (`profile.php`)
- Wishlist / favorites (`wishlist.php`, heart button on cards + product page)
- Notifications: order updates, announcements (`notifications.php`)
- Product listing, detail page with reviews & ratings (`product.php`)
- Cart & checkout, dynamic shipping/payment methods (`cart.php`, `checkout.php`)
- Order history & tracking timeline (`orders.php`, `order_detail.php`)
- Return / refund requests (from `order_detail.php`)
- Contact / support form (`contact.php`)
- CMS-driven static pages: About, FAQ, Privacy (`page.php?slug=...`)

**Admin Side**
- Admin login (`admin/login.php`)
- Product management: add/edit/deactivate/reactivate, stock, low-stock
  threshold (`admin/add_product.php`, `admin/edit_product.php`, `admin/products.php`)
- Orders: list + filter by status, detail view, status updates with
  tracking history, printable invoice/packing slip
  (`admin/orders.php`, `admin/order_detail.php`, `admin/invoice.php`)
- Returns / refunds management (`admin/returns.php`)
- Review moderation (`admin/reviews.php`)
- Customer accounts: view, suspend/activate (`admin/customers.php`)
- Admin accounts: create/delete (`admin/admins.php`)
- Sales & analytics: revenue chart, top products, top customers
  (`admin/reports.php`)
- Content management: static pages + homepage banners/promotions
  (`admin/cms.php`, `admin/banners.php`)
- Settings: currency, shipping, payment method toggles, contact info
  (`admin/settings.php`)
- Support inbox for contact form submissions (`admin/messages.php`)
- System notifications: new orders, low stock (`admin/notifications.php`)
- Audit trail of admin actions (`admin/audit_log.php`)
- Dashboard: stats + low-stock/returns/messages alerts (`admin/dashboard.php`)

**Backend Services** (`includes/*_service.php`)
- Product, Order, Wishlist, Review, Notification, Return, Contact, CMS,
  Settings, and Audit services — each a small set of functions over PDO
- Basic Authentication (bcrypt + PHP sessions), now with profile fields,
  password change, and account suspension — `includes/auth.php`
- Shared JSON API for web/mobile clients — `api/products.php`, `api/orders.php`

## 1. Requirements
- PHP 8.1+ with `pdo_mysql` extension
- MySQL 5.7+ / MariaDB 10.4+

## 2. Setup

```bash
# 1. Import the schema (creates the database, a dedicated app DB user,
#    a default admin account, and sample products), then the v2 upgrade
#    (wishlist, reviews, notifications, returns, CMS, settings, audit log)
mysql -u root -p < sql/schema.sql
mysql -u root -p < sql/upgrade_v2.sql

# 2. Point the app at your database (defaults already match the schema's
#    dedicated user, override via environment variables if needed)
export DB_HOST=127.0.0.1
export DB_NAME=solecraftph
export DB_USER=solecraft
export DB_PASS=solecraft_pw

# 3. Serve the app (from the project root, so /admin, /api, /assets resolve)
php -S 127.0.0.1:8000
```

Then visit `http://127.0.0.1:8000/index.php`.

For a production Apache/Nginx setup, point the document root at this
project folder so `/admin/*`, `/api/*`, and `/assets/*` resolve as shown
above (no special rewrite rules are required).

### Shared hosting (InfinityFree, and similar — no CREATE DATABASE/USER rights)

`sql/schema.sql` above assumes you have full MySQL admin rights (it
creates the database itself and a dedicated `solecraft` DB user via
`CREATE DATABASE` / `CREATE USER` / `GRANT`). Shared hosts like
InfinityFree don't allow any of that — your host's control panel
already created your database (e.g. `if0_42563506_solecraftph`) and
your one DB user already owns it. Running `sql/schema.sql` there fails
with `Access denied ... to database 'solecraftph'`.

Use `sql/schema_shared_hosting.sql` instead — it's identical except it
skips the `CREATE DATABASE`/`CREATE USER`/`GRANT`/`USE` statements:

1. In phpMyAdmin, click into your actual database in the left sidebar
   first (so it's the one selected/open).
2. Go to the **SQL** tab and paste in `sql/schema_shared_hosting.sql`
   (or use **Import**).
3. Do the same with `sql/upgrade_v2.sql` (it has no `USE` statement
   either, for the same reason).
4. Update `config/db.php` with the host's real `DB_HOST`/`DB_NAME`/
   `DB_USER`/`DB_PASS` (shown on your host's MySQL Connection Details
   page) — not the `solecraft`/`solecraft_pw` defaults above.

## 3. Default Admin Login
- URL: `/admin/login.php`
- Username: `admin`
- Password: `Admin@123`

**Change this password** (or create a new admin user with a fresh
`password_hash()` value) before using this in anything beyond local
testing.

## 4. Project Structure

```
config/db.php          PDO connection (env-configurable)
includes/auth.php       Basic auth (bcrypt + session)
includes/product_service.php   Product Service (list/search/create/stock)
includes/order_service.php     Order Service (checkout, order lookup)
includes/cart.php       Session-based shopping cart
includes/header.php     Shared page header/nav
includes/footer.php     Shared page footer
index.php               Storefront home + search + category filter
product.php              Single product page
cart.php                 Cart view + add/update/remove
checkout.php              Checkout form -> creates an order
order_success.php         Order confirmation
admin/login.php            Admin login
admin/logout.php
admin/dashboard.php        Stats + recent orders
admin/add_product.php      Add Product to Inventory
admin/products.php         Inventory management (restock/deactivate)
admin/orders.php           Full orders list
api/products.php           Shared JSON API: list/search/create products
api/orders.php              Shared JSON API: create/fetch orders
sql/schema.sql              Full database schema + seed data
assets/css/style.css        Shared stylesheet
uploads/                    Uploaded product images (admin add-product form)
```

## 5. Deploying in a subfolder (e.g. XAMPP/WAMP htdocs)
If you access the site at a URL like `http://localhost/solecraftph/`
rather than the domain root, `config/app.php` auto-detects the subfolder
by comparing your web server's document root to the project folder, and
every link/form/stylesheet reference in the app is built from that
(`BASE_PATH`) automatically — no manual configuration needed, and it
works if you rename the folder too.

## 6. Managing inventory (admin)
- **Inventory** (`admin/products.php`) now lists every product, including
  deactivated ones (with a thumbnail, Edit link, and Status column).
- **Edit** opens `admin/edit_product.php` where you can change any field
  — name, category, subcategory, price, sale price, stock, badge,
  description — and optionally replace the photo. Leave the photo field
  empty to keep the existing image.

### Category taxonomy
Products are organized into 3 main categories, each with 3 subcategories
(defined in `PRODUCT_TAXONOMY` in `includes/product_service.php`). The
Add/Edit Product forms use cascading dropdowns built from this list, and
the storefront (`index.php`) shows the main categories as filter pills,
with subcategory pills appearing once a category is selected:

- **Athletic & Performance Footwear** — Road Running Shoes, Trail Running
  Shoes, Training & Gym Shoes
- **Casual & Lifestyle Footwear** — Everyday Lifestyle Sneakers,
  Slip-On & Canvas Shoes, Retro & Heritage Sneakers
- **Formal & Dress Footwear** — Oxfords & Derby Shoes, Loafers & Dress
  Slip-On Shoes, Dress Boots

The seed data in `sql/schema.sql` includes 5 sample products per
subcategory (45 total), with no images — add product photos later
through the admin panel.
- **Deactivate / Reactivate**: "Deactivate" hides a product from the
  storefront without deleting it; it now stays visible in the admin list
  (greyed out) so you can "Reactivate" it later.
- Product photos are served from `/uploads/` and shown on the homepage,
  product page, and admin table; if a product has no photo, the site
  falls back to the default shoe illustration automatically.

## 7. Homepage hero (your own video, your own photos, or auto slideshow)
The homepage header checks these in order and uses the first one it finds:
1. **Your own video** — drop a file named `hero.mp4` into `assets/media/`.
   It plays automatically as a looping, muted background video.
2. **Your own promo photos** — drop any `.jpg`/`.jpeg`/`.png`/`.webp`/`.gif`
   files into `assets/media/hero/`. They're used as an independent
   crossfading slideshow — these do **not** need to be product photos,
   they can be pure marketing/promo images. Files are shown in filename
   order (e.g. `1-drop.jpg`, `2-drop.jpg`, `3-drop.jpg`).
3. **Automatic fallback** — if neither of the above exists, the header
   auto-builds a slideshow from your most recent product photos (up to
   6) so the homepage is never empty once you start adding inventory.
4. If nothing is available yet, the header just shows the plain text
   banner (no broken placeholders).

You can freely mix and match — swap files in/out any time, nothing
needs to be configured in code.

## 8. Notes
- The cart is stored in the PHP session (no login required to shop);
  checkout collects delivery details and writes the order to MySQL.
- Stock is decremented automatically when an order is placed
  (`order_service.php`), inside a DB transaction.
- `api/products.php` and `api/orders.php` are plain JSON endpoints so the
  same backend can serve a future mobile app without changes.
- This was built and smoke-tested end to end (search, add-to-cart,
  checkout → order persisted, admin login, add-product, and the JSON API)
  against a local MariaDB instance.
