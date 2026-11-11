<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_2fa.php';

if (auth_is_admin()) {
    header('Location: dashboard.php');
    exit;
}
if (empty($_SESSION['admin_pending'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$info  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['resend'])) {
        $p    = $_SESSION['admin_pending'];
        $wait = $p['sent'] + ADMIN_2FA_RESEND - time();
        $user = user_find($p['id']);
        if ($wait > 0) {
            $error = "Wait {$wait} seconds before asking for another code.";
        } elseif ($user && admin_2fa_start($user)) {
            $info = 'A new code is on its way. The old one no longer works.';
        } else {
            $error = 'We could not send a new code. Try again in a minute.';
        }
    } else {
        $error = admin_2fa_verify(trim($_POST['code'] ?? ''));
        if ($error === null) {
            require_once __DIR__ . '/../includes/audit_service.php';
            audit_log('Admin signed in', 'Email code verified');
            header('Location: dashboard.php');
            exit;
        }
        if (empty($_SESSION['admin_pending'])) {
            header('Location: login.php');
            exit;
        }
    }
}

$to = admin_2fa_mask($_SESSION['admin_pending']['to']);
$pageTitle = 'Verify — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
?>

<div class="adm-auth">
  <div class="adm-auth__brand">SOLE<span>CRAFT</span>PH <small>Admin</small></div>
  <div class="panel">
    <h1 class="display" style="font-size:34px;margin-bottom:6px;">Check your email</h1>
    <p style="margin-bottom:22px;font-size:14px;">Step 2 of 2 · we sent a 6-digit code to <b><?= htmlspecialchars($to) ?></b>. It expires in 10 minutes.</p>
    <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($info): ?><div class="alert alert--success"><?= htmlspecialchars($info) ?></div><?php endif; ?>
    <form method="post" action="verify.php">
      <div class="form-field">
        <label for="code">Sign-in code</label>
        <input id="code" type="text" name="code" required autofocus inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" style="font-size:26px;letter-spacing:.4em;text-align:center;">
      </div>
      <button type="submit" class="btn btn--solid" style="width:100%;justify-content:center;">Verify &amp; sign in</button>
    </form>
    <form method="post" action="verify.php" style="margin-top:14px;display:flex;justify-content:space-between;align-items:center;">
      <button type="submit" name="resend" value="1" class="btn btn--sm">Send a new code</button>
      <a href="logout.php" class="mono" style="color:var(--gray);">Cancel</a>
    </form>
  </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
