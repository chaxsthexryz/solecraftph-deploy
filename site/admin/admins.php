<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_service.php';
auth_require_admin();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    if ($formAction === 'create') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $email === '' || strlen($password) < 8) {
            $error = 'Please provide a username, email, and a password of at least 8 characters.';
        } else {
            [$ok, $message] = user_create_admin($username, $email, $password);
            if ($ok) {
                audit_log('Admin account created', $username);
                $success = $message;
            } else {
                $error = $message;
            }
        }
    } elseif ($formAction === 'email') {
        // Own email only: it is where this account's sign-in codes go.
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } else {
            try {
                db()->prepare("UPDATE users SET email = ? WHERE id = ? AND role = 'admin'")
                    ->execute([$email, (int) $_SESSION['user_id']]);
                audit_log('Admin email changed', $email);
                $success = 'Your email is updated. Sign-in codes now go to ' . $email . '.';
            } catch (PDOException $e) {
                $error = 'That email is already used by another account.';
            }
        }
    } elseif ($formAction === 'delete') {
        $id = (int) ($_POST['user_id'] ?? 0);
        if ($id === (int) $_SESSION['user_id']) {
            $error = 'You cannot delete your own account while logged in.';
        } else {
            user_delete($id);
            audit_log('Admin account deleted', 'User #' . $id);
            $success = 'Admin account removed.';
        }
    }
}

$admins = user_list_admins();

$pageTitle = 'Admins — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
$active = 'admins';
require __DIR__ . '/_nav.php';
?>

<section class="section" style="padding-top:0;">
  <div class="wrap">
    <h1 class="display" style="margin-bottom:30px;">Admin Accounts</h1>

    <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert--success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="panel" style="max-width:560px;margin-bottom:36px;">
      <h3 class="display" style="font-size:22px;margin-bottom:16px;">Add Admin</h3>
      <form method="post" action="admins.php">
        <input type="hidden" name="form_action" value="create">
        <div class="form-grid">
          <div class="form-field"><label>Username</label><input type="text" name="username" required></div>
          <div class="form-field"><label>Email</label><input type="email" name="email" required></div>
          <div class="form-field full"><label>Password</label><input type="password" name="password" required minlength="8"></div>
        </div>
        <button type="submit" class="btn btn--solid">Create Admin</button>
      </form>
    </div>

    <div class="table-scroll">
    <table class="admin-table">
      <thead><tr><th>Username</th><th>Email</th><th>Joined</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($admins as $a): ?>
          <tr>
            <td><?= htmlspecialchars($a['username']) ?></td>
            <td>
              <?php if ((int) $a['id'] === (int) $_SESSION['user_id']): ?>
                <form method="post" action="admins.php" style="display:flex;gap:8px;">
                  <input type="hidden" name="form_action" value="email">
                  <input type="email" name="email" value="<?= htmlspecialchars($a['email']) ?>" required aria-label="Your email" style="min-width:220px;">
                  <button type="submit" class="btn btn--sm">Save</button>
                </form>
                <span class="mono" style="color:var(--gray);font-size:11px;">Your sign-in codes go here.</span>
              <?php else: ?>
                <?= htmlspecialchars($a['email']) ?>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($a['created_at']) ?></td>
            <td>
              <?php if ((int) $a['id'] !== (int) $_SESSION['user_id']): ?>
                <form method="post" action="admins.php" onsubmit="return confirm('Remove this admin account?');">
                  <input type="hidden" name="form_action" value="delete">
                  <input type="hidden" name="user_id" value="<?= $a['id'] ?>">
                  <button type="submit" class="link-remove" style="background:none;border:none;cursor:pointer;">Delete</button>
                </form>
              <?php else: ?>
                <span class="mono" style="color:var(--gray);">You</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
