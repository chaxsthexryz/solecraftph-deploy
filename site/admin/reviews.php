<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/review_service.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/audit_service.php';
auth_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['review_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        review_delete($id);
        audit_log('Review deleted', 'Review #' . $id);
    } else {
        review_set_status($id, $action);
        audit_log('Review status updated', 'Review #' . $id . ' -> ' . $action);
    }
    header('Location: reviews.php');
    exit;
}

$reviews = review_list_all();

$pageTitle = 'Reviews — Admin — SoleCraftPH';
require __DIR__ . '/_header.php';
$active = 'reviews';
require __DIR__ . '/_nav.php';
?>

<section class="section" style="padding-top:0;">
  <div class="wrap">
    <h1 class="display" style="margin-bottom:30px;">Reviews</h1>

    <?php if (empty($reviews)): ?>
      <div class="empty-state">No reviews yet.</div>
    <?php else: ?>
      <div class="table-scroll">
      <table class="admin-table">
        <thead><tr><th>Product</th><th>User</th><th>Rating</th><th>Comment</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($reviews as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['product_name']) ?></td>
              <td><?= htmlspecialchars($r['username']) ?></td>
              <td><?= render_stars((float) $r['rating'], null, 'sm') ?></td>
              <td style="max-width:260px;"><?= htmlspecialchars($r['comment'] ?: '—') ?></td>
              <td><?= render_status_badge($r['status']) ?></td>
              <td><?= htmlspecialchars($r['created_at']) ?></td>
              <td>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                  <form method="post" action="reviews.php"><input type="hidden" name="review_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="approved"><button type="submit" class="mono" style="background:none;border:none;cursor:pointer;font-weight:700;">Approve</button></form>
                  <form method="post" action="reviews.php"><input type="hidden" name="review_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="rejected"><button type="submit" class="mono" style="background:none;border:none;cursor:pointer;font-weight:700;">Reject</button></form>
                  <form method="post" action="reviews.php" onsubmit="return confirm('Delete this review?');"><input type="hidden" name="review_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="delete"><button type="submit" class="link-remove" style="background:none;border:none;cursor:pointer;">Delete</button></form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
