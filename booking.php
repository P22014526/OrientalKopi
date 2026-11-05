<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/malaysian_phone.php';
require_login();
prevent_dynamic_page_cache();
require_customer_outlet($pdo);
$currentOutlet = current_outlet($pdo);
$outletId = (int)$currentOutlet['outlet_id'];
$today = date('Y-m-d');
$date = $_GET['date'] ?? $today;

if ($date < $today) {
  $date = $today;
}
$time = $_GET['time'] ?? '19:00';
$requestedPax = (int)($_GET['pax'] ?? 2);
$tableOptions = table_type_options($pdo);
$selectedTableKey = $_GET['table_type'] ?? table_type_key_for_pax($pdo, $requestedPax);

if (!isset($tableOptions[$selectedTableKey])) {
  $selectedTableKey = array_key_first($tableOptions);
}

$selectedTable = $tableOptions[$selectedTableKey] ?? null;
$pax = $selectedTable ? (int)$selectedTable['seats'] : 0;
$name = current_user_name();
$accountPhoneStmt = $pdo->prepare('SELECT phone FROM users WHERE user_id=?');
$accountPhoneStmt->execute([(int)$_SESSION['user_id']]);
$accountPhone = (string)$accountPhoneStmt->fetchColumn();
$normalizedPhone = normalize_malaysian_mobile($accountPhone);
$phone = $normalizedPhone ?? $accountPhone;
$error = '';
$maxCapacity = max_capacity($pdo);
$tableSummary = table_capacity_summary($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $date = $_POST['booking_date'];
  $time = $_POST['booking_time'];
  $selectedTableKey = $_POST['table_type'] ?? '';
  $selectedTable = $tableOptions[$selectedTableKey] ?? null;
  $pax = $selectedTable ? (int)$selectedTable['seats'] : 0;
  $name = trim($_POST['customer_name']);
  // Always use the signed-in account, never the submitted phone field.
  $bookingTimestamp = strtotime($date . ' ' . $time);

  $openTime = '09:30';
  $closeTime = '22:30';

  if ($date < $today) {
    $error = 'Past booking dates are not allowed.';
  } elseif ($bookingTimestamp === false) {
    $error = 'Please select a valid booking date and time.';
  } elseif ($bookingTimestamp <= time()) {
    $error = 'The selected booking time has already passed. Please choose another time.';
  } elseif ($time < $openTime || $time > $closeTime) {
    $error = "Booking unavailable. Please select a time between 9:30 AM and 10:30 PM.";
  } elseif ($normalizedPhone === null) {
    $error = 'Please update your profile with a valid Malaysian mobile number before booking.';
  } elseif (!$selectedTable) {
    $error = 'Please select a table type.';
  } elseif ($pax > $maxCapacity) {
    $error = "Booking unavailable. The selected table type exceeds the restaurant table capacity of {$maxCapacity} seats.";
  } elseif ($policyError = customer_booking_policy_violation($pdo, (int)$_SESSION['user_id'], $date, $time)) {
    $error = $policyError;
  } elseif (!is_slot_available($pdo, $date, $time, $pax, null, $selectedTableKey, $outletId)) {
    $usage = slot_usage($pdo, $date, $time, $pax, null, $selectedTableKey, $outletId);
    $remainingTables = (int)$usage['remaining_tables'];
    $remainingSeats = (int)$usage['remaining_seats'];
    $tableLabel = $selectedTable['label'] ?? 'selected table type';
    $error = "Selected slot is unavailable. No {$tableLabel} is available. Remaining: {$remainingTables} table(s), {$remainingSeats} seat(s).";
  } else {
    $reward = reward_for_date($date);
    $stmt = $pdo->prepare("INSERT INTO bookings (user_id, outlet_id, customer_name, phone, booking_date, booking_time, pax, table_type, status, reward_text) VALUES (?,?,?,?,?,?,?,?, 'Confirmed', ?)");
    $stmt->execute([$_SESSION['user_id'], $outletId, $name, $phone, $date, $time, $pax, $selectedTableKey, $reward]);
    $id = $pdo->lastInsertId();
    $code = booking_code($id);
    $pdo->prepare('UPDATE bookings SET booking_code=? WHERE booking_id=?')->execute([$code, $id]);

    header('Location: preorder_menu.php?id=' . $id . '&new_booking=1');
    exit;
  }
}

$slotTableUsage = table_usage_for_slot($pdo, $date, $time, null, $outletId);
$slotRemainingInventory = $slotTableUsage['remaining_inventory'];

include 'includes/header.php';
?>
<div class="card ok-card p-4">
  <div class="section-title">Quick booking</div>
  <h3>Book a Table</h3>
  <p class="muted-small">
    Outlet: <strong><?= h(outlet_label($currentOutlet)) ?></strong>
  </p>
  <div class="alert alert-info">
    You may hold up to <?= h(daily_booking_limit($pdo)) ?> active bookings per day across all outlets,
    with one booking each for breakfast, lunch, and dinner.
  </div>
  <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" id="bookingDate" name="booking_date" value="<?= h($date) ?>">
    <input type="hidden" id="bookingTime" name="booking_time" value="<?= h($time) ?>">

    <div class="row g-3">
      <div class="col-md-4">
        <div class="stat-pill h-100">
          <strong>Date</strong><br>
          <?= h(format_date_short($date)) ?>
        </div>
      </div>
      <div class="col-md-4">
        <div class="stat-pill h-100">
          <strong>Time</strong><br>
          <?= h(format_time12($time)) ?>
          <div class="muted-small mt-1">Opening Hours: 9:30 AM - 10:30 PM</div>
        </div>
      </div>
      <div class="col-md-4">
        <label for="tableType">Table Type</label>
        <select class="form-select" id="tableType" name="table_type" required>
          <?php foreach ($tableOptions as $key => $option): ?>
            <?php
            $availableTables = (int)($slotRemainingInventory[(int)$key]['tables'] ?? 0);
            ?>
            <option
              value="<?= h($key) ?>"
              data-seats="<?= h($option['seats']) ?>"
              data-table-type="<?= h($key) ?>"
              data-available-tables="<?= h($availableTables) ?>"
              <?= (string)$key === (string)$selectedTableKey ? 'selected' : '' ?>>
              <?= h($option['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="muted-small mt-1">
          Selected table seats: <span id="selectedTableSeats"><?= h($pax) ?> seats</span>
        </div>
      </div>
    </div>
    <div class="booking-seating-note mt-3">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
          <strong>Seating Setup</strong>
          <p class="muted-small mb-0">
            Availability below is for <?= h(format_date_short($date)) ?> at <?= h(format_time12($time)) ?>.
          </p>
        </div>
        <span class="badge bg-dark"><?= h($maxCapacity) ?> seats total</span>
      </div>

      <div class="booking-table-options">
        <?php foreach ($tableOptions as $key => $option): ?>
          <?php
          $availableTables = (int)($slotRemainingInventory[(int)$key]['tables'] ?? 0);
          $totalTables = (int)$option['tables'];
          $isSelectedTable = (string)$key === (string)$selectedTableKey;
          $isUnavailable = $availableTables <= 0;
          ?>
          <div
            class="booking-table-option <?= $isSelectedTable ? 'is-selected' : '' ?> <?= $isUnavailable ? 'is-unavailable' : '' ?>"
            data-table-card="<?= h($key) ?>">
            <span><?= h($option['type']) ?></span>
            <strong><?= h($option['seats']) ?> seats</strong>
            <small>
              <?= h($availableTables) ?>/<?= h($totalTables) ?>
              <?= $totalTables === 1 ? 'table' : 'tables' ?> available
            </small>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="muted-small mt-3">
        If a table type shows 0 available, it cannot be booked for this selected time slot.
      </div>
    </div>
    <div id="bookingValidationAnchor"></div>

    <div class="alert alert-info mt-3 d-none" id="partyBox">
      🎉 Large Group Booking Detected. Need Party Reservation Assistance?
      <a class="btn btn-sm btn-dark ms-2" id="partyLink" href="#">Yes</a>
      <button type="button" class="btn btn-sm btn-outline-dark" onclick="document.getElementById('partyBox').classList.add('d-none')">No</button>
    </div>
    <div class="row g-3 mt-1">
      <div class="col-md-6"><label>Name</label><input class="form-control" name="customer_name" value="<?= h($name) ?>" required></div>
      <div class="col-md-6"><?php render_malaysian_phone_field('booking-phone', $phone, true, true); ?>
      <?php if ($normalizedPhone === null): ?><div class="text-danger small mt-2">A valid Malaysian mobile number is required before booking.</div><?php endif; ?>
      <a href="edit_profile.php" class="btn btn-sm btn-outline-dark mt-2">Update Profile Phone</a></div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-3">
      <button type="submit" class="btn btn-dark">Confirm Booking</button>
      <a href="availability.php?date=<?= h($date) ?>&pax=<?= h($pax) ?>" class="btn btn-outline-dark" id="chooseAnotherTimeLink">
        Choose Another Time
      </a>
    </div>
  </form>
</div>
<script>
  function getOrCreateAlert(id, afterElement, className = 'alert alert-danger mt-3 d-none') {
    let alert = document.getElementById(id);

    if (!alert) {
      alert = document.createElement('div');
      alert.id = id;
      alert.className = className;
      afterElement.after(alert);
    }

    return alert;
  }

  async function checkSlotAvailability() {
    const tableTypeInput = document.getElementById('tableType');
    const timeInput = document.querySelector('[name="booking_time"]');
    const dateInput = document.querySelector('[name="booking_date"]');
    const submitBtn = document.querySelector('button[type="submit"], button.btn-dark.mt-3');

    const selectedOption = tableTypeInput?.selectedOptions?.[0];
    const pax = parseInt(selectedOption?.dataset?.seats || '0');
    const tableType = selectedOption?.dataset?.tableType || tableTypeInput?.value || '';
    const date = dateInput.value;
    const time = timeInput.value;

    const slotAlert = getOrCreateAlert(
      'slotAvailabilityAlert',
      document.getElementById('bookingValidationAnchor'),
      'alert mt-3 d-none'
    );

    if (!date || !time || pax <= 0) {
      slotAlert.className = 'alert mt-3 d-none';
      if (submitBtn) submitBtn.disabled = false;
      return;
    }

    try {
      const response = await fetch(
        `check_slot.php?date=${encodeURIComponent(date)}&time=${encodeURIComponent(time)}&pax=${encodeURIComponent(pax)}&table_type=${encodeURIComponent(tableType)}`
      );

      const data = await response.json();

      slotAlert.textContent = data.message;

      if (data.available) {
        slotAlert.className = 'alert alert-success mt-3';
        if (submitBtn) submitBtn.disabled = false;
      } else {
        slotAlert.className = 'alert alert-danger mt-3';
        if (submitBtn) submitBtn.disabled = true;
      }

    } catch (error) {
      slotAlert.textContent = 'Unable to check slot availability. Please try again.';
      slotAlert.className = 'alert alert-warning mt-3';
      if (submitBtn) submitBtn.disabled = false;
    }
  }

  function updateParty() {
    const tableTypeInput = document.getElementById('tableType');
    const timeInput = document.querySelector('[name="booking_time"]');
    const dateInput = document.querySelector('[name="booking_date"]');

    const selectedOption = tableTypeInput?.selectedOptions?.[0];
    const pax = parseInt(selectedOption?.dataset?.seats || '0');
    const box = document.getElementById('partyBox');
    const link = document.getElementById('partyLink');
    const seatsLabel = document.getElementById('selectedTableSeats');
    const chooseAnotherTimeLink = document.getElementById('chooseAnotherTimeLink');

    const d = dateInput.value;
    const t = timeInput.value;

    const maxCapacity = <?= json_encode($maxCapacity) ?>;

    const capacityWarning = getOrCreateAlert(
      'capacityWarning',
      tableTypeInput.closest('.row'),
      'alert alert-danger mt-3 d-none'
    );

    if (seatsLabel) {
      const availableTables = parseInt(selectedOption?.dataset?.availableTables || '0');
      seatsLabel.textContent = pax ? `${pax} seats, ${availableTables} table${availableTables === 1 ? '' : 's'} left` : '-';
    }

    document.querySelectorAll('[data-table-card]').forEach((card) => {
      card.classList.toggle('is-selected', card.dataset.tableCard === tableTypeInput.value);
    });

    if (chooseAnotherTimeLink) {
      chooseAnotherTimeLink.href = `availability.php?date=${encodeURIComponent(d)}&pax=${encodeURIComponent(pax)}`;
    }

    if (pax > maxCapacity) {
      capacityWarning.textContent = `Booking unavailable. Selected table type exceeds the table capacity of ${maxCapacity}.`;
      capacityWarning.classList.remove('d-none');
      box.classList.add('d-none');
      return;
    } else {
      capacityWarning.classList.add('d-none');
    }

    if (pax > 6) {
      box.classList.remove('d-none');
      link.href = `party_booking.php?date=${encodeURIComponent(d)}&time=${encodeURIComponent(t)}&pax=${encodeURIComponent(pax)}`;
    } else {
      box.classList.add('d-none');
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    const tableTypeInput = document.getElementById('tableType');

    function runChecks() {
      updateParty();
      checkSlotAvailability();
    }

    tableTypeInput?.addEventListener('change', runChecks);

    runChecks();
  });
</script>
<?php include 'includes/chatbot_widget.php';
include 'includes/footer.php'; ?>
