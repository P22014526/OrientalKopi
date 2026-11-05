<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_staff();

$assignedOutletIds = staff_assigned_outlet_ids($pdo);
$calendarMonth = $_GET['calendar_month'] ?? date('Y-m');
$selectedOutletId = (int)($_GET['outlet_id'] ?? 0);
$today = date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}$/', $calendarMonth)) {
  $calendarMonth = date('Y-m');
}

$calendarStart = $calendarMonth . '-01';
$calendarEnd = date('Y-m-t', strtotime($calendarStart));

$outletSql = "
    SELECT o.outlet_id, o.branch_name, s.state_name
    FROM outlets o
    JOIN outlet_states s ON s.state_id = o.state_id
    WHERE o.is_active = 1
";
$outletParams = [];

if (is_array($assignedOutletIds)) {
  if (!$assignedOutletIds) {
    $outletSql .= ' AND 1=0';
  } else {
    $outletSql .= ' AND o.outlet_id IN (' . implode(',', array_fill(0, count($assignedOutletIds), '?')) . ')';
    $outletParams = $assignedOutletIds;
  }
}

$outletSql .= ' ORDER BY s.display_order, o.branch_name';
$outletStmt = $pdo->prepare($outletSql);
$outletStmt->execute($outletParams);
$outlets = $outletStmt->fetchAll();
$outletIds = array_map('intval', array_column($outlets, 'outlet_id'));

if ($selectedOutletId > 0 && !in_array($selectedOutletId, $outletIds, true)) {
  $selectedOutletId = 0;
}

$calendarWhere = ['b.booking_date BETWEEN ? AND ?'];
$calendarParams = [$calendarStart, $calendarEnd];

if ($selectedOutletId > 0) {
  $calendarWhere[] = 'b.outlet_id = ?';
  $calendarParams[] = $selectedOutletId;
} elseif (is_array($assignedOutletIds)) {
  if (!$assignedOutletIds) {
    $calendarWhere[] = '1=0';
  } else {
    $calendarWhere[] = 'b.outlet_id IN (' . implode(',', array_fill(0, count($assignedOutletIds), '?')) . ')';
    $calendarParams = array_merge($calendarParams, $assignedOutletIds);
  }
}

$calendarStmt = $pdo->prepare("
    SELECT b.booking_date, COUNT(*) AS total,
           COUNT(CASE WHEN b.status = 'Pending' THEN 1 END) AS pending,
           COUNT(CASE WHEN b.status = 'Confirmed' THEN 1 END) AS confirmed
    FROM bookings b
    WHERE " . implode(' AND ', $calendarWhere) . "
    GROUP BY b.booking_date
");
$calendarStmt->execute($calendarParams);
$calendarCounts = [];

foreach ($calendarStmt->fetchAll() as $row) {
  $calendarCounts[$row['booking_date']] = $row;
}

$selectedOutletLabel = 'All visible outlets';
foreach ($outlets as $outlet) {
  if ((int)$outlet['outlet_id'] === $selectedOutletId) {
    $selectedOutletLabel = $outlet['state_name'] . ' - ' . $outlet['branch_name'];
    break;
  }
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Booking Calendar</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
  <?php include 'staff_sidebar.php'; ?>
  <main class="container py-4">
    <a href="dashboard.php" class="btn-nav-icon mb-3"><span class="btn-nav-icon-symbol">&larr;</span><span>Dashboard</span></a>

    <section class="dashboard-panel mb-3 no-print" aria-labelledby="booking-calendar-title">
      <div class="dashboard-section-heading">
        <div>
          <div class="section-title">Booking Calendar</div>
          <h3 id="booking-calendar-title" class="mb-0"><?= h(date('F Y', strtotime($calendarStart))) ?></h3>
          <p class="muted-small mb-0"><?= h($selectedOutletLabel) ?></p>
        </div>

        <span class="dashboard-date-pill"><?= h(format_date_short($today)) ?></span>
      </div>

      <form method="get" class="row g-3 align-items-end mb-4">
        <div class="col-md-4">
          <label class="form-label">Month</label>
          <input type="month" name="calendar_month" class="form-control" value="<?= h($calendarMonth) ?>">
        </div>

        <div class="col-md-5">
          <label class="form-label">Outlet</label>
          <select name="outlet_id" class="form-select">
            <option value="0">All Outlets</option>
            <?php foreach ($outlets as $outlet): ?>
              <option value="<?= h($outlet['outlet_id']) ?>" <?= $selectedOutletId === (int)$outlet['outlet_id'] ? 'selected' : '' ?>>
                <?= h($outlet['state_name']) ?> - <?= h($outlet['branch_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3">
          <button class="btn btn-dark w-100">View Calendar</button>
        </div>
      </form>

      <div class="table-responsive dashboard-calendar-table-wrap">
        <table class="table table-bordered align-middle mb-0 dashboard-calendar-table">
          <thead>
            <tr>
              <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dayLabel): ?>
                <th class="text-center"><?= h($dayLabel) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php
            $firstDay = new DateTime($calendarStart);
            $lastDay = new DateTime($calendarEnd);
            $calendarCursor = clone $firstDay;
            $calendarCursor->modify('-' . ((int)$calendarCursor->format('N') - 1) . ' days');
            $calendarEndCursor = clone $lastDay;
            $calendarEndCursor->modify('+' . (7 - (int)$calendarEndCursor->format('N')) . ' days');
            ?>

            <?php while ($calendarCursor <= $calendarEndCursor): ?>
              <tr>
                <?php for ($weekday = 1; $weekday <= 7; $weekday++): ?>
                  <?php
                  $dateKey = $calendarCursor->format('Y-m-d');
                  $isCurrentMonth = $calendarCursor->format('Y-m') === $calendarMonth;
                  $countRow = $calendarCounts[$dateKey] ?? ['total' => 0, 'pending' => 0, 'confirmed' => 0];
                  $bookingTotal = (int)$countRow['total'];
                  $hasBookings = $isCurrentMonth && $bookingTotal > 0;
                  $bookingBadgeClass = 'secondary';
                  $bookingBadgeTextClass = '';

                  if ($bookingTotal >= 7) {
                    $bookingBadgeClass = 'danger';
                  } elseif ($bookingTotal >= 4) {
                    $bookingBadgeClass = 'warning';
                    $bookingBadgeTextClass = ' text-dark';
                  } elseif ($bookingTotal >= 1) {
                    $bookingBadgeClass = 'success';
                  }
                  ?>
                  <td class="<?= $isCurrentMonth ? '' : 'is-muted-month' ?> <?= $hasBookings ? 'has-bookings' : '' ?>">
                    <?php if ($isCurrentMonth): ?>
                      <div class="dashboard-calendar-day">
                        <div class="dashboard-calendar-date-row">
                          <strong><?= h($calendarCursor->format('j')) ?></strong>
                        </div>

                        <div class="dashboard-calendar-booking-row">
                          <span class="badge bg-<?= h($bookingBadgeClass) ?><?= h($bookingBadgeTextClass) ?>">
                            <?= h($bookingTotal) ?> booking(s)
                          </span>
                        </div>

                        <?php if ($hasBookings): ?>
                          <div class="dashboard-calendar-detail-row">
                            <small><?= h($countRow['pending']) ?> pending · <?= h($countRow['confirmed']) ?> confirmed</small>
                          </div>
                        <?php else: ?>
                          <div class="dashboard-calendar-detail-row">
                            <small>No booking records</small>
                          </div>
                        <?php endif; ?>

                        <form method="get" action="bookings.php#booking-records" class="dashboard-calendar-action-row">
                          <input type="hidden" name="filter_date" value="<?= h($dateKey) ?>">
                          <?php if ($selectedOutletId > 0): ?>
                            <input type="hidden" name="filter_outlet_id" value="<?= h($selectedOutletId) ?>">
                          <?php endif; ?>
                          <button class="btn btn-sm btn-outline-dark dashboard-calendar-button" type="submit">
                            View Date
                          </button>
                        </form>
                      </div>
                    <?php else: ?>
                      <span class="dashboard-calendar-day is-empty">
                        <strong><?= h($calendarCursor->format('j')) ?></strong>
                      </span>
                    <?php endif; ?>
                  </td>
                  <?php $calendarCursor->modify('+1 day'); ?>
                <?php endfor; ?>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</body>

</html>
