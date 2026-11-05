<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/demand_forecast.php';
require_once 'includes/alternative_slots.php';
require_login();
prevent_dynamic_page_cache();
require_customer_outlet($pdo);
$currentOutlet = current_outlet($pdo);
$today = date('Y-m-d');

$date = $_GET['date'] ?? $today;
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) $date = $today;
$pax = max(1, (int)($_GET['pax'] ?? 4));
$tableOptions = table_type_options($pdo);
$selectedTableKey = $_GET['table_type'] ?? table_type_key_for_pax($pdo, $pax);

if (!isset($tableOptions[$selectedTableKey])) {
  $selectedTableKey = table_type_key_for_pax($pdo, $pax);
}

$selectedTable = $tableOptions[$selectedTableKey] ?? null;
$pax = $selectedTable ? (int)$selectedTable['seats'] : $pax;

$filterMode = $_GET['filter_mode'] ?? 'all';
$filterTime = $_GET['filter_time'] ?? '';

if ($date < $today) {
  $date = $today;
}
$allSlots = common_slots();
$openTime = '09:30';
$closeTime = '22:30';
$timeError = '';
$tableSummary = table_capacity_summary($pdo);

if (!in_array($filterMode, ['all', 'specific', 'breakfast', 'lunch', 'dinner'], true)) {
  $filterMode = 'all';
}

if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $filterTime)) {
  $filterTime = '';
} elseif ($filterTime < $openTime || $filterTime > $closeTime) {
  $timeError = 'Please enter a time between 9:30 AM and 10:30 PM.';
  $filterTime = '';
}

function availability_slot_period($slot)
{
  return booking_meal_period($slot);
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

$slotInsights = [];
$totalReservations = 0;
$totalUsedTables = 0;
$totalAvailableTables = 0;
$availableSlots = 0;
$limitedSlots = 0;
$fullSlots = 0;
$pastSlots = 0;
$bookableSlots = 0;
$busiestSlot = null;
$recommendedSlot = null;

foreach ($slots as $slot) {
  $usage = slot_usage($pdo, $date, $slot, $pax, null, $selectedTableKey);
  $slotTimestamp = strtotime($date . ' ' . $slot);
  $isPastSlot = $slotTimestamp !== false && $slotTimestamp <= time();
  $remainingPax = max((int)$usage['remaining_seats'], 0);
  $matchingTables = $usage['status'] === 'Full' ? 0 : max(0, (int)($usage['remaining_inventory'][(int)$selectedTableKey]['tables'] ?? 0));
  $occupancyPercent = $usage['total_tables'] > 0
    ? min(100, round(($usage['used_tables'] / $usage['total_tables']) * 100))
    : 0;

  if ($usage['status'] === 'Available') {
    $availableSlots++;
  } elseif ($usage['status'] === 'Limited') {
    $limitedSlots++;
  } elseif ($usage['status'] === 'Full' && !$isPastSlot) {
    $fullSlots++;
  }

  if ($isPastSlot) {
    $pastSlots++;
  }

  if (!$isPastSlot && $usage['status'] !== 'Full') {
    $bookableSlots++;

    if (
      $recommendedSlot === null
      || $matchingTables > $recommendedSlot['matching_tables']
      || (
        $matchingTables === $recommendedSlot['matching_tables']
        && strtotime($slot) < strtotime($recommendedSlot['slot'])
      )
    ) {
      $recommendedSlot = [
        'slot' => $slot,
        'matching_tables' => $matchingTables,
        'remaining_pax' => $remainingPax
      ];
    }
  }

  if (
    $busiestSlot === null
    || $usage['reservation_count'] > $busiestSlot['used']
    || (
      $usage['reservation_count'] === $busiestSlot['used']
      && strtotime($slot) < strtotime($busiestSlot['slot'])
    )
  ) {
    $busiestSlot = [
      'slot' => $slot,
      'used' => $usage['reservation_count']
    ];
  }

  $totalReservations += $usage['reservation_count'];
  $totalUsedTables += $usage['used_tables'];
  $totalAvailableTables += $usage['total_tables'];

  $slotInsights[$slot] = [
    'usage' => $usage,
    'matching_tables' => $matchingTables,
    'is_past' => $isPastSlot,
    'remaining_pax' => $remainingPax,
    'occupancy_percent' => $occupancyPercent
  ];
}

$overallOccupancy = $totalAvailableTables > 0
  ? min(100, round(($totalUsedTables / max($totalAvailableTables, 1)) * 100))
  : 0;
$capacitySlot = $filterMode === 'specific' && isset($slotInsights[$filterTime])
  ? $filterTime : ($recommendedSlot['slot'] ?? null);
$tablesAvailable = $capacitySlot !== null && !$slotInsights[$capacitySlot]['is_past']
  ? $slotInsights[$capacitySlot]['matching_tables'] : 0;
$capacityNote = $capacitySlot !== null
  ? ($selectedTable['label'] ?? 'Selected table type') . ' at ' . format_time12($capacitySlot)
  : 'Try another date or table type';
if ($capacitySlot !== null && $slotInsights[$capacitySlot]['is_past']) $capacityNote = 'Selected time has passed';
$busiestSlotLabel = $busiestSlot && $busiestSlot['used'] > 0 ? format_time12($busiestSlot['slot']) : 'No bookings yet';
$recommendedSlotLabel = $recommendedSlot ? format_time12($recommendedSlot['slot']) : 'No open slot';
$periodInsightLabel = match ($filterMode) {
  'breakfast' => 'Breakfast slots',
  'lunch' => 'Lunch slots',
  'dinner' => 'Dinner slots',
  'specific' => 'Selected time',
  default => 'All slots'
};

$forecastDate = $date;
$demandForecast = booking_demand_forecast($pdo, (int)$currentOutlet['outlet_id'], $forecastDate);
$showAlternatives = $filterMode === 'specific' && $filterTime !== '';
$alternatives = $showAlternatives ? smart_alternative_slots($pdo, (int)$currentOutlet['outlet_id'],
  (int)$_SESSION['user_id'], $date, $filterTime, $pax, $selectedTableKey, $demandForecast) : [];
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
      <p class="muted-small mb-0">
        Outlet: <strong><?= h(outlet_label($currentOutlet)) ?></strong>
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
    <div class="col-md-2">
      <label class="form-label">Table Type</label>
      <select class="form-select" name="table_type">
        <?php foreach ($tableOptions as $key => $option): ?>
          <option
            value="<?= h($key) ?>"
            <?= (string)$key === (string)$selectedTableKey ? 'selected' : '' ?>>
            <?= h($option['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="pax" value="<?= h($pax) ?>">
    </div>
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
  <?php include 'includes/demand_forecast_panel.php'; ?>
  <?php if ($showAlternatives): ?>
    <section id="alternative-slots" class="py-3 mb-3 border-top border-bottom">
      <h5>Smart Alternative Slots</h5>
      <p class="text-muted small">Same outlet and table type. Nearest times first; quieter meal periods break ties. Availability is checked again when you confirm.</p>
      <div class="row g-3">
        <?php foreach ($alternatives as $alternative): ?>
          <div class="col-md-4">
            <div class="border rounded p-3 h-100 bg-white">
              <strong><?= h(format_time12($alternative['time'])) ?></strong>
              <div class="small text-muted mb-3"><?= h(ucfirst($alternative['period'])) ?>
                <?= $alternative['level'] ? ' · ' . h($alternative['level']) . ' forecast' : ' · Live availability' ?></div>
              <a class="btn btn-outline-success w-100" href="booking.php?<?= h(http_build_query(['date' => $date, 'time' => $alternative['time'], 'pax' => $pax, 'table_type' => $selectedTableKey])) ?>">Book This Slot</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (!$alternatives): ?><p class="text-muted mb-0">No other eligible times on this date. Try another date or table type.</p><?php endif; ?>
    </section>
  <?php endif; ?>
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
            $u = $slotInsights[$slot]['usage'];
            $isPastSlot = $slotInsights[$slot]['is_past'];
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
                  <span class="muted-small"><?= h($u['used_tables']) ?>/<?= h($u['total_tables']) ?> tables used</span><br>
                  <small>Unavailable</small>
                </button>
                <a class="btn btn-sm btn-outline-dark w-100 mt-2" href="?<?= h(http_build_query(['date' => $date, 'table_type' => $selectedTableKey, 'filter_mode' => 'specific', 'filter_time' => $slot])) ?>#alternative-slots">Find Alternatives</a>
              <?php else: ?>
                <div class="border rounded p-3 h-100 bg-white text-center">
                  <strong><?= h(format_time12($slot)) ?></strong><br>
                  <?= $u['status'] === 'Available' ? '✅' : '⚠' ?> <?= h($u['status']) ?><br>
                  <span class="muted-small"><?= h($u['used_tables']) ?>/<?= h($u['total_tables']) ?> tables used</span>

                  <div class="d-grid gap-2 mt-3">
                    <a
                      class="btn btn-sm btn-outline-<?= h($u['color']) ?>"
                      href="booking.php?date=<?= h($date) ?>&time=<?= h($slot) ?>&pax=<?= h($pax) ?>&table_type=<?= h($selectedTableKey) ?>">
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
    <div class="col-lg-10">
      <div class="border rounded p-4 bg-white peak-hour-panel">
        <div class="text-center mb-3">
          <div class="section-title">Reservation Insights</div>
          <h5 class="mb-1">Peak Hour Statistics</h5>
          <p class="muted-small mb-0">
            Review the current booking demand before selecting your preferred time slot.
          </p>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-md-6 col-xl-3">
            <div class="stat-pill availability-insight-card h-100">
              <span>Active Reservations</span>
              <strong><?= h($totalReservations) ?></strong>
              <small>pending or confirmed across <?= h(count($slots)) ?> shown slot(s)</small>
            </div>
          </div>

          <div class="col-md-6 col-xl-3">
            <div class="stat-pill availability-insight-card h-100">
              <span>Tables Available</span>
              <strong><?= h($tablesAvailable) ?></strong>
              <small><?= h($capacityNote) ?></small>
            </div>
          </div>

          <div class="col-md-6 col-xl-3">
            <div class="stat-pill availability-insight-card h-100">
              <span>Bookable Slots</span>
              <strong><?= h($bookableSlots) ?></strong>
              <small>future times for <?= h($selectedTable['label'] ?? $pax . ' seats') ?></small>
            </div>
          </div>

          <div class="col-md-6 col-xl-3">
            <div class="stat-pill availability-insight-card h-100">
              <span>Recommended Time</span>
              <strong><?= h($recommendedSlotLabel) ?></strong>
              <small><?= h($periodInsightLabel) ?></small>
            </div>
          </div>
        </div>

        <div class="availability-summary-strip mb-4">
          <div>
            <span>Average Slot Occupancy</span>
            <strong><?= h($overallOccupancy) ?>%</strong>
          </div>

          <div>
            <span>Busiest Slot</span>
            <strong><?= h($busiestSlotLabel) ?></strong>
          </div>

          <div>
            <span>Full Upcoming Slots</span>
            <strong><?= h($fullSlots) ?></strong>
          </div>

        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Time</th>
                <th>Reservations</th>
                <th>Occupancy</th>
                <th>Selected Table Type</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$slots): ?>
                <tr>
                  <td colspan="5" class="text-center text-muted">
                    No time selected.
                  </td>
                </tr>
              <?php endif; ?>

              <?php foreach ($slots as $slot): ?>
                <?php
                $u = $slotInsights[$slot]['usage'];
                $occupancyPercent = $slotInsights[$slot]['occupancy_percent'];
                ?>
                <tr>
                  <td><?= h(format_time12($slot)) ?></td>
                  <td><?= h($u['reservation_count']) ?></td>
                  <td>
                    <div class="availability-usage-bar" aria-label="<?= h($occupancyPercent) ?>% occupied">
                      <span
                        class="bg-<?= h($u['color']) ?>"
                        style="width: <?= h($occupancyPercent) ?>%;">
                      </span>
                    </div>
                    <small class="text-muted"><?= h($occupancyPercent) ?>%</small>
                  </td>
                  <td>
                    <?= $slotInsights[$slot]['is_past'] ? 'Time passed' : h($slotInsights[$slot]['matching_tables']) . ' table(s) available' ?>
                  </td>
                  <td>
                    <span class="badge bg-<?= $slotInsights[$slot]['is_past'] ? 'secondary' : h($u['color']) ?>">
                      <?= $slotInsights[$slot]['is_past'] ? 'Time passed' : h($u['status']) ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <p class="text-warning text-center mt-3 mb-0">
          Table setup: <?= h($tableSummary) ?>. Peak periods are commonly between 12:00 PM–2:00 PM and 6:00 PM–8:00 PM.
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
