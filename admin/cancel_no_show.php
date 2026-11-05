<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_staff();

$id = $_GET['id'] ?? 0;
$mode = $_GET['mode'] ?? ($_POST['mode'] ?? 'cancel');

if (!in_array($mode, ['cancel', 'no_show'], true)) {
    $mode = 'cancel';
}

$stmt = $pdo->prepare('SELECT * FROM bookings WHERE booking_id=?');
$stmt->execute([$id]);
$b = $stmt->fetch();

if (!$b) {
    header('Location: bookings.php');
    exit;
}

require_staff_booking_access($pdo, $b);

$noShowGraceMinutes = no_show_grace_minutes($pdo);
$canMarkNoShow = booking_can_be_marked_no_show($b, $noShowGraceMinutes);
$noShowAvailableAt = booking_no_show_available_at($b, $noShowGraceMinutes);
$isNoShowMode = $mode === 'no_show';
$defaultReason = $isNoShowMode
    ? 'Customer did not arrive within the ' . no_show_grace_label($noShowGraceMinutes) . '.'
    : 'Booking cancelled by staff.';
$pageTitle = $isNoShowMode ? 'Mark Booking as No-show' : 'Cancel Booking';
$actionLabel = $isNoShowMode ? 'Confirm No-show' : 'Confirm Cancel';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim($_POST['reason'] ?? '');

    if ($reason === '') {
        $reason = $defaultReason;
    }

    if ($isNoShowMode && !$canMarkNoShow) {
        $error = 'This booking cannot be marked as no-show yet. Please wait until ' . ($noShowAvailableAt ? $noShowAvailableAt->format('d/m/Y g:i A') : no_show_grace_label($noShowGraceMinutes)) . '.';
    } else {
        $pdo->prepare("UPDATE bookings SET status='Cancelled', admin_reason=? WHERE booking_id=?")
            ->execute([$reason, $id]);

        log_staff_activity($pdo, $isNoShowMode ? 'Marked booking no-show' : 'Cancelled booking', $b, $b['booking_code'] . ': ' . $reason);

        create_notification(
            $pdo,
            $b['user_id'],
            $b['booking_id'],
            $isNoShowMode ? 'Booking Marked No-show' : 'Booking Cancelled',
            'Your booking ' . $b['booking_code'] . ' has been cancelled by staff. Reason: ' . $reason,
            'Booking Updates'
        );

        header('Location: bookings.php#booking-records');
        exit;
    }
}
?>

<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
  <?php include 'staff_sidebar.php'; ?>
    <main class="container py-4">

        <a href="bookings.php" class="btn-nav-icon mb-3"><span class="btn-nav-icon-symbol">←</span><span>Bookings</span></a>

        <div class="row justify-content-center">
            <div class="col-md-7">
                <div class="card ok-card p-4">
                    <h3 class="section-title"><?= h($pageTitle) ?></h3>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= h($error) ?></div>
                    <?php endif; ?>

                    <p><strong>Booking ID:</strong> <?= h($b['booking_code']) ?></p>
                    <p><strong>Name:</strong> <?= h($b['customer_name']) ?></p>
                    <p><strong>Booking Time:</strong> <?= h(format_time12($b['booking_time'])) ?></p>
                    <?php if ($isNoShowMode): ?>
                        <p class="muted-small">
                            No-show can be marked after <?= h(no_show_grace_label($noShowGraceMinutes)) ?>.
                            <?php if ($noShowAvailableAt): ?>
                                Available from <?= h($noShowAvailableAt->format('d/m/Y g:i A')) ?>.
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <form method="post">
                        <input type="hidden" name="mode" value="<?= h($mode) ?>">
                        <label class="form-label">Reason</label>
                        <textarea name="reason" class="form-control mb-3" rows="4" required><?= h($defaultReason) ?></textarea>

                        <div class="action-buttons">
                            <button class="btn btn-danger" <?= $isNoShowMode && !$canMarkNoShow ? 'disabled' : '' ?>><?= h($actionLabel) ?></button>
                            <a href="bookings.php#booking-records" class="btn-nav-icon mb-3"><span class="btn-nav-icon-symbol">←</span><span>Bookings</span></a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </main>
</body>

</html>
