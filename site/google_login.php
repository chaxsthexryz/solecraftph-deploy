<?php
/**
 * Website Google sign-in. The "Continue with Google" button (google_button.php)
 * posts Google's ID token here through an ordinary form, so the site's CSRF
 * guard covers it like any other POST.
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

$claims = google_verify_id_token((string) ($_POST['credential'] ?? ''));
[$user, $error] = $claims
    ? google_user_for($claims)
    : [null, 'Google sign-in could not be verified. Please try again.'];

if ($user === null) {
    $_SESSION['google_error'] = $error;
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

auth_start_session_for($user);

// Only a path on this site — never an address elsewhere.
$redirect = (string) ($_POST['redirect'] ?? '');
if ($redirect === '' || $redirect[0] !== '/' || strncmp($redirect, '//', 2) === 0) {
    $redirect = BASE_PATH . '/index.php';
}
header('Location: ' . $redirect);
exit;
