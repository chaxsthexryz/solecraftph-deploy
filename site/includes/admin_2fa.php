<?php
/**
 * Admin sign-in, second step: a 6-digit code emailed to the admin.
 *
 * The password only parks the admin in $_SESSION['admin_pending']; the session
 * becomes an admin session in admin_2fa_verify(). Only the code's hash is kept,
 * it lasts 10 minutes and allows 5 tries, so guessing it means 5 in a million.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail_service.php';

const ADMIN_2FA_TTL     = 600;
const ADMIN_2FA_TRIES   = 5;
const ADMIN_2FA_RESEND  = 60;

/** Where the code goes: the admin's own email, or the store mailbox when that cannot receive mail. */
function admin_2fa_address(array $user): string
{
    $email = (string) ($user['email'] ?? '');
    $real  = filter_var($email, FILTER_VALIDATE_EMAIL)
        && !preg_match('/\.(local|test|invalid|example|localhost)$/i', $email);
    return $real || !defined('SMTP_USER') ? $email : SMTP_USER;
}

/** Hides most of an address: jbbalitos@gmail.com -> jb•••••@gmail.com */
function admin_2fa_mask(string $email): string
{
    [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return substr($name, 0, 2) . str_repeat('•', max(3, strlen($name) - 2)) . '@' . $domain;
}

/** Emails a fresh code to $user and parks them as pending. False when the mail did not go. */
function admin_2fa_start(array $user): bool
{
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $to   = admin_2fa_address($user);
    $_SESSION['admin_pending'] = [
        'id'    => (int) $user['id'],
        'hash'  => hash('sha256', $code . '|' . $user['id']),
        'exp'   => time() + ADMIN_2FA_TTL,
        'sent'  => time(),
        'tries' => 0,
        'to'    => $to,
    ];
    $note = $to !== ($user['email'] ?? '')
        ? '<p style="margin:0;font-size:13px;color:#8A8578;">Sent here because the admin account <b>'
          . htmlspecialchars($user['username']) . '</b> has no email that can receive mail. Set one in Admin → Admins.</p>'
        : '';
    return mail_send(
        $to,
        'Your SoleCraftPH admin sign-in code: ' . $code,
        '<h1 style="font-size:22px;margin:0 0 12px;">Confirm it\'s you</h1>'
        . '<p style="margin:0;">Someone signed in to the SoleCraftPH admin panel as <b>'
        . htmlspecialchars($user['username']) . '</b>. Enter this code to finish signing in:</p>'
        . '<p style="margin:22px 0;font-size:34px;font-weight:800;letter-spacing:.3em;">' . $code . '</p>'
        . '<p style="margin:0 0 12px;font-size:13px;color:#8A8578;">It expires in 10 minutes. '
        . 'If this wasn\'t you, change the admin password now — your password is known to someone else.</p>'
        . $note
    );
}

/**
 * Checks $code against the pending sign-in. Returns null on success (the
 * session is now a verified admin session), or the message to show.
 */
function admin_2fa_verify(string $code): ?string
{
    $p = $_SESSION['admin_pending'] ?? null;
    if (!$p) {
        return 'Your sign-in expired. Enter your password again.';
    }
    if (time() > $p['exp'] || $p['tries'] >= ADMIN_2FA_TRIES) {
        unset($_SESSION['admin_pending']);
        return 'That code expired or had too many wrong tries. Enter your password again.';
    }
    $_SESSION['admin_pending']['tries']++;
    if (!preg_match('/^\d{6}$/', $code) || !hash_equals($p['hash'], hash('sha256', $code . '|' . $p['id']))) {
        $left = ADMIN_2FA_TRIES - $_SESSION['admin_pending']['tries'];
        return $left > 0 ? "Wrong code. {$left} tries left." : 'Too many wrong tries. Enter your password again.';
    }

    $user = user_find($p['id']);
    unset($_SESSION['admin_pending']);
    if (!$user || $user['role'] !== 'admin' || ($user['status'] ?? 'active') === 'suspended') {
        return 'This account can no longer sign in.';
    }
    auth_start_session_for($user);
    $_SESSION['admin_verified'] = true;
    return null;
}
