<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/outlet_inventory.php';

require_staff();
prevent_dynamic_page_cache();
if (empty($_SESSION['outlet_csrf'])) $_SESSION['outlet_csrf'] = bin2hex(random_bytes(32));

$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$assignedOutletIds = staff_assigned_outlet_ids($pdo);
$assignedStateIds = staff_assigned_state_ids($pdo);
$success = '';
$error = '';
$settingsOutletId = (int)($_GET['settings_outlet_id'] ?? ($_POST['outlet_id'] ?? 0));
$filterSearch = trim((string)($_GET['outlet_search'] ?? ($_POST['outlet_search'] ?? '')));
$filterStateId = (int)($_GET['outlet_state_id'] ?? ($_POST['outlet_state_id'] ?? 0));
$filterStatus = (string)($_GET['outlet_status'] ?? ($_POST['outlet_status'] ?? 'all'));

if (!in_array($filterStatus, ['all', 'active', 'inactive'], true)) {
  $filterStatus = 'all';
}

if (mb_strlen($filterSearch) > 100) {
  $filterSearch = mb_substr($filterSearch, 0, 100);
}

ensure_staff_activity_log_table($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $action = $_POST['dashboard_action'] ?? '';
  if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['outlet_csrf'], $_POST['csrf'])) {
    $error = 'Your form expired. Please reload the page and try again.';
    $action = '';
  }

  if ($action === 'create_outlet') {
    $stateId = (int)($_POST['state_id'] ?? 0);
    $branchName = trim($_POST['branch_name'] ?? '');
    $locationDetail = trim($_POST['location_detail'] ?? '');
    $contactPhone = trim($_POST['contact_phone'] ?? '');
    $mapUrl = trim($_POST['map_url'] ?? '');
    $isActive = $isAdmin && !isset($_POST['is_active']) ? 0 : 1;

    if ($stateId <= 0 || $branchName === '' || $locationDetail === '') {
      $error = 'Please complete the state, branch name, and location fields.';
    } elseif (!staff_can_access_state($pdo, $stateId)) {
      $error = 'You can only add outlets for your assigned state.';
    } else {
      try {
        $inventory = submitted_outlet_inventory($_POST, table_inventory($pdo, 0));
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO outlets (state_id, branch_name, location_detail, contact_phone, map_url, is_active)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$stateId, $branchName, $locationDetail, $contactPhone, $mapUrl, $isActive]);
        $newOutletId = (int)$pdo->lastInsertId();
        upsert_setting($pdo, 'table_inventory_outlet_' . $newOutletId, json_encode($inventory));

        if (!$isAdmin) {
          $pdo->prepare("
              INSERT IGNORE INTO shop_owner_outlets (user_id, outlet_id)
              VALUES (?, ?)
          ")->execute([$_SESSION['user_id'], $newOutletId]);

          if (is_array($assignedOutletIds)) {
            $assignedOutletIds[] = $newOutletId;
          }
        }

        $pdo->commit();
        $success = 'New outlet added successfully.';
        $settingsOutletId = $newOutletId;
        log_staff_activity($pdo, 'Added outlet', ['outlet_id' => $newOutletId], $branchName . ' was added.');
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }

        error_log('Outlet creation failed: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Outlet could not be added. Please check duplicate outlet names and try again.';
      }
    }
  } elseif ($action === 'update_outlet_settings' && $isAdmin) {
    $outletId = (int)($_POST['outlet_id'] ?? 0);
    $stateId = (int)($_POST['state_id'] ?? 0);
    $branchName = trim($_POST['branch_name'] ?? '');
    $locationDetail = trim($_POST['location_detail'] ?? '');
    $contactPhone = trim($_POST['contact_phone'] ?? '');
    $mapUrl = trim($_POST['map_url'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $openingHours = trim($_POST['opening_hours'] ?? '');

    if ($outletId <= 0 || $stateId <= 0 || $branchName === '' || $locationDetail === '') {
      $error = 'Please complete the outlet name, state, and location fields.';
    } else {
      try {
        $exists = $pdo->prepare('SELECT outlet_id FROM outlets WHERE outlet_id = ?');
        $exists->execute([$outletId]);
        if (!$exists->fetchColumn()) throw new InvalidArgumentException('Please select an existing outlet.');
        $inventory = submitted_outlet_inventory($_POST, table_inventory($pdo, $outletId));
        validate_outlet_inventory_bookings($pdo, $outletId, $inventory);
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE outlets
            SET state_id = ?, branch_name = ?, location_detail = ?, contact_phone = ?, map_url = ?, is_active = ?
            WHERE outlet_id = ?
        ");
        $stmt->execute([$stateId, $branchName, $locationDetail, $contactPhone, $mapUrl, $isActive, $outletId]);
        upsert_setting($pdo, 'table_inventory_outlet_' . $outletId, json_encode($inventory));

        if ($openingHours !== '') {
          upsert_setting($pdo, 'opening_hours', $openingHours);
        }

        $pdo->commit();
        $success = 'Outlet settings updated successfully.';
        $settingsOutletId = $outletId;
        log_staff_activity($pdo, 'Updated outlet settings', ['outlet_id' => $outletId], $branchName . ' settings were updated.');
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }

        error_log('Outlet settings update failed: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Outlet settings could not be updated. Please check duplicate outlet names and try again.';
      }
    }
  } elseif (in_array($action, ['update_booking_policy', 'update_cancellation_policy'], true) && $isAdmin) {
    $cutoffHours = (float)($_POST['cancellation_cutoff_hours'] ?? 12);
    $cutoffMinutes = (int)round($cutoffHours * 60);
    $graceMinutes = (int)($_POST['no_show_grace_minutes'] ?? 15);
    $dailyLimit = (int)($_POST['daily_booking_limit'] ?? 3);

    if ($cutoffMinutes < 0 || $cutoffMinutes > 4320) {
      $error = 'Cancellation cutoff must be between 0 and 72 hours.';
    } elseif ($graceMinutes < 0 || $graceMinutes > 120) {
      $error = 'No-show grace period must be between 0 and 120 minutes.';
    } elseif ($dailyLimit < 1 || $dailyLimit > 10) {
      $error = 'Daily booking limit must be between 1 and 10.';
    } else {
      upsert_setting($pdo, 'cancellation_cutoff_minutes', (string)$cutoffMinutes);
      upsert_setting($pdo, 'no_show_grace_minutes', (string)$graceMinutes);
      upsert_setting($pdo, 'daily_booking_limit', (string)$dailyLimit);
      $success = 'Booking policy updated successfully.';
      log_staff_activity($pdo, 'Updated booking policy', null, 'Daily booking limit set to ' . $dailyLimit . '; cancellation cutoff set to ' . cancellation_cutoff_label($cutoffMinutes) . '; no-show grace set to ' . no_show_grace_label($graceMinutes) . '.');
    }
  }
}

$openingHoursStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'opening_hours'");
$openingHoursStmt->execute();
$openingHours = $openingHoursStmt->fetchColumn() ?: '9:30 AM - 10:30 PM';
$cancellationCutoffMinutes = cancellation_cutoff_minutes($pdo);
$cancellationCutoffHours = $cancellationCutoffMinutes / 60;
$noShowGraceMinutes = no_show_grace_minutes($pdo);
$dailyBookingLimit = daily_booking_limit($pdo);

$stateSql = "
    SELECT state_id, state_name
    FROM outlet_states
    WHERE 1=1
";
$stateParams = [];

if (is_array($assignedStateIds)) {
  if (!$assignedStateIds) {
    $stateSql .= ' AND 1=0';
  } else {
    $stateSql .= ' AND state_id IN (' . implode(',', array_fill(0, count($assignedStateIds), '?')) . ')';
    $stateParams = $assignedStateIds;
  }
}

$stateSql .= ' ORDER BY display_order, state_name';
$stateStmt = $pdo->prepare($stateSql);
$stateStmt->execute($stateParams);
$stateOptions = $stateStmt->fetchAll();
$availableStateIds = array_map('intval', array_column($stateOptions, 'state_id'));

if ($filterStateId > 0 && !in_array($filterStateId, $availableStateIds, true)) {
  $filterStateId = 0;
}

$outletSql = "
    SELECT o.outlet_id, o.state_id, o.branch_name, o.location_detail, o.contact_phone, o.map_url, o.is_active, s.state_name
    FROM outlets o
    JOIN outlet_states s ON s.state_id = o.state_id
";
$outletWhere = ['1=1'];
$outletParams = [];

if (is_array($assignedOutletIds)) {
  if (!$assignedOutletIds) {
    $outletWhere[] = '1=0';
  } else {
    $outletWhere[] = 'o.outlet_id IN (' . implode(',', array_fill(0, count($assignedOutletIds), '?')) . ')';
    $outletParams = array_merge($outletParams, $assignedOutletIds);
  }
}

$scopeWhere = $outletWhere;
$scopeParams = $outletParams;

if ($filterSearch !== '') {
  $outletWhere[] = '(o.branch_name LIKE ? OR o.location_detail LIKE ? OR o.contact_phone LIKE ? OR s.state_name LIKE ?)';
  $searchPattern = '%' . $filterSearch . '%';
  array_push($outletParams, $searchPattern, $searchPattern, $searchPattern, $searchPattern);
}

if ($filterStateId > 0) {
  $outletWhere[] = 'o.state_id = ?';
  $outletParams[] = $filterStateId;
}

if ($filterStatus === 'active') {
  $outletWhere[] = 'o.is_active = 1';
} elseif ($filterStatus === 'inactive') {
  $outletWhere[] = 'o.is_active = 0';
}

$outletSql .= ' WHERE ' . implode(' AND ', $outletWhere);
$outletSql .= ' ORDER BY s.display_order, o.branch_name';
$outletStmt = $pdo->prepare($outletSql);
$outletStmt->execute($outletParams);
$outlets = $outletStmt->fetchAll();
$outletTotalStmt = $pdo->prepare(
  "
    SELECT COUNT(*)
    FROM outlets o
    JOIN outlet_states s ON s.state_id = o.state_id
    WHERE " . implode(' AND ', $scopeWhere)
);
$outletTotalStmt->execute($scopeParams);
$totalAccessibleOutlets = (int)$outletTotalStmt->fetchColumn();
$hasOutletFilters = $filterSearch !== '' || $filterStateId > 0 || $filterStatus !== 'all';
$editableStmt = $pdo->prepare('SELECT o.*, s.state_name FROM outlets o JOIN outlet_states s ON s.state_id=o.state_id WHERE ' . implode(' AND ', $scopeWhere) . ' ORDER BY s.display_order, o.branch_name');
$editableStmt->execute($scopeParams);
$editableOutlets = $editableStmt->fetchAll();
$outletIds = array_map('intval', array_column($editableOutlets, 'outlet_id'));

if (($settingsOutletId <= 0 || !in_array($settingsOutletId, $outletIds, true)) && $outletIds) {
  $settingsOutletId = $outletIds[0];
}

$settingsOutlet = null;
foreach ($editableOutlets as $outlet) {
  if ((int)$outlet['outlet_id'] === $settingsOutletId) {
    $settingsOutlet = $outlet;
    break;
  }
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Outlet Settings</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/style.css">
  <style>
    #outlet-list, #edit-outlet-details { scroll-margin-top: 100px; }
  </style>
</head>

<body>
  <?php include 'staff_sidebar.php'; ?>
  <main class="container py-4">
    <a href="dashboard.php" class="btn-nav-icon mb-3"><span class="btn-nav-icon-symbol">&larr;</span><span>Dashboard</span></a>

    <section class="dashboard-panel mb-3 no-print" aria-labelledby="outlet-settings-title">
      <div class="dashboard-section-heading">
        <div>
          <div class="section-title">Outlet Management</div>
          <h3 id="outlet-settings-title" class="mb-0">Branch Setup</h3>
          <p class="muted-small mb-0">
            <?= $isAdmin ? 'Add outlets, update branch details, and manage booking policies.' : 'Add outlets for your assigned state and review your branch list.' ?>
          </p>
        </div>
      </div>

      <?php if ($success): ?>
        <div class="alert alert-success"><?= h($success) ?></div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
      <?php endif; ?>

      <div class="dashboard-subsection mb-4">
        <div class="mb-3">
          <strong>Add New Outlet</strong>
          <p class="muted-small mb-0">
            <?= $isAdmin ? 'Admin can add an outlet under any state.' : 'Shop owners can only add outlets under states already assigned to them.' ?>
          </p>
        </div>

        <?php if ($stateOptions): ?>
          <form method="post" action="outlet_settings.php#outlet-list" class="dashboard-settings-form">
            <input type="hidden" name="dashboard_action" value="create_outlet">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['outlet_csrf']) ?>">
            <input type="hidden" name="outlet_search" value="<?= h($filterSearch) ?>">
            <input type="hidden" name="outlet_state_id" value="<?= h($filterStateId) ?>">
            <input type="hidden" name="outlet_status" value="<?= h($filterStatus) ?>">

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Branch Name</label>
                <input name="branch_name" class="form-control" placeholder="Example: Oriental Kopi New Outlet" required>
              </div>

              <div class="col-md-6">
                <label class="form-label">State / Federal Territory</label>
                <select name="state_id" class="form-select" required>
                  <?php foreach ($stateOptions as $state): ?>
                    <option value="<?= h($state['state_id']) ?>"><?= h($state['state_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label">Location Detail</label>
                <input name="location_detail" class="form-control" placeholder="Mall unit, floor, or street address" required>
              </div>

              <div class="col-md-6">
                <label class="form-label">Contact Number</label>
                <input name="contact_phone" class="form-control" placeholder="018-000 6363">
              </div>

              <div class="col-md-<?= $isAdmin ? '8' : '12' ?>">
                <label class="form-label">Map Link</label>
                <input type="url" name="map_url" class="form-control" placeholder="https://maps.app.goo.gl/...">
              </div>

              <?php if ($isAdmin): ?>
                <div class="col-md-4">
                  <label class="dashboard-check-row mt-4">
                    <input type="checkbox" name="is_active" value="1" checked>
                    <span>Activate immediately</span>
                  </label>
                </div>
              <?php endif; ?>
            </div>

            <?php render_outlet_inventory(table_inventory($pdo, 0), 'new-inventory'); ?>
            <button class="btn btn-dark mt-3">Add Outlet</button>
          </form>
        <?php else: ?>
          <div class="alert alert-info mb-0">No assigned state is available for outlet creation.</div>
        <?php endif; ?>
      </div>

      <div class="dashboard-subsection mb-4" id="outlet-list">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <div>
            <strong><?= $isAdmin ? 'Outlet List' : 'Your Outlets' ?></strong>
            <p class="muted-small mb-0"><?= $isAdmin ? 'Branch locations, contacts, and availability.' : 'New outlets you add will be assigned to your account automatically.' ?></p>
          </div>
          <span class="badge bg-dark">
            <?= h(count($outlets)) ?><?= $hasOutletFilters ? ' of ' . h($totalAccessibleOutlets) : '' ?> outlet(s)
          </span>
        </div>

        <form method="get" action="outlet_settings.php#outlet-list" class="row g-2 align-items-end mb-3">
          <div class="col-lg-5 col-md-6">
            <label class="form-label" for="outletSearch">Search outlet</label>
            <input
              type="search"
              id="outletSearch"
              name="outlet_search"
              class="form-control"
              value="<?= h($filterSearch) ?>"
              placeholder="Branch, location, contact, or state">
          </div>

          <div class="col-lg-3 col-md-3">
            <label class="form-label" for="outletStateFilter">State / Federal Territory</label>
            <select id="outletStateFilter" name="outlet_state_id" class="form-select">
              <option value="0"><?= $isAdmin ? 'All States' : 'All Assigned States' ?></option>
              <?php foreach ($stateOptions as $state): ?>
                <option value="<?= h($state['state_id']) ?>" <?= $filterStateId === (int)$state['state_id'] ? 'selected' : '' ?>>
                  <?= h($state['state_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-lg-2 col-md-3">
            <label class="form-label" for="outletStatusFilter">Status</label>
            <select id="outletStatusFilter" name="outlet_status" class="form-select">
              <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
              <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
              <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
          </div>

          <div class="col-lg-2 col-12">
            <div class="d-flex gap-2">
              <button class="btn btn-dark flex-grow-1" type="submit">Filter</button>
              <a class="btn btn-outline-dark" href="outlet_settings.php#outlet-list">Reset</a>
            </div>
          </div>
        </form>

        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Outlet</th>
                <th>State</th>
                <th>Location</th>
                <th>Contact</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($outlets as $outlet): ?>
                <tr>
                  <td><strong><?= h($outlet['branch_name']) ?></strong></td>
                  <td><?= h($outlet['state_name']) ?></td>
                  <td><?= h($outlet['location_detail']) ?></td>
                  <td><?= h($outlet['contact_phone'] ?: '-') ?></td>
                  <td>
                    <span class="badge bg-<?= (int)$outlet['is_active'] === 1 ? 'success' : 'secondary' ?>">
                      <?= (int)$outlet['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (!$outlets): ?>
                <tr>
                  <td colspan="5" class="text-center text-muted">No outlets found.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if ($isAdmin && $settingsOutlet): ?>
        <div class="dashboard-subsection mb-4" id="edit-outlet-details">
          <div class="mb-3">
            <strong>Edit Outlet Details</strong>
            <p class="muted-small mb-0">Branch information and table inventory for <?= h($settingsOutlet['branch_name']) ?>.</p>
          </div>

          <form method="get" action="outlet_settings.php#edit-outlet-details" class="row g-2 align-items-end mb-4">
            <input type="hidden" name="outlet_search" value="<?= h($filterSearch) ?>">
            <input type="hidden" name="outlet_state_id" value="<?= h($filterStateId) ?>">
            <input type="hidden" name="outlet_status" value="<?= h($filterStatus) ?>">
            <div class="col-md-9">
              <label for="edit-outlet-select" class="form-label">Select outlet to edit</label>
              <select id="edit-outlet-select" name="settings_outlet_id" class="form-select">
                <?php foreach ($editableOutlets as $outlet): ?>
                  <option value="<?= h($outlet['outlet_id']) ?>" <?= $settingsOutletId === (int)$outlet['outlet_id'] ? 'selected' : '' ?>><?= h($outlet['state_name'] . ' - ' . $outlet['branch_name']) ?><?= (int)$outlet['is_active'] === 1 ? '' : ' (Inactive)' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><button class="btn btn-dark w-100">Load Outlet</button></div>
          </form>

          <form method="post" action="outlet_settings.php#edit-outlet-details" class="dashboard-settings-form">
            <input type="hidden" name="dashboard_action" value="update_outlet_settings">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['outlet_csrf']) ?>">
            <input type="hidden" name="outlet_id" value="<?= h($settingsOutlet['outlet_id']) ?>">
            <input type="hidden" name="outlet_search" value="<?= h($filterSearch) ?>">
            <input type="hidden" name="outlet_state_id" value="<?= h($filterStateId) ?>">
            <input type="hidden" name="outlet_status" value="<?= h($filterStatus) ?>">

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Branch Name</label>
                <input name="branch_name" class="form-control" value="<?= h($settingsOutlet['branch_name']) ?>" required>
              </div>

              <div class="col-md-6">
                <label class="form-label">State / Federal Territory</label>
                <select name="state_id" class="form-select" required>
                  <?php foreach ($stateOptions as $state): ?>
                    <option value="<?= h($state['state_id']) ?>" <?= (int)$settingsOutlet['state_id'] === (int)$state['state_id'] ? 'selected' : '' ?>>
                      <?= h($state['state_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label">Location Detail</label>
                <input name="location_detail" class="form-control" value="<?= h($settingsOutlet['location_detail']) ?>" required>
              </div>

              <div class="col-md-6">
                <label class="form-label">Contact Number</label>
                <input name="contact_phone" class="form-control" value="<?= h($settingsOutlet['contact_phone']) ?>">
              </div>

              <div class="col-md-8">
                <label class="form-label">Map Link</label>
                <input type="url" name="map_url" class="form-control" value="<?= h($settingsOutlet['map_url']) ?>">
              </div>

              <div class="col-md-4">
                <label class="form-label">Opening Hours</label>
                <input name="opening_hours" class="form-control" value="<?= h($openingHours) ?>">
              </div>

              <div class="col-12">
                <label class="dashboard-check-row">
                  <input type="checkbox" name="is_active" value="1" <?= (int)$settingsOutlet['is_active'] === 1 ? 'checked' : '' ?>>
                  <span>Outlet is active and available to customers</span>
                </label>
              </div>
            </div>

            <?php render_outlet_inventory(table_inventory($pdo, $settingsOutletId), 'edit-inventory'); ?>
            <div class="d-flex flex-wrap gap-2 mt-3">
              <button class="btn btn-dark">Save Outlet Settings</button>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <?php if ($isAdmin): ?>
        <div class="dashboard-subsection mb-4">
          <div class="mb-3">
            <strong>Booking Policy</strong>
            <p class="muted-small mb-0">
              Customers can cancel until <?= h(cancellation_cutoff_label($cancellationCutoffMinutes)) ?>.
              Staff can mark no-show after <?= h(no_show_grace_label($noShowGraceMinutes)) ?>.
              Each customer may hold <?= h($dailyBookingLimit) ?> active bookings per day, limited to one per meal period.
            </p>
          </div>

          <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="dashboard_action" value="update_booking_policy">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['outlet_csrf']) ?>">

            <div class="col-md-3">
              <label class="form-label">Cancellation cutoff in hours</label>
              <input
                type="number"
                name="cancellation_cutoff_hours"
                class="form-control"
                min="0"
                max="72"
                step="0.5"
                value="<?= h(rtrim(rtrim(number_format($cancellationCutoffHours, 2, '.', ''), '0'), '.')) ?>">
            </div>

            <div class="col-md-3">
              <label class="form-label">No-show grace minutes</label>
              <input
                type="number"
                name="no_show_grace_minutes"
                class="form-control"
                min="0"
                max="120"
                step="1"
                value="<?= h($noShowGraceMinutes) ?>">
            </div>

            <div class="col-md-3">
              <label class="form-label">Maximum bookings per customer per day</label>
              <input
                type="number"
                name="daily_booking_limit"
                class="form-control"
                min="1"
                max="10"
                step="1"
                value="<?= h($dailyBookingLimit) ?>">
            </div>

            <div class="col-md-3">
              <button class="btn btn-dark w-100">Save Policy</button>
            </div>

            <div class="col-12">
              <p class="muted-small mb-0">
                Pending and confirmed bookings count. Cancelled, declined, and completed bookings do not count.
                Customers may hold only one booking in each breakfast, lunch, or dinner period.
              </p>
            </div>
          </form>
        </div>

      <?php endif; ?>
    </section>
  </main>
</body>

</html>
