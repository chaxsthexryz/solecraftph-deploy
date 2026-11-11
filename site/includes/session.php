<?php
/**
 * Session start, in one place.
 *
 * auth.php and cart.php both used to call session_start() themselves, which
 * meant the cookie was created with PHP's defaults: no HttpOnly, no SameSite.
 * Without HttpOnly any script on the page can read the session cookie through
 * document.cookie, which turns a cross-site scripting bug into a full account
 * takeover — an admin's included. Setting it here means whichever file gets
 * there first sets the flags, and neither has to remember.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        // Safe to assert: the host answers plain HTTP with a 301 to HTTPS, so
        // there is no insecure context left for this cookie to travel in.
        'secure'   => true,
        'httponly' => true,
        // Lax still sends the cookie when a customer follows a link from an
        // order email, but withholds it on a cross-site POST — which is most
        // of what CSRF needs. Not a substitute for tokens, but it is free.
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ---------------------------------------------------------------------------
 * CSRF, for browser requests only.
 *
 * The API and the PayMongo webhook are deliberately exempt. They authenticate
 * with a bearer token and a signature respectively, carry JSON rather than a
 * form, and have no session to hang a token on — guarding them would break the
 * mobile app and every payment confirmation without adding protection they do
 * not already have.
 * ------------------------------------------------------------------------- */
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
if (strpos($scriptPath, '/api/') === false && strpos($scriptPath, '/webhook/') === false) {
    require_once __DIR__ . '/csrf.php';
    csrf_guard();      // refuse a forged POST before any handler runs
    csrf_autoinject(); // and make sure every real form carries a token
}
