<?php
/**
 * Auto-detects the folder this app is installed in, so links like
 * "/index.php" or "/assets/css/style.css" resolve correctly whether
 * the app lives at the domain root (http://localhost/) or in a
 * subfolder (http://localhost/solecraftph/), with no manual config.
 */
if (!defined('BASE_PATH')) {
    $docRoot     = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $projectRoot = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');

    $base = '';
    if ($docRoot !== '' && stripos($projectRoot, $docRoot) === 0) {
        // Use the real (correctly-cased) suffix from $projectRoot, but match
        // case-insensitively since Windows/XAMPP paths can differ in case
        // between $_SERVER['DOCUMENT_ROOT'] and realpath().
        $base = substr($projectRoot, strlen($docRoot));
    }

    define('BASE_PATH', $base); // e.g. '' at domain root, or '/solecraftph' in a subfolder
}
