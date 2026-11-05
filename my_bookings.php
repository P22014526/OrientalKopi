<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();
prevent_dynamic_page_cache();

$columnCheck = $pdo->query("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'reviews'
      AND COLUMN_NAME = 'booking_id'
");

$hasReviewBookingId = (bool)$columnCheck->fetchColumn();

$filterSearch = trim($_GET['search'] ?? '');
$filterDate = trim($_GET['booking_date'] ?? '');
$filterTime = trim($_GET['booking_time'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterType = trim($_GET['request_type'] ?? '');
$filterOutletId = (int)($_GET['outlet_id'] ?? 0);
$cancellationCutoffMinutes = cancellation_cutoff_minutes($pdo);
$cancellationCutoffLabel = cancellation_cutoff_label($cancellationCutoffMinutes);
$validStatuses = ['Needs Review', 'Confirmed', 'Pending', 'Completed', 'Cancelled', 'Declined'];
$validRequestTypes = ['Normal', 'Reschedule', 'Party'];

if ($filterDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDate = '';
}

if ($filterTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $filterTime)) {
    $filterTime = '';
}

if ($filterStatus !== '' && !in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = '';
}

if ($filterType !== '' && !in_array($filterType, $validRequestTypes, true)) {
    $filterType = '';
}

$outletStmt = $pdo->prepare("
    SELECT DISTINCT o.outlet_id, o.branch_name, s.state_name
    FROM bookings b
    LEFT JOIN outlets o ON o.outlet_id = b.outlet_id
    LEFT JOIN outlet_states s ON s.state_id = o.state_id
    WHERE b.user_id = ?
      AND o.outlet_id IS NOT NULL
    ORDER BY s.display_order, o.branch_name
");
$outletStmt->execute([$_SESSION['user_id']]);
$bookingOutlets = $outletStmt->fetchAll();
$filterableOutletIds = array_map('intval', array_column($bookingOutlets, 'outlet_id'));

if ($filterOutletId > 0 && !in_array($filterOutletId, $filterableOutletIds, true)) {
    $filterOutletId = 0;
}

$where = ['b.user_id = ?'];
$params = [$_SESSION['user_id']];

if ($filterSearch !== '') {
    $where[] = "(b.booking_code LIKE ? OR o.branch_name LIKE ? OR s.state_name LIKE ?)";
    $searchLike = '%' . $filterSearch . '%';
    $params = array_merge($params, [$searchLike, $searchLike, $searchLike]);
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

if ($filterType !== '') {
    $where[] = "b.request_type = ?";
    $params[] = $filterType;
}

if ($filterOutletId > 0) {
    $where[] = "b.outlet_id = ?";
    $params[] = $filterOutletId;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);
$hasActiveFilters = $filterSearch !== ''
    || $filterDate !== ''
    || $filterTime !== ''
    || $filterStatus !== ''
    || $filterType !== ''
    || $filterOutletId > 0;

if ($hasReviewBookingId) {
    $stmt = $pdo->prepare("
        SELECT b.*,
               r.review_id,
               r.rating AS review_rating,
               o.branch_name,
               s.state_name
        FROM bookings b
        LEFT JOIN outlets o ON o.outlet_id = b.outlet_id
        LEFT JOIN outlet_states s ON s.state_id = o.state_id
        LEFT JOIN reviews r
          ON r.booking_id = b.booking_id
         AND r.user_id = b.user_id
        $whereSql
        ORDER BY b.booking_date DESC, b.booking_time DESC
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT b.*,
               NULL AS review_id,
               NULL AS review_rating,
               o.branch_name,
               s.state_name
        FROM bookings b
        LEFT JOIN outlets o ON o.outlet_id = b.outlet_id
        LEFT JOIN outlet_states s ON s.state_id = o.state_id
        $whereSql
        ORDER BY b.booking_date DESC, b.booking_time DESC
    ");
}

$stmt->execute($params);
$bookings = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="card ok-card p-4">
    <div class="section-title">Customer Area</div>
    <h3>My Bookings</h3>

    <p class="muted-small">
        Manage active reservations and write a review after a booking has been completed.
        Use the pencil button to view the available actions for each booking.
    </p>

    <?php if (!$hasReviewBookingId): ?>
        <div class="alert alert-warning">
            Run <strong>review_booking_update.sql</strong> once in phpMyAdmin to enable completed-booking reviews.
        </div>
    <?php endif; ?>

    <div class="my-bookings-filter-panel mb-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <div class="section-title">Booking Filters</div>
                <h4 class="mb-0">Find a Reservation</h4>
            </div>

            <?php if ($hasActiveFilters): ?>
                <span class="badge bg-warning text-dark">Filtered Results</span>
            <?php endif; ?>
        </div>

        <form method="get" class="row g-3">
            <div class="col-lg-4 col-md-6">
                <label class="form-label">Search</label>
                <input
                    type="search"
                    name="search"
                    class="form-control"
                    value="<?= h($filterSearch) ?>"
                    placeholder="Booking ID, outlet, or state">
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label">Date</label>
                <input
                    type="date"
                    name="booking_date"
                    class="form-control"
                    value="<?= h($filterDate) ?>">
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label">Time</label>
                <input
                    type="time"
                    name="booking_time"
                    class="form-control"
                    value="<?= h($filterTime) ?>">
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <?php foreach ($validStatuses as $status): ?>
                        <option value="<?= h($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>>
                            <?= h($status === 'Needs Review' ? 'Awaiting Update' : $status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label">Type</label>
                <select name="request_type" class="form-select">
                    <option value="">All Types</option>
                    <?php foreach ($validRequestTypes as $requestType): ?>
                        <option value="<?= h($requestType) ?>" <?= $filterType === $requestType ? 'selected' : '' ?>>
                            <?= h($requestType) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-8 col-md-6">
                <label class="form-label">Outlet</label>
                <select name="outlet_id" class="form-select">
                    <option value="0">All Outlets</option>
                    <?php foreach ($bookingOutlets as $outlet): ?>
                        <option value="<?= h($outlet['outlet_id']) ?>" <?= $filterOutletId === (int)$outlet['outlet_id'] ? 'selected' : '' ?>>
                            <?= h($outlet['state_name']) ?> - <?= h($outlet['branch_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-4 d-flex align-items-end gap-2 flex-wrap">
                <button class="btn btn-dark">Apply Filter</button>
                <a href="my_bookings.php" class="btn btn-outline-dark">Reset</a>
            </div>
        </form>
    </div>

    <div class="table-responsive my-bookings-table-wrap">
        <table class="table table-hover align-middle my-bookings-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Outlet</th>
                    <th>Pax</th>
                    <th>Type</th>
                    <th>Reason</th>
                    <th class="text-center my-bookings-actions-col">Actions</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($bookings as $b): ?>
                    <?php
                    $bookingTimestamp = strtotime(
                        $b['booking_date'] . ' ' . $b['booking_time']
                    );

                    $rescheduleDeadline = $bookingTimestamp
                        ? $bookingTimestamp - 86400
                        : 0;

                    $canReschedule =
                        $b['status'] === 'Confirmed'
                        && $bookingTimestamp !== false
                        && time() <= $rescheduleDeadline;

                    $canCancelBooking = customer_can_cancel_booking($b, $cancellationCutoffMinutes);

                    $hasAvailableAction =
                        $b['status'] === 'Confirmed'
                        || $b['status'] === 'Pending'
                        || $b['status'] === 'Completed';
                    ?>

                    <tr>
                        <td data-label="Booking"><?= h($b['booking_code']) ?></td>
                        <td data-label="Date"><?= h(date('d/m/Y', strtotime($b['booking_date']))) ?></td>
                        <td data-label="Time"><?= h(format_time12($b['booking_time'])) ?></td>
                        <td data-label="Outlet">
                            <strong><?= h($b['branch_name'] ?? 'Unassigned') ?></strong><br>
                            <span class="muted-small"><?= h($b['state_name'] ?? '-') ?></span>
                        </td>
                        <td data-label="Pax"><?= h($b['pax']) ?></td>
                        <td data-label="Type"><?= h($b['request_type']) ?></td>

                        <td data-label="Reason">
                            <?php if (
                                in_array($b['status'], ['Declined', 'Cancelled'], true)
                                && !empty($b['admin_reason'])
                            ): ?>
                                <?= h($b['admin_reason']) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>

                        <td data-label="Actions" class="text-center my-bookings-actions-cell">
                            <?php if ($hasAvailableAction): ?>
                                <div class="dropdown dropup">
                                    <button
                                        class="btn btn-sm btn-action-icon dropdown-toggle"
                                        type="button"
                                        data-bs-toggle="dropdown"
                                        data-bs-boundary="viewport"
                                        aria-expanded="false"
                                        title="Manage booking"
                                        aria-label="Manage booking <?= h($b['booking_code']) ?>">
                                        ✏️
                                    </button>

                                    <ul class="dropdown-menu dropdown-menu-end booking-actions-dropdown">
                                        <?php if ($b['status'] === 'Confirmed'): ?>
                                            <?php if ($canReschedule): ?>
                                                <li>
                                                    <a
                                                        class="dropdown-item"
                                                        href="reschedule.php?id=<?= h($b['booking_id']) ?>">
                                                        🔁 Reschedule Booking
                                                    </a>
                                                </li>
                                            <?php else: ?>
                                                <li>
                                                    <span
                                                        class="dropdown-item disabled"
                                                        title="Rescheduling must be requested at least 24 hours before the booking.">
                                                        🔁 Reschedule Unavailable
                                                    </span>
                                                </li>
                                            <?php endif; ?>

                                            <?php if ($canCancelBooking): ?>
                                                <li>
                                                    <a
                                                        class="dropdown-item text-danger"
                                                        href="cancel_booking.php?id=<?= h($b['booking_id']) ?>">
                                                        ❌ Cancel Booking
                                                    </a>
                                                </li>
                                            <?php else: ?>
                                                <li>
                                                    <span
                                                        class="dropdown-item disabled"
                                                        title="Online cancellation closes <?= h($cancellationCutoffLabel) ?>.">
                                                        ❌ Cancel Unavailable
                                                    </span>
                                                </li>
                                            <?php endif; ?>

                                            <li>
                                                <a
                                                    class="dropdown-item"
                                                    href="preorder_menu.php?id=<?= h($b['booking_id']) ?>">
                                                    🍽️ Menu / Preorder
                                                </a>
                                            </li>

                                        <?php elseif (
                                            $b['status'] === 'Pending'
                                            && $b['request_type'] === 'Party'
                                        ): ?>
                                            <li>
                                                <a
                                                    class="dropdown-item"
                                                    href="edit_party_request.php?id=<?= h($b['booking_id']) ?>">
                                                    🎉 View / Edit Party Request
                                                </a>
                                            </li>

                                            <?php if ($canCancelBooking): ?>
                                                <li>
                                                    <a
                                                        class="dropdown-item text-danger"
                                                        href="cancel_booking.php?id=<?= h($b['booking_id']) ?>">
                                                        ❌ Cancel Pending Request
                                                    </a>
                                                </li>
                                            <?php else: ?>
                                                <li>
                                                    <span
                                                        class="dropdown-item disabled"
                                                        title="Online cancellation closes <?= h($cancellationCutoffLabel) ?>.">
                                                        ❌ Cancel Unavailable
                                                    </span>
                                                </li>
                                            <?php endif; ?>

                                        <?php elseif ($b['status'] === 'Pending'): ?>
                                            <?php if ($canCancelBooking): ?>
                                                <li>
                                                    <a
                                                        class="dropdown-item text-danger"
                                                        href="cancel_booking.php?id=<?= h($b['booking_id']) ?>">
                                                        ❌ Cancel Pending Request
                                                    </a>
                                                </li>
                                            <?php else: ?>
                                                <li>
                                                    <span
                                                        class="dropdown-item disabled"
                                                        title="Online cancellation closes <?= h($cancellationCutoffLabel) ?>.">
                                                        ❌ Cancel Unavailable
                                                    </span>
                                                </li>
                                            <?php endif; ?>

                                        <?php elseif ($b['status'] === 'Completed'): ?>
                                            <?php if (!$hasReviewBookingId): ?>
                                                <li>
                                                    <span class="dropdown-item disabled">
                                                        ⚠️ Review Setup Required
                                                    </span>
                                                </li>

                                            <?php elseif (!empty($b['review_id'])): ?>
                                                <li>
                                                    <span class="dropdown-item disabled">
                                                        ✅ Reviewed <?= str_repeat('⭐', (int)$b['review_rating']) ?>
                                                    </span>
                                                </li>

                                            <?php else: ?>
                                                <li>
                                                    <a
                                                        class="dropdown-item"
                                                        href="write_review.php?id=<?= h($b['booking_id']) ?>">
                                                        ⭐ Write Review
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            <?php else: ?>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-action-icon disabled"
                                    disabled
                                    title="No available actions">
                                    ✏️
                                </button>
                            <?php endif; ?>
                        </td>

                        <td data-label="Status">
                            <span class="badge <?= booking_needs_review($b) ? 'bg-info text-dark' : 'bg-' . h(status_badge_class($b['status'])) ?>">
                                <?= h(booking_needs_review($b) ? 'Awaiting Update' : $b['status']) ?>
                            </span>
                            <?php if (booking_needs_review($b)): ?><div class="small text-muted mt-1">Your booking date has passed. The restaurant will update its final status.</div><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$bookings): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted">
                            No bookings found.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
