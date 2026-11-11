<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/contact_service.php';
require_once __DIR__ . '/includes/settings_service.php';
require_once __DIR__ . '/includes/login_throttle.php';

$error = '';
$success = '';
$me = auth_current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    // Bots filled the inbox: a hidden field only they fill in, and a per-IP limit.
    $wait = throttle_delay('contact');
    if (($_POST['website'] ?? '') !== '') {
        $success = "Thanks — we've received your message and will get back to you shortly.";
    } elseif ($wait > 0) {
        $error = login_throttle_message($wait);
    } elseif ($name === '' || $email === '' || $subject === '' || $message === '') {
        $error = 'Please fill in every field.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        throttle_record('contact');
        contact_create($name, $email, $subject, $message);
        $success = "Thanks — we've received your message and will get back to you shortly.";
    }
}

$pageTitle = 'Contact Support — SoleCraftPH';
require __DIR__ . '/includes/header.php';
?>

<header class="pagehead">
  <div class="wrap">
    <h1 class="display">Contact Support</h1>
    <p>Questions about an order, a product, or anything else — reach out and we'll help.</p>
  </div>
</header>

<section class="section">
  <div class="wrap" style="max-width:640px;">
    <div class="panel">
      <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="alert alert--success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

      <form method="post" action="<?= BASE_PATH ?>/contact.php">
        <div class="form-grid">
          <div class="form-field">
            <label>Your Name *</label>
            <input type="text" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? ($me['full_name'] ?? $me['username'] ?? '')) ?>">
          </div>
          <div class="form-field">
            <label>Your Email *</label>
            <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? ($me['email'] ?? '')) ?>">
          </div>
          <div class="form-field full">
            <label>Subject *</label>
            <input type="text" name="subject" required value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>">
          </div>
          <div class="form-field full">
            <label>Message *</label>
            <textarea name="message" required rows="6"><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
          </div>
        </div>
        <div style="position:absolute;left:-9999px;" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <button type="submit" class="btn btn--solid">Send Message</button>
      </form>

      <p class="mono" style="margin-top:22px;color:var(--gray);">
        Or email us directly at <?= htmlspecialchars(setting('support_email', 'support@solecraftph.local')) ?>
        <?php if (setting('support_phone')): ?> &middot; <?= htmlspecialchars(setting('support_phone')) ?><?php endif; ?>
      </p>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
