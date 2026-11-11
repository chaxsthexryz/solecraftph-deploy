<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/password_reset_service.php';

if (auth_is_logged_in()) {
    header('Location: ' . BASE_PATH . '/profile.php');
    exit;
}

$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    password_reset_request(trim($_POST['email'] ?? ''));
    // Deliberately the same answer whether or not that address has an account —
    // otherwise this form tells a stranger which of your customers exist.
    $sent = true;
}

$pageTitle = 'Forgot Password — SoleCraftPH';
require __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap" style="max-width:440px;">
    <h1 class="display" style="margin-bottom:24px;">Forgot Password</h1>
    <div class="panel">
      <?php if ($sent): ?>
        <div class="alert">If that email has an account, a reset link is on its way. It expires in an hour.</div>
        <p style="margin-top:18px;"><a href="<?= BASE_PATH ?>/login.php" class="btn">Back to log in</a></p>
      <?php else: ?>
        <p style="margin-bottom:18px;color:var(--gray);">Enter the email on your account and we will send you a link to choose a new password.</p>
        <form method="post" action="<?= BASE_PATH ?>/forgot-password.php">
          <div class="form-field">
            <label>Email</label>
            <input type="email" name="email" required autofocus>
          </div>
          <button type="submit" class="btn btn--solid" style="width:100%;justify-content:center;">Send reset link</button>
        </form>
        <p style="margin-top:18px;font-size:14px;"><a href="<?= BASE_PATH ?>/login.php">Back to log in</a></p>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
