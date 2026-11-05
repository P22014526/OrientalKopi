<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/demand_forecast.php';

require_login();
prevent_dynamic_page_cache();

if ($_SESSION['role'] === 'admin') {
  header('Location: admin/dashboard.php');
  exit;
}

if ($_SESSION['role'] === 'shop_owner') {
  header('Location: admin/dashboard.php');
  exit;
}

require_customer_outlet($pdo);
$currentOutlet = current_outlet($pdo);

$stmt = $pdo->prepare("SELECT * FROM bookings WHERE user_id=? AND status IN ('Confirmed','Pending') ORDER BY booking_date, booking_time LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$upcoming = $stmt->fetch();

$today = date('Y-m-d');
$slots = common_slots();
$tableSummary = table_capacity_summary($pdo);
$outletPhoneHref = phone_href($currentOutlet['contact_phone'] ?? '');
$outletMapUrl = $currentOutlet['map_url'] ?? '';

$forecastDate = $today;
$demandForecast = booking_demand_forecast($pdo, (int)$currentOutlet['outlet_id'], $forecastDate);
include 'includes/header.php';
?>

<div class="customer-home-wrapper">

  <section class="customer-hero">
    <div class="customer-hero-overlay"></div>

    <div class="customer-hero-content">
      <div>
        <div class="section-title text-warning"><?= h($currentOutlet['branch_name']) ?></div>
        <h1>Authentic Nanyang Flavours,<br>Now Bookable Online.</h1>
        <p>
          Check availability, reserve your seat, manage bookings, and get instant FAQ support before visiting.
        </p>
        <p class="badge bg-warning text-dark mt-2">
          <?= h(outlet_label($currentOutlet)) ?>
        </p>
      </div>
    </div>

    <div class="quick-booking-card">
      <div class="quick-booking-title">Secure Your Table</div>

      <div class="quick-booking-actions">
        <a href="availability.php" class="btn btn-gold">Book a Table</a>
      </div>
    </div>
  </section>

  <section class="row g-4 mt-4">
    <div class="col-lg-7">
      <div class="card ok-card p-4 h-100">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div>
            <div class="section-title">Customer Dashboard</div>
            <h4 class="mb-0">Plan Your Visit</h4>
          </div>
          <span class="badge bg-dark">Today</span>
        </div>

        <p class="muted-small">
          View today’s table capacity usage for <?= h($currentOutlet['branch_name']) ?> before making your reservation.
        </p>

        <?php include 'includes/demand_forecast_panel.php'; ?>
        <div class="table-responsive mb-3">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th>Time</th>
                <th>Tables Booked</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>

            <tbody>
              <?php foreach ($slots as $slot): ?>
                <?php
                $u = slot_usage($pdo, $today, $slot, 2, null, '0');
                $slotTimestamp = strtotime($today . ' ' . $slot);
                $isPastSlot = $slotTimestamp !== false && $slotTimestamp <= time();
                $canBookSlot = !$isPastSlot && $u['status'] !== 'Full';
                ?>
                <tr>
                  <td><?= h(format_time12($slot)) ?></td>
                  <td><?= h($u['used_tables']) ?>/<?= h($u['total_tables']) ?> tables</td>
                  <td>
                    <span class="badge bg-<?= h($u['color']) ?>">
                      <?= h($u['status']) ?>
                    </span>
                  </td>
                  <td>
                    <?php if ($canBookSlot): ?>
                      <a
                        class="btn btn-sm btn-outline-<?= h($u['color']) ?>"
                        href="booking.php?date=<?= h($today) ?>&time=<?= h($slot) ?>&pax=2&table_type=0">
                        Book This Slot
                      </a>
                    <?php else: ?>
                      <button class="btn btn-sm btn-outline-secondary" disabled>
                        <?= $isPastSlot ? 'Time Passed' : 'Unavailable' ?>
                      </button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <canvas id="peakChart"></canvas>

        <p class="text-warning mt-3 mb-0">
          Table setup: <?= h($tableSummary) ?>. Early booking is recommended for 12:00 PM–2:00 PM and 6:00 PM–8:00 PM.
        </p>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card ok-card p-4 h-100">
        <div class="section-title">Signatures You Cannot Miss</div>
        <h4>Customer Booking Benefits</h4>

        <div class="home-benefit-card">
          <span>📅</span>
          <div>
            <strong>Real-Time Availability</strong>
            <p>Check available booking slots before arriving at the restaurant.</p>
          </div>
        </div>

        <div class="home-benefit-card">
          <span>🤖</span>
          <div>
            <strong>Rule-Based Chatbot</strong>
            <p>Ask about opening hours, booking policies, location, and reservation status.</p>
          </div>
        </div>

        <div class="home-benefit-card">
          <span>🎉</span>
          <div>
            <strong>Party Reservation Request</strong>
            <p>Large groups can submit special requests for admin review.</p>
          </div>
        </div>

        <div class="home-benefit-card">
          <span>🎁</span>
          <div>
            <strong>Booking Rewards</strong>
            <p>Receive rewards by collecting badges after your visit.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="contactSection" class="contact-section mt-4">
    <div class="row g-4 align-items-center">
      <div class="col-lg-7">
        <div class="section-title text-warning">Contact Us</div>
        <h3>Oriental Kopi <?= h($currentOutlet['branch_name']) ?></h3>
        <p>
          Visit Oriental Kopi <?= h($currentOutlet['branch_name']) ?> and enjoy authentic Nanyang flavours in a comfortable dining environment.
          Customers are encouraged to check availability and reserve a table before visiting, especially during peak hours.
        </p>

        <div class="row g-3">
          <div class="col-md-6">
            <div class="contact-info-box">
              <strong>Opening Hours (Mon - Sun)</strong>
              <p>09:30 AM – 10:30 PM</p>
            </div>
          </div>

          <div class="col-md-6">
            <div class="contact-info-box">
              <strong>Location</strong>
              <p>
                <?= h($currentOutlet['branch_name']) ?><br>
                <span class="muted-small"><?= h($currentOutlet['location_detail']) ?></span><br>
                <span class="muted-small"><?= h($currentOutlet['state_name']) ?></span>
              </p>
            </div>
          </div>

          <div class="col-md-6">
            <div class="contact-info-box">
              <strong>Phone Number</strong>
              <p>
                <?php if (!empty($currentOutlet['contact_phone'])): ?>
                  <a href="tel:<?= h($outletPhoneHref) ?>" class="contact-link">
                    <?= h($currentOutlet['contact_phone']) ?>
                  </a>
                <?php else: ?>
                  <span class="text-muted">Not available</span>
                <?php endif; ?>
              </p>
            </div>
          </div>

          <div class="col-md-6">
            <div class="contact-info-box">
              <strong>Email</strong>
              <p>
                <a href="mailto:hq@orientalkopi.asia" class="contact-link">
                  hq@orientalkopi.asia
                </a>
              </p>
            </div>
          </div>
        </div>

        <?php if (!empty($outletMapUrl)): ?>
          <a
            href="<?= h($outletMapUrl) ?>"
            target="_blank"
            rel="noopener"
            class="btn btn-gold mt-4">
            Open Location in Google Maps
          </a>
        <?php endif; ?>
      </div>

      <div class="col-lg-5">
        <?php if (!empty($outletMapUrl)): ?>
          <a
            href="<?= h($outletMapUrl) ?>"
            target="_blank"
            rel="noopener"
            class="map-preview-link branch-map-card">
            <span class="branch-map-card-title">Open <?= h($currentOutlet['branch_name']) ?> Map</span>
            <span class="branch-map-card-location"><?= h($currentOutlet['location_detail']) ?></span>
          </a>
        <?php else: ?>
          <div class="branch-map-card">
            <span class="branch-map-card-title">Map not available</span>
            <span class="branch-map-card-location"><?= h(outlet_label($currentOutlet)) ?></span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

</div>

<?php include 'includes/chatbot_widget.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    const ctx = document.getElementById('peakChart');

    if (ctx && typeof Chart !== 'undefined') {
      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: <?= json_encode(array_map('format_time12', $slots)) ?>,
          datasets: [{
            label: 'Pax Booked',
            data: <?= json_encode(array_map(fn($s) => slot_usage($pdo, $today, $s)['used'], $slots)) ?>
          }]
        },
        options: {
          plugins: {
            title: {
              display: true,
              text: 'Peak Hour Booking Statistics',
              font: {
                size: 18,
                weight: 'bold'
              },
              padding: {
                top: 10,
                bottom: 20
              }
            },
            legend: {
              display: false
            }
          },
          scales: {
            x: {
              title: {
                display: true,
                text: 'Booking Time'
              }
            },
            y: {
              beginAtZero: true,
              title: {
                display: true,
                text: 'Number of Pax Booked'
              }
            }
          }
        }
      });
    }
  });
</script>

<?php include 'includes/footer.php'; ?>
