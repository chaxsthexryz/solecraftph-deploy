<?php
/**
 * SMTP credentials. Copy to config/mail.php and fill in.
 *
 * config/mail.php is gitignored and must never be committed — SMTP_PASS is a
 * live credential. Upload it straight to the server.
 *
 * Gmail: SMTP_PASS is an App Password (Google Account > Security > 2-Step
 * Verification > App passwords), NOT your normal Google password. It looks like
 * "abcd efgh ijkl mnop" — paste it with or without the spaces, both work.
 */
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'you@gmail.com');
define('SMTP_PASS', 'your-16-char-app-password');

// The display name on outgoing mail. Gmail forces the address itself to
// SMTP_USER, so only the name is ours to choose.
define('MAIL_FROM_NAME', 'SoleCraftPH');
