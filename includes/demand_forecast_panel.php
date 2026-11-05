<?php
// Caller supplies $demandForecast and $forecastDate for an authorized outlet.
$forecastLabels = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'];
$forecastHours = ['breakfast' => '9:30 AM - 12 PM', 'lunch' => '12 PM - 5 PM', 'dinner' => '5 PM - 10:30 PM'];
?>
<section class="demand-forecast my-3" aria-label="Booking demand forecast">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <div class="section-title">Demand Forecast</div>
      <h4 class="mb-1">Plan Ahead</h4>
      <p class="muted-small mb-0"><?= h(format_date_short($forecastDate)) ?> &middot; Selected outlet</p>
    </div>
    <span class="badge bg-light text-dark border">Historical estimate</span>
  </div>
  <?php if ($demandForecast['ready']): ?>
    <div class="demand-forecast-grid">
      <?php foreach ($demandForecast['periods'] as $period => $prediction): ?>
        <div class="demand-forecast-period">
          <strong><?= h($forecastLabels[$period]) ?></strong>
          <small><?= h($forecastHours[$period]) ?></small>
          <span class="badge bg-<?= h($prediction['color']) ?><?= $prediction['color'] === 'warning' ? ' text-dark' : '' ?>"><?= h($prediction['level']) ?></span>
          <p class="mb-0"><b><?= h(number_format($prediction['expected'], 1)) ?></b> estimated bookings</p>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="muted-small mt-3 mb-0">Based on <?= h($demandForecast['samples']) ?> matching weekdays. Live availability determines whether you can book.</p>
    <details class="mt-2">
      <summary class="muted-small">About this forecast</summary>
      <p class="muted-small mt-2 mb-0">Recent matching weekdays carry more weight. Includes confirmed and completed bookings over the last 12 weeks, including quiet days. Busier and quieter compare bookings per hour across this outlet's meal periods. Holidays, walk-ins, and special events are not included.</p>
    </details>
  <?php else: ?>
    <p class="fw-semibold mb-1"><?= h($demandForecast['reason']) ?></p>
    <p class="muted-small mb-0">Forecasts need at least three matching weekdays with bookings and ten confirmed or completed reservations. Live availability is still available below.</p>
  <?php endif; ?>
</section>
