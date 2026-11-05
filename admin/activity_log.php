<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_staff();

$assignedOutletIds = staff_assigned_outlet_ids($pdo);
$filterDate = $_GET['date'] ?? '';
$filterOutletId = (int)($_GET['outlet_id'] ?? 0);
$filterStaffId = (int)($_GET['staff_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

ensure_staff_activity_log_table($pdo);

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

if ($filterOutletId > 0 && !in_array($filterOutletId, $outletIds, true)) {
  $filterOutletId = 0;
}

if ($filterDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
  $filterDate = '';
}

$staffSql = "
    SELECT DISTINCT u.user_id, u.full_name, u.role
    FROM staff_activity_log sal
    JOIN users u ON u.user_id = sal.user_id
";
$staffParams = [];

if (is_array($assignedOutletIds)) {
  if (!$assignedOutletIds) {
    $staffSql .= ' WHERE 1=0';
  } else {
    $staffSql .= ' WHERE sal.outlet_id IN (' . implode(',', array_fill(0, count($assignedOutletIds), '?')) . ')';
    $staffParams = $assignedOutletIds;
  }
}

$staffSql .= ' ORDER BY u.full_name';
$staffStmt = $pdo->prepare($staffSql);
$staffStmt->execute($staffParams);
$staffOptions = $staffStmt->fetchAll();
$staffIds = array_map('intval', array_column($staffOptions, 'user_id'));

if ($filterStaffId > 0 && !in_array($filterStaffId, $staffIds, true)) {
  $filterStaffId = 0;
}

$where = [];
$params = [];

if (is_array($assignedOutletIds)) {
  if (!$assignedOutletIds) {
    $where[] = '1=0';
  } else {
    $where[] = 'sal.outlet_id IN (' . implode(',', array_fill(0, count($assignedOutletIds), '?')) . ')';
    $params = array_merge($params, $assignedOutletIds);
  }
}

if ($filterDate !== '') {
  $where[] = 'DATE(sal.created_at) = ?';
  $params[] = $filterDate;
}

if ($filterOutletId > 0) {
  $where[] = 'sal.outlet_id = ?';
  $params[] = $filterOutletId;
}

if ($filterStaffId > 0) {
  $where[] = 'sal.user_id = ?';
  $params[] = $filterStaffId;
}

if ($search !== '') {
  $where[] = '(sal.action LIKE ? OR sal.details LIKE ? OR b.booking_code LIKE ? OR o.branch_name LIKE ? OR u.full_name LIKE ?)';
  $searchLike = '%' . $search . '%';
  $params = array_merge($params, [$searchLike, $searchLike, $searchLike, $searchLike, $searchLike]);
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$activityStmt = $pdo->prepare("
    SELECT sal.*, u.full_name, u.role, o.branch_name, s.state_name, b.booking_code
    FROM staff_activity_log sal
    LEFT JOIN users u ON u.user_id = sal.user_id
    LEFT JOIN outlets o ON o.outlet_id = sal.outlet_id
    LEFT JOIN outlet_states s ON s.state_id = o.state_id
    LEFT JOIN bookings b ON b.booking_id = sal.booking_id
    $whereSql
    ORDER BY sal.created_at DESC
    LIMIT 100
");
$activityStmt->execute($params);
$activityRows = $activityStmt->fetchAll();
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Activity Log</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
  <?php include 'staff_sidebar.php'; ?>
  <main class="container py-4">
    <a href="dashboard.php" class="btn-nav-icon mb-3"><span class="btn-nav-icon-symbol">&larr;</span><span>Dashboard</span></a>

    <section class="dashboard-panel mb-3" aria-labelledby="activity-log-title">
      <div class="dashboard-section-heading">
        <div>
          <div class="section-title">Activity Log</div>
          <h3 id="activity-log-title" class="mb-0">Staff Actions</h3>
          <p class="muted-small mb-0">Track recent admin and shop owner actions by outlet, date, or staff member.</p>
        </div>
      </div>

      <form method="get" class="row g-3 mb-4">
        <div class="col-md-3">
          <label class="form-label">Search</label>
          <input type="search" name="search" class="form-control" value="<?= h($search) ?>" placeholder="Action, booking, outlet">
        </div>

        <div class="col-md-2">
          <label class="form-label">Date</label>
          <input type="date" name="date" class="form-control" value="<?= h($filterDate) ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Outlet</label>
          <select name="outlet_id" class="form-select">
            <option value="0">All Outlets</option>
            <?php foreach ($outlets as $outlet): ?>
              <option value="<?= h($outlet['outlet_id']) ?>" <?= $filterOutletId === (int)$outlet['outlet_id'] ? 'selected' : '' ?>>
                <?= h($outlet['state_name']) ?> - <?= h($outlet['branch_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-2">
          <label class="form-label">Staff</label>
          <select name="staff_id" class="form-select">
            <option value="0">All Staff</option>
            <?php foreach ($staffOptions as $staff): ?>
              <option value="<?= h($staff['user_id']) ?>" <?= $filterStaffId === (int)$staff['user_id'] ? 'selected' : '' ?>>
                <?= h($staff['full_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-2 align-self-end">
          <button class="btn btn-dark">Apply Filter</button>
          <a href="activity_log.php" class="btn btn-outline-dark mt-2 mt-md-0">Reset</a>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Date & Time</th>
              <th>Staff</th>
              <th>Outlet</th>
              <th>Booking</th>
              <th>Action</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($activityRows as $activity): ?>
              <tr>
                <td><?= h(date('d/m/Y g:i A', strtotime($activity['created_at']))) ?></td>
                <td>
                  <strong><?= h($activity['full_name'] ?? 'Staff') ?></strong>
                  <br><span class="muted-small"><?= h(ucwords(str_replace('_', ' ', $activity['role'] ?? 'staff'))) ?></span>
                </td>
                <td>
                  <?php if (!empty($activity['branch_name'])): ?>
                    <strong><?= h($activity['branch_name']) ?></strong>
                    <br><span class="muted-small"><?= h($activity['state_name'] ?? '-') ?></span>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
                <td><?= h($activity['booking_code'] ?? '-') ?></td>
                <td><span class="badge bg-dark"><?= h($activity['action']) ?></span></td>
                <td><?= h($activity['details'] ?? '-') ?></td>
              </tr>
            <?php endforeach; ?>

            <?php if (!$activityRows): ?>
              <tr>
                <td colspan="6" class="text-center text-muted">No activity found.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</body>

</html>
