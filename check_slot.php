<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();
require_customer_outlet($pdo);

header('Content-Type: application/json');

$date = $_GET['date'] ?? '';
$time = $_GET['time'] ?? '';
$pax = (int)($_GET['pax'] ?? 0);
$tableType = $_GET['table_type'] ?? '';
$tableOptions = table_type_options($pdo);
$currentOutlet = current_outlet($pdo);
$outletId = (int)$currentOutlet['outlet_id'];

$openTime = '09:30';
$closeTime = '22:30';

if (!$date || !$time || $pax <= 0 || !isset($tableOptions[$tableType])) {
    echo json_encode([
        'available' => false,
        'message' => 'Please select date, time, and table type.'
    ]);
    exit;
}

$maxCapacity = max_capacity($pdo);
$tableSummary = table_capacity_summary($pdo);

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
        'message' => "Booking unavailable. Pax exceeds the table capacity of {$maxCapacity}.",
        'table_setup' => $tableSummary
    ]);
    exit;
}

$usage = slot_usage($pdo, $date, $time, $pax, null, $tableType, $outletId);
$used = (int)$usage['used'];
$capacity = (int)$usage['capacity'];
$remaining = (int)$usage['remaining_seats'];
$remainingTables = (int)$usage['remaining_tables'];

if (!$usage['available_for_pax']) {
    $tableLabel = table_type_label($pdo, $tableType);

    echo json_encode([
        'available' => false,
        'message' => "Selected slot is unavailable. No {$tableLabel} is available for this time slot.",
        'used' => $used,
        'capacity' => $capacity,
        'remaining' => $remaining,
        'remaining_tables' => $remainingTables,
        'table_setup' => $tableSummary
    ]);
    exit;
}

echo json_encode([
    'available' => true,
    'message' => "Selected slot is available. Table reserved: {$usage['allocation_label']}.",
    'used' => $used,
    'capacity' => $capacity,
    'remaining' => $remaining,
    'remaining_tables' => $remainingTables,
    'allocation' => $usage['allocation_label'],
    'table_setup' => $tableSummary
]);
