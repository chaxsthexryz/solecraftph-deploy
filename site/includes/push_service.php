<?php
/**
 * Push Notification Service (Firebase Cloud Messaging, HTTP v1).
 *
 * The only file that knows how a push leaves the server, the same way
 * mail_service.php is the only file that knows how mail leaves. Callers just
 * say who and what; everything below — OAuth, token hygiene, transport — is
 * this file's problem.
 *
 * With no config/fcm.php it logs and returns. That is the normal state until
 * the Firebase service account key is added, and it means nothing else in the
 * app has to care whether push is configured yet.
 */
require_once __DIR__ . '/../config/db.php';

/** Where the OAuth access token is cached. Above public_html, like the logs. */
const PUSH_TOKEN_CACHE = __DIR__ . '/../../fcm_token.json';
const PUSH_LOG = __DIR__ . '/../../push.log';

function push_log(string $line): void
{
    @file_put_contents(
        PUSH_LOG,
        date('Y-m-d H:i:s') . ' ' . $line . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/** The service account, or null when push has not been configured yet. */
function push_config(): ?array
{
    static $config = null;
    static $loaded = false;

    if (!$loaded) {
        $loaded = true;
        $path = __DIR__ . '/../config/fcm.php';
        if (is_file($path)) {
            $values = require $path;
            if (is_array($values)
                && !empty($values['project_id'])
                && !empty($values['client_email'])
                && !empty($values['private_key'])) {
                $config = $values;
            }
        }
    }
    return $config;
}

/** base64url, which is what JWTs use and PHP has no builtin for. */
function push_b64(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/**
 * An OAuth2 access token for the FCM scope, minted from the service account
 * and cached until it nearly expires. Google's tokens last an hour, so without
 * the cache every status change would cost two round trips instead of one.
 */
function push_access_token(): ?string
{
    $config = push_config();
    if ($config === null) {
        return null;
    }

    if (is_file(PUSH_TOKEN_CACHE)) {
        $cached = json_decode((string) @file_get_contents(PUSH_TOKEN_CACHE), true);
        if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 60) {
            return $cached['token'];
        }
    }

    $now = time();
    $header = push_b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = push_b64(json_encode([
        'iss'   => $config['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $signature = '';
    if (!openssl_sign($header . '.' . $claims, $signature, $config['private_key'], 'sha256WithRSAEncryption')) {
        push_log('ERROR could not sign the service account JWT');
        return null;
    }
    $jwt = $header . '.' . $claims . '.' . push_b64($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $body, true);
    if ($status !== 200 || empty($decoded['access_token'])) {
        push_log('ERROR token exchange HTTP ' . $status . ' ' . substr((string) $body, 0, 300));
        return null;
    }

    @file_put_contents(PUSH_TOKEN_CACHE, json_encode([
        'token'      => $decoded['access_token'],
        'expires_at' => $now + (int) ($decoded['expires_in'] ?? 3600),
    ]), LOCK_EX);

    return $decoded['access_token'];
}

/** Every device this user has signed in on. */
function push_tokens_for_user(int $userId): array
{
    $stmt = db()->prepare('SELECT token FROM device_tokens WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/** Records a device against a user. One row per token, whoever last signed in on it. */
function push_register_token(int $userId, string $token, string $platform = 'android'): void
{
    db()->prepare(
        'INSERT INTO device_tokens (user_id, token, platform) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), platform = VALUES(platform), updated_at = NOW()'
    )->execute([$userId, $token, $platform]);
}

function push_forget_token(string $token): void
{
    db()->prepare('DELETE FROM device_tokens WHERE token = ?')->execute([$token]);
}

/**
 * Sends one notification to every device [userId] is signed in on.
 *
 * Silent and harmless when push is not configured — that is the expected
 * state until the service account key is in place.
 */
function push_send_to_user(int $userId, string $title, string $body): void
{
    $config = push_config();
    if ($config === null) {
        push_log('SKIP no config/fcm.php yet; user ' . $userId . ': ' . $title);
        return;
    }

    $tokens = push_tokens_for_user($userId);
    if (!$tokens) {
        push_log('SKIP user ' . $userId . ' has no registered device');
        return;
    }

    $accessToken = push_access_token();
    if ($accessToken === null) {
        return; // already logged
    }

    $url = 'https://fcm.googleapis.com/v1/projects/' . $config['project_id'] . '/messages:send';

    foreach ($tokens as $token) {
        $payload = json_encode([
            'message' => [
                'token'        => $token,
                'notification' => ['title' => $title, 'body' => $body],
                // initial_page_name is what FlutterFlow's push handler reads to
                // decide where a tapped notification lands.
                'data'         => ['initial_page_name' => 'Notifications'],
                'android'      => ['priority' => 'high'],
            ],
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => $payload,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 200) {
            push_log('OK user ' . $userId . ' ' . substr($token, 0, 12) . '… ' . $title);
            continue;
        }

        // 404 UNREGISTERED / 400 INVALID_ARGUMENT mean the app was uninstalled
        // or the token rotated. Dropping it keeps the table from filling with
        // devices that can never be reached again.
        if ($status === 404 || $status === 400) {
            push_forget_token($token);
            push_log('DROP dead token ' . substr($token, 0, 12) . '… (HTTP ' . $status . ')');
            continue;
        }

        push_log('ERROR HTTP ' . $status . ' ' . substr((string) $response, 0, 300));
    }
}
