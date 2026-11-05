<?php

function rank_alternative_slots(array $candidates, string $requestedTime): array
{
    $minutes = static function (string $time): int {
        return (int)substr($time, 0, 2) * 60 + (int)substr($time, 3, 2);
    };
    $requested = $minutes($requestedTime);
    foreach ($candidates as &$candidate) {
        $candidate['distance'] = abs($minutes($candidate['time']) - $requested);
    }
    unset($candidate);
    usort($candidates, static function ($a, $b) {
        return ($a['distance'] <=> $b['distance'])
            ?: (($a['rate'] ?? 0) <=> ($b['rate'] ?? 0))
            ?: strcmp($a['time'], $b['time']);
    });
    return array_slice($candidates, 0, 3);
}

function smart_alternative_slots(PDO $pdo, int $outletId, int $userId, string $date,
    string $requestedTime, int $pax, string $tableType, array $forecast): array
{
    $candidates = [];
    $policy = [];
    $now = time();
    for ($minute = 570; $minute <= 1350; $minute += 30) {
        $time = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
        if ($time === $requestedTime || strtotime("$date $time") <= $now) continue;
        $period = booking_meal_period($time);
        if (!array_key_exists($period, $policy)) {
            $policy[$period] = customer_booking_policy_violation($pdo, $userId, $date, $time);
        }
        if ($policy[$period] !== null) continue;
        $usage = slot_usage($pdo, $date, $time, $pax, null, $tableType, $outletId);
        if ($usage['status'] === 'Full' || !$usage['available_for_pax']) continue;
        $prediction = $forecast['periods'][$period] ?? [];
        $candidates[] = ['time' => $time, 'period' => $period,
            'rate' => $prediction['rate'] ?? null, 'level' => $prediction['level'] ?? null];
    }
    return rank_alternative_slots($candidates, $requestedTime);
}
