<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_staff();

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM bookings WHERE booking_id = ?');
$stmt->execute([$id]);
$booking = $stmt->fetch();

if (!$booking) {
    header('Location: bookings.php');
    exit;
}

require_staff_booking_access($pdo, $booking);

if ($booking['status'] !== 'Pending') {
    header('Location: bookings.php');
    exit;
}

if (is_slot_available(
    $pdo,
    $booking['booking_date'],
    $booking['booking_time'],
    $booking['pax'],
    $booking['booking_id'],
    $booking['table_type'] ?? null,
    $booking['outlet_id'] ?? null
)) {
    $pdo->prepare("
        UPDATE bookings
        SET status = 'Confirmed', admin_reason = NULL
        WHERE booking_id = ?
    ")->execute([$id]);

    log_staff_activity($pdo, 'Approved booking', $booking, $booking['booking_code'] . ' was approved.');

    create_notification(
        $pdo,
        $booking['user_id'],
        $booking['booking_id'],
        'Booking Approved',
        'Your booking request ' . $booking['booking_code'] . ' has been approved.',
        'Booking Updates'
    );
} else {
    $reason = 'The selected time slot no longer has sufficient capacity.';

    $pdo->prepare("
        UPDATE bookings
        SET status = 'Declined', admin_reason = ?
        WHERE booking_id = ?
    ")->execute([$reason, $id]);

    log_staff_activity($pdo, 'Declined booking', $booking, $booking['booking_code'] . ' was declined because the slot was full.');

    create_notification(
        $pdo,
        $booking['user_id'],
        $booking['booking_id'],
        'Booking Declined',
        'Your booking request ' . $booking['booking_code'] . ' has been declined. Reason: ' . $reason,
        'Booking Updates'
    );
}

header('Location: bookings.php');
exit;
