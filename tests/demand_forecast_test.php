<?php
require_once __DIR__ . '/../includes/demand_forecast.php';

function check_forecast(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$today = new DateTimeImmutable('2026-10-12');
$history = [];
foreach (['2026-09-21', '2026-09-28', '2026-10-05'] as $day) {
    $history[$day] = ['breakfast' => 2, 'lunch' => 10, 'dinner' => 3];
}
$forecast = calculate_demand_forecast($history, '2026-09-21', '2026-10-12', $today);
check_forecast($forecast['ready'], 'Sufficient history must yield a forecast');
check_forecast($forecast['samples'] === 3, 'Only matching weekdays should count');
check_forecast($forecast['periods']['lunch']['expected'] == 10, 'Constant history must predict its mean');
check_forecast($forecast['periods']['lunch']['level'] === 'Busier', 'Lunch should be relatively busier');
check_forecast($forecast['periods']['dinner']['level'] === 'Quieter', 'Dinner should be relatively quieter');
$history['2026-10-06'] = ['lunch' => 999];
$history['2026-10-12'] = ['lunch' => 999];
$history['2026-10-19'] = ['lunch' => 999];
check_forecast(calculate_demand_forecast($history, '2026-09-21', '2026-10-12', $today)['periods']['lunch']['expected'] == 10, 'Other weekdays, today and future must not leak into training');
check_forecast(!calculate_demand_forecast([], '', '2026-10-12', $today)['ready'], 'New outlets must abstain');
check_forecast(!calculate_demand_forecast($history, '2026-10-01', '2026-10-12', $today)['ready'], 'Insufficient weeks must abstain');
foreach (['2026-10-11', '2026-11-10', '2026-02-30', 'invalid'] as $date) {
    check_forecast(!calculate_demand_forecast($history, '2026-09-21', $date, $today)['ready'], 'Invalid or out-of-horizon date must abstain');
}
$withQuietWeek = calculate_demand_forecast($history, '2026-09-14', '2026-10-12', $today);
check_forecast($withQuietWeek['samples'] === 4 && $withQuietWeek['periods']['lunch']['expected'] < 10, 'Quiet weekdays must be included');
$history['2026-10-05']['lunch'] = 20;
$recent = calculate_demand_forecast($history, '2026-09-21', '2026-10-12', $today);
check_forecast($recent['periods']['lunch']['expected'] > 13.3, 'Recent weeks must carry greater weight');
echo "Demand forecast checks passed.\n";
