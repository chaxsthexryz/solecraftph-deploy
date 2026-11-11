<?php
/**
 * PayMongo credentials.
 *
 * Shared hosting (Hostinger included) generally doesn't expose a working
 * PHP-level environment variable panel, so getenv() alone won't pick up
 * anything set in hPanel. Instead this loads a small keys file that sits
 * next to this one but is never uploaded to any public repo — put the
 * real values only in that file, never in this one.
 */

$__paymongoKeysFile = __DIR__ . '/paymongo_keys.php';
if (is_file($__paymongoKeysFile)) {
    require_once $__paymongoKeysFile; // defines the three constants below
} else {
    define('PAYMONGO_SECRET_KEY', getenv('PAYMONGO_SECRET_KEY') ?: '');
    define('PAYMONGO_PUBLIC_KEY', getenv('PAYMONGO_PUBLIC_KEY') ?: '');
    define('PAYMONGO_WEBHOOK_SECRET', getenv('PAYMONGO_WEBHOOK_SECRET') ?: '');
}
unset($__paymongoKeysFile);