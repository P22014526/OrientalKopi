<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_staff();

$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM bookings WHERE booking_id = ?");
$stmt->execute([$id]);
$booking = $stmt->fetch();

if (!$booking) {
    header('Location: bookings.php');
    exit;
}

require_staff_booking_access($pdo, $booking);

if ($booking['status'] === 'Confirmed') {
    $stmt = $pdo->prepare("
        UPDATE bookings
        SET status = 'Completed'
        WHERE booking_id = ?
    ");
    $stmt->execute([$id]);

    award_completion_badge($pdo, $booking['user_id'], $booking['booking_id']);

    log_staff_activity($pdo, 'Completed booking', $booking, $booking['booking_code'] . ' was marked completed.');

    create_notification(
        $pdo,
        $booking['user_id'],
        $booking['booking_id'],
        'Booking Completed',
        'Your booking ' . $booking['booking_code'] . ' has been completed. You have earned 1 reward badge.',
        'Booking Updates'
    );
}

header('Location: bookings.php');
exit;
