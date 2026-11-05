<?php
function h($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function prevent_dynamic_page_cache()
{
    if (headers_sent()) {
        return;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

function current_outlet_id()
{
    return isset($_SESSION['selected_outlet_id']) ? (int)$_SESSION['selected_outlet_id'] : null;
}

function current_outlet($pdo)
{
    $outletId = current_outlet_id();

    if (!$outletId) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT o.*, s.state_name
        FROM outlets o
        JOIN outlet_states s ON s.state_id = o.state_id
        WHERE o.outlet_id = ?
          AND o.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$outletId]);
    $outlet = $stmt->fetch();

    if (!$outlet) {
        unset($_SESSION['selected_outlet_id'], $_SESSION['selected_state_id'], $_SESSION['selected_outlet_name']);
        return null;
    }

    return $outlet;
}

function require_customer_outlet($pdo)
{
    if (($_SESSION['role'] ?? '') !== 'customer') {
        return;
    }

    if (!current_outlet($pdo)) {
        header('Location: select_outlet.php');
        exit;
    }
}

function all_states_with_outlets($pdo)
{
    $stmt = $pdo->query("
        SELECT s.state_id, s.state_name, s.display_order,
               o.outlet_id, o.branch_name, o.location_detail, o.contact_phone, o.map_url
        FROM outlet_states s
        LEFT JOIN outlets o
          ON o.state_id = s.state_id
         AND o.is_active = 1
        ORDER BY s.display_order, s.state_name, o.branch_name
    ");
    $rows = $stmt->fetchAll();
    $states = [];

    foreach ($rows as $row) {
        $stateId = (int)$row['state_id'];

        if (!isset($states[$stateId])) {
            $states[$stateId] = [
                'state_id' => $stateId,
                'state_name' => $row['state_name'],
                'outlets' => []
            ];
        }

        if (!empty($row['outlet_id'])) {
            $states[$stateId]['outlets'][] = [
                'outlet_id' => (int)$row['outlet_id'],
                'branch_name' => $row['branch_name'],
                'location_detail' => $row['location_detail'],
                'contact_phone' => $row['contact_phone'],
                'map_url' => $row['map_url']
            ];
        }
    }

    return array_values($states);
}

function outlet_label($outlet)
{
    if (!$outlet) {
        return 'No outlet selected';
    }

    return $outlet['branch_name'] . ', ' . $outlet['state_name'];
}

function phone_href($phone)
{
    $digits = preg_replace('/\D+/', '', (string)$phone);

    if ($digits === '') {
        return '';
    }

    if (str_starts_with($digits, '0')) {
        $digits = '60' . substr($digits, 1);
    }

    return '+' . $digits;
}

function setting_value(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value !== false ? (string)$value : $default;
}

function upsert_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$key, $value]);
}

function staff_assigned_outlet_ids($pdo)
{
    if (($_SESSION['role'] ?? '') === 'admin') {
        return null;
    }

    if (($_SESSION['role'] ?? '') !== 'shop_owner') {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT outlet_id
        FROM shop_owner_outlets
        WHERE user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);

    return array_map('intval', array_column($stmt->fetchAll(), 'outlet_id'));
}

function staff_assigned_state_ids(PDO $pdo)
{
    if (($_SESSION['role'] ?? '') === 'admin') {
        return null;
    }

    if (($_SESSION['role'] ?? '') !== 'shop_owner') {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT o.state_id
        FROM shop_owner_outlets soo
        JOIN outlets o ON o.outlet_id = soo.outlet_id
        WHERE soo.user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);

    return array_map('intval', array_column($stmt->fetchAll(), 'state_id'));
}

function staff_can_access_state(PDO $pdo, int $stateId): bool
{
    if (($_SESSION['role'] ?? '') === 'admin') {
        return true;
    }

    $assignedStateIds = staff_assigned_state_ids($pdo);
    return in_array($stateId, $assignedStateIds, true);
}

function staff_can_access_outlet($pdo, $outletId)
{
    if (($_SESSION['role'] ?? '') === 'admin') {
        return true;
    }

    $assignedIds = staff_assigned_outlet_ids($pdo);
    return in_array((int)$outletId, $assignedIds, true);
}

function require_staff_booking_access($pdo, $booking)
{
    if (($_SESSION['role'] ?? '') === 'admin') {
        return;
    }

    if (!$booking || !staff_can_access_outlet($pdo, $booking['outlet_id'] ?? 0)) {
        header('Location: dashboard.php');
        exit;
    }
}

function cancellation_cutoff_minutes(PDO $pdo): int
{
    $minutes = (int)setting_value($pdo, 'cancellation_cutoff_minutes', '720');
    return max(0, $minutes);
}

function cancellation_cutoff_label(int $minutes): string
{
    if ($minutes <= 0) {
        return 'any time before the booking';
    }

    if ($minutes % 1440 === 0) {
        $days = (int)($minutes / 1440);
        return $days . ' day' . ($days === 1 ? '' : 's') . ' before the booking';
    }

    if ($minutes % 60 === 0) {
        $hours = (int)($minutes / 60);
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' before the booking';
    }

    return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' before the booking';
}

function booking_cancel_deadline(array $booking, int $cutoffMinutes): ?DateTimeImmutable
{
    try {
        $bookingAt = new DateTimeImmutable($booking['booking_date'] . ' ' . $booking['booking_time']);
        return $bookingAt->modify('-' . max(0, $cutoffMinutes) . ' minutes');
    } catch (Throwable $e) {
        return null;
    }
}

function customer_can_cancel_booking(array $booking, int $cutoffMinutes): bool
{
    if (!in_array($booking['status'] ?? '', ['Confirmed', 'Pending'], true)) {
        return false;
    }

    $deadline = booking_cancel_deadline($booking, $cutoffMinutes);

    if (!$deadline) {
        return false;
    }

    return new DateTimeImmutable() <= $deadline;
}

function no_show_grace_minutes(PDO $pdo): int
{
    $minutes = (int)setting_value($pdo, 'no_show_grace_minutes', '15');
    return max(0, $minutes);
}

function no_show_grace_label(int $minutes): string
{
    if ($minutes <= 0) {
        return 'at the booking time';
    }

    return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' after the booking time';
}

function booking_no_show_available_at(array $booking, int $graceMinutes): ?DateTimeImmutable
{
    try {
        $bookingAt = new DateTimeImmutable($booking['booking_date'] . ' ' . $booking['booking_time']);
        return $bookingAt->modify('+' . max(0, $graceMinutes) . ' minutes');
    } catch (Throwable $e) {
        return null;
    }
}

function booking_can_be_marked_no_show(array $booking, int $graceMinutes): bool
{
    if (($booking['status'] ?? '') !== 'Confirmed') {
        return false;
    }

    $availableAt = booking_no_show_available_at($booking, $graceMinutes);

    if (!$availableAt) {
        return false;
    }

    return new DateTimeImmutable() >= $availableAt;
}

function daily_booking_limit(PDO $pdo): int
{
    $limit = (int)setting_value($pdo, 'daily_booking_limit', '3');
    return min(10, max(1, $limit));
}

function booking_meal_period(string $time): string
{
    $parts = explode(':', $time);
    $hour = (int)($parts[0] ?? 0);
    $minute = (int)($parts[1] ?? 0);
    $minutesAfterMidnight = ($hour * 60) + $minute;

    if ($minutesAfterMidnight < 12 * 60) {
        return 'breakfast';
    }

    if ($minutesAfterMidnight < 17 * 60) {
        return 'lunch';
    }

    return 'dinner';
}

function booking_meal_period_label(string $period): string
{
    return match ($period) {
        'breakfast' => 'breakfast',
        'lunch' => 'lunch',
        'dinner' => 'dinner',
        default => 'meal period'
    };
}

function customer_booking_policy_violation(
    PDO $pdo,
    int $userId,
    string $bookingDate,
    string $bookingTime,
    ?int $excludeBookingId = null
): ?string {
    $sql = "
        SELECT booking_id, booking_time
        FROM bookings
        WHERE user_id = ?
          AND booking_date = ?
          AND status IN ('Pending', 'Confirmed')
    ";
    $params = [$userId, $bookingDate];

    if ($excludeBookingId !== null && $excludeBookingId > 0) {
        $sql .= ' AND booking_id <> ?';
        $params[] = $excludeBookingId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $activeBookings = $stmt->fetchAll();
    $requestedPeriod = booking_meal_period($bookingTime);

    foreach ($activeBookings as $activeBooking) {
        if (booking_meal_period((string)$activeBooking['booking_time']) === $requestedPeriod) {
            $periodLabel = booking_meal_period_label($requestedPeriod);
            return "You already have an active {$periodLabel} booking on "
                . format_date_short($bookingDate)
                . ". Only one booking is allowed per meal period.";
        }
    }

    $dailyLimit = daily_booking_limit($pdo);

    if (count($activeBookings) >= $dailyLimit) {
        return "You have reached the limit of {$dailyLimit} active bookings for "
            . format_date_short($bookingDate)
            . '. Cancel or reschedule an existing booking before making another.';
    }

    return null;
}

function ensure_staff_activity_log_table(PDO $pdo): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS staff_activity_log (
            activity_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            outlet_id INT NULL,
            booking_id INT NULL,
            action VARCHAR(80) NOT NULL,
            details VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_staff_activity_user (user_id),
            INDEX idx_staff_activity_outlet (outlet_id),
            INDEX idx_staff_activity_booking (booking_id),
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
            FOREIGN KEY (outlet_id) REFERENCES outlets(outlet_id) ON DELETE SET NULL,
            FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE SET NULL
        )
    ");

    $checked = true;
}

function log_staff_activity(PDO $pdo, string $action, ?array $booking = null, string $details = ''): void
{
    try {
        ensure_staff_activity_log_table($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO staff_activity_log (user_id, outlet_id, booking_id, action, details)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $booking['outlet_id'] ?? null,
            $booking['booking_id'] ?? null,
            $action,
            $details !== '' ? mb_substr($details, 0, 255) : null
        ]);
    } catch (Throwable $e) {
        error_log('Staff activity log failed: ' . $e->getMessage());
    }
}

function booking_code($id)
{
    return 'BK' . str_pad($id, 4, '0', STR_PAD_LEFT);
}

function format_time12($time)
{
    if (!$time) return '';
    $timestamp = strtotime($time);
    return $timestamp ? date('g:i A', $timestamp) : $time;
}

function format_date_short($date)
{
    return date('d/m/Y', strtotime($date));
}

function reward_for_date($date)
{
    $today = new DateTime(date('Y-m-d'));
    $bookingDate = new DateTime($date);
    $diff = (int)$today->diff($bookingDate)->format('%r%a');
    if ($diff >= 7) return 'RM10 Voucher';
    if ($diff == 6) return 'Free Drink';
    if ($diff == 5) return '5% Discount';
    return null;
}

function default_table_inventory()
{
    return [
        ['type' => 'Couple tables', 'seats' => 2, 'tables' => 6],
        ['type' => 'Standard tables', 'seats' => 4, 'tables' => 7],
        ['type' => 'Family tables', 'seats' => 6, 'tables' => 2],
        ['type' => 'Party table', 'seats' => 8, 'tables' => 1]
    ];
}

function table_inventory($pdo, $outletId = null)
{
    $outletId = $outletId ?? current_outlet_id();
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$outletId ? 'table_inventory_outlet_' . (int)$outletId : 'table_inventory']);
    $row = $stmt->fetch();
    if (!$row && $outletId) {
        $stmt->execute(['table_inventory']);
        $row = $stmt->fetch();
    }
    $rawInventory = $row['setting_value'] ?? '';
    $decoded = json_decode($rawInventory, true);

    if (!is_array($decoded)) {
        $inventory = default_table_inventory();
        return $inventory;
    }

    $parsedInventory = [];

    foreach ($decoded as $row) {
        $seats = (int)($row['seats'] ?? 0);
        $tables = (int)($row['tables'] ?? 0);

        if ($seats > 0 && $tables >= 0) {
            $parsedInventory[] = [
                'type' => trim((string)($row['type'] ?? '')),
                'seats' => $seats,
                'tables' => $tables
            ];
        }
    }

    $inventory = $parsedInventory ?: default_table_inventory();
    return $inventory;
}

function table_capacity(array $inventory)
{
    $capacity = 0;

    foreach ($inventory as $row) {
        $capacity += (int)$row['seats'] * (int)$row['tables'];
    }

    return $capacity;
}

function table_capacity_summary($pdo, $outletId = null)
{
    $parts = [];

    foreach (table_inventory($pdo, $outletId) as $row) {
        $seatLabel = ((int)$row['seats'] === 1) ? 'seat' : 'seats';
        $tableLabel = ((int)$row['tables'] === 1) ? 'set' : 'sets';
        $type = trim((string)($row['type'] ?? ''));
        $prefix = $type !== '' ? $type . ': ' : '';
        $parts[] = $prefix . (int)$row['tables'] . ' ' . $tableLabel . ' of ' . (int)$row['seats'] . ' ' . $seatLabel;
    }

    return implode(', ', $parts);
}

function table_type_options($pdo, $outletId = null)
{
    $options = [];

    foreach (table_inventory($pdo, $outletId) as $index => $row) {
        if ((int)$row['tables'] === 0) continue;
        $type = trim((string)($row['type'] ?? 'Table'));
        $seats = (int)$row['seats'];
        $tables = (int)$row['tables'];
        $seatLabel = $seats === 1 ? 'seat' : 'seats';
        $tableLabel = $tables === 1 ? 'table' : 'tables';

        $options[(string)$index] = [
            'key' => (string)$index,
            'type' => $type,
            'seats' => $seats,
            'tables' => $tables,
            'label' => $type . ' - ' . $seats . ' ' . $seatLabel,
            'detail' => $tables . ' ' . $tableLabel . ' available'
        ];
    }

    return $options;
}

function table_type_key_for_pax($pdo, int $pax)
{
    $options = table_type_options($pdo);
    $selectedKey = array_key_first($options);
    $selectedSeats = null;
    $largestKey = $selectedKey;
    $largestSeats = 0;

    foreach ($options as $key => $option) {
        if ($option['seats'] > $largestSeats) {
            $largestSeats = $option['seats'];
            $largestKey = $key;
        }

        if ($option['seats'] >= $pax && ($selectedSeats === null || $option['seats'] < $selectedSeats)) {
            $selectedKey = $key;
            $selectedSeats = $option['seats'];
        }
    }

    return $selectedSeats === null ? $largestKey : $selectedKey;
}

function table_type_label($pdo, $tableTypeKey)
{
    $options = table_type_options($pdo);
    return $options[(string)$tableTypeKey]['label'] ?? 'selected table type';
}

function max_capacity($pdo, $outletId = null)
{
    return table_capacity(table_inventory($pdo, $outletId));
}

function active_capacity_statuses()
{
    return ['Confirmed', 'Pending'];
}

function total_tables(array $inventory)
{
    $total = 0;

    foreach ($inventory as $row) {
        $total += (int)$row['tables'];
    }

    return $total;
}

function table_allocation_summary(array $inventory, array $allocation)
{
    $parts = [];

    foreach ($allocation as $index => $count) {
        if ((int)$count <= 0 || !isset($inventory[$index])) {
            continue;
        }

        $row = $inventory[$index];
        $type = trim((string)($row['type'] ?? 'Table'));
        $seatLabel = ((int)$row['seats'] === 1) ? 'seat' : 'seats';
        $parts[] = (int)$count . ' x ' . $type . ' (' . (int)$row['seats'] . ' ' . $seatLabel . ' each)';
    }

    return implode(', ', $parts);
}

function find_exact_table_type_allocation(array $inventory, $tableTypeKey, int $pax): ?array
{
    $index = (int)$tableTypeKey;

    if (
        !isset($inventory[$index])
        || (int)$inventory[$index]['tables'] <= 0
        || $pax > (int)$inventory[$index]['seats']
    ) {
        return null;
    }

    return [
        'allocation' => [$index => 1],
        'seats' => (int)$inventory[$index]['seats'],
        'tables' => 1,
        'waste' => 0
    ];
}

function find_best_table_allocation(array $inventory, int $pax, $tableTypeKey = null): ?array
{
    if ($pax <= 0) {
        return null;
    }

    if ($tableTypeKey !== null && $tableTypeKey !== '') {
        return find_exact_table_type_allocation($inventory, $tableTypeKey, $pax);
    }

    $best = null;
    $current = [];
    $count = count($inventory);

    $search = function ($index) use (&$search, &$best, &$current, $inventory, $count, $pax) {
        if ($index >= $count) {
            $seatTotal = 0;
            $tableTotal = 0;

            foreach ($current as $rowIndex => $selectedTables) {
                $seatTotal += (int)$selectedTables * (int)$inventory[$rowIndex]['seats'];
                $tableTotal += (int)$selectedTables;
            }

            if ($seatTotal < $pax || $tableTotal === 0) {
                return;
            }

            $waste = $seatTotal - $pax;

            if (
                $best === null
                || $waste < $best['waste']
                || ($waste === $best['waste'] && $tableTotal < $best['tables'])
            ) {
                $best = [
                    'allocation' => $current,
                    'seats' => $seatTotal,
                    'tables' => $tableTotal,
                    'waste' => $waste
                ];
            }

            return;
        }

        $availableTables = (int)$inventory[$index]['tables'];

        for ($selected = 0; $selected <= $availableTables; $selected++) {
            $current[$index] = $selected;
            $search($index + 1);
        }
    };

    $search(0);
    return $best;
}

function booked_pax_for_slot($pdo, $date, $time, $excludeId = null, $outletId = null)
{
    $activeStatuses = active_capacity_statuses();
    $statusPlaceholders = implode(',', array_fill(0, count($activeStatuses), '?'));
    $outletId = $outletId ?? current_outlet_id();
    $sql = "
        SELECT booking_id, pax, table_type
        FROM bookings
        WHERE booking_date = ?
          AND booking_time = ?
          AND status IN ($statusPlaceholders)
    ";
    $params = array_merge([$date, $time], $activeStatuses);

    if ($outletId) {
        $sql .= " AND outlet_id = ?";
        $params[] = (int)$outletId;
    }

    if ($excludeId) {
        $sql .= " AND booking_id <> ?";
        $params[] = $excludeId;
    }

    $sql .= " ORDER BY pax DESC, created_at ASC, booking_id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function table_usage_for_slot($pdo, $date, $time, $excludeId = null, $outletId = null)
{
    $inventory = table_inventory($pdo, $outletId);
    $remainingInventory = $inventory;
    $bookings = booked_pax_for_slot($pdo, $date, $time, $excludeId, $outletId);
    $allocated = [];
    $unallocated = [];
    $usedTables = 0;
    $bookedPax = 0;

    foreach ($bookings as $booking) {
        $bookingPax = (int)$booking['pax'];
        $bookedPax += $bookingPax;
        $bookingTableType = $booking['table_type'] ?? null;
        $allocation = find_best_table_allocation($remainingInventory, $bookingPax, $bookingTableType);

        if ($allocation === null) {
            $unallocated[] = $booking;
            continue;
        }

        foreach ($allocation['allocation'] as $index => $selectedTables) {
            $remainingInventory[$index]['tables'] -= (int)$selectedTables;
        }

        $usedTables += (int)$allocation['tables'];
        $allocated[] = [
            'booking_id' => $booking['booking_id'],
            'pax' => $bookingPax,
            'table_type' => $bookingTableType,
            'allocation' => $allocation
        ];
    }

    return [
        'inventory' => $inventory,
        'remaining_inventory' => $remainingInventory,
        'allocated' => $allocated,
        'unallocated' => $unallocated,
        'used_tables' => $usedTables,
        'total_tables' => total_tables($inventory),
        'remaining_tables' => total_tables($remainingInventory),
        'used_pax' => $bookedPax,
        'remaining_seats' => table_capacity($remainingInventory),
        'capacity' => table_capacity($inventory)
    ];
}

function used_pax_for_slot($pdo, $date, $time, $excludeId = null, $outletId = null)
{
    $activeStatuses = active_capacity_statuses();
    $statusPlaceholders = implode(',', array_fill(0, count($activeStatuses), '?'));
    $outletId = $outletId ?? current_outlet_id();
    $sql = "SELECT COALESCE(SUM(pax),0) AS used_pax FROM bookings 
            WHERE booking_date = ? AND booking_time = ? AND status IN ($statusPlaceholders)";
    $params = array_merge([$date, $time], $activeStatuses);

    if ($outletId) {
        $sql .= " AND outlet_id = ?";
        $params[] = (int)$outletId;
    }

    if ($excludeId) {
        $sql .= " AND booking_id <> ?";
        $params[] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetch()['used_pax'];
}

function is_slot_available($pdo, $date, $time, $pax, $excludeId = null, $tableTypeKey = null, $outletId = null)
{
    $usage = table_usage_for_slot($pdo, $date, $time, $excludeId, $outletId);
    return empty($usage['unallocated'])
        && find_best_table_allocation($usage['remaining_inventory'], (int)$pax, $tableTypeKey) !== null;
}

function slot_usage($pdo, $date, $time, $pax = null, $excludeId = null, $tableTypeKey = null, $outletId = null)
{
    $tableUsage = table_usage_for_slot($pdo, $date, $time, $excludeId, $outletId);
    $requestedAllocation = null;
    $availableForPax = $tableUsage['remaining_tables'] > 0;

    if ($pax !== null) {
        $requestedAllocation = find_best_table_allocation(
            $tableUsage['remaining_inventory'],
            (int)$pax,
            $tableTypeKey
        );
        $availableForPax = $requestedAllocation !== null;
    }

    $ratio = $tableUsage['used_tables'] / max($tableUsage['total_tables'], 1);
    $status = 'Available';
    $color = 'success';

    if (!empty($tableUsage['unallocated']) || !$availableForPax) {
        $status = 'Full';
        $color = 'danger';
    } elseif ($ratio >= 0.6 || $tableUsage['remaining_tables'] <= 2) {
        $status = 'Limited';
        $color = 'warning';
    }

    return [
        'status' => $status,
        'color' => $color,
        'used' => $tableUsage['used_pax'],
        'reservation_count' => count($tableUsage['allocated']) + count($tableUsage['unallocated']),
        'remaining_inventory' => $tableUsage['remaining_inventory'],
        'capacity' => $tableUsage['capacity'],
        'used_tables' => $tableUsage['used_tables'],
        'total_tables' => $tableUsage['total_tables'],
        'remaining_tables' => $tableUsage['remaining_tables'],
        'remaining_seats' => $tableUsage['remaining_seats'],
        'available_for_pax' => $availableForPax,
        'allocation' => $requestedAllocation,
        'allocation_label' => $requestedAllocation
            ? table_allocation_summary($tableUsage['remaining_inventory'], $requestedAllocation['allocation'])
            : ''
    ];
}

function slot_status($pdo, $date, $time)
{
    $usage = slot_usage($pdo, $date, $time);
    return [$usage['status'], $usage['color']];
}

function common_slots()
{
    return ['09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30', '13:00', '13:30', '14:00', '18:00', '18:30', '19:00', '20:00'];
}

function booking_needs_review(array $booking, ?string $today = null): bool
{
    $today = $today ?? (new DateTimeImmutable('today', new DateTimeZone('Asia/Kuala_Lumpur')))->format('Y-m-d');
    return in_array($booking['status'] ?? '', ['Pending', 'Confirmed'], true)
        && !empty($booking['booking_date']) && $booking['booking_date'] < $today;
}

function booking_display_status(array $booking): string
{
    return booking_needs_review($booking) ? 'Needs Review' : (string)$booking['status'];
}

function status_badge_class($status)
{
    return match ($status) {
        'Confirmed' => 'success',
        'Pending' => 'warning',
        'Needs Review' => 'danger',
        'Completed' => 'secondary',
        'Cancelled' => 'danger',
        'Declined' => 'danger',
        default => 'light'
    };
}

function create_notification($pdo, $userId, $bookingId, $title, $message, $category = 'System')
{
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, booking_id, title, message, category)
        VALUES (?, ?, ?, ?, ?)
    ");

    return $stmt->execute([$userId, $bookingId, $title, $message, $category]);
}

function reward_progress(int $badgeCount): array
{
    $badgeCount = max(0, $badgeCount);
    $cyclePosition = $badgeCount % 15;
    $target = $cyclePosition < 5 ? 5 : 10;
    $current = $cyclePosition < 5 ? $cyclePosition : $cyclePosition - 5;

    return [
        'current' => $current,
        'target' => $target,
        'remaining' => $target - $current,
        'percent' => ($current / $target) * 100,
        'next_voucher' => $cyclePosition < 5 ? 'RM10 Voucher' : 'RM25 Voucher',
        'earned_voucher' => $badgeCount > 0 && in_array($cyclePosition, [0, 5], true)
            ? ($cyclePosition === 0 ? 'RM25 Voucher' : 'RM10 Voucher')
            : null,
    ];
}

function award_completion_badge($pdo, $userId, $bookingId) {
    // Check if badge already awarded for this booking
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM reward_badges
        WHERE booking_id = ?
    ");
    $stmt->execute([$bookingId]);
    $exists = $stmt->fetch()['total'];

    if ($exists > 0) {
        return false;
    }

    // Award badge
    $stmt = $pdo->prepare("
        INSERT INTO reward_badges (user_id, booking_id, badge_name)
        VALUES (?, ?, 'Completed Booking Badge')
    ");
    $stmt->execute([$userId, $bookingId]);

    // Count total badges
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM reward_badges
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $badgeCount = (int)$stmt->fetch()['total'];

    $rewardProgress = reward_progress($badgeCount);
    if ($rewardProgress['earned_voucher'] !== null) {
        create_reward_voucher($pdo, $userId, $rewardProgress['earned_voucher'], $badgeCount);
    }

    return true;
}

function create_reward_voucher($pdo, $userId, $voucherName, $badgeRequired) {
    $voucherCode = 'OK-' . strtoupper(substr(md5($userId . time() . $voucherName), 0, 8));

    $stmt = $pdo->prepare("
        INSERT INTO reward_vouchers (user_id, voucher_code, voucher_name, badge_required)
        VALUES (?, ?, ?, ?)
    ");

    return $stmt->execute([$userId, $voucherCode, $voucherName, $badgeRequired]);
}
