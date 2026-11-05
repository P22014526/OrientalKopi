<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_staff();

$id = (int)($_GET['id'] ?? 0);
$error = '';
$success = '';

$stmt = $pdo->prepare("
    SELECT b.*, o.branch_name, s.state_name
    FROM bookings b
    LEFT JOIN outlets o ON o.outlet_id = b.outlet_id
    LEFT JOIN outlet_states s ON s.state_id = o.state_id
    WHERE b.booking_id = ?
");
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['customer_name'] ?? '');
    $date = trim($_POST['booking_date'] ?? '');
    $time = trim($_POST['booking_time'] ?? '');
    $pax = max(1, (int)($_POST['pax'] ?? 1));

    if ($name === '' || $date === '' || $time === '') {
        $error = 'Name, date, and time are required.';
    } elseif ($time < '09:30' || $time > '22:30') {
        $error = 'Booking time must be between 9:30 AM and 10:30 PM.';
    } elseif (!is_slot_available($pdo, $date, $time, $pax, $id, $b['table_type'] ?? null, $b['outlet_id'] ?? null)) {
        $error = 'The selected branch slot no longer has enough table availability.';
    } else {
        $stmt = $pdo->prepare('UPDATE bookings SET customer_name=?, booking_date=?, booking_time=?, pax=? WHERE booking_id=?');
        $stmt->execute([$name, $date, $time, $pax, $id]);
        $success = 'Booking details updated successfully.';
        log_staff_activity($pdo, 'Edited booking', $b, $b['booking_code'] . ' details were updated.');

        $stmt = $pdo->prepare("
            SELECT b.*, o.branch_name, s.state_name
            FROM bookings b
            LEFT JOIN outlets o ON o.outlet_id = b.outlet_id
            LEFT JOIN outlet_states s ON s.state_id = o.state_id
            WHERE b.booking_id = ?
        ");
        $stmt->execute([$id]);
        $b = $stmt->fetch();
    }
}

$stmt = $pdo->prepare("\n    SELECT p.quantity, p.status, p.submitted_at,\n           m.item_name, m.price, m.image_path\n    FROM preorders p\n    JOIN menu_items m ON m.item_id = p.item_id\n    WHERE p.booking_id = ?\n      AND p.status = 'Submitted'\n    ORDER BY m.item_name\n");
$stmt->execute([$id]);
$preorders = $stmt->fetchAll();

$preorderTotal = 0;
foreach ($preorders as $item) {
    $preorderTotal += (float)$item['price'] * (int)$item['quantity'];
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Booking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
  <?php include 'staff_sidebar.php'; ?>
    <main class="container py-4">
        <a href="bookings.php" class="btn-nav-icon mb-3"><span class="btn-nav-icon-symbol">←</span><span>Bookings</span></a>

        <div class="card ok-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <div class="section-title">Booking Management</div>
                    <h3 class="mb-1">Edit Booking</h3>
                    <p class="muted-small mb-0">Booking ID: <strong><?= h($b['booking_code']) ?></strong></p>
                </div>
                <span class="badge bg-<?= h(status_badge_class(booking_display_status($b))) ?>"><?= h(booking_display_status($b)) ?></span>
                <?php if (booking_needs_review($b)): ?><span class="small text-muted">Previously <?= h($b['status']) ?></span><?php endif; ?>
            </div>

            <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

            <?php if (in_array($b['status'], ['Declined', 'Cancelled'], true) && !empty($b['admin_reason'])): ?>
                <div class="alert alert-warning"><strong>Reason:</strong> <?= h($b['admin_reason']) ?></div>
            <?php endif; ?>

            <form method="post">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Customer Name</label>
                        <input name="customer_name" class="form-control" value="<?= h($b['customer_name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input class="form-control" value="<?= h($b['phone']) ?>" disabled>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Outlet</label>
                        <input class="form-control" value="<?= h(($b['branch_name'] ?? 'Unassigned') . ' - ' . ($b['state_name'] ?? '-')) ?>" disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Date</label>
                        <input type="date" name="booking_date" class="form-control" value="<?= h($b['booking_date']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Time</label>
                        <input type="time" name="booking_time" class="form-control" value="<?= h(substr($b['booking_time'], 0, 5)) ?>" min="09:30" max="22:30" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Pax</label>
                        <input type="number" name="pax" class="form-control" min="1" value="<?= h($b['pax']) ?>" required>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button class="btn btn-dark">Save Changes</button>
                </div>
            </form>
        </div>

        <div class="card ok-card p-4 mb-4">
            <div class="section-title">Booking Actions</div>
            <h4>Manage Booking Status</h4>
            <p class="muted-small">These buttons keep your existing notification, decline-reason, cancellation, badge, and voucher workflows.</p>

            <div class="action-buttons">
                <?php if ($b['status'] === 'Pending'): ?>
                    <?php if ($b['request_type'] === 'Party'): ?>
                        <a class="btn btn-outline-dark" href="view_request.php?id=<?= h($b['booking_id']) ?>">View Party Request</a>
                    <?php endif; ?>
                    <a class="btn btn-success" href="approve.php?id=<?= h($b['booking_id']) ?>">Approve</a>
                    <a class="btn btn-danger" href="decline.php?id=<?= h($b['booking_id']) ?>">Decline</a>
                <?php elseif ($b['status'] === 'Confirmed'): ?>
                    <a class="btn btn-success" href="complete_booking.php?id=<?= h($b['booking_id']) ?>">Complete</a>
                    <a class="btn btn-outline-danger" href="cancel_no_show.php?id=<?= h($b['booking_id']) ?>&mode=cancel">Cancel Booking</a>
                    <?php if ($canMarkNoShow): ?>
                        <a class="btn btn-danger" href="cancel_no_show.php?id=<?= h($b['booking_id']) ?>&mode=no_show">Mark No-show</a>
                    <?php else: ?>
                        <span
                            class="btn btn-outline-secondary disabled"
                            title="Available after <?= h($noShowAvailableAt ? $noShowAvailableAt->format('d/m/Y g:i A') : no_show_grace_label($noShowGraceMinutes)) ?>">
                            No-show after <?= h(no_show_grace_label($noShowGraceMinutes)) ?>
                        </span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="text-muted">No additional actions are available for this booking.</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ok-card p-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <div class="section-title">Submitted Preorder</div>
                    <h4 class="mb-0">Customer Food Preorder</h4>
                </div>
                <?php if ($preorders): ?><span class="badge bg-success"><?= h(count($preorders)) ?> item(s)</span><?php endif; ?>
            </div>

            <?php if (!$preorders): ?>
                <div class="alert alert-info mb-0">This booking does not have a submitted preorder.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Menu Item</th>
                                <th>Price</th>
                                <th>Quantity</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preorders as $item): $subtotal = (float)$item['price'] * (int)$item['quantity']; ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($item['image_path'])): ?>
                                            <img src="../<?= h($item['image_path']) ?>" alt="<?= h($item['item_name']) ?>" class="admin-preorder-img">
                                        <?php else: ?>
                                            <div class="admin-preorder-placeholder">No image</div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($item['item_name']) ?></td>
                                    <td>RM <?= h(number_format((float)$item['price'], 2)) ?></td>
                                    <td><?= h($item['quantity']) ?></td>
                                    <td>RM <?= h(number_format($subtotal, 2)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="4" class="text-end">Total</th>
                                <th>RM <?= h(number_format($preorderTotal, 2)) ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>

</html>
