<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_login();
$today = date('Y-m-d');

$date = $_GET['date'] ?? $today;
$pax = max(1, (int)($_GET['pax'] ?? 4));
$filterMode = $_GET['filter_mode'] ?? 'all';
$filterTime = $_GET['filter_time'] ?? '';

if ($date < $today) {
  $date = $today;
}
$allSlots = common_slots();
$openTime = '09:30';
$closeTime = '22:30';
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

function availability_slot_period($slot)
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

$categorizedSlots = [
  'breakfast' => [],
  'lunch' => [],
  'dinner' => [],
  'other' => []
];

foreach ($allSlots as $slot) {
  $categorizedSlots[availability_slot_period($slot)][] = $slot;
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
  $period = availability_slot_period($slot);
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
<div class="card ok-card p-4">
  <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center mb-3">
    <div>
      <div class="section-title">Reservation planning</div>
      <h3 class="mb-0">View Availability</h3>
      <p class="muted-small mb-0">
        Select an available time slot to continue with your reservation.
      </p>
    </div>
  </div>
  <form class="row g-2 mb-4" method="get">
    <div class="col-md-3"><label class="form-label">Date</label><input
        class="form-control"
        type="date"
        name="date"
        value="<?= h($date) ?>"
        min="<?= h($today) ?>"
        required></div>
    <div class="col-md-2"><label class="form-label">Pax</label><input class="form-control" type="number" min="1" name="pax" value="<?= h($pax) ?>"></div>
    <div class="col-md-3">
      <label class="form-label">Filter</label>
      <select class="form-select" name="filter_mode" id="availabilityFilterMode">
        <option value="all" <?= $filterMode === 'all' ? 'selected' : '' ?>>All Times</option>
        <option value="breakfast" <?= $filterMode === 'breakfast' ? 'selected' : '' ?>>Breakfast Hour</option>
        <option value="lunch" <?= $filterMode === 'lunch' ? 'selected' : '' ?>>Lunch Hour</option>
        <option value="dinner" <?= $filterMode === 'dinner' ? 'selected' : '' ?>>Dinner Hour</option>
        <option value="specific" <?= $filterMode === 'specific' ? 'selected' : '' ?>>Specific Time</option>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Time</label>
      <input
        class="form-control"
        type="time"
        name="filter_time"
        id="availabilityFilterTime"
        value="<?= h($filterTime) ?>"
        min="<?= h($openTime) ?>"
        max="<?= h($closeTime) ?>">
    </div>
    <div class="col-md-2 align-self-end"><button class="btn btn-dark w-100">Check</button></div>
  </form>
  <h5>Available Time Slots</h5>
  <?php if ($timeError): ?>
    <div class="alert alert-danger">
      <?= h($timeError) ?>
    </div>
  <?php endif; ?>

  <?php if (!$slots): ?>
    <div class="alert alert-info">
      Please choose a specific time, then click Check to view availability.
    </div>
  <?php endif; ?>

  <div class="availability-slot-groups mb-4">
    <?php foreach ($slotGroups as $group => $groupSlots): ?>
      <section class="availability-slot-group mb-4">
        <div class="availability-slot-heading">
          <h6 class="mb-0"><?= h($slotGroupLabels[$group] ?? 'Available Time') ?></h6>
          <span class="muted-small"><?= h(count($groupSlots)) ?> slot(s)</span>
        </div>

        <div class="row g-2">
          <?php foreach ($groupSlots as $slot): ?>
            <?php
            $u = slot_usage($pdo, $date, $slot);

            $slotTimestamp = strtotime($date . ' ' . $slot);
            $isPastSlot = $slotTimestamp !== false && $slotTimestamp <= time();
            ?>

            <div class="col-md-4 col-lg-3">
              <?php if ($isPastSlot): ?>
                <button
                  type="button"
                  class="btn btn-outline-secondary slot-btn w-100"
                  disabled>
                  <strong><?= h(format_time12($slot)) ?></strong><br>
                  ⏰ Time Passed<br>
                  <span class="muted-small">
                    This time slot is no longer available
                  </span>
                </button>

              <?php elseif ($u['status'] === 'Full'): ?>
                <button class="btn btn-outline-secondary slot-btn w-100" disabled>
                  <strong><?= h(format_time12($slot)) ?></strong><br>
                  ❌ Full<br>
                  <span class="muted-small"><?= h($u['used']) ?>/<?= h($u['capacity']) ?> pax</span><br>
                  <small>Unavailable</small>
                </button>
              <?php else: ?>
                <div class="border rounded p-3 h-100 bg-white text-center">
                  <strong><?= h(format_time12($slot)) ?></strong><br>
                  <?= $u['status'] === 'Available' ? '✅' : '⚠' ?> <?= h($u['status']) ?><br>
                  <span class="muted-small"><?= h($u['used']) ?>/<?= h($u['capacity']) ?> pax</span>

                  <div class="d-grid gap-2 mt-3">
                    <a
                      class="btn btn-sm btn-outline-<?= h($u['color']) ?>"
                      href="booking.php?date=<?= h($date) ?>&time=<?= h($slot) ?>&pax=<?= h($pax) ?>">
                      Book This Slot
                    </a>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
  <div class="row justify-content-center">
    <div class="col-lg-8">
      <div class="border rounded p-4 bg-white peak-hour-panel">
        <div class="text-center mb-3">
          <div class="section-title">Reservation Insights</div>
          <h5 class="mb-1">Peak Hour Statistics</h5>
          <p class="muted-small mb-0">
            Review the current booking demand before selecting your preferred time slot.
          </p>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Time</th>
                <th>Bookings</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$slots): ?>
                <tr>
                  <td colspan="3" class="text-center text-muted">
                    No time selected.
                  </td>
                </tr>
              <?php endif; ?>

              <?php foreach ($slots as $slot): $u = slot_usage($pdo, $date, $slot); ?>
                <tr>
                  <td><?= h(format_time12($slot)) ?></td>
                  <td><?= h($u['used']) ?>/<?= h($u['capacity']) ?> pax</td>
                  <td>
                    <span class="badge bg-<?= h($u['color']) ?>">
                      <?= h($u['status']) ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <p class="text-warning text-center mt-3 mb-0">
          Peak periods are commonly between 12:00 PM–2:00 PM and 6:00 PM–8:00 PM.
        </p>
      </div>
    </div>
  </div>
</div>
<script>
  const availabilityFilterMode = document.getElementById('availabilityFilterMode');
  const availabilityFilterTime = document.getElementById('availabilityFilterTime');

  function syncAvailabilityTimeFilter() {
    if (!availabilityFilterMode || !availabilityFilterTime) {
      return;
    }

    availabilityFilterTime.disabled = availabilityFilterMode.value !== 'specific';
    availabilityFilterTime.required = availabilityFilterMode.value === 'specific';
  }

  syncAvailabilityTimeFilter();
  availabilityFilterMode?.addEventListener('change', syncAvailabilityTimeFilter);
</script>
<?php include 'includes/chatbot_widget.php';
include 'includes/footer.php'; ?>
