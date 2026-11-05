<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_staff();
$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare('SELECT * FROM bookings WHERE booking_id=?');
$stmt->execute([$id]);
$b = $stmt->fetch();
if (!$b) {
    header('Location: bookings.php');
    exit;
}

require_staff_booking_access($pdo, $b);
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Party Request</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
  <?php include 'staff_sidebar.php'; ?>
    <main class="container py-4">
        <a href="bookings.php" class="btn-nav-icon mb-3"><span class="btn-nav-icon-symbol">←</span><span>Bookings</span></a>
        <div class="card ok-card p-4">
            <div class="section-title">Admin review</div>
            <h3>Party Request Details</h3>
            <div class="row g-3">
                <div class="col-md-6">
                    <p><strong>Booking ID:</strong> <?= h($b['booking_code']) ?></p>
                    <p><strong>Name:</strong> <?= h($b['customer_name']) ?></p>
                    <p><strong>Phone:</strong> <?= h($b['phone']) ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Date:</strong> <?= h(format_date_short($b['booking_date'])) ?></p>
                    <p><strong>Time:</strong> <?= h(format_time12($b['booking_time'])) ?></p>
                    <p><strong>Pax:</strong> <?= h($b['pax']) ?></p>
                </div>
            </div>
            <p><strong>Event Type:</strong> <?= h($b['event_type'] ?: '-') ?></p>
            <p><strong>Special Request:</strong></p>
            <div class="border rounded bg-white p-3 mb-3"><?= nl2br(h($b['special_request'] ?: 'No special request provided.')) ?></div>
            <div class="d-flex gap-3 mt-3">
                <a class="btn btn-success px-4" href="approve.php?id=<?= h($b['booking_id']) ?>">
                    Approve
                </a>

                <a class="btn btn-danger px-4" href="decline.php?id=<?= h($b['booking_id']) ?>">
                    Decline
                </a>
            </div>
        </div>
    </main>
</body>

</html>
