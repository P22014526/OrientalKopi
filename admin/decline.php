<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_staff();

$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM bookings WHERE booking_id=?");
$stmt->execute([$id]);
$booking = $stmt->fetch();

if (!$booking) {
    header('Location: bookings.php');
    exit;
}

require_staff_booking_access($pdo, $booking);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim($_POST['reason'] ?? '');

    if ($reason === '') {
        $reason = 'Request declined by admin.';
    }

    $stmt = $pdo->prepare("UPDATE bookings SET status='Declined', admin_reason=? WHERE booking_id=?");
    $stmt->execute([$reason, $id]);

    log_staff_activity($pdo, 'Declined booking', $booking, $booking['booking_code'] . ': ' . $reason);

    create_notification(
        $pdo,
        $booking['user_id'],
        $booking['booking_id'],
        'Booking Declined',
        'Your booking request ' . $booking['booking_code'] . ' has been declined. Reason: ' . $reason,
        'Booking Updates'
    );

    header('Location: bookings.php');
    exit;
}
?>

<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Decline Request</title>
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
                    <h3 class="section-title">Decline Booking Request</h3>

                    <p><strong>Booking ID:</strong> <?= h($booking['booking_code']) ?></p>
                    <p><strong>Name:</strong> <?= h($booking['customer_name']) ?></p>
                    <p><strong>Date:</strong> <?= h(date('d/m/Y', strtotime($booking['booking_date']))) ?></p>
                    <p><strong>Time:</strong> <?= h(format_time12($booking['booking_time'])) ?></p>

                    <form method="post">
                        <label class="form-label">Reason for declining</label>
                        <textarea name="reason" class="form-control mb-3" rows="4" placeholder="Example: Selected time slot is no longer available." required></textarea>

                        <div class="action-buttons">
                            <button class="btn btn-danger">Send Decline Reason</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </main>
</body>

</html>
