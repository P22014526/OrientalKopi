<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();
prevent_dynamic_page_cache();

$userId = (int)$_SESSION['user_id'];
$filterSearch = trim((string)($_GET['search'] ?? ''));
$filterType = (string)($_GET['type'] ?? 'all');
$filterRead = (string)($_GET['read_status'] ?? 'all');
$filterDateFrom = trim((string)($_GET['date_from'] ?? ''));
$filterDateTo = trim((string)($_GET['date_to'] ?? ''));
$filterError = '';

if (mb_strlen($filterSearch) > 100) {
    $filterSearch = mb_substr($filterSearch, 0, 100);
}

if (!in_array($filterType, ['all', 'reminders', 'updates', 'history'], true)) {
    $filterType = 'all';
}

if (!in_array($filterRead, ['all', 'unread', 'read'], true)) {
    $filterRead = 'all';
}

if ($filterType === 'reminders') {
    $filterRead = 'all';
}

$isValidDate = static function (string $date): bool {
    if ($date === '') {
        return true;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
};

if (!$isValidDate($filterDateFrom) || !$isValidDate($filterDateTo)) {
    $filterError = 'Please enter a valid date range.';
    $filterDateFrom = '';
    $filterDateTo = '';
} elseif ($filterDateFrom !== '' && $filterDateTo !== '' && $filterDateFrom > $filterDateTo) {
    $filterError = 'The start date cannot be after the end date.';
}

$hasActiveFilters = $filterSearch !== ''
    || $filterType !== 'all'
    || $filterRead !== 'all'
    || $filterDateFrom !== ''
    || $filterDateTo !== '';

$showReminders = in_array($filterType, ['all', 'reminders'], true) && $filterRead === 'all';
$showBookingUpdates = in_array($filterType, ['all', 'updates'], true);
$showBookingHistory = in_array($filterType, ['all', 'history'], true);
$reminders = [];
$notifications = [];

/* Reminders: upcoming confirmed and pending bookings */
if ($showReminders && $filterError === '') {
    $reminderWhere = [
        'user_id = ?',
        "status IN ('Confirmed', 'Pending')",
        'booking_date >= CURDATE()'
    ];
    $reminderParams = [$userId];

    if ($filterSearch !== '') {
        $reminderWhere[] = '(booking_code LIKE ? OR customer_name LIKE ?)';
        $searchPattern = '%' . $filterSearch . '%';
        array_push($reminderParams, $searchPattern, $searchPattern);
    }

    if ($filterDateFrom !== '') {
        $reminderWhere[] = 'booking_date >= ?';
        $reminderParams[] = $filterDateFrom;
    }

    if ($filterDateTo !== '') {
        $reminderWhere[] = 'booking_date <= ?';
        $reminderParams[] = $filterDateTo;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM bookings
        WHERE " . implode(' AND ', $reminderWhere) . "
        ORDER BY booking_date ASC, booking_time ASC
    ");
    $stmt->execute($reminderParams);
    $reminders = $stmt->fetchAll();
}

/* Booking Updates and Booking History only */
$notificationWhere = ['n.user_id = ?'];
$notificationParams = [$userId];
$notificationCategories = [];

if ($showBookingUpdates) {
    $notificationCategories[] = 'Booking Updates';
}

if ($showBookingHistory) {
    $notificationCategories[] = 'Booking History';
}

if ($notificationCategories && $filterError === '') {
    $notificationWhere[] = 'n.category IN (' . implode(',', array_fill(0, count($notificationCategories), '?')) . ')';
    $notificationParams = array_merge($notificationParams, $notificationCategories);

    if ($filterSearch !== '') {
        $notificationWhere[] = '(n.title LIKE ? OR n.message LIKE ? OR b.booking_code LIKE ?)';
        $searchPattern = '%' . $filterSearch . '%';
        array_push($notificationParams, $searchPattern, $searchPattern, $searchPattern);
    }

    if ($filterRead === 'unread') {
        $notificationWhere[] = 'n.is_read = 0';
    } elseif ($filterRead === 'read') {
        $notificationWhere[] = 'n.is_read = 1';
    }

    if ($filterDateFrom !== '') {
        $notificationWhere[] = 'DATE(n.created_at) >= ?';
        $notificationParams[] = $filterDateFrom;
    }

    if ($filterDateTo !== '') {
        $notificationWhere[] = 'DATE(n.created_at) <= ?';
        $notificationParams[] = $filterDateTo;
    }

    $stmt = $pdo->prepare("
        SELECT n.*, b.booking_code
        FROM notifications n
        LEFT JOIN bookings b ON b.booking_id = n.booking_id
        WHERE " . implode(' AND ', $notificationWhere) . "
        ORDER BY n.created_at DESC
    ");
    $stmt->execute($notificationParams);
    $notifications = $stmt->fetchAll();
}

$bookingUpdates = [];
$bookingHistory = [];

foreach ($notifications as $notification) {
    if ($notification['category'] === 'Booking History') {
        $bookingHistory[] = $notification;
    } else {
        $bookingUpdates[] = $notification;
    }
}

/* Mark only the notifications included in this result set as read. */
$displayedNotificationIds = array_values(array_filter(array_map(
    'intval',
    array_column($notifications, 'notification_id')
)));

if ($displayedNotificationIds) {
    $markReadSql = 'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND notification_id IN ('
        . implode(',', array_fill(0, count($displayedNotificationIds), '?'))
        . ')';
    $pdo->prepare($markReadSql)->execute(array_merge([$userId], $displayedNotificationIds));
}

$totalResults = count($reminders) + count($bookingUpdates) + count($bookingHistory);

include 'includes/header.php';
?>

<a href="view_profile.php" class="btn-nav-icon mb-3">
    <span class="btn-nav-icon-symbol">←</span>
    <span>Profile</span>
</a>

<div class="card ok-card p-4">
    <div class="section-title">Customer Area</div>
    <h3>Notifications</h3>
    <p class="muted-small">
        View reminders, booking status updates, and completed booking history.
    </p>

    <?php if ($filterError): ?>
        <div class="alert alert-danger"><?= h($filterError) ?></div>
    <?php endif; ?>

    <div class="my-bookings-filter-panel mb-4 no-print">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <div class="section-title">Notification Filters</div>
                <h4 class="mb-0">Find a Notification</h4>
            </div>

            <?php if ($hasActiveFilters): ?>
                <span class="badge bg-warning text-dark">Filtered Results</span>
            <?php endif; ?>
        </div>

        <form method="get" action="notifications.php#notification-results" class="row g-3">
            <div class="col-lg-4 col-md-6">
                <label class="form-label" for="notificationSearch">Search</label>
                <input
                    type="search"
                    id="notificationSearch"
                    name="search"
                    class="form-control"
                    value="<?= h($filterSearch) ?>"
                    placeholder="Title, message, booking ID, or name">
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label" for="notificationType">Type</label>
                <select id="notificationType" name="type" class="form-select">
                    <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All Types</option>
                    <option value="reminders" <?= $filterType === 'reminders' ? 'selected' : '' ?>>Reminders</option>
                    <option value="updates" <?= $filterType === 'updates' ? 'selected' : '' ?>>Booking Updates</option>
                    <option value="history" <?= $filterType === 'history' ? 'selected' : '' ?>>Booking History</option>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label" for="notificationReadStatus">Read Status</label>
                <select id="notificationReadStatus" name="read_status" class="form-select">
                    <option value="all" <?= $filterRead === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="unread" <?= $filterRead === 'unread' ? 'selected' : '' ?>>Unread</option>
                    <option value="read" <?= $filterRead === 'read' ? 'selected' : '' ?>>Read</option>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label" for="notificationDateFrom">From</label>
                <input
                    type="date"
                    id="notificationDateFrom"
                    name="date_from"
                    class="form-control"
                    value="<?= h($filterDateFrom) ?>">
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label" for="notificationDateTo">To</label>
                <input
                    type="date"
                    id="notificationDateTo"
                    name="date_to"
                    class="form-control"
                    value="<?= h($filterDateTo) ?>">
            </div>

            <div class="col-12 d-flex gap-2 flex-wrap">
                <button class="btn btn-dark" type="submit">Apply Filter</button>
                <a href="notifications.php#notification-results" class="btn btn-outline-dark">Reset</a>
            </div>
        </form>
    </div>

    <div id="notification-results" class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <div>
            <div class="section-title">Results</div>
            <strong><?= h($totalResults) ?> matching item<?= $totalResults === 1 ? '' : 's' ?></strong>
        </div>
    </div>

    <!-- REMINDERS -->
    <?php if ($showReminders): ?>
    <section class="notification-section mb-4">
        <div class="notification-section-heading">
            <div class="d-flex justify-content-between align-items-center gap-2">
                <h4 class="mb-1">Reminders</h4>
                <span class="badge bg-dark"><?= h(count($reminders)) ?></span>
            </div>
            <p class="muted-small mb-0">
                Upcoming confirmed and pending reservations.
            </p>
        </div>

        <div class="mt-3">
            <?php if (!$reminders): ?>
                <div class="alert alert-info mb-0">
                    No upcoming reminders.
                </div>
            <?php endif; ?>

            <?php foreach ($reminders as $r): ?>
                <div class="notification-card reminder-notification mb-3">
                    <div class="d-flex justify-content-between flex-wrap gap-2">
                        <strong><?= h($r['booking_code']) ?></strong>
                        <small class="text-muted">
                            <?= h(date('d/m/Y', strtotime($r['booking_date']))) ?>
                            |
                            <?= h(format_time12($r['booking_time'])) ?>
                        </small>
                    </div>

                    <p class="mb-1 mt-2">
                        Pax: <strong><?= h($r['pax']) ?></strong>
                    </p>

                    <p class="mb-1">
                        Status:
                        <span class="badge bg-<?= h(status_badge_class($r['status'])) ?>">
                            <?= h($r['status']) ?>
                        </span>
                    </p>

                    <p class="mb-0 muted-small">
                        Please review your reservation details and arrive on time.
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- BOOKING UPDATES -->
    <?php if ($showBookingUpdates): ?>
    <section class="notification-section mb-4">
        <div class="notification-section-heading">
            <div class="d-flex justify-content-between align-items-center gap-2">
                <h4 class="mb-1">Booking Updates</h4>
                <span class="badge bg-dark"><?= h(count($bookingUpdates)) ?></span>
            </div>
            <p class="muted-small mb-0">
                Pending requests and administrator approval, decline, or cancellation updates.
            </p>
        </div>

        <div class="mt-3">
            <?php if (!$bookingUpdates): ?>
                <div class="alert alert-info mb-0">
                    No booking updates.
                </div>
            <?php endif; ?>

            <?php foreach ($bookingUpdates as $n): ?>
                <div class="notification-card booking-update-notification mb-3">
                    <div class="d-flex justify-content-between flex-wrap gap-2">
                        <div class="d-flex align-items-center flex-wrap gap-2">
                            <strong><?= h($n['title']) ?></strong>
                            <?php if (!(int)$n['is_read']): ?>
                                <span class="badge bg-primary">New</span>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted">
                            <?= h(date('d/m/Y h:i A', strtotime($n['created_at']))) ?>
                        </small>
                    </div>

                    <p class="mb-0 mt-2">
                        <?= h($n['message']) ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- BOOKING HISTORY -->
    <?php if ($showBookingHistory): ?>
    <section class="notification-section">
        <div class="notification-section-heading">
            <div class="d-flex justify-content-between align-items-center gap-2">
                <h4 class="mb-1">Booking History</h4>
                <span class="badge bg-dark"><?= h(count($bookingHistory)) ?></span>
            </div>
            <p class="muted-small mb-0">
                Completed reservations are archived here.
            </p>
        </div>

        <div class="mt-3">
            <?php if (!$bookingHistory): ?>
                <div class="alert alert-info mb-0">
                    No completed booking history.
                </div>
            <?php endif; ?>

            <?php foreach ($bookingHistory as $n): ?>
                <div class="notification-card booking-history-notification mb-3">
                    <div class="d-flex justify-content-between flex-wrap gap-2">
                        <div class="d-flex align-items-center flex-wrap gap-2">
                            <strong><?= h($n['title']) ?></strong>
                            <?php if (!(int)$n['is_read']): ?>
                                <span class="badge bg-primary">New</span>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted">
                            <?= h(date('d/m/Y h:i A', strtotime($n['created_at']))) ?>
                        </small>
                    </div>

                    <p class="mb-0 mt-2">
                        <?= h($n['message']) ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>

<script>
    const notificationType = document.getElementById('notificationType');
    const notificationReadStatus = document.getElementById('notificationReadStatus');

    function syncNotificationReadFilter() {
        if (!notificationType || !notificationReadStatus) {
            return;
        }

        const remindersOnly = notificationType.value === 'reminders';
        notificationReadStatus.disabled = remindersOnly;

        if (remindersOnly) {
            notificationReadStatus.value = 'all';
        }
    }

    syncNotificationReadFilter();
    notificationType?.addEventListener('change', syncNotificationReadFilter);
</script>

<?php include 'includes/footer.php'; ?>
