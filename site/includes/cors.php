<?php
/**
 * CORS for the JSON API.
 *
 * Every endpoint used to answer `Access-Control-Allow-Origin: *`, including the
 * ones returning a customer's addresses, orders and profile.
 *
 * That was not exploitable, and it is worth being accurate about why: these
 * endpoints authenticate with an Authorization header, not a cookie, so a
 * browser attaches no credentials of its own and the wildcard cannot legally be
 * combined with any. It was a loaded gun with the safety on. The day someone
 * adds a cookie, a session fallback or a web client — in a different file,
 * written by someone who never read this one — the wildcard is what turns that
 * into a data leak.
 *
 * The native app is unaffected either way: it sends no Origin header and never
 * preflights. Same-origin pages on this site do not consult CORS at all. What
 * changes is that an arbitrary website can no longer read a response.
 */
function api_cors_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        // The native app, or a same-origin request. Nothing to negotiate, and
        // emitting a header here would only invite one to be trusted.
        return;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return;
    }

    // Only this site. Both schemes because the host answers plain HTTP with a
    // redirect rather than a refusal, and a request can legitimately originate
    // from the pre-redirect page.
    $allowed = ['https://' . $host, 'http://' . $host];

    if (in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        // The answer now depends on who asked, so a cache must not hand one
        // origin's response to another.
        header('Vary: Origin');
    }
}
