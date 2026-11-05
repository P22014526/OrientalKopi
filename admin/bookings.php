<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_staff();
prevent_dynamic_page_cache();

$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$assignedOutletIds = staff_assigned_outlet_ids($pdo);
$noShowGraceMinutes = no_show_grace_minutes($pdo);
$filterDate = $_GET['filter_date'] ?? '';
$filterTime = $_GET['filter_time'] ?? '';
$filterStatus = $_GET['filter_status'] ?? '';
$filterOutletId = (int)($_GET['filter_outlet_id'] ?? 0);
$bookingSearch = trim($_GET['booking_search'] ?? '');
$alertOptions = ['pending' => 'Pending requests', 'preorders' => 'Submitted preorders',
  'no_show' => 'No-show candidates', 'cancellations' => 'Recent cancellations'];
$alertFilter = (string)($_GET['alert'] ?? '');
if (!isset($alertOptions[$alertFilter])) $alertFilter = '';
$validStatuses = ['Needs Review', 'Pending', 'Confirmed', 'Completed', 'Declined', 'Cancelled'];
if (!in_array($filterStatus, $validStatuses, true)) $filterStatus = '';
// A direct status selection must not inherit an alert's conflicting status/date restrictions.
if ($filterStatus !== '') $alertFilter = '';

$outletContactSql = "
    SELECT o.outlet_id, o.state_id, o.branch_name, o.location_detail, o.contact_phone, o.map_url, o.is_active, s.state_name
    FROM outlets o
    JOIN outlet_states s ON s.state_id = o.state_id
    WHERE 1=1
";
$outletContactParams = [];

if (is_array($assignedOutletIds)) {
  $outletContactSql .= " AND o.is_active = 1";

  if (!$assignedOutletIds) {
    $outletContactSql .= " AND 1=0";
  } else {
    $outletContactSql .= " AND o.outlet_id IN (" . implode(',', array_fill(0, count($assignedOutletIds), '?')) . ")";
    $outletContactParams = $assignedOutletIds;
  }
} else {
  $outletContactSql .= " AND (o.is_active = 1 OR ? = 1)";
  $outletContactParams[] = $isAdmin ? 1 : 0;
}

$outletContactSql .= " ORDER BY s.display_order, o.branch_name";
$outletContactStmt = $pdo->prepare($outletContactSql);
$outletContactStmt->execute($outletContactParams);
$outletContacts = $outletContactStmt->fetchAll();

$filterableOutletIds = array_map('intval', array_column($outletContacts, 'outlet_id'));
if ($filterOutletId > 0 && !in_array($filterOutletId, $filterableOutletIds, true)) {
  $filterOutletId = 0;
}

$where = [];
$params = [];

// Match the dashboard alert conditions while retaining the staff outlet scope below.
if ($alertFilter === 'pending') {
  $where[] = "b.status = 'Pending' AND b.booking_date >= CURDATE()";
} elseif ($alertFilter === 'preorders') {
  $where[] = "b.booking_date >= CURDATE() AND EXISTS (SELECT 1 FROM preorders ap WHERE ap.booking_id=b.booking_id AND ap.status='Submitted')";
} elseif ($alertFilter === 'no_show') {
  $where[] = "b.status='Confirmed' AND TIMESTAMP(b.booking_date,b.booking_time) <= DATE_SUB(NOW(), INTERVAL ? MINUTE)";
  $params[] = $noShowGraceMinutes;
} elseif ($alertFilter === 'cancellations') {
  $where[] = "b.status IN ('Cancelled','Declined') AND b.booking_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
}

if ($filterDate !== '') {
  $where[] = "b.booking_date = ?";
  $params[] = $filterDate;
}

if ($filterTime !== '') {
  $where[] = "b.booking_time = ?";
  $params[] = $filterTime;
}

if ($filterStatus === 'Needs Review') {
  $where[] = "b.status IN ('Pending','Confirmed') AND b.booking_date < ?";
  $params[] = date('Y-m-d');
} elseif ($filterStatus !== '') {
  $where[] = "b.status = ?";
  $params[] = $filterStatus;
}

if ($filterOutletId > 0) {
  $where[] = "b.outlet_id = ?";
  $params[] = $filterOutletId;
}

if ($bookingSearch !== '') {
  $where[] = "(b.booking_code LIKE ? OR b.customer_name LIKE ? OR b.phone LIKE ? OR o.branch_name LIKE ?)";
  $searchLike = '%' . $bookingSearch . '%';
  $params = array_merge($params, [$searchLike, $searchLike, $searchLike, $searchLike]);
}

if (is_array($assignedOutletIds)) {
  if (!$assignedOutletIds) {
    $where[] = '1=0';
  } else {
    $where[] = 'b.outlet_id IN (' . implode(',', array_fill(0, count($assignedOutletIds), '?')) . ')';
    $params = array_merge($params, $assignedOutletIds);
  }
}

$whereSql = '';

if (!empty($where)) {
  $whereSql = 'WHERE ' . implode(' AND ', $where);
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings b
    LEFT JOIN outlets o ON o.outlet_id=b.outlet_id $whereSql");
$countStmt->execute($params);
$totalBookings = (int)$countStmt->fetchColumn();
$pageSize = 30;
$totalPages = max(1, (int)ceil($totalBookings / $pageSize));
$page = min($totalPages, max(1, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $pageSize;
$pageFilters = ['alert' => $alertFilter, 'filter_date' => $filterDate, 'filter_time' => $filterTime,
  'filter_status' => $filterStatus, 'filter_outlet_id' => $filterOutletId, 'booking_search' => $bookingSearch];
$pageUrl = static function (int $number) use ($pageFilters): string {
  return 'bookings.php?' . http_build_query(array_merge($pageFilters, ['page' => $number])) . '#booking-records';
};

$stmt = $pdo->prepare("
    SELECT b.*,
           o.branch_name,
           o.location_detail,
           o.contact_phone,
           o.map_url,
           s.state_name,
           COALESCE(SUM(CASE WHEN p.status = 'Submitted' THEN p.quantity ELSE 0 END), 0) AS preorder_count
    FROM bookings b
    LEFT JOIN outlets o ON o.outlet_id = b.outlet_id
    LEFT JOIN outlet_states s ON s.state_id = o.state_id
    LEFT JOIN preorders p ON b.booking_id = p.booking_id
    $whereSql
    GROUP BY b.booking_id
    ORDER BY b.booking_date DESC, b.booking_time DESC, b.booking_id DESC
    LIMIT $pageSize OFFSET $offset
");

$stmt->execute($params);
$bookings = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bookings | Oriental Kopi</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
  <?php include 'staff_sidebar.php'; ?>
  <main class="container py-4">
    <a href="dashboard.php" class="btn-nav-icon mb-3"><span class="btn-nav-icon-symbol">&larr;</span><span>Dashboard</span></a>
    <section id="booking-records" class="dashboard-panel mb-3" aria-labelledby="booking-records-title">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
          <div class="section-title">Booking Records</div>
          <h4 id="booking-records-title" class="mb-0">Booking List</h4>
          <p class="muted-small mb-0">Filter bookings by outlet, date, time, and status. Use the pencil button to manage a booking.</p>
        </div>

        <?php if ($filterDate || $filterTime || $filterStatus || $filterOutletId || $bookingSearch || $alertFilter): ?>
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-warning text-dark">Filtered Results<?= $filterStatus !== '' ? ': ' . h($filterStatus) : '' ?><?= $alertFilter !== '' ? ' / ' . h($alertOptions[$alertFilter]) : '' ?></span>
            <a href="bookings.php#booking-records" class="btn btn-sm btn-outline-dark">Show All Bookings</a>
          </div>
        <?php endif; ?>
      </div>

      <form method="get" action="bookings.php#booking-records" class="row g-3 mb-4">
        <div class="col-md-4">
          <label for="alert-filter" class="form-label">Operational Alert</label>
          <select id="alert-filter" name="alert" class="form-select">
            <option value="">All Bookings</option>
            <?php foreach ($alertOptions as $key => $label): ?>
              <option value="<?= h($key) ?>" <?= $alertFilter === $key ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Search</label>
          <input
            type="search"
            name="booking_search"
            class="form-control"
            value="<?= h($bookingSearch) ?>"
            placeholder="Booking ID, name, phone, or outlet">
        </div>

        <div class="col-md-2">
          <label class="form-label">Date</label>
          <input
            type="date"
            name="filter_date"
            class="form-control"
            value="<?= h($filterDate) ?>">
        </div>

        <div class="col-md-2">
          <label class="form-label">Time</label>
          <input
            type="time"
            name="filter_time"
            class="form-control"
            value="<?= h($filterTime) ?>">
          <div class="muted-small mt-1">
            Select exact booking time.
          </div>
        </div>

        <div class="col-md-2">
          <label class="form-label">Outlet</label>
          <select name="filter_outlet_id" class="form-select">
            <option value="0">All Outlets</option>
            <?php foreach ($outletContacts as $outlet): ?>
              <option value="<?= h($outlet['outlet_id']) ?>" <?= $filterOutletId === (int)$outlet['outlet_id'] ? 'selected' : '' ?>>
                <?= h($outlet['state_name']) ?> - <?= h($outlet['branch_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-2">
          <label class="form-label">Status</label>
          <select id="booking-status-filter" name="filter_status" class="form-select">
            <option value="">All Status</option>
            <option value="Needs Review" <?= $filterStatus === 'Needs Review' ? 'selected' : '' ?>>Needs Review</option>
            <option value="Pending" <?= $filterStatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
            <option value="Confirmed" <?= $filterStatus === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
            <option value="Completed" <?= $filterStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
            <option value="Declined" <?= $filterStatus === 'Declined' ? 'selected' : '' ?>>Declined</option>
            <option value="Cancelled" <?= $filterStatus === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
          </select>
        </div>

        <div class="col-md-4 align-self-end">
          <button class="btn btn-dark">Apply Filter</button>
          <a href="bookings.php#booking-records" class="btn btn-outline-dark">Reset</a>
        </div>
      </form>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Outlet</th>
              <th>Date</th>
              <th>Time</th>
              <th>Pax</th>
              <th>Type</th>
              <th>Preorder</th>
              <th class="text-center">Edit</th>
              <th>Quick Actions</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bookings as $b): ?>
              <?php
              $canMarkNoShow = booking_can_be_marked_no_show($b, $noShowGraceMinutes);
              $noShowAvailableAt = booking_no_show_available_at($b, $noShowGraceMinutes);
              ?>
              <tr>
                <td><?= h($b['booking_code']) ?></td>
                <td>
                  <?= h($b['customer_name']) ?>
                  <?php if (!empty($b['phone'])): ?>
                    <br><a class="muted-small" href="tel:<?= h(phone_href($b['phone'])) ?>">Call Customer</a>
                  <?php endif; ?>
                </td>
                <td>
                  <strong><?= h($b['branch_name'] ?? 'Unassigned') ?></strong><br>
                  <span class="muted-small"><?= h($b['state_name'] ?? '-') ?></span>
                  <?php if (!empty($b['contact_phone'])): ?>
                    <br><a class="muted-small" href="tel:<?= h(phone_href($b['contact_phone'])) ?>"><?= h($b['contact_phone']) ?></a>
                  <?php endif; ?>
                  <?php if (!empty($b['map_url'])): ?>
                    <br><a class="muted-small" href="<?= h($b['map_url']) ?>" target="_blank" rel="noopener">Map</a>
                  <?php endif; ?>
                </td>
                <td><?= h(date('d/m', strtotime($b['booking_date']))) ?></td>
                <td><?= h(format_time12($b['booking_time'])) ?></td>
                <td><?= h($b['pax']) ?></td>
                <td><?= h($b['request_type']) ?></td>
                <td>
                  <?php if ((int)$b['preorder_count'] > 0): ?>
                    <span class="badge bg-success"><?= h($b['preorder_count']) ?> item(s)</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">None</span>
                  <?php endif; ?>
                </td>

                <td class="text-center">
                  <a
                    class="btn btn-sm btn-outline-dark"
                    href="edit_booking.php?id=<?= h($b['booking_id']) ?>"
                    title="Manage booking"
                    aria-label="Manage booking <?= h($b['booking_code']) ?>">
                    ✏️
                  </a>
                </td>

                <td>
                  <?php if ($b['status'] === 'Confirmed'): ?>
                    <div class="d-flex flex-wrap gap-1">
                      <a class="btn btn-sm btn-success" href="complete_booking.php?id=<?= h($b['booking_id']) ?>">Complete</a>
                      <?php if ($canMarkNoShow): ?>
                        <a class="btn btn-sm btn-outline-danger" href="cancel_no_show.php?id=<?= h($b['booking_id']) ?>&mode=no_show">No-show</a>
                      <?php else: ?>
                        <span
                          class="btn btn-sm btn-outline-secondary disabled"
                          title="Available after <?= h($noShowAvailableAt ? $noShowAvailableAt->format('d/m/Y g:i A') : no_show_grace_label($noShowGraceMinutes)) ?>">
                          No-show Locked
                        </span>
                      <?php endif; ?>
                    </div>
                  <?php elseif ($b['status'] === 'Pending'): ?>
                    <div class="d-flex flex-wrap gap-1">
                      <a class="btn btn-sm btn-success" href="approve.php?id=<?= h($b['booking_id']) ?>">Approve</a>
                      <a class="btn btn-sm btn-outline-danger" href="decline.php?id=<?= h($b['booking_id']) ?>">Decline</a>
                    </div>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>

                <td>
                  <span class="badge bg-<?= h(status_badge_class($b['status'])) ?>">
                    <?= h($b['status']) ?>
                  </span>
                  <?php if (booking_needs_review($b)): ?><div class="mt-1"><span class="badge bg-danger">Needs Review</span></div><?php endif; ?>
                </td>
              </tr><?php endforeach; ?>

            <?php if (!$bookings): ?>
              <tr>
                <td colspan="11" class="text-center text-muted">No bookings found.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <nav class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-3" aria-label="Booking list pages">
        <span class="small text-muted">Showing <?= $totalBookings ? $offset + 1 : 0 ?>–<?= min($offset + $pageSize, $totalBookings) ?> of <?= $totalBookings ?> bookings</span>
        <div class="d-flex align-items-center gap-2">
          <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-dark" href="<?= h($pageUrl($page - 1)) ?>" aria-label="Previous page">&larr; Previous</a><?php endif; ?>
          <span class="small">Page <?= $page ?> of <?= $totalPages ?></span>
          <?php if ($page < $totalPages): ?><a class="btn btn-sm btn-outline-dark" href="<?= h($pageUrl($page + 1)) ?>" aria-label="Next page">Next &rarr;</a><?php endif; ?>
        </div>
      </nav>
    </section>
  </main>
  <script>
    const alertFilter = document.getElementById('alert-filter');
    const statusFilter = document.getElementById('booking-status-filter');
    statusFilter.addEventListener('change', () => { alertFilter.value = ''; });
    alertFilter.addEventListener('change', () => { statusFilter.value = ''; });
  </script>
</body>
</html>
