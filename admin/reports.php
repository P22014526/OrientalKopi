<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_staff();

$validReportTypes = ['daily', 'monthly', 'yearly'];
$reportType = $_GET['report_type'] ?? 'daily';

if (!in_array($reportType, $validReportTypes, true)) {
  $reportType = 'daily';
}

$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedMonth = $_GET['month'] ?? date('Y-m');
$selectedYear = $_GET['year'] ?? date('Y');
$selectedStatus = $_GET['status'] ?? 'All';
$selectedOutletId = (int)($_GET['outlet_id'] ?? 0);

$validStatuses = ['All', 'Confirmed', 'Completed', 'Cancelled', 'Declined', 'Pending'];

if (!in_array($selectedStatus, $validStatuses, true)) {
  $selectedStatus = 'All';
}

$assignedOutletIds = staff_assigned_outlet_ids($pdo);
$reportScopeSql = '';
$reportScopeParams = [];
$outletOptionsSql = "
    SELECT o.outlet_id, o.branch_name, s.state_name
    FROM outlets o
    JOIN outlet_states s ON s.state_id = o.state_id
    WHERE o.is_active = 1
";
$outletOptionsParams = [];

if (is_array($assignedOutletIds)) {
  if (!$assignedOutletIds) {
    $reportScopeSql = ' AND 1=0';
    $outletOptionsSql .= ' AND 1=0';
  } else {
    $reportScopeSql = ' AND outlet_id IN (' . implode(',', array_fill(0, count($assignedOutletIds), '?')) . ')';
    $reportScopeParams = $assignedOutletIds;
    $outletOptionsSql .= ' AND o.outlet_id IN (' . implode(',', array_fill(0, count($assignedOutletIds), '?')) . ')';
    $outletOptionsParams = $assignedOutletIds;
  }
}

$outletOptionsSql .= ' ORDER BY s.display_order, o.branch_name';
$outletOptionsStmt = $pdo->prepare($outletOptionsSql);
$outletOptionsStmt->execute($outletOptionsParams);
$outletOptions = $outletOptionsStmt->fetchAll();
$filterableOutletIds = array_map('intval', array_column($outletOptions, 'outlet_id'));

if ($selectedOutletId > 0 && !in_array($selectedOutletId, $filterableOutletIds, true)) {
  $selectedOutletId = 0;
}

if ($selectedOutletId > 0) {
  $reportScopeSql .= ' AND outlet_id = ?';
  $reportScopeParams[] = $selectedOutletId;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
  $selectedDate = date('Y-m-d');
}

if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
  $selectedMonth = date('Y-m');
}

if (!preg_match('/^\d{4}$/', $selectedYear)) {
  $selectedYear = date('Y');
}

if ($reportType === 'monthly') {
  $periodStart = $selectedMonth . '-01';
  $periodEnd = date('Y-m-t', strtotime($periodStart));
  $periodLabel = date('F Y', strtotime($periodStart));
  $trendTitle = 'Bookings by Date';
  $trendSql = "
      SELECT 
          DATE_FORMAT(booking_date, '%d/%m') AS label,
          COUNT(*) AS total_bookings,
          COALESCE(SUM(pax), 0) AS total_pax
      FROM bookings
      WHERE booking_date BETWEEN ? AND ?
      $reportScopeSql
      GROUP BY booking_date
      ORDER BY booking_date
  ";
} elseif ($reportType === 'yearly') {
  $periodStart = $selectedYear . '-01-01';
  $periodEnd = $selectedYear . '-12-31';
  $periodLabel = $selectedYear;
  $trendTitle = 'Bookings by Month';
  $trendSql = "
      SELECT 
          DATE_FORMAT(booking_date, '%b') AS label,
          DATE_FORMAT(booking_date, '%Y-%m') AS sort_key,
          COUNT(*) AS total_bookings,
          COALESCE(SUM(pax), 0) AS total_pax
      FROM bookings
      WHERE booking_date BETWEEN ? AND ?
      $reportScopeSql
      GROUP BY sort_key, label
      ORDER BY sort_key
  ";
} else {
  $periodStart = $selectedDate;
  $periodEnd = $selectedDate;
  $periodLabel = format_date_short($selectedDate);
  $trendTitle = 'Bookings by Time';
  $trendSql = "
      SELECT 
          TIME_FORMAT(booking_time, '%H:%i') AS label,
          COUNT(*) AS total_bookings,
          COALESCE(SUM(pax), 0) AS total_pax
      FROM bookings
      WHERE booking_date BETWEEN ? AND ?
      $reportScopeSql
      GROUP BY booking_time
      ORDER BY booking_time
  ";
}

/*
|--------------------------------------------------------------------------
| Summary data based on selected period
|--------------------------------------------------------------------------
*/

$statusCountStmt = $pdo->prepare("
    SELECT status, COUNT(*) AS total
    FROM bookings
    WHERE booking_date BETWEEN ? AND ?
    $reportScopeSql
    GROUP BY status
");
$statusCountStmt->execute(array_merge([$periodStart, $periodEnd], $reportScopeParams));
$statusRows = $statusCountStmt->fetchAll();

$statusCounts = [
  'Confirmed' => 0,
  'Completed' => 0,
  'Cancelled' => 0,
  'Declined' => 0,
  'Pending' => 0
];

foreach ($statusRows as $row) {
  if (isset($statusCounts[$row['status']])) {
    $statusCounts[$row['status']] = (int)$row['total'];
  }
}

$totalBookings = array_sum($statusCounts);

/*
|--------------------------------------------------------------------------
| Average completed bookings
|--------------------------------------------------------------------------
*/

$timeSlotStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT CONCAT(booking_date, ' ', booking_time)) AS total_slots
    FROM bookings
    WHERE booking_date BETWEEN ? AND ?
    $reportScopeSql
");
$timeSlotStmt->execute(array_merge([$periodStart, $periodEnd], $reportScopeParams));
$totalUsedSlots = (int)$timeSlotStmt->fetch()['total_slots'];

$averageCompletedBookings = $totalUsedSlots > 0
  ? round($statusCounts['Completed'] / $totalUsedSlots, 2)
  : 0;

/*
|--------------------------------------------------------------------------
| Trend chart data and peak period
|--------------------------------------------------------------------------
*/

$trendStmt = $pdo->prepare($trendSql);
$trendStmt->execute(array_merge([$periodStart, $periodEnd], $reportScopeParams));
$trendRows = $trendStmt->fetchAll();

$peak = null;

foreach ($trendRows as $row) {
  if (!$peak || (int)$row['total_bookings'] > (int)$peak['total_bookings']) {
    $peak = $row;
  }
}

$totalPax = array_sum(array_column($trendRows, 'total_pax'));
$capacity = $selectedOutletId > 0 ? max_capacity($pdo, $selectedOutletId)
  : array_sum(array_map(fn($id) => max_capacity($pdo, $id), $filterableOutletIds));
$tableSummary = $selectedOutletId > 0 ? table_capacity_summary($pdo, $selectedOutletId)
  : 'Total seating across the listed outlets';

/*
|--------------------------------------------------------------------------
| Status chart data
|--------------------------------------------------------------------------
*/

$byStatusStmt = $pdo->prepare("
    SELECT status AS label, COUNT(*) AS total
    FROM bookings
    WHERE booking_date BETWEEN ? AND ?
    $reportScopeSql
    GROUP BY status
");
$byStatusStmt->execute(array_merge([$periodStart, $periodEnd], $reportScopeParams));
$byStatus = $byStatusStmt->fetchAll();

$reviewScopeSql = str_replace('outlet_id', 'b.outlet_id', $reportScopeSql);
$averageRatingStmt = $pdo->prepare("
    SELECT ROUND(AVG(r.rating), 1) AS average_rating, COUNT(DISTINCT r.review_id) AS review_count
    FROM reviews r
    JOIN bookings b ON b.booking_id = r.booking_id
    WHERE b.booking_date BETWEEN ? AND ?
      AND b.status = 'Completed'
      $reviewScopeSql
");
$averageRatingStmt->execute(array_merge([$periodStart, $periodEnd], $reportScopeParams));
$averageRatingRow = $averageRatingStmt->fetch();
$averageRating = $averageRatingRow['average_rating'] ?? null;
$reviewCount = (int)($averageRatingRow['review_count'] ?? 0);

/*
|--------------------------------------------------------------------------
| Booking list with optional status filter
|--------------------------------------------------------------------------
*/

$listScopeSql = str_replace('outlet_id', 'b.outlet_id', $reportScopeSql);

if ($selectedStatus === 'All') {
  $listStmt = $pdo->prepare("
        SELECT b.*, o.branch_name, s.state_name
        FROM bookings b
        LEFT JOIN outlets o ON o.outlet_id = b.outlet_id
        LEFT JOIN outlet_states s ON s.state_id = o.state_id
        WHERE b.booking_date BETWEEN ? AND ?
        $listScopeSql
        ORDER BY b.booking_date DESC, b.booking_time DESC
    ");
  $listStmt->execute(array_merge([$periodStart, $periodEnd], $reportScopeParams));
} else {
  $listStmt = $pdo->prepare("
        SELECT b.*, o.branch_name, s.state_name
        FROM bookings b
        LEFT JOIN outlets o ON o.outlet_id = b.outlet_id
        LEFT JOIN outlet_states s ON s.state_id = o.state_id
        WHERE b.booking_date BETWEEN ? AND ?
          $listScopeSql
          AND b.status = ?
        ORDER BY b.booking_date DESC, b.booking_time DESC
    ");
  $listStmt->execute(array_merge([$periodStart, $periodEnd], $reportScopeParams, [$selectedStatus]));
}

$bookings = $listStmt->fetchAll();

$trendLabels = array_column($trendRows, 'label');

if ($reportType === 'daily') {
  $trendLabels = array_map('format_time12', $trendLabels);
}

$availableYears = range((int)date('Y') - 2, (int)date('Y') + 1);
if (!in_array((int)$selectedYear, $availableYears, true)) {
  $availableYears[] = (int)$selectedYear;
  sort($availableYears);
}
?>

<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Booking Reports</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
  <?php include 'staff_sidebar.php'; ?>
  <main class="container py-4">

    <a href="dashboard.php" class="btn-nav-icon mb-3">
      <span class="btn-nav-icon-symbol">←</span>
      <span>Dashboard</span>
    </a>

    <div class="hero p-4 mb-4">
      <div class="section-title text-warning">Admin analytics</div>
      <h2>Booking Reports</h2>
      <a href="forecast_accuracy.php" class="btn btn-light my-2">Forecast Accuracy</a>
      <p class="mb-0">
        Filter bookings by daily, monthly or yearly periods to review restaurant booking performance.
      </p>
    </div>

    <div class="card ok-card p-4 mb-3">
      <form class="row g-3" method="get">
        <div class="col-md-3">
          <label class="form-label">Report Range</label>
          <select name="report_type" class="form-select" id="reportTypeSelect">
            <option value="daily" <?= $reportType === 'daily' ? 'selected' : '' ?>>Daily</option>
            <option value="monthly" <?= $reportType === 'monthly' ? 'selected' : '' ?>>Monthly</option>
            <option value="yearly" <?= $reportType === 'yearly' ? 'selected' : '' ?>>Yearly</option>
          </select>
        </div>

        <div class="col-md-3 report-filter-field" data-filter-field="daily">
          <label class="form-label">Choose Date</label>
          <input type="date" name="date" class="form-control" value="<?= h($selectedDate) ?>">
        </div>

        <div class="col-md-3 report-filter-field" data-filter-field="monthly">
          <label class="form-label">Choose Month</label>
          <input type="month" name="month" class="form-control" value="<?= h($selectedMonth) ?>">
        </div>

        <div class="col-md-3 report-filter-field" data-filter-field="yearly">
          <label class="form-label">Choose Year</label>
          <select name="year" class="form-select">
            <?php foreach ($availableYears as $year): ?>
              <option value="<?= h($year) ?>" <?= (string)$selectedYear === (string)$year ? 'selected' : '' ?>>
                <?= h($year) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label">Filter Outlet</label>
          <select name="outlet_id" class="form-select">
            <option value="0">All Outlets</option>
            <?php foreach ($outletOptions as $outlet): ?>
              <option value="<?= h($outlet['outlet_id']) ?>" <?= $selectedOutletId === (int)$outlet['outlet_id'] ? 'selected' : '' ?>>
                <?= h($outlet['state_name']) ?> - <?= h($outlet['branch_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label">Filter Status</label>
          <select name="status" class="form-select">
            <?php foreach ($validStatuses as $status): ?>
              <option value="<?= h($status) ?>" <?= $selectedStatus === $status ? 'selected' : '' ?>>
                <?= h($status) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-2 align-self-end">
          <button class="btn btn-dark w-100">
            📊 View Report
          </button>
        </div>

        <div class="col-md-2 align-self-end">
          <a href="reports.php" class="btn btn-outline-dark w-100">
            Reset
          </a>
        </div>
      </form>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <div class="card ok-card p-3">
          Selected Period:
          <strong><?= h($periodLabel) ?></strong>
        </div>
      </div>

      <div class="col-md-4">
        <div class="card ok-card p-3">
          Total Bookings:
          <strong><?= h($totalBookings) ?></strong>
        </div>
      </div>

      <div class="col-md-4">
        <div class="card ok-card p-3">
          Total Pax:
          <strong><?= h($totalPax) ?></strong>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <div class="card ok-card p-3">
          Peak <?= $reportType === 'daily' ? 'Time' : 'Period' ?>:
          <strong>
            <?php if ($peak): ?>
              <?= h($reportType === 'daily' ? format_time12($peak['label']) : $peak['label']) ?>
              (<?= h($peak['total_bookings']) ?> bookings)
            <?php else: ?>
              -
            <?php endif; ?>
          </strong>
        </div>
      </div>

      <div class="col-md-4">
        <div class="card ok-card p-3">
          Completed Bookings:
          <strong><?= h($statusCounts['Completed']) ?></strong>
        </div>
      </div>

      <div class="col-md-4">
        <div class="card ok-card p-3">
          Average Rating:
          <strong><?= $averageRating !== null ? h($averageRating) . ' / 5' : '-' ?></strong>
          <span class="muted-small"><?= h($reviewCount) ?> review(s)</span>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-12">
        <div class="card ok-card p-3">
          Table Capacity:
          <strong><?= h($capacity) ?> pax</strong>
          <span class="muted-small"><?= h($tableSummary) ?></span>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <div class="card ok-card p-4">
          <h5><?= h($trendTitle) ?></h5>
          <canvas id="trendChart"></canvas>
        </div>
      </div>

      <div class="col-md-6">
        <div class="card ok-card p-4">
          <h5>Status Summary</h5>
          <canvas id="statusChart"></canvas>
        </div>
      </div>
    </div>

    <div class="card ok-card p-4 mt-3">
      <h5>Report Summary for <?= h($periodLabel) ?></h5>

      <div class="table-responsive">
        <table class="table table-bordered align-middle report-summary-table">
          <tbody>
            <tr>
              <th>Report Range</th>
              <td><?= h(ucfirst($reportType)) ?></td>
            </tr>

            <tr>
              <th>Selected Period</th>
              <td><?= h($periodLabel) ?></td>
            </tr>

            <tr>
              <th>Selected Outlet</th>
              <td>
                <?php if ($selectedOutletId > 0): ?>
                  <?php foreach ($outletOptions as $outlet): ?>
                    <?php if ((int)$outlet['outlet_id'] === $selectedOutletId): ?>
                      <?= h($outlet['state_name']) ?> - <?= h($outlet['branch_name']) ?>
                    <?php endif; ?>
                  <?php endforeach; ?>
                <?php else: ?>
                  All Outlets
                <?php endif; ?>
              </td>
            </tr>

            <tr>
              <th>Total Bookings</th>
              <td><?= h($totalBookings) ?></td>
            </tr>

            <tr>
              <th>Total Confirmed Bookings</th>
              <td><?= h($statusCounts['Confirmed']) ?></td>
            </tr>

            <tr>
              <th>Total Completed Bookings</th>
              <td><?= h($statusCounts['Completed']) ?></td>
            </tr>

            <tr>
              <th>Total Cancelled Bookings</th>
              <td><?= h($statusCounts['Cancelled']) ?></td>
            </tr>

            <tr>
              <th>Total Declined Bookings</th>
              <td><?= h($statusCounts['Declined']) ?></td>
            </tr>

            <tr>
              <th>Total Pending Bookings</th>
              <td><?= h($statusCounts['Pending']) ?></td>
            </tr>

            <tr>
              <th>Average Completed Bookings</th>
              <td>
                <?= h($averageCompletedBookings) ?>
                completed bookings per used date/time slot
              </td>
            </tr>

            <tr>
              <th>Average Rating</th>
              <td>
                <?= $averageRating !== null ? h($averageRating) . ' / 5' : '-' ?>
                from <?= h($reviewCount) ?> review(s)
              </td>
            </tr>

            <tr>
              <th>Peak <?= $reportType === 'daily' ? 'Booking Time' : 'Booking Period' ?></th>
              <td>
                <?php if ($peak): ?>
                  <?= h($reportType === 'daily' ? format_time12($peak['label']) : $peak['label']) ?>
                  with <?= h($peak['total_bookings']) ?> bookings
                <?php else: ?>
                  No peak period available
                <?php endif; ?>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card ok-card p-4 mt-3">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h5 class="mb-0">
          Booking Details for <?= h($periodLabel) ?>

          <?php if ($selectedStatus !== 'All'): ?>
            <span class="badge bg-dark"><?= h($selectedStatus) ?></span>
          <?php endif; ?>
        </h5>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Outlet</th>
              <th>Date</th>
              <th>Time</th>
              <th>Pax</th>
              <th>Type</th>
              <th>Declined / Cancelled Reason</th>
              <th>Status</th>
            </tr>
          </thead>

          <tbody>
            <?php foreach ($bookings as $b): ?>
              <tr>
                <td><?= h($b['booking_code']) ?></td>
                <td><?= h($b['customer_name']) ?></td>
                <td>
                  <strong><?= h($b['branch_name'] ?? 'Unassigned') ?></strong><br>
                  <span class="muted-small"><?= h($b['state_name'] ?? '-') ?></span>
                </td>
                <td><?= h(format_date_short($b['booking_date'])) ?></td>
                <td><?= h(format_time12($b['booking_time'])) ?></td>
                <td><?= h($b['pax']) ?></td>
                <td><?= h($b['request_type']) ?></td>
                <td>
                  <?php if (in_array($b['status'], ['Declined', 'Cancelled'], true) && !empty($b['admin_reason'])): ?>
                    <?= h($b['admin_reason']) ?>
                  <?php else: ?>
                    -
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge bg-<?= h(status_badge_class($b['status'])) ?>">
                    <?= h($b['status']) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>

            <?php if (!$bookings): ?>
              <tr>
                <td colspan="9" class="text-center text-muted">
                  No bookings found for the selected report filter.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <script>
    const reportTypeSelect = document.getElementById('reportTypeSelect');
    const filterFields = document.querySelectorAll('.report-filter-field');

    function updateReportFields() {
      const selectedType = reportTypeSelect.value;

      filterFields.forEach(function(field) {
        field.classList.toggle(
          'd-none',
          field.dataset.filterField !== selectedType
        );
      });
    }

    reportTypeSelect.addEventListener('change', updateReportFields);
    updateReportFields();

    new Chart(document.getElementById('trendChart'), {
      type: 'bar',
      data: {
        labels: <?= json_encode($trendLabels) ?>,
        datasets: [{
            label: 'Bookings',
            data: <?= json_encode(array_column($trendRows, 'total_bookings')) ?>
          },
          {
            label: 'Pax',
            data: <?= json_encode(array_column($trendRows, 'total_pax')) ?>
          }
        ]
      },
      options: {
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              precision: 0
            }
          }
        }
      }
    });

    new Chart(document.getElementById('statusChart'), {
      type: 'pie',
      data: {
        labels: <?= json_encode(array_column($byStatus, 'label')) ?>,
        datasets: [{
          label: 'Status',
          data: <?= json_encode(array_column($byStatus, 'total')) ?>
        }]
      }
    });
  </script>

</body>

</html>
