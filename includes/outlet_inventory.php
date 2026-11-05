<?php
// Keep type indexes and seat sizes stable: existing bookings reference these indexes.
function submitted_outlet_inventory(array $post, array $existing): array
{
    $counts = $post['inventory_tables'] ?? null;
    if (!is_array($counts) || count($counts) !== count($existing)) {
        throw new InvalidArgumentException('Please enter the number of tables for every table type.');
    }
    foreach ($existing as $index => &$row) {
        $count = filter_var($counts[$index] ?? null, FILTER_VALIDATE_INT);
        if ($count === false || $count === null || $count < 0 || $count > 100) {
            throw new InvalidArgumentException('Each table count must be a whole number from 0 to 100.');
        }
        $row['tables'] = $count;
    }
    unset($row);
    if (total_tables($existing) === 0) {
        throw new InvalidArgumentException('Please keep at least one table available at this outlet.');
    }
    return $existing;
}

function validate_outlet_inventory_bookings($pdo, int $outletId, array $inventory): void
{
    $stmt = $pdo->prepare("SELECT booking_date, booking_time, pax, table_type FROM bookings
        WHERE outlet_id = ? AND status IN ('Confirmed', 'Pending')
          AND TIMESTAMP(booking_date, booking_time) >= NOW()
        ORDER BY booking_date, booking_time, pax DESC, created_at, booking_id");
    $stmt->execute([$outletId]);
    $lastSlot = null;
    foreach ($stmt as $booking) {
        $slot = $booking['booking_date'] . ' ' . $booking['booking_time'];
        if ($slot !== $lastSlot) $remaining = $inventory;
        $allocation = find_best_table_allocation($remaining, (int)$booking['pax'], $booking['table_type']);
        if ($allocation === null) {
            throw new InvalidArgumentException('This setup cannot accommodate existing bookings on ' . $slot . '. Keep enough tables or resolve those bookings first.');
        }
        foreach ($allocation['allocation'] as $index => $count) $remaining[$index]['tables'] -= $count;
        $lastSlot = $slot;
    }
}

function render_outlet_inventory(array $inventory, string $prefix): void
{
    ?>
    <fieldset class="mt-3">
      <legend class="fs-6 fw-semibold">Table Inventory</legend>
      <div class="row g-3">
        <?php foreach ($inventory as $index => $row): ?>
          <div class="col-6 col-lg-3">
            <label for="<?= h($prefix . '-' . $index) ?>" class="form-label mb-1"><?= h($row['type']) ?></label>
            <div class="small text-muted mb-2"><?= h($row['seats']) ?> seats per table</div>
            <input id="<?= h($prefix . '-' . $index) ?>" type="number" name="inventory_tables[<?= h($index) ?>]"
              class="form-control" min="0" max="100" step="1" value="<?= h($row['tables']) ?>" required>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="small text-muted mt-2 mb-0">Set a count to 0 for table types not offered at this outlet.</p>
    </fieldset>
    <?php
}
