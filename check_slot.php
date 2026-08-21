<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();

header('Content-Type: application/json');

$date = $_GET['date'] ?? '';
$time = $_GET['time'] ?? '';
$pax = (int)($_GET['pax'] ?? 0);

$openTime = '09:30';
$closeTime = '22:30';

if (!$date || !$time || $pax <= 0) {
    echo json_encode([
        'available' => false,
        'message' => 'Please select date, time, and pax.'
    ]);
    exit;
}

$maxCapacity = max_capacity($pdo);

if ($time < $openTime || $time > $closeTime) {
    echo json_encode([
        'available' => false,
        'message' => 'Booking unavailable. Please select a time between 9:30 AM and 10:30 PM.'
    ]);
    exit;
}

if ($pax > $maxCapacity) {
    echo json_encode([
        'available' => false,
        'message' => "Booking unavailable. Pax exceeds the maximum restaurant capacity of {$maxCapacity}."
    ]);
    exit;
}

$usage = slot_usage($pdo, $date, $time);
$used = (int)$usage['used'];
$capacity = (int)$usage['capacity'];
$remaining = max(0, $capacity - $used);$remaining = max(0, $capacity - $used);

if ($remaining <= 0) {
    echo json_encode([
        'available' => false,
        'message' => 'Selected time slot is full. Please choose another time.',
        'used' => $used,
        'capacity' => $capacity,
        'remaining' => $remaining
    ]);
    exit;
}

if ($pax > $remaining) {
    echo json_encode([
        'available' => false,
        'message' => "Selected slot is unavailable. Only {$remaining} pax remaining for this time slot.",
        'used' => $used,
        'capacity' => $capacity,
        'remaining' => $remaining
    ]);
    exit;
}

echo json_encode([
    'available' => true,
    'message' => "Selected slot is available. {$remaining} pax remaining.",
    'used' => $used,
    'capacity' => $capacity,
    'remaining' => $remaining
]);
