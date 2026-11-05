<?php
require_once __DIR__ . '/../includes/demand_forecast.php';
require_once __DIR__ . '/../includes/alternative_slots.php';
function verify_smart($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$ranked = rank_alternative_slots([
    ['time'=>'13:00','rate'=>3], ['time'=>'12:00','rate'=>1],
    ['time'=>'14:00','rate'=>0], ['time'=>'11:00','rate'=>0]
], '12:30');
verify_smart(array_column($ranked, 'time') === ['12:00','13:00','11:00'], 'Nearest first, quieter breaks ties, three results');
verify_smart(rank_alternative_slots([], '12:30') === [], 'Empty candidates');
$metrics = forecast_accuracy_metrics([['expected'=>3,'actual'=>0], ['expected'=>5,'actual'=>7]]);
verify_smart($metrics['mae'] === 2.5 && $metrics['bias'] === 0.5, 'Known errors including zero actual');
verify_smart(forecast_accuracy_metrics([])['mae'] === null, 'No invented accuracy for empty data');
echo "Smart booking tests passed\n";
