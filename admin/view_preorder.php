<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_staff();

$bookingId = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT b.*, u.full_name, u.email, o.branch_name, s.state_name
    FROM bookings b
    JOIN users u ON b.user_id = u.user_id
    LEFT JOIN outlets o ON o.outlet_id = b.outlet_id
    LEFT JOIN outlet_states s ON s.state_id = o.state_id
    WHERE b.booking_id = ?
");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    header('Location: bookings.php');
    exit;
}

require_staff_booking_access($pdo, $booking);

$stmt = $pdo->prepare("
    SELECT p.*, m.item_name, m.category, m.price, m.image_path
    FROM preorders p
    JOIN menu_items m ON p.item_id = m.item_id
    WHERE p.booking_id = ?
    AND p.status = 'Submitted'
    ORDER BY p.submitted_at DESC, p.created_at DESC
");
$stmt->execute([$bookingId]);
$preorders = $stmt->fetchAll();

$preorderStatus = $preorders[0]['status'] ?? 'No preorder';
$submittedAt = $preorders[0]['submitted_at'] ?? null;

$totalQty = 0;
$totalAmount = 0;

foreach ($preorders as $p) {
    $totalQty += (int)$p['quantity'];
    $totalAmount += ((float)$p['price'] * (int)$p['quantity']);
}
?>

<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>View Preorder</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css?v=20">
</head>

<body>
  <?php include 'staff_sidebar.php'; ?>
    <main class="container py-4">

        <a href="bookings.php" class="btn-nav-icon mb-3">
            <span class="btn-nav-icon-symbol">←</span>
            <span>Bookings</span>
        </a>

        <div class="card ok-card p-4 mb-4">
            <div class="section-title">Staff Area</div>
            <h3>Customer Preorder Details</h3>

            <div class="row g-3 mt-2">
                <div class="col-md-4">
                    <div class="stat-pill">
                        <strong>Booking ID</strong><br>
                        <?= h($booking['booking_code']) ?>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="stat-pill">
                        <strong>Customer</strong><br>
                        <?= h($booking['customer_name']) ?>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="stat-pill">
                        <strong>Email</strong><br>
                        <?= h($booking['email']) ?>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="stat-pill">
                        <strong>Outlet</strong><br>
                        <?= h(($booking['branch_name'] ?? 'Unassigned') . ' - ' . ($booking['state_name'] ?? '-')) ?>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="stat-pill">
                        <strong>Date</strong><br>
                        <?= h(date('d/m/Y', strtotime($booking['booking_date']))) ?>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="stat-pill">
                        <strong>Time</strong><br>
                        <?= h(format_time12($booking['booking_time'])) ?>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="stat-pill">
                        <strong>Pax</strong><br>
                        <?= h($booking['pax']) ?> pax
                    </div>
                </div>
            </div>
        </div>

        <div class="card ok-card p-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <div class="section-title">Preorder List</div>
                    <h4 class="mb-0">Food Selected by Customer</h4>
                </div>

                <div class="text-end">
                    <span class="badge bg-dark">Total Items: <?= h($totalQty) ?></span>

                    <?php if ($totalAmount > 0): ?>
                        <span class="badge bg-success">Total: RM <?= h(number_format($totalAmount, 2)) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$preorders): ?>
                <div class="alert alert-info">
                    This customer has not submitted any preorder for this booking yet.
                </div>
            <?php else: ?>

                <div class="alert <?= $preorderStatus === 'Submitted' ? 'alert-success' : 'alert-warning' ?>">
                    <strong>Preorder Status:</strong> <?= h($preorderStatus) ?>

                    <?php if ($submittedAt): ?>
                        <br>
                        <small>Submitted at: <?= h($submittedAt) ?></small>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Food Item</th>
                                <th>Category</th>
                                <th>Quantity</th>
                                <th>Price</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($preorders as $p): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($p['image_path'])): ?>
                                            <img
                                                src="../<?= h($p['image_path']) ?>"
                                                alt="<?= h($p['item_name']) ?>"
                                                class="admin-preorder-img">
                                        <?php else: ?>
                                            <div class="admin-preorder-img admin-preorder-placeholder">
                                                No Image
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <strong><?= h($p['item_name']) ?></strong>
                                    </td>

                                    <td><?= h($p['category']) ?></td>

                                    <td>
                                        <span class="badge bg-dark">
                                            <?= h($p['quantity']) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?php if ((float)$p['price'] > 0): ?>
                                            RM <?= h(number_format($p['price'], 2)) ?>
                                        <?php else: ?>
                                            <span class="text-muted">Price not set</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ((float)$p['price'] > 0): ?>
                                            RM <?= h(number_format((float)$p['price'] * (int)$p['quantity'], 2)) ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="muted-small mt-3 mb-0">
                    This list allows staff to view customer food preferences before the customer arrives.
                    Final preparation, item availability, and payment can be confirmed at the restaurant.
                </p>
            <?php endif; ?>
        </div>

    </main>
</body>

</html>
