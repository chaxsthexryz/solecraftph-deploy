<?php
/**
 * Content Management Service.
 * Static pages (About, FAQ, Privacy...) + homepage banners/promotions.
 */
require_once __DIR__ . '/../config/db.php';

function cms_page_find(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM cms_pages WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function cms_page_list(): array
{
    $stmt = db()->query('SELECT * FROM cms_pages ORDER BY title');
    return $stmt->fetchAll();
}

function cms_page_save(string $slug, string $title, string $content): void
{
    $stmt = db()->prepare(
        'INSERT INTO cms_pages (slug, title, content) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content)'
    );
    $stmt->execute([$slug, $title, $content]);
}

function cms_page_find_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM cms_pages WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Banners shown on the storefront */
function banner_list_active(): array
{
    $stmt = db()->query('SELECT * FROM banners WHERE is_active = 1 ORDER BY sort_order ASC, id DESC');
    return $stmt->fetchAll();
}

function banner_list_all(): array
{
    $stmt = db()->query('SELECT * FROM banners ORDER BY sort_order ASC, id DESC');
    return $stmt->fetchAll();
}

function banner_create(string $title, string $subtitle, string $linkUrl, int $sortOrder, string $imageUrl = ''): int
{
    $stmt = db()->prepare(
        'INSERT INTO banners (title, subtitle, link_url, sort_order, image_url, is_active) VALUES (?, ?, ?, ?, ?, 1)'
    );
    $stmt->execute([$title, $subtitle, $linkUrl, $sortOrder, $imageUrl]);
    return (int) db()->lastInsertId();
}

/** Adds banners.video_url the first time the admin page runs after deploy. */
function banner_ensure_video_column(): void
{
    if (!db()->query("SHOW COLUMNS FROM banners LIKE 'video_url'")->fetch()) {
        db()->exec("ALTER TABLE banners ADD COLUMN video_url VARCHAR(255) NOT NULL DEFAULT ''");
    }
}

function banner_set_video(int $id, string $videoUrl): void
{
    db()->prepare('UPDATE banners SET video_url = ? WHERE id = ?')->execute([$videoUrl, $id]);
}

function banner_toggle(int $id): void
{
    db()->prepare('UPDATE banners SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
}

function banner_delete(int $id): void
{
    // The uploaded image is deliberately left on disk. Deleting it would be the
    // only destructive file operation in this admin, for the sake of a few
    // kilobytes, and getting it wrong costs a picture still in use by a banner
    // someone recreated. Orphans are cheap; a wrong unlink is not.
    db()->prepare('DELETE FROM banners WHERE id = ?')->execute([$id]);
}

/**
 * Saves an uploaded promo banner image and returns its stored path.
 *
 * ['url' => 'uploads/banner_xxx.jpg', 'error' => null]  saved
 * ['url' => '', 'error' => null]                        no file chosen — text banner
 * ['url' => '', 'error' => '...']                       rejected
 *
 * Lands in uploads/ alongside product photos on purpose: that directory
 * already has an .htaccess denying php/phtml/phar/cgi execution and opening
 * CORS for image types. A new folder would mean a second copy of those rules
 * to keep in step, and the day one copy drifts is the day an upload directory
 * executes PHP.
 */
function banner_handle_image_upload(string $field = 'image'): array
{
    if (empty($_FILES[$field]['name'])) {
        return ['url' => '', 'error' => null];
    }

    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        // Worth naming this one: the usual cause is a banner bigger than the
        // server's upload_max_filesize, and "please try again" sends someone
        // round the same loop with the same file.
        $tooBig = in_array(
            $_FILES[$field]['error'],
            [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE],
            true
        );
        return [
            'url'   => '',
            'error' => $tooBig
                ? 'That image is larger than the server accepts. Export it smaller and try again.'
                : 'The image upload failed (error code ' . $_FILES[$field]['error'] . '). Please try again.',
        ];
    }

    // The extension comes from the *detected* image type, never from the name
    // the browser sent, and the filename is generated here — so nothing a
    // caller uploads can choose where it lands or what it ends up called.
    $detected  = @getimagesize($_FILES[$field]['tmp_name']);
    $extByType = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF  => 'gif',
    ];
    if ($detected === false || !isset($extByType[$detected[2]])) {
        return ['url' => '', 'error' => 'That file is not a JPG, PNG, WEBP or GIF image.'];
    }
    $ext = $extByType[$detected[2]];

    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['url' => '', 'error' => 'The uploads folder could not be created. Check folder permissions.'];
    }
    if (!is_writable($dir)) {
        return ['url' => '', 'error' => 'The uploads folder is not writable. Check folder permissions.'];
    }

    $name = uniqid('banner_') . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $name)) {
        return ['url' => '', 'error' => 'Could not save the uploaded image to the server.'];
    }

    // Stored relative to the document root, so the row keeps working whether
    // the site is served from / or from a subfolder.
    return ['url' => 'uploads/' . $name, 'error' => null];
}

/**
 * Saves an uploaded promo video (a banner's, or the app's welcome video) in
 * uploads/ and returns its stored path, in the same shape as
 * banner_handle_image_upload().
 *
 * MP4 or MOV, checked from the file's own bytes (the "ftyp" box every
 * MPEG-4/QuickTime file starts with), never from its name. Both phones play
 * these; the file is always saved as .mp4 under a generated name.
 */
function video_handle_upload(string $field, string $prefix): array
{
    if (empty($_FILES[$field]['name'])) {
        return ['url' => '', 'error' => 'Choose a video file first.'];
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        $tooBig = in_array($_FILES[$field]['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        return [
            'url'   => '',
            'error' => $tooBig
                ? 'That video is larger than the server accepts (' . ini_get('upload_max_filesize') . '). Export it shorter or smaller and try again.'
                : 'The video upload failed (error code ' . $_FILES[$field]['error'] . '). Please try again.',
        ];
    }

    $head = @file_get_contents($_FILES[$field]['tmp_name'], false, null, 0, 12);
    if ($head === false || strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') {
        return ['url' => '', 'error' => 'That file is not an MP4 or MOV video.'];
    }

    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir) || !is_writable($dir)) {
        return ['url' => '', 'error' => 'The uploads folder is not writable. Check folder permissions.'];
    }

    $name = uniqid($prefix . '_') . '.mp4';
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $name)) {
        return ['url' => '', 'error' => 'Could not save the uploaded video to the server.'];
    }
    return ['url' => 'uploads/' . $name, 'error' => null];
}
