<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_staff();

$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

$today = date('Y-m-d');
$assignedOutletIds = staff_assigned_outlet_ids($pdo);
$scopeSql = '';
$scopeParams = [];

if (is_array($assignedOutletIds)) {
  if (!$assignedOutletIds) {
    $scopeSql = ' AND 1=0';
  } else {
    $scopeSql = ' AND b.outlet_id IN (' . implode(',', array_fill(0, count($assignedOutletIds), '?')) . ')';
    $scopeParams = $assignedOutletIds;
  }
}

$total = $pdo->prepare('SELECT COUNT(*) c FROM bookings b WHERE b.booking_date=?' . $scopeSql);
$total->execute(array_merge([$today], $scopeParams));
$totalToday = $total->fetch()['c'];
$pax = $pdo->prepare("SELECT COALESCE(SUM(pax),0) p FROM bookings b WHERE b.booking_date=? AND b.status='Confirmed'" . $scopeSql);
$pax->execute(array_merge([$today], $scopeParams));
$currentPax = $pax->fetch()['p'];
$averageRatingStmt = $pdo->prepare("
    SELECT ROUND(AVG(r.rating), 1) AS average_rating, COUNT(DISTINCT r.review_id) AS review_count
    FROM reviews r
    JOIN bookings b ON b.booking_id = r.booking_id
    WHERE b.status = 'Completed'
      $scopeSql
");
$averageRatingStmt->execute($scopeParams);
$averageRatingRow = $averageRatingStmt->fetch();
$averageRating = $averageRatingRow['average_rating'] ?? null;
$reviewCount = (int)($averageRatingRow['review_count'] ?? 0);
$noShowGraceMinutes = no_show_grace_minutes($pdo);
$preorderDate = $_GET['preorder_date'] ?? '';
$preorderTime = $_GET['preorder_time'] ?? '';
$preorderSearch = trim($_GET['preorder_search'] ?? '');

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
$dashboardStateNames = array_values(array_unique(array_filter(array_column($outletContacts, 'state_name'))));
$dashboardStateLabel = implode(' / ', $dashboardStateNames);
$dashboardTitle = 'Admin Dashboard';
$dashboardDescription = 'Manage bookings, party requests, FAQ content, shop owners, and performance reports by branch.';

if (!$isAdmin) {
  if ($dashboardStateLabel !== '') {
    $dashboardTitle = $dashboardStateLabel . ' Dashboard';
    $dashboardDescription = count($dashboardStateNames) === 1
      ? 'Manage bookings, party requests, and performance reports for your assigned ' . $dashboardStateLabel . ' outlets.'
      : 'Manage bookings, party requests, and performance reports across ' . $dashboardStateLabel . '.';
  } else {
    $dashboardTitle = 'Assigned Outlets Dashboard';
    $dashboardDescription = 'Manage bookings, party requests, and performance reports for your assigned branches.';
  }
}

$filterableOutletIds = array_map('intval', array_column($outletContacts, 'outlet_id'));
$max = array_sum(array_map(fn($id) => max_capacity($pdo, $id), $filterableOutletIds));
$tableSummary = 'Total seating across your outlets';
$focusOutletId = (int)($_GET['focus_outlet_id'] ?? 0);
$availabilityDate = $_GET['availability_date'] ?? $today;

if ($focusOutletId > 0 && !in_array($focusOutletId, $filterableOutletIds, true)) {
  $focusOutletId = 0;
}

if ($focusOutletId <= 0 && $filterableOutletIds) {
  $focusOutletId = $filterableOutletIds[0];
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $availabilityDate)) {
  $availabilityDate = $today;
}

if ($preorderDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $preorderDate)) {
  $preorderDate = '';
}

if ($preorderTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $preorderTime)) {
  $preorderTime = '';
}

$focusOutlet = null;
foreach ($outletContacts as $outlet) {
  if ((int)$outlet['outlet_id'] === $focusOutletId) {
    $focusOutlet = $outlet;
  }
}

$todayStatusCounts = [
  'Confirmed' => 0,
  'Pending' => 0,
  'Completed' => 0,
  'Cancelled' => 0,
  'Declined' => 0
];
$todayTablesBooked = 0;
$pendingQueue = [];
$preorderSummary = [];
$dailyPrintBookings = [];
$availabilityRows = [];

if ($focusOutletId > 0) {
  $todayStatusStmt = $pdo->prepare("
      SELECT b.status, COUNT(*) AS total
      FROM bookings b
      WHERE b.booking_date = ?
        AND b.outlet_id = ?
      GROUP BY b.status
  ");
  $todayStatusStmt->execute([$today, $focusOutletId]);

  foreach ($todayStatusStmt->fetchAll() as $row) {
    if (isset($todayStatusCounts[$row['status']])) {
      $todayStatusCounts[$row['status']] = (int)$row['total'];
    }
  }

  foreach (common_slots() as $slot) {
    $usage = slot_usage($pdo, $today, $slot, null, null, null, $focusOutletId);
    $todayTablesBooked += (int)$usage['used_tables'];
  }

  $pendingStmt = $pdo->prepare("
      SELECT b.*, o.branch_name, s.state_name
      FROM bookings b
      LEFT JOIN outlets o ON o.outlet_id = b.outlet_id
      LEFT JOIN outlet_states s ON s.state_id = o.state_id
      WHERE b.status = 'Pending'
        AND b.booking_date >= CURDATE()
        AND b.outlet_id = ?
      ORDER BY b.booking_date ASC, b.booking_time ASC
      LIMIT 8
  ");
  $pendingStmt->execute([$focusOutletId]);
  $pendingQueue = $pendingStmt->fetchAll();

  $preorderWhere = [
    "b.booking_date >= CURDATE()",
    "b.outlet_id = ?"
  ];
  $preorderParams = [$focusOutletId];

  if ($preorderDate !== '') {
    $preorderWhere[] = "b.booking_date = ?";
    $preorderParams[] = $preorderDate;
  }

  if ($preorderTime !== '') {
    $preorderWhere[] = "b.booking_time = ?";
    $preorderParams[] = $preorderTime;
  }

  if ($preorderSearch !== '') {
    $preorderWhere[] = "(b.customer_name LIKE ? OR b.booking_code LIKE ?)";
    $preorderParams[] = '%' . $preorderSearch . '%';
    $preorderParams[] = '%' . $preorderSearch . '%';
  }

  $preorderStmt = $pdo->prepare("
      SELECT b.*, o.branch_name, s.state_name,
             COALESCE(SUM(p.quantity), 0) AS preorder_count,
             COALESCE(SUM(p.quantity * m.price), 0) AS preorder_total
      FROM bookings b
      JOIN preorders p ON p.booking_id = b.booking_id AND p.status = 'Submitted'
      JOIN menu_items m ON m.item_id = p.item_id
      LEFT JOIN outlets o ON o.outlet_id = b.outlet_id
      LEFT JOIN outlet_states s ON s.state_id = o.state_id
      WHERE " . implode(' AND ', $preorderWhere) . "
      GROUP BY b.booking_id
      ORDER BY b.booking_date ASC, b.booking_time ASC
      LIMIT 8
  ");
  $preorderStmt->execute($preorderParams);
  $preorderSummary = $preorderStmt->fetchAll();

  $dailyPrintStmt = $pdo->prepare("
      SELECT b.*, o.branch_name, s.state_name
      FROM bookings b
      LEFT JOIN outlets o ON o.outlet_id = b.outlet_id
      LEFT JOIN outlet_states s ON s.state_id = o.state_id
      WHERE b.booking_date = ?
        AND b.outlet_id = ?
      ORDER BY b.booking_time ASC, b.customer_name ASC
  ");
  $dailyPrintStmt->execute([$today, $focusOutletId]);
  $dailyPrintBookings = $dailyPrintStmt->fetchAll();

  foreach (common_slots() as $slot) {
    $availabilityRows[] = [
      'slot' => $slot,
      'usage' => slot_usage($pdo, $availabilityDate, $slot, null, null, null, $focusOutletId)
    ];
  }
}

$branchPerformanceRows = [];
$adminAlerts = [];
$reviewAttentionRows = [];
$customerIssueRows = [];
$lowAvailabilityRows = [];
$preorderAlertCount = 0;
$pendingAlertCount = 0;
$lowReviewAlertCount = 0;
$cancelAlertCount = 0;
$noShowAlertCount = 0;

if ($focusOutletId > 0) {
  foreach (common_slots() as $slot) {
    $usage = slot_usage($pdo, $today, $slot, null, null, null, $focusOutletId);

    if (in_array($usage['status'], ['Limited', 'Full'], true)) {
      $lowAvailabilityRows[] = [
        'slot' => $slot,
        'usage' => $usage
      ];
    }
  }
}

$pendingAlertStmt = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM bookings b
    WHERE b.status = 'Pending'
      AND b.booking_date >= CURDATE()
      $scopeSql
");
$pendingAlertStmt->execute($scopeParams);
$pendingAlertCount = (int)$pendingAlertStmt->fetch()['total'];

$preorderAlertStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT b.booking_id) AS total
    FROM bookings b
    JOIN preorders p ON p.booking_id = b.booking_id AND p.status = 'Submitted'
    WHERE b.booking_date >= CURDATE()
      $scopeSql
");
$preorderAlertStmt->execute($scopeParams);
$preorderAlertCount = (int)$preorderAlertStmt->fetch()['total'];

$lowReviewStmt = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM reviews r
    JOIN bookings b ON b.booking_id = r.booking_id
    WHERE r.rating <= 2
      AND b.status = 'Completed'
      AND (r.admin_reply IS NULL OR r.admin_reply = '')
      $scopeSql
");
$lowReviewStmt->execute($scopeParams);
$lowReviewAlertCount = (int)$lowReviewStmt->fetch()['total'];

$cancelAlertStmt = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM bookings b
    WHERE b.status IN ('Cancelled', 'Declined')
      AND b.booking_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
      $scopeSql
");
$cancelAlertStmt->execute($scopeParams);
$cancelAlertCount = (int)$cancelAlertStmt->fetch()['total'];

$noShowAlertStmt = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM bookings b
    WHERE b.status = 'Confirmed'
      AND TIMESTAMP(b.booking_date, b.booking_time) <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
      $scopeSql
");
$noShowAlertStmt->execute(array_merge([$noShowGraceMinutes], $scopeParams));
$noShowAlertCount = (int)$noShowAlertStmt->fetch()['total'];

$reviewNeededStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings b
    WHERE b.status IN ('Pending','Confirmed') AND b.booking_date < ? $scopeSql");
$reviewNeededStmt->execute(array_merge([$today], $scopeParams));
$reviewNeededCount = (int)$reviewNeededStmt->fetchColumn();

$adminAlerts = [
  [
    'label' => 'Bookings needing review',
    'url' => 'bookings.php?filter_status=Needs%20Review#booking-records',
    'action' => 'Review Bookings',
    'value' => $reviewNeededCount,
    'detail' => 'Past-date bookings still pending or confirmed',
    'level' => $reviewNeededCount > 0 ? 'danger' : 'calm',
    'status' => $reviewNeededCount > 0 ? 'Needs Review' : 'Clear'
  ],
  [
    'label' => 'Pending requests',
    'url' => 'bookings.php?alert=pending#booking-records',
    'action' => 'View Requests',
    'value' => $pendingAlertCount,
    'detail' => 'Bookings waiting for approval',
    'level' => $pendingAlertCount > 0 ? 'warning' : 'calm',
    'status' => $pendingAlertCount > 0 ? 'Action needed' : 'Clear'
  ],
  [
    'label' => 'Low availability slots',
    'url' => 'dashboard.php?' . http_build_query(['focus_outlet_id' => $focusOutletId, 'availability_date' => $today]) . '#branch-availability',
    'action' => 'View Slots',
    'value' => count($lowAvailabilityRows),
    'detail' => 'Limited or full slots today for focused branch',
    'level' => count($lowAvailabilityRows) > 0 ? 'danger' : 'calm',
    'status' => count($lowAvailabilityRows) > 0 ? 'Watch capacity' : 'Healthy'
  ],
  [
    'label' => 'Reviews needing attention',
    'url' => 'view_reviews.php',
    'action' => 'View Reviews',
    'value' => $lowReviewAlertCount,
    'detail' => 'Low ratings without staff reply',
    'level' => $lowReviewAlertCount > 0 ? 'danger' : 'calm',
    'status' => $lowReviewAlertCount > 0 ? 'Reply suggested' : 'Clear'
  ],
  [
    'label' => 'Submitted preorders',
    'url' => 'bookings.php?alert=preorders#booking-records',
    'action' => 'View Preorders',
    'value' => $preorderAlertCount,
    'detail' => 'Upcoming bookings with submitted food lists',
    'level' => $preorderAlertCount > 0 ? 'info' : 'calm',
    'status' => $preorderAlertCount > 0 ? 'Kitchen prep' : 'None waiting'
  ],
  [
    'label' => 'No-show candidates',
    'url' => 'bookings.php?alert=no_show#booking-records',
    'action' => 'Review Bookings',
    'value' => $noShowAlertCount,
    'detail' => 'Confirmed bookings past the ' . no_show_grace_label($noShowGraceMinutes),
    'level' => $noShowAlertCount > 0 ? 'warning' : 'calm',
    'status' => $noShowAlertCount > 0 ? 'Check arrivals' : 'Clear'
  ],
  [
    'label' => 'Recent cancellations',
    'url' => 'bookings.php?alert=cancellations#booking-records',
    'action' => 'View Cancellations',
    'value' => $cancelAlertCount,
    'detail' => 'Cancelled or declined bookings in the last 7 days',
    'level' => $cancelAlertCount >= 3 ? 'warning' : 'calm',
    'status' => $cancelAlertCount >= 3 ? 'Monitor trend' : 'Normal'
  ]
];

$performanceScope = '';
$performanceParams = [];

if (is_array($assignedOutletIds)) {
  if (!$assignedOutletIds) {
    $performanceScope = ' AND 1=0';
  } else {
    $performanceScope = ' AND o.outlet_id IN (' . implode(',', array_fill(0, count($assignedOutletIds), '?')) . ')';
    $performanceParams = $assignedOutletIds;
  }
}

$branchPerformanceStmt = $pdo->prepare("
    SELECT o.outlet_id, o.branch_name, s.state_name,
           COUNT(DISTINCT b.booking_id) AS total_bookings,
           COUNT(DISTINCT CASE WHEN b.status = 'Completed' THEN b.booking_id END) AS completed_bookings,
           COUNT(DISTINCT CASE WHEN b.status = 'Cancelled' THEN b.booking_id END) AS cancelled_bookings,
           COUNT(DISTINCT CASE WHEN b.status = 'Declined' THEN b.booking_id END) AS declined_bookings,
           COUNT(DISTINCT CASE WHEN p.booking_id IS NOT NULL THEN b.booking_id END) AS preorder_bookings,
           ROUND(AVG(CASE WHEN b.status = 'Completed' THEN r.rating END), 1) AS average_rating
    FROM outlets o
    JOIN outlet_states s ON s.state_id = o.state_id
    LEFT JOIN bookings b
      ON b.outlet_id = o.outlet_id
     AND b.booking_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    LEFT JOIN (
      SELECT DISTINCT booking_id
      FROM preorders
      WHERE status = 'Submitted'
    ) p ON p.booking_id = b.booking_id
    LEFT JOIN reviews r
      ON r.booking_id = b.booking_id
     AND b.status = 'Completed'
    WHERE o.is_active = 1
      $performanceScope
    GROUP BY o.outlet_id
    ORDER BY total_bookings DESC, s.display_order, o.branch_name
    LIMIT 12
");
$branchPerformanceStmt->execute($performanceParams);
$branchPerformanceRows = $branchPerformanceStmt->fetchAll();

$reviewAttentionStmt = $pdo->prepare("
    SELECT r.*, u.full_name, b.booking_code, b.outlet_id, o.branch_name, s.state_name
    FROM reviews r
    JOIN users u ON u.user_id = r.user_id
    JOIN bookings b ON b.booking_id = r.booking_id
    LEFT JOIN outlets o ON o.outlet_id = b.outlet_id
    LEFT JOIN outlet_states s ON s.state_id = o.state_id
    WHERE r.rating <= 2
      AND b.status = 'Completed'
      AND (r.admin_reply IS NULL OR r.admin_reply = '')
      $scopeSql
    ORDER BY r.created_at DESC
    LIMIT 6
");
$reviewAttentionStmt->execute($scopeParams);
$reviewAttentionRows = $reviewAttentionStmt->fetchAll();

$customerIssueStmt = $pdo->prepare("
    SELECT b.user_id, b.customer_name, u.email, u.phone,
           COUNT(*) AS issue_count,
           MAX(b.booking_date) AS last_issue_date
    FROM bookings b
    LEFT JOIN users u ON u.user_id = b.user_id
    WHERE b.status IN ('Cancelled', 'Declined')
      AND b.booking_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
      $scopeSql
    GROUP BY b.user_id, b.customer_name, u.email, u.phone
    HAVING issue_count >= 2
    ORDER BY issue_count DESC, last_issue_date DESC
    LIMIT 8
");
$customerIssueStmt->execute($scopeParams);
$customerIssueRows = $customerIssueStmt->fetchAll();

?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($dashboardTitle) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
  <?php include 'staff_sidebar.php'; ?>
  <main class="container py-4">
    <div class="hero p-4 mb-4">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <div class="section-title text-warning">Back office</div>
          <h2><?= h($dashboardTitle) ?></h2>
          <p class="mb-0"><?= h($dashboardDescription) ?></p>
        </div>

        <a class="btn btn-gold position-relative" style="z-index: 9999;" href="../logout.php">Logout</a>
      </div>
    </div>

    <nav class="dashboard-category-nav no-print mb-4" aria-label="Dashboard sections">
      <a href="#branch-operations">
        <span>01</span>
        <strong>Branch Operations</strong>
        <small>Outlet, queue, preorders, availability</small>
      </a>
      <a href="#outlet-overview">
        <span>02</span>
        <strong>Outlet Overview</strong>
        <small>Alerts, performance, feedback, tools</small>
      </a>
      <a href="bookings.php">
        <span>03</span>
        <strong>Booking Records</strong>
        <small>Filter, edit, approve, complete</small>
      </a>
    </nav>

    <section class="dashboard-panel mb-3 no-print" aria-labelledby="dashboard-overview-title">
      <div class="dashboard-section-heading">
        <div>
          <div class="section-title">Today Overview</div>
          <h4 id="dashboard-overview-title" class="mb-0">At a Glance</h4>
        </div>
        <span class="dashboard-date-pill"><?= h(format_date_short($today)) ?></span>
      </div>

      <div class="dashboard-metric-grid">
        <div class="dashboard-metric">
          <span>Total Bookings Today</span>
          <strong><?= h($totalToday) ?></strong>
          <small>All statuses within your visible outlets</small>
        </div>

        <div class="dashboard-metric">
          <span>Confirmed Pax</span>
          <strong><?= h($currentPax) ?> / <?= h($max) ?></strong>
          <small><?= h($tableSummary) ?></small>
        </div>

        <div class="dashboard-metric">
          <span>Average Rating</span>
          <strong><?= $averageRating !== null ? h($averageRating) . ' / 5' : '-' ?></strong>
          <small><?= h($reviewCount) ?> completed dining review(s)</small>
        </div>

        <div class="dashboard-metric">
          <span>Outlets</span>
          <strong><?= h(count($outletContacts)) ?></strong>
          <small><?= $isAdmin ? 'Across all states' : 'Assigned to your account' ?></small>
        </div>
      </div>
    </section>


    <div class="dashboard-branch-group">
      <section id="branch-operations" class="dashboard-panel mb-3 no-print" aria-labelledby="branch-operations-title">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
          <div>
            <div class="section-title">Branch Operations</div>
            <h4 id="branch-operations-title" class="mb-0">Focused Branch</h4>
          </div>

          <form method="get" action="dashboard.php#branch-operations" class="dashboard-focus-form">
            <input type="hidden" name="availability_date" value="<?= h($availabilityDate) ?>">
            <select name="focus_outlet_id" class="form-select" aria-label="Focused branch">
              <?php foreach ($outletContacts as $outlet): ?>
                <option value="<?= h($outlet['outlet_id']) ?>" <?= $focusOutletId === (int)$outlet['outlet_id'] ? 'selected' : '' ?>>
                  <?= h($outlet['state_name']) ?> - <?= h($outlet['branch_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-dark">Switch</button>
          </form>
        </div>

        <?php if ($focusOutlet): ?>
          <div class="dashboard-focused-details">
            <div>
              <h3 class="mb-1"><?= h($focusOutlet['branch_name']) ?></h3>
              <p class="mb-1 fw-semibold"><?= h($focusOutlet['state_name']) ?></p>
              <p class="muted-small mb-0"><?= h($focusOutlet['location_detail']) ?></p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <?php if (!empty($focusOutlet['contact_phone'])): ?>
                <a class="btn btn-outline-dark" href="tel:<?= h(phone_href($focusOutlet['contact_phone'])) ?>"><?= h($focusOutlet['contact_phone']) ?></a>
              <?php endif; ?>
              <?php if (!empty($focusOutlet['map_url'])): ?>
                <a class="btn btn-outline-dark" href="<?= h($focusOutlet['map_url']) ?>" target="_blank" rel="noopener">Open Map</a>
              <?php endif; ?>
              <a class="btn btn-dark" href="bookings.php?filter_outlet_id=<?= h($focusOutletId) ?>">Branch Bookings</a>
            </div>
          </div>
        <?php else: ?>
          <p class="alert alert-info mt-3 mb-0">No outlet is currently assigned to this account.</p>
        <?php endif; ?>

        <div class="dashboard-section-heading">
          <div>
            <h4 id="branch-status-title" class="mb-0">Today's Branch Status</h4>
          </div>
        </div>

        <div class="dashboard-status-grid">
          <div class="dashboard-status-tile">
            <span class="muted-small">Confirmed</span>
            <strong><?= h($todayStatusCounts['Confirmed']) ?></strong>
          </div>

          <div class="dashboard-status-tile">
            <span class="muted-small">Pending</span>
            <strong><?= h($todayStatusCounts['Pending']) ?></strong>
          </div>

          <div class="dashboard-status-tile">
            <span class="muted-small">Completed</span>
            <strong><?= h($todayStatusCounts['Completed']) ?></strong>
          </div>

          <div class="dashboard-status-tile">
            <span class="muted-small">Cancelled / No-show</span>
            <strong><?= h($todayStatusCounts['Cancelled']) ?></strong>
          </div>

          <div class="dashboard-status-tile">
            <span class="muted-small">Declined</span>
            <strong><?= h($todayStatusCounts['Declined']) ?></strong>
          </div>

          <div class="dashboard-status-tile">
            <span class="muted-small">Tables Booked</span>
            <strong><?= h($todayTablesBooked) ?></strong>
          </div>
        </div>
      </section>

      <div class="row g-3 mb-3 no-print">
        <div class="col-lg-6">
          <div id="pending-queue" class="card ok-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
              <div>
                <div class="section-title">Pending Approval Queue</div>
                <h4 class="mb-0">Requests Waiting for Action</h4>
              </div>
              <span class="badge bg-warning text-dark"><?= h(count($pendingQueue)) ?> pending</span>
            </div>

            <?php if (!$pendingQueue): ?>
              <div class="alert alert-info mb-0">No pending requests for this branch.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Customer</th>
                      <th>Date / Time</th>
                      <th>Type</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($pendingQueue as $request): ?>
                      <tr>
                        <td><?= h($request['booking_code']) ?></td>
                        <td>
                          <?= h($request['customer_name']) ?><br>
                          <?php if (!empty($request['phone'])): ?>
                            <a class="muted-small" href="tel:<?= h(phone_href($request['phone'])) ?>">Call Customer</a>
                          <?php endif; ?>
                        </td>
                        <td><?= h(format_date_short($request['booking_date'])) ?><br><?= h(format_time12($request['booking_time'])) ?></td>
                        <td><?= h($request['request_type']) ?></td>
                        <td>
                          <div class="d-flex flex-wrap gap-1">
                            <a class="btn btn-sm btn-success" href="approve.php?id=<?= h($request['booking_id']) ?>">Approve</a>
                            <a class="btn btn-sm btn-outline-danger" href="decline.php?id=<?= h($request['booking_id']) ?>">Decline</a>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="col-lg-6">
          <div id="preorder-summary" class="card ok-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
              <div>
                <div class="section-title">Preorder Summary</div>
                <h4 class="mb-0">Upcoming Submitted Food Lists</h4>
              </div>
              <span class="badge bg-success"><?= h(count($preorderSummary)) ?> booking(s)</span>
            </div>

            <form method="get" action="dashboard.php#preorder-summary" class="row g-2 mb-3">
              <input type="hidden" name="focus_outlet_id" value="<?= h($focusOutletId) ?>">
              <input type="hidden" name="availability_date" value="<?= h($availabilityDate) ?>">

              <div class="col-md-4">
                <label class="form-label">Date</label>
                <input type="date" name="preorder_date" class="form-control form-control-sm" value="<?= h($preorderDate) ?>">
              </div>

              <div class="col-md-4">
                <label class="form-label">Time</label>
                <input type="time" name="preorder_time" class="form-control form-control-sm" value="<?= h($preorderTime) ?>">
              </div>

              <div class="col-md-4">
                <label class="form-label">Search</label>
                <input
                  type="search"
                  name="preorder_search"
                  class="form-control form-control-sm"
                  value="<?= h($preorderSearch) ?>"
                  placeholder="Name or ID">
              </div>

              <div class="col-12 d-flex flex-wrap gap-2">
                <button class="btn btn-sm btn-dark">Filter Preorders</button>
                <a
                  class="btn btn-sm btn-outline-dark"
                  href="dashboard.php?focus_outlet_id=<?= h($focusOutletId) ?>&availability_date=<?= h(urlencode($availabilityDate)) ?>#preorder-summary">
                  Reset Preorder Filter
                </a>
              </div>
            </form>

            <?php if ($preorderDate || $preorderTime || $preorderSearch): ?>
              <div class="alert alert-warning py-2 mb-3">
                Showing filtered preorder results.
              </div>
            <?php endif; ?>

            <?php if (!$preorderSummary): ?>
              <div class="alert alert-info mb-0">No submitted preorders for upcoming bookings.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Customer</th>
                      <th>Date / Time</th>
                      <th>Items</th>
                      <th>View</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($preorderSummary as $preorder): ?>
                      <tr>
                        <td><?= h($preorder['booking_code']) ?></td>
                        <td><?= h($preorder['customer_name']) ?></td>
                        <td><?= h(format_date_short($preorder['booking_date'])) ?><br><?= h(format_time12($preorder['booking_time'])) ?></td>
                        <td>
                          <?= h($preorder['preorder_count']) ?> item(s)<br>
                          <span class="muted-small">RM <?= h(number_format((float)$preorder['preorder_total'], 2)) ?></span>
                        </td>
                        <td>
                          <a class="btn btn-sm btn-outline-dark" href="view_preorder.php?id=<?= h($preorder['booking_id']) ?>">View</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <section id="branch-availability" class="dashboard-panel mb-3 no-print" aria-labelledby="availability-title">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <div>
            <div class="section-title">Branch Availability</div>
            <h4 id="availability-title" class="mb-0"><?= h($focusOutlet['branch_name'] ?? 'Selected Branch') ?> Slots</h4>
          </div>
          <form method="get" action="dashboard.php#branch-availability" class="d-flex flex-wrap gap-2 align-items-end">
            <input type="hidden" name="focus_outlet_id" value="<?= h($focusOutletId) ?>">
            <div>
              <label class="form-label">Date</label>
              <input type="date" name="availability_date" class="form-control" value="<?= h($availabilityDate) ?>">
            </div>
            <button class="btn btn-dark">View Availability</button>
          </form>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Time</th>
                <th>Tables Booked</th>
                <th>Remaining Tables</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($availabilityRows as $row): ?>
                <tr>
                  <td><?= h(format_time12($row['slot'])) ?></td>
                  <td><?= h($row['usage']['used_tables']) ?>/<?= h($row['usage']['total_tables']) ?></td>
                  <td><?= h($row['usage']['remaining_tables']) ?></td>
                  <td><span class="badge bg-<?= h($row['usage']['color']) ?>"><?= h($row['usage']['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="dashboard-panel mb-3 no-print" aria-labelledby="peak-heatmap-title">
        <div class="dashboard-section-heading">
          <div>
            <div class="section-title">Peak Hour Usage</div>
            <h4 id="peak-heatmap-title" class="mb-0">Slot Usage · <?= h(format_date_short($availabilityDate)) ?></h4>
          </div>
        </div>

        <div class="table-responsive dashboard-usage-table-wrap">
          <table class="table table-hover align-middle mb-0 dashboard-usage-table">
            <thead>
              <tr>
                <th>Time Slot</th>
                <th class="text-center">Tables Booked</th>
                <th class="text-center">Remaining Tables</th>
                <th class="text-center">Remaining Seats</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($availabilityRows as $row): ?>
                <tr class="dashboard-usage-row dashboard-usage-row-<?= h($row['usage']['color']) ?>">
                  <td><strong><?= h(format_time12($row['slot'])) ?></strong></td>
                  <td class="text-center"><?= h($row['usage']['used_tables']) ?>/<?= h($row['usage']['total_tables']) ?></td>
                  <td class="text-center"><?= h($row['usage']['remaining_tables']) ?></td>
                  <td class="text-center"><?= h($row['usage']['remaining_seats']) ?></td>
                  <td>
                    <span class="dashboard-usage-status"><?= h($row['usage']['status']) ?></span>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (!$availabilityRows): ?>
                <tr>
                  <td colspan="5" class="text-center text-muted">Select a focused branch to view peak hour usage.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="dashboard-panel mb-3 daily-print-section" aria-labelledby="daily-print-title">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print">
          <div>
            <div class="section-title">Daily Booking List</div>
            <h4 id="daily-print-title" class="mb-0">Print Today’s Reservations</h4>
          </div>
          <button type="button" class="btn btn-dark" onclick="window.print()">Print Daily List</button>
        </div>

        <div class="print-heading">
          <h3><?= h($focusOutlet['branch_name'] ?? 'Selected Branch') ?> Daily Booking List</h3>
          <p><?= h(format_date_short($today)) ?> | <?= h($focusOutlet['state_name'] ?? '') ?></p>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Time</th>
                <th>ID</th>
                <th>Customer</th>
                <th>Phone</th>
                <th>Pax</th>
                <th>Type</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dailyPrintBookings as $dailyBooking): ?>
                <tr>
                  <td><?= h(format_time12($dailyBooking['booking_time'])) ?></td>
                  <td><?= h($dailyBooking['booking_code']) ?></td>
                  <td><?= h($dailyBooking['customer_name']) ?></td>
                  <td><?= h($dailyBooking['phone']) ?></td>
                  <td><?= h($dailyBooking['pax']) ?></td>
                  <td><?= h($dailyBooking['request_type']) ?></td>
                  <td><?= h($dailyBooking['status']) ?></td>
                </tr>
              <?php endforeach; ?>

              <?php if (!$dailyPrintBookings): ?>
                <tr>
                  <td colspan="7" class="text-center text-muted">No bookings for today.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <div id="outlet-overview" class="dashboard-group-heading no-print">
      <div class="section-title">Outlet Overview</div>
      <h3><?= $isAdmin ? 'All Outlets' : 'Your Assigned Outlets' ?></h3>
    </div>

    <section class="dashboard-panel mb-3 no-print" aria-labelledby="operational-alerts-title">
      <div class="dashboard-section-heading">
        <div>
          <div class="section-title">Operational Alerts</div>
          <h4 id="operational-alerts-title" class="mb-0">Needs Attention</h4>
        </div>
      </div>

      <div class="table-responsive dashboard-alert-table-wrap">
        <table class="table table-hover align-middle mb-0 dashboard-alert-table">
          <thead>
            <tr>
              <th>Alert Type</th>
              <th class="text-center">Count</th>
              <th>Status</th>
              <th>Details</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($adminAlerts as $alert): ?>
              <tr class="dashboard-alert-row dashboard-alert-row-<?= h($alert['level']) ?>">
                <td>
                  <strong><?= h($alert['label']) ?></strong>
                </td>
                <td class="text-center">
                  <span class="dashboard-alert-count"><?= h($alert['value']) ?></span>
                </td>
                <td>
                  <span class="dashboard-alert-status"><?= h($alert['status']) ?></span>
                </td>
                <td>
                  <span class="muted-small"><?= h($alert['detail']) ?></span>
                </td>
                <td><a class="btn btn-sm btn-outline-dark text-nowrap" href="<?= h($alert['url']) ?>"><?= h($alert['action']) ?></a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="dashboard-panel mb-3 no-print" aria-labelledby="branch-performance-title">
      <div class="dashboard-section-heading">
        <div>
          <div class="section-title">Branch Performance Summary</div>
          <h4 id="branch-performance-title" class="mb-0">Last 30 Days</h4>
        </div>
        <a class="btn btn-sm btn-outline-dark" href="export_dashboard.php?dataset=performance&format=csv">Export CSV</a>
      </div>

      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Outlet</th>
              <th>Total</th>
              <th>Completed</th>
              <th>Cancelled</th>
              <th>Declined</th>
              <th>Preorders</th>
              <th>Avg Rating</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($branchPerformanceRows as $row): ?>
              <tr>
                <td>
                  <strong><?= h($row['branch_name']) ?></strong><br>
                  <span class="muted-small"><?= h($row['state_name']) ?></span>
                </td>
                <td><?= h($row['total_bookings']) ?></td>
                <td><?= h($row['completed_bookings']) ?></td>
                <td><?= h($row['cancelled_bookings']) ?></td>
                <td><?= h($row['declined_bookings']) ?></td>
                <td><?= h($row['preorder_bookings']) ?></td>
                <td><?= $row['average_rating'] !== null ? h($row['average_rating']) . ' / 5' : '-' ?></td>
              </tr>
            <?php endforeach; ?>

            <?php if (!$branchPerformanceRows): ?>
              <tr>
                <td colspan="7" class="text-center text-muted">No branch performance data available.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <div class="row g-3 mb-3 no-print">
      <div class="col-xl-6">
        <section class="dashboard-panel h-100" aria-labelledby="review-attention-title">
          <div class="dashboard-section-heading">
            <div>
              <div class="section-title">Review Attention</div>
              <h4 id="review-attention-title" class="mb-0">Low Ratings</h4>
            </div>
          </div>

          <div class="dashboard-mini-list">
            <?php foreach ($reviewAttentionRows as $review): ?>
              <a href="view_reviews.php?rating=<?= h($review['rating']) ?>&outlet_id=<?= h($review['outlet_id'] ?? 0) ?>" class="dashboard-mini-item">
                <strong><?= h($review['rating']) ?>/5 - <?= h($review['full_name']) ?></strong>
                <span><?= h($review['branch_name'] ?? 'Unassigned') ?> · <?= h($review['booking_code']) ?></span>
              </a>
            <?php endforeach; ?>

            <?php if (!$reviewAttentionRows): ?>
              <div class="alert alert-info mb-0">No low-rated reviews waiting for reply.</div>
            <?php endif; ?>
          </div>
        </section>
      </div>

      <div class="col-xl-6">
        <section class="dashboard-panel h-100" aria-labelledby="customer-history-title">
          <div class="dashboard-section-heading">
            <div>
              <div class="section-title">Customer History</div>
              <h4 id="customer-history-title" class="mb-0">Repeat Cancellations</h4>
            </div>
          </div>

          <div class="dashboard-mini-list">
            <?php foreach ($customerIssueRows as $customer): ?>
              <div class="dashboard-mini-item">
                <strong><?= h($customer['customer_name']) ?></strong>
                <span><?= h($customer['issue_count']) ?> issue(s) · Last <?= h(format_date_short($customer['last_issue_date'])) ?></span>
                <?php if (!empty($customer['phone'])): ?>
                  <a href="tel:<?= h(phone_href($customer['phone'])) ?>">Call customer</a>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>

            <?php if (!$customerIssueRows): ?>
              <div class="alert alert-info mb-0">No repeat cancellation patterns found.</div>
            <?php endif; ?>
          </div>
        </section>
      </div>

    </div>

    <section id="management-tools" class="dashboard-panel mb-3 no-print" aria-labelledby="management-tools-title">
      <div class="dashboard-section-heading">
        <div>
          <div class="section-title">Management Tools</div>
          <h4 id="management-tools-title" class="mb-0">Quick Access</h4>
        </div>
      </div>

      <div class="dashboard-tool-grid">
        <a class="dashboard-tool-link" href="reports.php">
          <span>📊</span>
          <strong>Reports</strong>
          <small>Review booking performance</small>
        </a>

        <a class="dashboard-tool-link" href="view_reviews.php">
          <span>⭐</span>
          <strong>Reviews</strong>
          <small>Filter customer feedback by outlet</small>
        </a>

        <a class="dashboard-tool-link" href="booking_calendar.php">
          <span>CAL</span>
          <strong>Booking Calendar</strong>
          <small>View monthly booking volume</small>
        </a>

        <a class="dashboard-tool-link" href="activity_log.php">
          <span>LOG</span>
          <strong>Activity Log</strong>
          <small>Review staff actions by outlet</small>
        </a>

        <a class="dashboard-tool-link" href="outlet_settings.php">
          <span>SET</span>
          <strong>Outlets</strong>
          <small><?= $isAdmin ? 'Edit branch details and tables' : 'Add outlets for your state' ?></small>
        </a>

        <a class="dashboard-tool-link" href="export_dashboard.php?dataset=performance&format=print" target="_blank" rel="noopener">
          <span>PDF</span>
          <strong>Print Report</strong>
          <small>Open print-ready report</small>
        </a>

        <a class="dashboard-tool-link" href="export_dashboard.php?dataset=reviews&format=csv">
          <span>CSV</span>
          <strong>Export Reviews</strong>
          <small>Download customer feedback</small>
        </a>

        <?php if ($isAdmin): ?>
          <a class="dashboard-tool-link" href="shop_owners.php">
            <span>👤</span>
            <strong>Shop Owners</strong>
            <small>Edit owner names and assigned outlets</small>
          </a>

          <a class="dashboard-tool-link" href="manage_faqs.php">
            <span>💬</span>
            <strong>Manage FAQs</strong>
            <small>Update chatbot support content</small>
          </a>

          <a class="dashboard-tool-link" href="add_faq.php">
            <span>＋</span>
            <strong>Add FAQ</strong>
            <small>Create a new FAQ answer</small>
          </a>
        <?php endif; ?>
      </div>
    </section>

    <section class="dashboard-panel mb-3" aria-labelledby="branch-contacts-title">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
          <div class="section-title">Branch Contacts</div>
          <h4 id="branch-contacts-title" class="mb-0">
            <?= $isAdmin ? 'All Outlet Contacts' : 'Your Assigned Outlets' ?>
          </h4>
        </div>
        <span class="badge bg-dark"><?= h(count($outletContacts)) ?> outlet(s)</span>
      </div>

      <div class="branch-contact-grid">
        <?php foreach ($outletContacts as $outlet): ?>
          <div class="branch-contact-card">
            <strong><?= h($outlet['branch_name']) ?></strong>
            <span><?= h($outlet['state_name']) ?> - <?= h($outlet['location_detail']) ?></span>
            <?php if (!empty($outlet['contact_phone'])): ?>
              <a href="tel:<?= h(phone_href($outlet['contact_phone'])) ?>">
                <?= h($outlet['contact_phone']) ?>
              </a>
            <?php endif; ?>
            <?php if (!empty($outlet['map_url'])): ?>
              <a href="<?= h($outlet['map_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-dark mt-2">
                Open Map
              </a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

  </main>
</body>

</html>
