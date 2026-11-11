<?php
/**
 * Image proxy — serves files from /uploads/ with a guaranteed CORS header.
 * Use this instead of linking directly to /uploads/<file> when .htaccess
 * Header directives aren't being honored by the host.
 *
 * Usage: /api/serve_image.php?file=prod_6a8da9f3a2120.jpg
 */

$file = basename($_GET['file'] ?? ''); // basename() blocks directory traversal
if ($file === '') {
    http_response_code(400);
    exit('Missing file parameter');
}

$path = __DIR__ . '/../uploads/' . $file;

if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$mime = mime_content_type($path) ?: 'application/octet-stream';

header('Access-Control-Allow-Origin: *');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=604800');

readfile($path);
