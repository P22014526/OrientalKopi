<?php
require_once __DIR__ . '/../includes/functions.php';
$today = '2026-10-15';
foreach (['Pending', 'Confirmed', 'Completed', 'Cancelled', 'Declined'] as $status) {
    foreach (['2026-10-14', '2026-10-15', '2026-10-16'] as $date) {
        $expected = $date < $today && in_array($status, ['Pending', 'Confirmed'], true);
        if (booking_needs_review(['status' => $status, 'booking_date' => $date], $today) !== $expected) {
            throw new RuntimeException("Incorrect review flag for $date $status");
        }
    }
}
if (booking_needs_review([], $today)) throw new RuntimeException('Empty booking flagged');
if (status_badge_class('Needs Review') !== 'danger') throw new RuntimeException('Review badge color');
echo "Booking review tests passed.\n";
