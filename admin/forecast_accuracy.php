<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/demand_forecast.php';
require_staff();
prevent_dynamic_page_cache();
$assigned = staff_assigned_outlet_ids($pdo);
$sql = 'SELECT o.outlet_id, o.branch_name, s.state_name FROM outlets o JOIN outlet_states s ON s.state_id=o.state_id WHERE o.is_active=1';
$params = [];
if (is_array($assigned)) {
    $sql .= $assigned ? ' AND o.outlet_id IN (' . implode(',', array_fill(0, count($assigned), '?')) . ')' : ' AND 1=0';
    $params = $assigned;
}
$stmt = $pdo->prepare($sql . ' ORDER BY s.state_name, o.branch_name');
$stmt->execute($params);
$outlets = $stmt->fetchAll();
$allowed = array_map('intval', array_column($outlets, 'outlet_id'));
$outletId = (int)($_GET['outlet_id'] ?? ($allowed[0] ?? 0));
if (!in_array($outletId, $allowed, true)) {
    $outletId = $allowed[0] ?? 0;
}
$days = (int)($_GET['days'] ?? 28);
if (!in_array($days, [28, 56, 84], true)) $days = 28;
$today = new DateTimeImmutable('today');
$rows = [];
$pending = 0;
ensure_forecast_tracking($pdo);
if ($outletId) {
    // Opening the report captures the next week before any of those days begin.
    for ($i = 1; $i <= 7; $i++) {
        booking_demand_forecast($pdo, $outletId, $today->modify("+$i days")->format('Y-m-d'));
    }
    $stmt = $pdo->prepare("SELECT f.*, COUNT(b.booking_id) AS actual
        FROM demand_forecast_snapshots f
        LEFT JOIN bookings b ON b.outlet_id=f.outlet_id AND b.booking_date=f.forecast_date
          AND b.status IN ('Confirmed','Completed')
          AND b.booking_time BETWEEN '09:30:00' AND '22:30:00'
          AND CASE WHEN b.booking_time < '12:00:00' THEN 'breakfast'
                   WHEN b.booking_time < '17:00:00' THEN 'lunch' ELSE 'dinner' END = f.period
        WHERE f.outlet_id=? AND f.forecast_date>=? AND f.forecast_date<?
          AND DATE(f.recorded_at)<f.forecast_date
        GROUP BY f.outlet_id, f.forecast_date, f.period, f.expected, f.model_version, f.recorded_at
        ORDER BY f.forecast_date DESC, FIELD(f.period,'breakfast','lunch','dinner')");
    $stmt->execute([$outletId, $today->modify("-$days days")->format('Y-m-d'), $today->format('Y-m-d')]);
    $rows = $stmt->fetchAll();
    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT forecast_date) FROM demand_forecast_snapshots WHERE outlet_id=? AND forecast_date>=?');
    $stmt->execute([$outletId, $today->format('Y-m-d')]);
    $pending = (int)$stmt->fetchColumn();
}
$metrics = forecast_accuracy_metrics($rows);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forecast Accuracy</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<?php include 'staff_sidebar.php'; ?>
<main class="container py-4">
  <a href="reports.php" class="btn btn-outline-dark mb-3">Back to Reports</a>
  <h2>Forecast Accuracy</h2>
  <p class="text-muted">Saved predictions compared with confirmed and completed bookings, after each booking date ends.</p>
  <form method="get" action="#accuracy" class="row g-3 py-3 mb-4 border-bottom">
    <div class="col-md-7">
      <label for="outlet" class="form-label">Outlet</label>
      <select id="outlet" name="outlet_id" class="form-select">
        <?php foreach ($outlets as $outlet): ?>
          <option value="<?= (int)$outlet['outlet_id'] ?>" <?= $outletId === (int)$outlet['outlet_id'] ? 'selected' : '' ?>><?= h($outlet['state_name'] . ' - ' . $outlet['branch_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label for="days" class="form-label">Completed Dates</label>
      <select name="days" id="days" class="form-select">
        <?php foreach ([28,56,84] as $range): ?><option value="<?= $range ?>" <?= $days === $range ? 'selected' : '' ?>>Last <?= $range ?> days</option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 d-flex align-items-end"><button class="btn btn-dark w-100">Apply</button></div>
  </form>
  <section id="accuracy">
    <div class="row g-3 mb-4">
      <div class="col-md-4"><div class="border-bottom pb-3"><div class="text-muted">Average Absolute Error</div><strong class="fs-3"><?= $metrics['mae'] === null ? 'Not available' : number_format($metrics['mae'], 1) ?></strong><div class="small">Bookings per meal period; lower is better</div></div></div>
      <div class="col-md-4"><div class="border-bottom pb-3"><div class="text-muted">Meal Periods Evaluated</div><strong class="fs-3"><?= $metrics['count'] ?></strong><div class="small"><?= count(array_unique(array_column($rows, 'forecast_date'))) ?> completed dates</div></div></div>
      <div class="col-md-4"><div class="border-bottom pb-3"><div class="text-muted">Awaiting Results</div><strong class="fs-3"><?= $pending ?></strong><div class="small">Saved dates that have not ended</div></div></div>
    </div>
    <?php if (!$rows): ?>
      <p class="alert alert-info"><?= !$outletId ? 'No assigned active outlets.' : 'No completed forecasts yet. Results appear after a saved forecast date ends. Outlets need at least three active matching weekdays and ten historical reservations before a forecast can be saved.' ?></p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>Date</th><th>Meal Period</th><th>Forecast</th><th>Actual</th><th>Absolute Error</th><th>Saved On</th></tr></thead>
          <tbody><?php foreach ($rows as $row): ?><tr>
            <td><?= h(format_date_short($row['forecast_date'])) ?></td><td><?= h(ucfirst($row['period'])) ?></td>
            <td><?= number_format((float)$row['expected'], 1) ?></td><td><?= (int)$row['actual'] ?></td>
            <td><?= number_format(abs((float)$row['expected'] - (int)$row['actual']), 1) ?></td>
            <td><?= h(format_date_short(substr($row['recorded_at'], 0, 10))) ?></td>
          </tr><?php endforeach; ?></tbody>
        </table>
      </div>
    <?php endif; ?>
    <p class="small text-muted">The first forecast saved before each date is retained. Opening this report captures the next seven days for this outlet; customer forecast views also save future predictions. Days without a saved prediction are not scored. Actual counts reflect current booking statuses and can change after corrections. Model: weekday-v1.</p>
  </section>
</main>
</body>
</html>
