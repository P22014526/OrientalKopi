<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();

$id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
$newDate = $_POST['new_date'] ?? ($_GET['new_date'] ?? '');
$newTime = $_POST['new_time'] ?? ($_GET['new_time'] ?? '');
$filterMode = $_POST['filter_mode'] ?? ($_GET['filter_mode'] ?? 'all');
$filterTime = $_POST['filter_time'] ?? ($_GET['filter_time'] ?? '');

$today = date('Y-m-d');
$openTime = '09:30';
$closeTime = '22:30';
$error = '';

$stmt = $pdo->prepare("
    SELECT *
    FROM bookings
    WHERE booking_id = ?
      AND user_id = ?
    LIMIT 1
");
$stmt->execute([$id, $_SESSION['user_id']]);
$booking = $stmt->fetch();

if (!$booking || $booking['status'] !== 'Confirmed') {
    header('Location: my_bookings.php');
    exit;
}

$originalBookingTimestamp = strtotime(
    $booking['booking_date'] . ' ' . $booking['booking_time']
);
$rescheduleAllowed = time() <= ($originalBookingTimestamp - 86400);

if (!$rescheduleAllowed) {
    $error = 'Rescheduling must be requested at least 24 hours before the original booking.';
} elseif ($newDate === '' || $newTime === '') {
    $error = 'Please select a new date and time slot.';
} elseif ($newDate < $today) {
    $error = 'Past booking dates cannot be selected.';
} elseif (!preg_match('/^\d{2}:\d{2}$/', $newTime) || $newTime < $openTime || $newTime > $closeTime) {
    $error = 'Please select a valid time within the restaurant opening hours.';
} else {
    $newBookingTimestamp = strtotime($newDate . ' ' . $newTime);

    if ($newBookingTimestamp === false) {
        $error = 'Please select a valid booking date and time.';
    } elseif ($newBookingTimestamp <= time()) {
        $error = 'The selected booking date and time have already passed.';
    } elseif (
        $newDate === $booking['booking_date']
        && $newTime === substr($booking['booking_time'], 0, 5)
    ) {
        $error = 'Please select a different date or time from your current booking.';
    } elseif (!is_slot_available($pdo, $newDate, $newTime, (int)$booking['pax'])) {
        $usage = slot_usage($pdo, $newDate, $newTime);
        $remaining = max(0, (int)$usage['capacity'] - (int)$usage['used']);
        $error = "The selected slot is unavailable. Only {$remaining} pax remaining.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $reward = reward_for_date($newDate);

    $stmt = $pdo->prepare("
        INSERT INTO bookings (
            booking_code,
            user_id,
            customer_name,
            phone,
            booking_date,
            booking_time,
            pax,
            status,
            request_type,
            old_booking_date,
            old_booking_time,
            reward_text
        )
        VALUES (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            'Pending',
            'Reschedule',
            ?,
            ?,
            ?
        )
    ");

    $stmt->execute([
        null,
        $_SESSION['user_id'],
        $booking['customer_name'],
        $booking['phone'],
        $newDate,
        $newTime,
        $booking['pax'],
        $booking['booking_date'],
        $booking['booking_time'],
        $reward
    ]);

    $newId = $pdo->lastInsertId();
    $newCode = booking_code($newId);

    $pdo->prepare("
        UPDATE bookings
        SET booking_code = ?
        WHERE booking_id = ?
    ")->execute([$newCode, $newId]);

    header('Location: reschedule_confirm.php?id=' . $newId);
    exit;
}

$backUrl = 'reschedule.php?id=' . urlencode((string)$id)
    . '&new_date=' . urlencode($newDate)
    . '&filter_mode=' . urlencode($filterMode)
    . '&filter_time=' . urlencode($filterTime);

include 'includes/header.php';
?>

<a href="<?= h($backUrl) ?>" class="btn-nav-icon mb-3">
    <span class="btn-nav-icon-symbol">←</span>
    <span>Back</span>
</a>

<div class="card ok-card p-4">
    <div class="section-title">Review Request</div>
    <h3>Confirm Reschedule Details</h3>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="stat-pill h-100">
                <strong>Current Booking</strong><br>
                <?= h(format_date_short($booking['booking_date'])) ?>
                |
                <?= h(format_time12($booking['booking_time'])) ?>
                <div class="muted-small mt-1">
                    Booking ID: <?= h($booking['booking_code']) ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="stat-pill h-100">
                <strong>New Requested Slot</strong><br>
                <?= h(format_date_short($newDate)) ?>
                |
                <?= h(format_time12($newTime)) ?>
                <div class="muted-small mt-1">
                    <?= h($booking['pax']) ?> Pax
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-warning">
        This request will be sent to the administrator for approval. Your original booking remains unchanged until the request is approved.
    </div>

    <div class="d-flex flex-wrap gap-2">
        <?php if (!$error): ?>
            <form method="post">
                <input type="hidden" name="id" value="<?= h($id) ?>">
                <input type="hidden" name="new_date" value="<?= h($newDate) ?>">
                <input type="hidden" name="new_time" value="<?= h($newTime) ?>">
                <input type="hidden" name="filter_mode" value="<?= h($filterMode) ?>">
                <input type="hidden" name="filter_time" value="<?= h($filterTime) ?>">
                <button class="btn btn-dark">Confirm and Send Request</button>
            </form>
        <?php endif; ?>

        <a href="<?= h($backUrl) ?>" class="btn btn-outline-dark">
            Choose Another Slot
        </a>
    </div>
</div>

<?php
include 'includes/chatbot_widget.php';
include 'includes/footer.php';
?>
