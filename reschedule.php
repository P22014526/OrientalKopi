<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();

$id = (int)($_GET['id'] ?? 0);
$error = '';

$today = date('Y-m-d');
$openTime = '09:30';
$closeTime = '22:30';

$allSlots = array_values(array_filter(
    common_slots(),
    function ($slot) use ($openTime, $closeTime) {
        return $slot >= $openTime && $slot <= $closeTime;
    }
));
$filterMode = $_POST['filter_mode'] ?? ($_GET['filter_mode'] ?? 'all');
$filterTime = $_POST['filter_time'] ?? ($_GET['filter_time'] ?? '');
$timeError = '';

if (!in_array($filterMode, ['all', 'specific', 'breakfast', 'lunch', 'dinner'], true)) {
    $filterMode = 'all';
}

if (!preg_match('/^\d{2}:\d{2}$/', $filterTime)) {
    $filterTime = '';
} elseif ($filterTime < $openTime || $filterTime > $closeTime) {
    $timeError = 'Please enter a time between 9:30 AM and 10:30 PM.';
    $filterTime = '';
}

function reschedule_slot_period($slot)
{
    $hour = (int)date('H', strtotime($slot));

    if ($hour >= 9 && $hour < 12) {
        return 'breakfast';
    }

    if ($hour >= 12 && $hour < 15) {
        return 'lunch';
    }

    if ($hour >= 17 && $hour <= 22) {
        return 'dinner';
    }

    return 'other';
}

$stmt = $pdo->prepare("
    SELECT *
    FROM bookings
    WHERE booking_id = ?
      AND user_id = ?
    LIMIT 1
");
$stmt->execute([$id, $_SESSION['user_id']]);
$b = $stmt->fetch();

if (!$b) {
    header('Location: my_bookings.php');
    exit;
}


if ($b['status'] !== 'Confirmed') {
    header('Location: my_bookings.php');
    exit;
}


$originalBookingTimestamp = strtotime(
    $b['booking_date'] . ' ' . $b['booking_time']
);

$rescheduleDeadline = $originalBookingTimestamp - 86400;

$rescheduleAllowed = time() <= $rescheduleDeadline;

$newDate = $_POST['new_date'] ?? ($_GET['new_date'] ?? $today);
$newTime = $_POST['new_time'] ?? '';

if ($newDate < $today) {
    $newDate = $today;
}

$categorizedSlots = [
    'breakfast' => [],
    'lunch' => [],
    'dinner' => [],
    'other' => []
];

foreach ($allSlots as $slot) {
    $categorizedSlots[reschedule_slot_period($slot)][] = $slot;
}

if ($filterMode === 'specific') {
    $slots = $filterTime !== '' ? [$filterTime] : [];
} elseif ($filterMode === 'breakfast') {
    $slots = $categorizedSlots['breakfast'];
} elseif ($filterMode === 'lunch') {
    $slots = $categorizedSlots['lunch'];
} elseif ($filterMode === 'dinner') {
    $slots = $categorizedSlots['dinner'];
} else {
    $slots = $allSlots;
}

$slotGroups = [];

foreach ($slots as $slot) {
    $period = reschedule_slot_period($slot);
    $slotGroups[$period][] = $slot;
}

$slotGroupLabels = [
    'breakfast' => 'Breakfast Hour',
    'lunch' => 'Lunch Hour',
    'dinner' => 'Dinner Hour',
    'other' => 'Other Time'
];

include 'includes/header.php';
?>

<a href="my_bookings.php" class="btn-nav-icon mb-3">
    <span class="btn-nav-icon-symbol">←</span>
    <span>My Bookings</span>
</a>

<div class="card ok-card p-4">
    <div class="section-title">Pending Admin Approval</div>
    <h3>Reschedule Request</h3>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="stat-pill h-100">
                <strong>Booking ID</strong><br>
                <?= h($b['booking_code']) ?>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-pill h-100">
                <strong>Current Date</strong><br>
                <?= h(format_date_short($b['booking_date'])) ?>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-pill h-100">
                <strong>Current Time Slot</strong><br>
                <?= h(format_time12($b['booking_time'])) ?>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-pill h-100">
                <strong>Pax</strong><br>
                <?= h($b['pax']) ?> Pax
            </div>
        </div>
    </div>

    <div class="alert alert-info">
        Rescheduling must be requested at least
        <strong>24 hours before</strong> the original booking.
        The restaurant operates from
        <strong>9:30 AM to 10:30 PM</strong>.
    </div>

    <?php if (!$rescheduleAllowed): ?>
        <div class="alert alert-danger">
            <strong>Reschedule unavailable.</strong><br>
            The 24-hour rescheduling deadline has passed.
            Your original booking is scheduled for
            <?= h(format_date_short($b['booking_date'])) ?>
            at
            <?= h(format_time12($b['booking_time'])) ?>.
        </div>

    <?php else: ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <form method="get" class="row g-3 mb-4">
            <input type="hidden" name="id" value="<?= h($id) ?>">

            <div class="col-md-3">
                <label
                    for="rescheduleDate"
                    class="form-label">
                    Select New Date
                </label>

                <input
                    type="date"
                    id="rescheduleDate"
                    name="new_date"
                    class="form-control"
                    value="<?= h($newDate) ?>"
                    min="<?= h($today) ?>"
                    required>
            </div>

            <div class="col-md-3">
                <label class="form-label" for="rescheduleFilterMode">
                    Filter
                </label>

                <select
                    class="form-select"
                    name="filter_mode"
                    id="rescheduleFilterMode">
                    <option value="all" <?= $filterMode === 'all' ? 'selected' : '' ?>>All Times</option>
                    <option value="breakfast" <?= $filterMode === 'breakfast' ? 'selected' : '' ?>>Breakfast Hour</option>
                    <option value="lunch" <?= $filterMode === 'lunch' ? 'selected' : '' ?>>Lunch Hour</option>
                    <option value="dinner" <?= $filterMode === 'dinner' ? 'selected' : '' ?>>Dinner Hour</option>
                    <option value="specific" <?= $filterMode === 'specific' ? 'selected' : '' ?>>Specific Time</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label" for="rescheduleFilterTime">
                    Time
                </label>

                <input
                    class="form-control"
                    type="time"
                    name="filter_time"
                    id="rescheduleFilterTime"
                    value="<?= h($filterTime) ?>"
                    min="<?= h($openTime) ?>"
                    max="<?= h($closeTime) ?>">
            </div>

            <div class="col-md-2 align-self-end">
                <button class="btn btn-dark w-100" type="submit">
                    Check Availability
                </button>
            </div>
        </form>

        <h5>Available Time Slots for <?= h(format_date_short($newDate)) ?></h5>

        <?php if ($timeError): ?>
            <div class="alert alert-danger">
                <?= h($timeError) ?>
            </div>
        <?php endif; ?>

        <?php if (!$slots): ?>
            <div class="alert alert-info">
                Please choose a specific time, then click Check Availability.
            </div>
        <?php endif; ?>

        <div class="availability-slot-groups">
            <?php foreach ($slotGroups as $group => $groupSlots): ?>
                <section class="availability-slot-group mb-4">
                    <div class="availability-slot-heading">
                        <h6 class="mb-0"><?= h($slotGroupLabels[$group] ?? 'Available Time') ?></h6>
                        <span class="muted-small"><?= h(count($groupSlots)) ?> slot(s)</span>
                    </div>

                    <div class="row g-2">
                        <?php foreach ($groupSlots as $slot): ?>
                            <?php
                            $u = slot_usage($pdo, $newDate, $slot);
                            $slotTimestamp = strtotime($newDate . ' ' . $slot);
                            $isPastSlot = $slotTimestamp !== false && $slotTimestamp <= time();
                            $isCurrentBooking =
                                $newDate === $b['booking_date']
                                && $slot === substr($b['booking_time'], 0, 5);
                            $isUnavailable =
                                $isPastSlot
                                || $u['status'] === 'Full'
                                || $isCurrentBooking;
                            ?>

                            <div class="col-md-4 col-lg-3">
                                <?php if ($isUnavailable): ?>
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary slot-btn w-100"
                                        disabled>
                                        <strong><?= h(format_time12($slot)) ?></strong><br>
                                        <?php if ($isCurrentBooking): ?>
                                            Current Booking<br>
                                        <?php elseif ($isPastSlot): ?>
                                            Time Passed<br>
                                        <?php else: ?>
                                            Full<br>
                                        <?php endif; ?>
                                        <span class="muted-small">
                                            <?= h($u['used']) ?>/<?= h($u['capacity']) ?> pax
                                        </span>
                                    </button>
                                <?php else: ?>
                                    <form
                                        method="get"
                                        action="reschedule_review.php"
                                        class="h-100">
                                        <input type="hidden" name="id" value="<?= h($id) ?>">
                                        <input type="hidden" name="new_date" value="<?= h($newDate) ?>">
                                        <input type="hidden" name="filter_mode" value="<?= h($filterMode) ?>">
                                        <input type="hidden" name="filter_time" value="<?= h($filterTime) ?>">
                                        <input type="hidden" name="new_time" value="<?= h($slot) ?>">
                                        <button
                                            class="border rounded p-3 h-100 bg-white text-center w-100 reschedule-slot-card"
                                            type="submit">
                                            <strong><?= h(format_time12($slot)) ?></strong><br>
                                            <?= $u['status'] === 'Available' ? 'Available' : 'Limited' ?><br>
                                            <span class="muted-small">
                                                <?= h($u['used']) ?>/<?= h($u['capacity']) ?> pax
                                            </span>
                                            <span class="btn btn-sm btn-outline-<?= h($u['color']) ?> mt-3 w-100">
                                                Request This Slot
                                            </span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>
</div>

<script>
    const rescheduleFilterMode = document.getElementById('rescheduleFilterMode');
    const rescheduleFilterTime = document.getElementById('rescheduleFilterTime');

    function syncRescheduleTimeFilter() {
        if (!rescheduleFilterMode || !rescheduleFilterTime) {
            return;
        }

        rescheduleFilterTime.disabled = rescheduleFilterMode.value !== 'specific';
        rescheduleFilterTime.required = rescheduleFilterMode.value === 'specific';
    }

    syncRescheduleTimeFilter();
    rescheduleFilterMode?.addEventListener('change', syncRescheduleTimeFilter);
</script>

<?php
include 'includes/chatbot_widget.php';
include 'includes/footer.php';
?>
