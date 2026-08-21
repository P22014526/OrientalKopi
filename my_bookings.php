<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();

$columnCheck = $pdo->query("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'reviews'
      AND COLUMN_NAME = 'booking_id'
");

$hasReviewBookingId = (bool)$columnCheck->fetchColumn();

if ($hasReviewBookingId) {
    $stmt = $pdo->prepare("
        SELECT b.*,
               r.review_id,
               r.rating AS review_rating
        FROM bookings b
        LEFT JOIN reviews r
          ON r.booking_id = b.booking_id
         AND r.user_id = b.user_id
        WHERE b.user_id = ?
        ORDER BY b.booking_date DESC, b.booking_time DESC
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT b.*,
               NULL AS review_id,
               NULL AS review_rating
        FROM bookings b
        WHERE b.user_id = ?
        ORDER BY b.booking_date DESC, b.booking_time DESC
    ");
}

$stmt->execute([$_SESSION['user_id']]);
$bookings = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="card ok-card p-4">
    <div class="section-title">Customer Area</div>
    <h3>My Bookings</h3>

    <p class="muted-small">
        Manage active reservations and write a review after a booking has been completed.
        Use the pencil button to view the available actions for each booking.
    </p>

    <?php if (!$hasReviewBookingId): ?>
        <div class="alert alert-warning">
            Run <strong>review_booking_update.sql</strong> once in phpMyAdmin to enable completed-booking reviews.
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Pax</th>
                    <th>Type</th>
                    <th>Reason</th>
                    <th class="text-center">Actions</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($bookings as $b): ?>
                    <?php
                    $bookingTimestamp = strtotime(
                        $b['booking_date'] . ' ' . $b['booking_time']
                    );

                    $rescheduleDeadline = $bookingTimestamp
                        ? $bookingTimestamp - 86400
                        : 0;

                    $canReschedule =
                        $b['status'] === 'Confirmed'
                        && $bookingTimestamp !== false
                        && time() <= $rescheduleDeadline;

                    $hasAvailableAction =
                        $b['status'] === 'Confirmed'
                        || $b['status'] === 'Pending'
                        || $b['status'] === 'Completed';
                    ?>

                    <tr>
                        <td><?= h($b['booking_code']) ?></td>
                        <td><?= h(date('d/m/Y', strtotime($b['booking_date']))) ?></td>
                        <td><?= h(format_time12($b['booking_time'])) ?></td>
                        <td><?= h($b['pax']) ?></td>
                        <td><?= h($b['request_type']) ?></td>

                        <td>
                            <?php if (
                                in_array($b['status'], ['Declined', 'Cancelled'], true)
                                && !empty($b['admin_reason'])
                            ): ?>
                                <?= h($b['admin_reason']) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>

                        <td class="text-center">
                            <?php if ($hasAvailableAction): ?>
                                <div class="dropdown">
                                    <button
                                        class="btn btn-sm btn-action-icon dropdown-toggle"
                                        type="button"
                                        data-bs-toggle="dropdown"
                                        aria-expanded="false"
                                        title="Manage booking"
                                        aria-label="Manage booking <?= h($b['booking_code']) ?>">
                                        ✏️
                                    </button>

                                    <ul class="dropdown-menu dropdown-menu-end booking-actions-dropdown">
                                        <?php if ($b['status'] === 'Confirmed'): ?>
                                            <?php if ($canReschedule): ?>
                                                <li>
                                                    <a
                                                        class="dropdown-item"
                                                        href="reschedule.php?id=<?= h($b['booking_id']) ?>">
                                                        🔁 Reschedule Booking
                                                    </a>
                                                </li>
                                            <?php else: ?>
                                                <li>
                                                    <span
                                                        class="dropdown-item disabled"
                                                        title="Rescheduling must be requested at least 24 hours before the booking.">
                                                        🔁 Reschedule Unavailable
                                                    </span>
                                                </li>
                                            <?php endif; ?>

                                            <li>
                                                <a
                                                    class="dropdown-item text-danger"
                                                    href="cancel_booking.php?id=<?= h($b['booking_id']) ?>">
                                                    ❌ Cancel Booking
                                                </a>
                                            </li>

                                            <li>
                                                <a
                                                    class="dropdown-item"
                                                    href="preorder_menu.php?id=<?= h($b['booking_id']) ?>">
                                                    🍽️ Menu / Preorder
                                                </a>
                                            </li>

                                        <?php elseif (
                                            $b['status'] === 'Pending'
                                            && $b['request_type'] === 'Party'
                                        ): ?>
                                            <li>
                                                <a
                                                    class="dropdown-item"
                                                    href="edit_party_request.php?id=<?= h($b['booking_id']) ?>">
                                                    🎉 View / Edit Party Request
                                                </a>
                                            </li>

                                            <li>
                                                <a
                                                    class="dropdown-item text-danger"
                                                    href="cancel_booking.php?id=<?= h($b['booking_id']) ?>">
                                                    ❌ Cancel Pending Request
                                                </a>
                                            </li>

                                        <?php elseif ($b['status'] === 'Pending'): ?>
                                            <li>
                                                <a
                                                    class="dropdown-item text-danger"
                                                    href="cancel_booking.php?id=<?= h($b['booking_id']) ?>">
                                                    ❌ Cancel Pending Request
                                                </a>
                                            </li>

                                        <?php elseif ($b['status'] === 'Completed'): ?>
                                            <?php if (!$hasReviewBookingId): ?>
                                                <li>
                                                    <span class="dropdown-item disabled">
                                                        ⚠️ Review Setup Required
                                                    </span>
                                                </li>

                                            <?php elseif (!empty($b['review_id'])): ?>
                                                <li>
                                                    <span class="dropdown-item disabled">
                                                        ✅ Reviewed <?= str_repeat('⭐', (int)$b['review_rating']) ?>
                                                    </span>
                                                </li>

                                            <?php else: ?>
                                                <li>
                                                    <a
                                                        class="dropdown-item"
                                                        href="write_review.php?id=<?= h($b['booking_id']) ?>">
                                                        ⭐ Write Review
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            <?php else: ?>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-action-icon disabled"
                                    disabled
                                    title="No available actions">
                                    ✏️
                                </button>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="badge bg-<?= h(status_badge_class($b['status'])) ?>">
                                <?= h($b['status']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$bookings): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">
                            No bookings found.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
