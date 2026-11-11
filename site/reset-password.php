<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/password_reset_service.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$done  = false;

// Check the token before drawing the form, so a dead link says so immediately
// rather than after someone has typed a password twice.
$valid = password_reset_user_for_token($token) !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if ($new !== $confirm) {
        $error = 'The two passwords do not match.';
    } else {
        $error = password_reset_complete($token, $new) ?? '';
        if ($error === '') {
            $done  = true;
            $valid = false;
        }
    }
}

$pageTitle = 'Reset Password — SoleCraftPH';
require __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap" style="max-width:440px;">
    <h1 class="display" style="margin-bottom:24px;">Choose a New Password</h1>
    <div class="panel">
      <?php if ($done): ?>
        <div class="alert">Your password has been changed. You have been signed out everywhere, including the app.</div>
        <p style="margin-top:18px;"><a href="<?= BASE_PATH ?>/login.php" class="btn btn--solid">Log in</a></p>
      <?php elseif (!$valid): ?>
        <div class="alert alert--error"><?= htmlspecialchars($error ?: 'That reset link has expired or has already been used.') ?></div>
        <p style="margin-top:18px;"><a href="<?= BASE_PATH ?>/forgot-password.php" class="btn">Request a new link</a></p>
      <?php else: ?>
        <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" action="<?= BASE_PATH ?>/reset-password.php">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
          <div class="form-field">
            <label>New password</label>
            <input type="password" name="new_password" required minlength="8" autofocus>
          </div>
          <div class="form-field">
            <label>Confirm new password</label>
            <input type="password" name="confirm_password" required minlength="8">
          </div>
          <button type="submit" class="btn btn--solid" style="width:100%;justify-content:center;">Change password</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
