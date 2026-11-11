<?php
/**
 * Outgoing email.
 *
 * Sends over authenticated SMTP when config/mail.php exists, and falls back to
 * PHP's mail() when it does not. The fallback is kept because it costs nothing
 * and means a missing config file degrades rather than breaks — but it does not
 * deliver reliably from this domain, which is why SMTP exists.
 *
 * Why SMTP: mail() sends as noreply@<free-hostinger-subdomain>, which has no
 * SPF or DKIM we control and no mailbox behind it. Receiving servers — a
 * university's especially — drop that silently. Gmail SMTP sends as a real,
 * authenticated mailbox instead.
 *
 * Every attempt is logged to mail.log one level ABOVE public_html, so mail that
 * was never sent can be told apart from mail that was sent and never arrived.
 * That distinction is most of the debugging.
 *
 * This is the only file that knows how mail leaves. Everything else calls
 * mail_send().
 */
require_once __DIR__ . '/../config/app.php';

// Optional. Absent = fall back to mail(). See config/mail.sample.php.
$__mailConfig = __DIR__ . '/../config/mail.php';
if (is_file($__mailConfig)) {
    require_once $__mailConfig;
}
unset($__mailConfig);

if (!defined('MAIL_FROM')) {
    define('MAIL_FROM', 'noreply@snow-jellyfish-553645.hostingersite.com');
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', 'SoleCraftPH');
}

function mail_log(string $message): void
{
    @file_put_contents(
        __DIR__ . '/../../mail.log',
        gmdate('c') . ' ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

/** True when SMTP credentials are configured. */
function mail_smtp_configured(): bool
{
    return defined('SMTP_HOST') && defined('SMTP_USER') && defined('SMTP_PASS')
        && SMTP_HOST !== '' && SMTP_USER !== '' && SMTP_PASS !== '';
}

/**
 * Sends one HTML email. Returns true when the server accepted it — which is
 * still not a promise the customer received it, hence the log.
 */
function mail_send(string $to, string $subject, string $bodyHtml): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        mail_log('SKIPPED invalid address: ' . $to);
        return false;
    }

    $html = mail_wrap($bodyHtml);

    if (mail_smtp_configured()) {
        return mail_send_smtp($to, $subject, $html);
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
        'Reply-To: ' . MAIL_FROM,
        'X-Mailer: SoleCraftPH',
    ];
    $ok = @mail($to, $subject, $html, implode("\r\n", $headers));
    mail_log(($ok ? 'SENT    ' : 'FAILED  ') . $to . ' — ' . $subject . ' [mail()]');
    return $ok;
}

/**
 * Authenticated SMTP via PHPMailer 6.9.3, vendored under includes/PHPMailer.
 *
 * PHPMailer rather than a hand-rolled client because these bodies contain very
 * long lines, and SMTP's line-length limit, dot-stuffing and header encoding
 * are exactly where a home-made sender quietly mangles mail.
 */
function mail_send_smtp(string $to, string $subject, string $html): bool
{
    require_once __DIR__ . '/PHPMailer/Exception.php';
    require_once __DIR__ . '/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 5;

        // Gmail rewrites From to the authenticated account anyway, so claiming
        // anything else just looks like a spoof attempt to spam filters.
        $mail->setFrom(SMTP_USER, MAIL_FROM_NAME);
        $mail->addReplyTo(SMTP_USER, MAIL_FROM_NAME);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));

        $mail->send();
        mail_log('SENT    ' . $to . ' — ' . $subject . ' [smtp]');
        return true;
    } catch (Throwable $e) {
        mail_log('FAILED  ' . $to . ' — ' . $subject . ' [smtp] ' . $e->getMessage());
        return false;
    }
}

/** Wraps body copy in the storefront's look, so mail matches the site. */
function mail_wrap(string $inner): string
{
    return '<!doctype html><html><body style="margin:0;padding:24px;'
        . 'background:#FAF9F6;font-family:Inter,Arial,Helvetica,sans-serif;'
        . 'color:#111111;line-height:1.6;">'
        . '<div style="max-width:520px;margin:0 auto;background:#FFFFFF;'
        . 'border:1px solid #EDEAE3;border-radius:12px;padding:28px;">'
        . '<div style="font-size:13px;letter-spacing:.16em;text-transform:uppercase;'
        . 'color:#E2412A;margin-bottom:18px;">SoleCraftPH</div>'
        . $inner
        . '</div>'
        . '<p style="max-width:520px;margin:16px auto 0;font-size:12px;color:#8A8578;'
        . 'text-align:center;">You are receiving this because you have an account '
        . 'at SoleCraftPH.</p>'
        . '</body></html>';
}

/** A primary call-to-action button, inline-styled so mail clients keep it. */
function mail_button(string $label, string $url): string
{
    return '<p style="margin:24px 0;"><a href="' . htmlspecialchars($url) . '" '
        . 'style="display:inline-block;background:#111111;color:#FFFFFF;'
        . 'text-decoration:none;padding:14px 22px;border-radius:12px;'
        . 'font-weight:600;">' . htmlspecialchars($label) . '</a></p>';
}

/** The site's own base URL, for links inside emails. */
function mail_site_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'snow-jellyfish-553645.hostingersite.com';
    return $scheme . '://' . $host . (defined('BASE_PATH') ? BASE_PATH : '');
}
