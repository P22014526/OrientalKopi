<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_login();
$today = date('Y-m-d');
$date = $_GET['date'] ?? $today;

if ($date < $today) {
  $date = $today;
}
$time = $_GET['time'] ?? '19:00';
$pax = (int)($_GET['pax'] ?? 2);
$name = current_user_name();
$phone = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $date = $_POST['booking_date'];
  $time = $_POST['booking_time'];
  $pax = (int)$_POST['pax'];
  $name = trim($_POST['customer_name']);
  $phone = trim($_POST['phone']);
  $bookingTimestamp = strtotime($date . ' ' . $time);

  $maxCapacity = max_capacity($pdo);

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
  } elseif ($phone === '') {
    $error = 'Phone number is required.';
  } elseif ($pax > $maxCapacity) {
    $error = "Booking unavailable. The selected pax exceeds the restaurant maximum capacity of {$maxCapacity} pax.";
  } elseif (!is_slot_available($pdo, $date, $time, $pax)) {
    $usage = slot_usage($pdo, $date, $time);
    $used = (int)$usage['used'];
    $capacity = (int)$usage['capacity'];
    $remaining = max(0, $capacity - $used);
    $error = "Selected slot is unavailable. Only {$remaining} pax remaining for this time slot.";
  } else {
    $reward = reward_for_date($date);
    $stmt = $pdo->prepare("INSERT INTO bookings (user_id, customer_name, phone, booking_date, booking_time, pax, status, reward_text) VALUES (?,?,?,?,?,?, 'Confirmed', ?)");
    $stmt->execute([$_SESSION['user_id'], $name, $phone, $date, $time, $pax, $reward]);
    $id = $pdo->lastInsertId();
    $code = booking_code($id);
    $pdo->prepare('UPDATE bookings SET booking_code=? WHERE booking_id=?')->execute([$code, $id]);

    header('Location: preorder_menu.php?id=' . $id . '&new_booking=1');
    exit;
  }
}
include 'includes/header.php';
?>
<div class="card ok-card p-4">
  <div class="section-title">Quick booking</div>
  <h3>Book a Table</h3>
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
      <div class="col-md-4"><label>Pax</label><input class="form-control" id="pax" type="number" min="1" name="pax" value="<?= h($pax) ?>" required></div>
    </div>
    <div id="bookingValidationAnchor"></div>

    <div class="alert alert-info mt-3 d-none" id="partyBox">
      🎉 Large Group Booking Detected. Need Party Reservation Assistance?
      <a class="btn btn-sm btn-dark ms-2" id="partyLink" href="#">Yes</a>
      <button type="button" class="btn btn-sm btn-outline-dark" onclick="document.getElementById('partyBox').classList.add('d-none')">No</button>
    </div>
    <div class="row g-3 mt-1">
      <div class="col-md-6"><label>Name</label><input class="form-control" name="customer_name" value="<?= h($name) ?>" required></div>
      <div class="col-md-6"><label>Phone</label><input class="form-control" name="phone" value="<?= h($phone) ?>" required aria-required="true"></div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-3">
      <button type="submit" class="btn btn-dark">Confirm Booking</button>
      <a href="availability.php?date=<?= h($date) ?>&pax=<?= h($pax) ?>" class="btn btn-outline-dark">
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
    const paxInput = document.getElementById('pax');
    const timeInput = document.querySelector('[name="booking_time"]');
    const dateInput = document.querySelector('[name="booking_date"]');
    const submitBtn = document.querySelector('button[type="submit"], button.btn-dark.mt-3');

    const pax = parseInt(paxInput.value || '0');
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
        `check_slot.php?date=${encodeURIComponent(date)}&time=${encodeURIComponent(time)}&pax=${encodeURIComponent(pax)}`
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
    const paxInput = document.getElementById('pax');
    const timeInput = document.querySelector('[name="booking_time"]');
    const dateInput = document.querySelector('[name="booking_date"]');

    const pax = parseInt(paxInput.value || '0');
    const box = document.getElementById('partyBox');
    const link = document.getElementById('partyLink');

    const d = dateInput.value;
    const t = timeInput.value;

    const maxCapacity = 60;

    const capacityWarning = getOrCreateAlert(
      'capacityWarning',
      paxInput.closest('.row'),
      'alert alert-danger mt-3 d-none'
    );

    if (pax > maxCapacity) {
      capacityWarning.textContent = `Booking unavailable. Pax exceeds the maximum restaurant capacity of ${maxCapacity}.`;
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
    const paxInput = document.getElementById('pax');

    function runChecks() {
      updateParty();
      checkSlotAvailability();
    }

    paxInput?.addEventListener('input', runChecks);

    runChecks();
  });
</script>
<?php include 'includes/chatbot_widget.php';
include 'includes/footer.php'; ?>
