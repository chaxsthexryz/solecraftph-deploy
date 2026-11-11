<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/login_throttle.php';
require_once __DIR__ . '/../includes/admin_2fa.php';

if (auth_is_admin()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Refused before the password is even looked at, so a throttled attempt
    // cannot be used to probe whether an account exists.
    $wait = login_throttle_delay($username);
    if ($wait > 0) {
        $error = login_throttle_message($wait);
    } else {
        $user = auth_check_password($username, $password);
        $ok   = $user !== null && $user['role'] === 'admin';
        login_attempt_record($username, $ok);
        if ($ok && admin_2fa_start($user)) {
            header('Location: verify.php');
            exit;
        }
        $error = $ok
            ? 'We could not send your sign-in code. Try again in a minute.'
            : 'Invalid username or password, or account is not an admin.';
    }
}

$pageTitle = 'Admin Login — SoleCraftPH';
require __DIR__ . '/_header.php';
?>

<div class="adm-auth">
  <div class="adm-auth__brand">SOLE<span>CRAFT</span>PH <small>Admin</small></div>
  <div class="panel">
    <h1 class="display" style="font-size:34px;margin-bottom:6px;">Admin Login</h1>
    <p class="mono" style="color:var(--gray);margin-bottom:22px;">Step 1 of 2 · then a code is emailed to you</p>
    <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" action="login.php">
      <div class="form-field"><label for="u">Username or email</label><input id="u" type="text" name="username" required autofocus autocomplete="username"></div>
      <div class="form-field"><label for="p">Password</label><input id="p" type="password" name="password" required autocomplete="current-password"></div>
      <button type="submit" class="btn btn--solid" style="width:100%;justify-content:center;">Continue</button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
