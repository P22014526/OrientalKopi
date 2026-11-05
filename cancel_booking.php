<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();
prevent_dynamic_page_cache();

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("
    SELECT b.*, o.branch_name, o.contact_phone
    FROM bookings b
    LEFT JOIN outlets o ON o.outlet_id = b.outlet_id
    WHERE b.booking_id = ?
      AND b.user_id = ?
");
$stmt->execute([$id, $_SESSION['user_id']]);
$b = $stmt->fetch();

if (!$b) {
    header('Location: my_bookings.php');
    exit;
}

$cutoffMinutes = cancellation_cutoff_minutes($pdo);
$cutoffLabel = cancellation_cutoff_label($cutoffMinutes);
$cancelDeadline = booking_cancel_deadline($b, $cutoffMinutes);
$canCancel = customer_can_cancel_booking($b, $cutoffMinutes);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canCancel) {
        $error = 'This booking can no longer be cancelled online. Please contact the outlet for help.';
    } else {
        $pdo->prepare("UPDATE bookings SET status='Cancelled' WHERE booking_id=? AND user_id=?")->execute([$id, $_SESSION['user_id']]);
        header('Location: my_bookings.php');
        exit;
    }
}

include 'includes/header.php';
?>
<div class="row justify-content-center">
  <div class="col-md-6">
    <div class="card ok-card p-4">
      <div class="section-title">Customer cancellation</div>
      <h3>Cancel Booking Confirmation</h3>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
      <?php endif; ?>

      <p>Booking ID: <strong><?= h($b['booking_code']) ?></strong></p>
      <p>Date: <?= h(format_date_short($b['booking_date'])) ?> | Time: <?= h(format_time12($b['booking_time'])) ?></p>
      <p class="muted-small">
        Online cancellation is available until <?= h($cutoffLabel) ?>.
        <?php if ($cancelDeadline): ?>
          Deadline: <?= h($cancelDeadline->format('d/m/Y g:i A')) ?>.
        <?php endif; ?>
      </p>

      <?php if ($canCancel): ?>
        <p>Are you sure you want to cancel this booking?</p>
        <form method="post">
          <button class="btn btn-danger">Yes, Cancel</button>
          <a href="my_bookings.php" class="btn btn-outline-dark">Keep Booking</a>
        </form>
      <?php else: ?>
        <div class="alert alert-warning">
          This booking is past the online cancellation cutoff.
          <?php if (!empty($b['contact_phone'])): ?>
            Please call <?= h($b['branch_name'] ?? 'the outlet') ?> at
            <a href="tel:<?= h(phone_href($b['contact_phone'])) ?>"><?= h($b['contact_phone']) ?></a>.
          <?php endif; ?>
        </div>
        <a href="my_bookings.php" class="btn btn-outline-dark">Back to My Bookings</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include 'includes/footer.php'; ?>
