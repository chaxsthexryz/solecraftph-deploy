<?php
/**
 * Google sign-in, shared by the website (google_login.php) and the app
 * (api/auth.php?action=google). Both hand over a Google ID token; this checks
 * it with Google and maps it to an account here.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/google.php';

/**
 * The claims of a genuine, unexpired Google ID token issued for our client
 * with a verified email, or null.
 *
 * ponytail: asks Google's tokeninfo endpoint instead of verifying the JWT
 * signature locally against Google's published keys. One HTTPS call per
 * sign-in; switch to cached-JWKS verification if sign-ins ever get busy.
 */
function google_verify_id_token(string $idToken): ?array
{
    if (GOOGLE_WEB_CLIENT_ID === '' || $idToken === '') {
        return null;
    }

    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $status !== 200) {
        return null;
    }

    $c = json_decode($body, true);
    if (!is_array($c)) {
        return null;
    }

    // aud is the whole point: without it, a token Google issued to any other
    // app would sign someone in here.
    $ok = ($c['aud'] ?? '') === GOOGLE_WEB_CLIENT_ID
        && in_array($c['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
        && in_array($c['email_verified'] ?? null, ['true', true], true)
        && (int) ($c['exp'] ?? 0) > time()
        && filter_var($c['email'] ?? '', FILTER_VALIDATE_EMAIL);

    return $ok ? $c : null;
}

/**
 * The account for a verified Google identity: [user row, null] or [null, error].
 *
 * Matched by email — Google has just proved the person owns it, so an account
 * registered with that address is theirs. A new email gets a new customer
 * account with a random password nobody knows; "Forgot password" sets a real
 * one if they ever want to sign in without Google.
 *
 * Admin accounts are refused: one Google account should not be all it takes to
 * reach the admin panel. Admins keep signing in with their password.
 */
function google_user_for(array $claims): array
{
    $db    = db();
    $email = strtolower(trim($claims['email']));
    $find  = $db->prepare(
        'SELECT id, username, full_name, email, phone, address, role, status
           FROM users WHERE LOWER(email) = ? LIMIT 1'
    );
    $find->execute([$email]);
    $user = $find->fetch();

    if (!$user) {
        // Username from the part before the @, made unique with a number.
        $base = substr(preg_replace('/[^a-z0-9_]/', '', strstr($email, '@', true)) ?: 'shopper', 0, 40);
        $taken = $db->prepare('SELECT 1 FROM users WHERE username = ?');
        $username = $base;
        for ($n = 2; ; $n++) {
            $taken->execute([$username]);
            if (!$taken->fetchColumn()) {
                break;
            }
            $username = $base . $n;
        }

        $db->prepare(
            'INSERT INTO users (username, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, "customer")'
        )->execute([
            $username,
            $email,
            password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            trim((string) ($claims['name'] ?? '')) ?: null,
        ]);
        $find->execute([$email]);
        $user = $find->fetch();
    }

    if (($user['status'] ?? 'active') === 'suspended') {
        return [null, 'This account has been suspended.'];
    }
    if (($user['role'] ?? '') === 'admin') {
        return [null, 'Admin accounts sign in on the admin site.'];
    }
    return [$user, null];
}
