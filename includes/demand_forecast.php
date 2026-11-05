<?php

/**
 * Seasonal baseline: matching weekdays with a six-week recency half-life.
 * Counts are reservations per meal period, not table occupancy or availability.
 */
function calculate_demand_forecast(array $history, string $firstDate, string $targetDate, DateTimeImmutable $today): array
{
    $result = ['ready' => false, 'samples' => 0, 'periods' => [], 'reason' => 'Building history'];
    $target = DateTimeImmutable::createFromFormat('!Y-m-d', $targetDate);
    if (!$target || $target->format('Y-m-d') !== $targetDate) {
        $result['reason'] = 'Choose a valid date';
        return $result;
    }
    if ($target < $today || $target > $today->modify('+28 days')) {
        $result['reason'] = 'Forecasts cover the next 28 days';
        return $result;
    }
    $start = $today->modify('-84 days');
    $observed = DateTimeImmutable::createFromFormat('!Y-m-d', $firstDate);
    if (!$observed || $observed >= $today) {
        return $result;
    }
    if ($observed > $start) $start = $observed;
    $samples = [];
    $activeDays = 0;
    $totalBookings = 0;
    for ($day = $start; $day < $today; $day = $day->modify('+1 day')) {
        if ($day->format('N') !== $target->format('N')) continue;
        $counts = $history[$day->format('Y-m-d')] ?? [];
        $total = array_sum($counts);
        $totalBookings += $total;
        if ($total > 0) $activeDays++;
        $age = (int)$day->diff($today)->format('%a');
        $samples[] = ['counts' => $counts, 'weight' => pow(0.5, $age / 42)];
    }
    $result['samples'] = count($samples);
    if (count($samples) < 3 || $activeDays < 3 || $totalBookings < 10) return $result;

    $hours = ['breakfast' => 2.5, 'lunch' => 5, 'dinner' => 5.5];
    $weightTotal = array_sum(array_column($samples, 'weight'));
    foreach ($hours as $period => $duration) {
        $weighted = 0;
        $counts = [];
        foreach ($samples as $sample) {
            $count = (int)($sample['counts'][$period] ?? 0);
            $weighted += $count * $sample['weight'];
            $counts[] = $count;
        }
        $mean = $weighted / $weightTotal;
        $result['periods'][$period] = [
            'expected' => round($mean, 1),
            'rate' => $mean / $duration,
            'min' => min($counts),
            'max' => max($counts),
        ];
    }
    $averageRate = array_sum(array_column($result['periods'], 'expected')) / array_sum($hours);
    foreach ($result['periods'] as &$row) {
        $row['level'] = $row['rate'] > $averageRate * 1.25 ? 'Busier'
            : ($row['rate'] < $averageRate * 0.75 ? 'Quieter' : 'Typical');
        $row['color'] = ['Busier' => 'warning', 'Quieter' => 'success', 'Typical' => 'primary'][$row['level']];
    }
    unset($row);
    $result['ready'] = true;
    return $result;
}

function booking_demand_forecast(PDO $pdo, int $outletId, string $date): array
{
    $today = new DateTimeImmutable('today');
    $stmt = $pdo->prepare('SELECT MIN(booking_date) FROM bookings WHERE outlet_id = ? AND booking_date < ?');
    $stmt->execute([$outletId, $today->format('Y-m-d')]);
    $firstDate = $stmt->fetchColumn() ?: '';
    $stmt = $pdo->prepare("
        SELECT booking_date,
               CASE WHEN booking_time < '12:00:00' THEN 'breakfast'
                    WHEN booking_time < '17:00:00' THEN 'lunch' ELSE 'dinner' END AS period,
               COUNT(*) AS total
        FROM bookings
        WHERE outlet_id = ? AND booking_date >= ? AND booking_date < ?
          AND status IN ('Confirmed', 'Completed')
          AND booking_time BETWEEN '09:30:00' AND '22:30:00'
        GROUP BY booking_date, period
    ");
    $stmt->execute([$outletId, $today->modify('-84 days')->format('Y-m-d'), $today->format('Y-m-d')]);
    $history = [];
    foreach ($stmt->fetchAll() as $row) {
        $history[$row['booking_date']][$row['period']] = (int)$row['total'];
    }
    $forecast = calculate_demand_forecast($history, $firstDate, $date, $today);
    if ($forecast['ready'] && $date > $today->format('Y-m-d')) {
        ensure_forecast_tracking($pdo);
        // Keep the first published prediction; never replace it with later knowledge.
        $save = $pdo->prepare('INSERT IGNORE INTO demand_forecast_snapshots
            (outlet_id, forecast_date, period, expected, model_version, recorded_at)
            VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($forecast['periods'] as $period => $row) {
            $save->execute([$outletId, $date, $period, $row['expected'], 'weekday-v1', date('Y-m-d H:i:s')]);
        }
    }
    return $forecast;
}

function ensure_forecast_tracking(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS demand_forecast_snapshots (
        outlet_id INT NOT NULL,
        forecast_date DATE NOT NULL,
        period VARCHAR(12) NOT NULL,
        expected DECIMAL(10,1) NOT NULL,
        model_version VARCHAR(32) NOT NULL,
        recorded_at DATETIME NOT NULL,
        PRIMARY KEY (outlet_id, forecast_date, period)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

function forecast_accuracy_metrics(array $rows): array
{
    $error = 0.0;
    $bias = 0.0;
    foreach ($rows as $row) {
        $difference = (float)$row['expected'] - (int)$row['actual'];
        $error += abs($difference);
        $bias += $difference;
    }
    return ['count' => count($rows), 'mae' => $rows ? $error / count($rows) : null,
        'bias' => $rows ? $bias / count($rows) : null];
}
