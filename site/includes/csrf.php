<?php
/**
 * Cross-site request forgery protection.
 *
 * There were fourteen POST forms on this site and not one carried a token,
 * including the admin forms for order status, product edits and store
 * settings. A page on another domain could make a logged-in admin's browser
 * submit any of them.
 *
 * The conventional fix is a hidden field in every form and a check in every
 * handler. That is twenty-odd files, most of which would have to be fetched,
 * hand-edited and re-uploaded — and a slip in any one of them breaks a working
 * checkout. So the field is injected into the response instead, and the check
 * runs before any handler sees the request. One file, no form left out, and
 * nothing to remember when the next form is written.
 *
 * ponytail: output-buffer injection rather than per-form fields. It covers
 * every form at once and cannot be forgotten, but it only sees server-rendered
 * HTML — a form built by JavaScript after load would need csrf_field() inlined
 * by hand. If this site grows those, inline the field per form and delete the
 * injector.
 */

/** This session's token, minted once and reused. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** The hidden input, for inlining into a form by hand. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token()) . '">';
}

/** Whether this POST carried the right token. */
function csrf_valid(): bool
{
    $sent = $_POST['_csrf'] ?? '';
    return is_string($sent)
        && $sent !== ''
        && !empty($_SESSION['csrf_token'])
        // Constant time: a plain === leaks how much of the token was right.
        && hash_equals($_SESSION['csrf_token'], $sent);
}

/**
 * Refuses a POST that did not come from one of our own pages.
 *
 * Deliberately blunt: no partial trust, no "admins only". A 403 here should be
 * a bug report rather than a customer-facing state, because every real form
 * gets a token injected by csrf_autoinject().
 */
function csrf_guard(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    if (csrf_valid()) {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8">'
        . '<title>Request blocked</title>'
        . '<div style="font:16px/1.5 system-ui;max-width:34em;margin:12vh auto;padding:0 1em;">'
        . '<h1 style="font-size:1.4em;">That request could not be verified</h1>'
        . '<p>Your session may have expired, or the form was submitted from '
        . 'somewhere other than this site. Go back, reload the page and try '
        . 'again.</p>'
        . '</div>';
    exit;
}

/**
 * Puts the hidden field into every POST form on the way out.
 *
 * Only touches HTML: anything that has set a Content-Type of its own — an
 * image from serve_image.php, JSON from an API, the service worker — passes
 * through untouched, as does any response with no form in it.
 */
function csrf_autoinject(): void
{
    ob_start(static function (string $html): string {
        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') === 0
                && stripos($header, 'text/html') === false) {
                return $html; // not a page; leave it exactly as it is
            }
        }
        if (stripos($html, '<form') === false) {
            return $html;
        }

        $field = csrf_field();

        return preg_replace_callback(
            '/<form\b[^>]*>/i',
            static function (array $m) use ($field): string {
                // A GET form needs no token, and adding one would drop the
                // value into the query string of every search.
                if (preg_match('/method\s*=\s*["\']?post["\']?/i', $m[0]) !== 1) {
                    return $m[0];
                }
                return $m[0] . $field;
            },
            $html
        ) ?? $html;
    });
}
