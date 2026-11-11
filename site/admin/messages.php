<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/contact_service.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
auth_require_admin();

$flash = $_SESSION['msg_flash'] ?? null;
unset($_SESSION['msg_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id    = (int) ($_POST['message_id'] ?? 0);
    $reply = trim($_POST['reply'] ?? '');
    $m     = contact_find($id);
    if ($m && $reply !== '') {
        // Sent from the store's own mailbox, so it works without a mail app on this computer.
        require_once __DIR__ . '/../includes/mail_service.php';
        require_once __DIR__ . '/../includes/audit_service.php';
        $sent = mail_send(
            $m['email'],
            'Re: ' . $m['subject'],
            '<p style="margin:0 0 14px;">Hi ' . htmlspecialchars($m['name']) . ',</p>'
            . '<p style="margin:0 0 18px;">' . nl2br(htmlspecialchars($reply)) . '</p>'
            . '<p style="margin:0;font-size:13px;color:#8A8578;">— SoleCraftPH Support</p>'
            . '<hr style="border:none;border-top:1px solid #EDEAE3;margin:22px 0;">'
            . '<p style="margin:0;font-size:13px;color:#8A8578;">You wrote:<br>' . nl2br(htmlspecialchars($m['message'])) . '</p>'
        );
        if ($sent) {
            contact_set_status($id, 'resolved');
            audit_log('Support reply sent', 'Message #' . $id . ' to ' . $m['email']);
        }
        $_SESSION['msg_flash'] = $sent
            ? ['ok', 'Reply sent to ' . $m['email'] . ' and the message marked resolved.']
            : ['error', 'The reply could not be sent. Try again in a minute.'];
    } elseif ($m) {
        contact_set_status($id, $_POST['status'] ?? 'read');
    }
    header('Location: messages.php');
    exit;
}

$messages = contact_list();

$pageTitle = 'Messages — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
$active = 'messages';
require __DIR__ . '/_nav.php';
?>

<section class="section" style="padding-top:0;">
  <div class="wrap">
    <h1 class="display" style="margin-bottom:30px;">Support Messages</h1>
    <?php if ($flash): ?><div class="alert alert--<?= $flash[0] === 'ok' ? 'success' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

    <?php if (empty($messages)): ?>
      <div class="empty-state">No messages yet.</div>
    <?php else: ?>
      <?php foreach ($messages as $m): ?>
        <div class="panel" style="margin-bottom:16px;">
          <div class="section-head" style="margin-bottom:14px;">
            <div>
              <span class="mono"><?= htmlspecialchars($m['created_at']) ?></span>
              <h3 class="display" style="font-size:22px;"><?= htmlspecialchars($m['subject']) ?></h3>
            </div>
            <?= render_status_badge($m['status']) ?>
          </div>
          <p style="margin-bottom:10px;"><strong><?= htmlspecialchars($m['name']) ?></strong> &lt;<?= htmlspecialchars($m['email']) ?>&gt;</p>
          <p style="color:#3d3b36;line-height:1.6;margin-bottom:16px;"><?= nl2br(htmlspecialchars($m['message'])) ?></p>
          <form method="post" action="messages.php" style="display:flex;gap:10px;">
            <input type="hidden" name="message_id" value="<?= $m['id'] ?>">
            <select name="status" style="padding:8px;border:1.5px solid var(--ink);font-size:12px;">
              <option value="new" <?= $m['status'] === 'new' ? 'selected' : '' ?>>New</option>
              <option value="read" <?= $m['status'] === 'read' ? 'selected' : '' ?>>Read</option>
              <option value="resolved" <?= $m['status'] === 'resolved' ? 'selected' : '' ?>>Resolved</option>
            </select>
            <button type="submit" class="btn btn--sm">Save</button>
          </form>
          <form method="post" action="messages.php" style="margin-top:12px;">
            <input type="hidden" name="message_id" value="<?= $m['id'] ?>">
            <label for="reply<?= $m['id'] ?>" class="mono" style="color:var(--gray);">Reply to <?= htmlspecialchars($m['email']) ?></label>
            <textarea id="reply<?= $m['id'] ?>" name="reply" rows="3" required style="width:100%;margin:6px 0 8px;padding:10px;border:1.5px solid var(--ink);font:inherit;"></textarea>
            <button type="submit" class="btn btn--sm btn--solid">Send reply</button>
          </form>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
