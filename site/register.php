<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';

if (auth_is_logged_in()) {
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if ($username === '' || $email === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        [$ok, $message] = auth_register_customer($username, $email, $password);

        if ($ok) {
            // Auto-login right after a successful signup.
            auth_attempt_login($username, $password);
            header('Location: ' . BASE_PATH . '/index.php?welcome=1');
            exit;
        }
        $error = $message;
    }
}

$pageTitle = 'Create Account — SoleCraftPH';
require __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap" style="max-width:440px;">
    <h1 class="display" style="margin-bottom:24px;">Create Account</h1>
    <div class="panel">
      <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="post" action="<?= BASE_PATH ?>/register.php">
        <div class="form-field">
          <label>Username</label>
          <input type="text" name="username" required autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>
        <div class="form-field">
          <label>Email</label>
          <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
        <div class="form-field">
          <label>Password</label>
          <input type="password" name="password" required minlength="8">
        </div>
        <div class="form-field">
          <label>Confirm Password</label>
          <input type="password" name="confirm_password" required minlength="8">
        </div>
        <button type="submit" class="btn btn--solid" style="width:100%;justify-content:center;">Create Account</button>
        <p style="margin-top:12px;font-size:13px;color:var(--gray);">
          By creating an account you agree to our
          <a href="<?= BASE_PATH ?>/page.php?slug=terms">Terms &amp; Conditions</a> and
          <a href="<?= BASE_PATH ?>/page.php?slug=privacy">Privacy Policy</a>.
        </p>
      </form>
      <?php $googleRedirect = BASE_PATH . '/index.php?welcome=1'; require __DIR__ . '/includes/google_button.php'; ?>
      <p class="mono" style="margin-top:18px;color:var(--gray);">
        Already have an account? <a href="<?= BASE_PATH ?>/login.php">Log in</a>
      </p>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
