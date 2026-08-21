<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();

$userId = $_SESSION['user_id'];

/* Reminders: upcoming confirmed and pending bookings */
$stmt = $pdo->prepare("
    SELECT *
    FROM bookings
    WHERE user_id = ?
      AND status IN ('Confirmed', 'Pending')
      AND booking_date >= CURDATE()
    ORDER BY booking_date ASC, booking_time ASC
");
$stmt->execute([$userId]);
$reminders = $stmt->fetchAll();

/* Booking Updates and Booking History only */
$stmt = $pdo->prepare("
    SELECT *
    FROM notifications
    WHERE user_id = ?
      AND category IN ('Booking Updates', 'Booking History')
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

$bookingUpdates = [];
$bookingHistory = [];

foreach ($notifications as $notification) {
    if ($notification['category'] === 'Booking History') {
        $bookingHistory[] = $notification;
    } else {
        $bookingUpdates[] = $notification;
    }
}

/* Mark displayed notifications as read */
$pdo->prepare("
    UPDATE notifications
    SET is_read = 1
    WHERE user_id = ?
      AND category IN ('Booking Updates', 'Booking History')
")->execute([$userId]);

include 'includes/header.php';
?>

<a href="view_profile.php" class="btn-nav-icon mb-3">
    <span class="btn-nav-icon-symbol">←</span>
    <span>Profile</span>
</a>

<div class="card ok-card p-4">
    <div class="section-title">Customer Area</div>
    <h3>Notifications</h3>
    <p class="muted-small">
        View reminders, booking status updates, and completed booking history.
    </p>

    <!-- REMINDERS -->
    <section class="notification-section mb-4">
        <div class="notification-section-heading">
            <h4 class="mb-1">⏰ Reminders</h4>
            <p class="muted-small mb-0">
                Upcoming confirmed and pending reservations.
            </p>
        </div>

        <div class="mt-3">
            <?php if (!$reminders): ?>
                <div class="alert alert-info mb-0">
                    No upcoming reminders.
                </div>
            <?php endif; ?>

            <?php foreach ($reminders as $r): ?>
                <div class="notification-card reminder-notification mb-3">
                    <div class="d-flex justify-content-between flex-wrap gap-2">
                        <strong><?= h($r['booking_code']) ?></strong>
                        <small class="text-muted">
                            <?= h(date('d/m/Y', strtotime($r['booking_date']))) ?>
                            |
                            <?= h(format_time12($r['booking_time'])) ?>
                        </small>
                    </div>

                    <p class="mb-1 mt-2">
                        Pax: <strong><?= h($r['pax']) ?></strong>
                    </p>

                    <p class="mb-1">
                        Status:
                        <span class="badge bg-<?= h(status_badge_class($r['status'])) ?>">
                            <?= h($r['status']) ?>
                        </span>
                    </p>

                    <p class="mb-0 muted-small">
                        Please review your reservation details and arrive on time.
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- BOOKING UPDATES -->
    <section class="notification-section mb-4">
        <div class="notification-section-heading">
            <h4 class="mb-1">📌 Booking Updates</h4>
            <p class="muted-small mb-0">
                Pending requests and administrator approval, decline, or cancellation updates.
            </p>
        </div>

        <div class="mt-3">
            <?php if (!$bookingUpdates): ?>
                <div class="alert alert-info mb-0">
                    No booking updates.
                </div>
            <?php endif; ?>

            <?php foreach ($bookingUpdates as $n): ?>
                <div class="notification-card booking-update-notification mb-3">
                    <div class="d-flex justify-content-between flex-wrap gap-2">
                        <strong><?= h($n['title']) ?></strong>
                        <small class="text-muted">
                            <?= h(date('d/m/Y h:i A', strtotime($n['created_at']))) ?>
                        </small>
                    </div>

                    <p class="mb-0 mt-2">
                        <?= h($n['message']) ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- BOOKING HISTORY -->
    <section class="notification-section">
        <div class="notification-section-heading">
            <h4 class="mb-1">📚 Booking History</h4>
            <p class="muted-small mb-0">
                Completed reservations are archived here.
            </p>
        </div>

        <div class="mt-3">
            <?php if (!$bookingHistory): ?>
                <div class="alert alert-info mb-0">
                    No completed booking history.
                </div>
            <?php endif; ?>

            <?php foreach ($bookingHistory as $n): ?>
                <div class="notification-card booking-history-notification mb-3">
                    <div class="d-flex justify-content-between flex-wrap gap-2">
                        <strong><?= h($n['title']) ?></strong>
                        <small class="text-muted">
                            <?= h(date('d/m/Y h:i A', strtotime($n['created_at']))) ?>
                        </small>
                    </div>

                    <p class="mb-0 mt-2">
                        <?= h($n['message']) ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<?php include 'includes/footer.php'; ?>
