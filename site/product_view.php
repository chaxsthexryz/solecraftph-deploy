<?php
/**
 * Extra "angles" for a product photo: close-ups cut from the one photo every
 * product has, so each shoe shows three pictures until real ones are uploaded.
 *
 *   /product_view.php?file=prod_abc.jpg&v=2   front half, zoomed
 *   /product_view.php?file=prod_abc.jpg&v=3   back half, zoomed
 *
 * The catalog photos are side views on white with the shoe across the middle,
 * so the two crops are the left and right 60% squares of the centre band.
 * Each crop is made once and kept under uploads/views/.
 *
 * ponytail: fixed crop boxes tuned for side-view photos; replace with real
 * uploaded angles (a product_images table) when the shop has them.
 */
$file = basename((string) ($_GET['file'] ?? ''));
$view = (int) ($_GET['v'] ?? 0);
$src  = __DIR__ . '/uploads/' . $file;

if ($file === '' || !in_array($view, [2, 3], true) || !is_file($src)
    || !preg_match('/\.(jpe?g|png|webp)$/i', $file)) {
    http_response_code(404);
    exit;
}

$dir  = __DIR__ . '/uploads/views';
$dest = $dir . '/' . pathinfo($file, PATHINFO_FILENAME) . '-v' . $view . '.jpg';

if (!is_file($dest) || filemtime($dest) < filemtime($src)) {
    $img = @imagecreatefromstring((string) file_get_contents($src));
    if (!$img) {
        // Not something GD can read: show the original rather than nothing.
        header('Location: uploads/' . rawurlencode($file));
        exit;
    }
    $w    = imagesx($img);
    $h    = imagesy($img);
    $side = (int) round(min($w, $h) * 0.6);
    $x    = $view === 2 ? 0 : $w - $side;
    $y    = (int) round(($h - $side) / 2);
    $out  = imagecreatetruecolor($side, $side);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopy($out, $img, 0, 0, $x, $y, $side, $side);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    imagejpeg($out, $dest, 88);
    imagedestroy($img);
    imagedestroy($out);
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($dest));
header('Cache-Control: public, max-age=604800');
header('Access-Control-Allow-Origin: *');
readfile($dest);
