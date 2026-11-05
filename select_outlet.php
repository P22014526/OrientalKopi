<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();
prevent_dynamic_page_cache();

if (($_SESSION['role'] ?? '') !== 'customer') {
  header('Location: admin/dashboard.php');
  exit;
}

$states = all_states_with_outlets($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $outletId = (int)($_POST['outlet_id'] ?? 0);

  $stmt = $pdo->prepare("
    SELECT o.outlet_id, o.branch_name, s.state_id, s.state_name
    FROM outlets o
    JOIN outlet_states s ON s.state_id = o.state_id
    WHERE o.outlet_id = ?
      AND o.is_active = 1
    LIMIT 1
  ");
  $stmt->execute([$outletId]);
  $outlet = $stmt->fetch();

  if (!$outlet) {
    $error = 'Please select an available branch.';
  } else {
    $_SESSION['selected_state_id'] = (int)$outlet['state_id'];
    $_SESSION['selected_outlet_id'] = (int)$outlet['outlet_id'];
    $_SESSION['selected_outlet_name'] = $outlet['branch_name'];

    header('Location: index.php');
    exit;
  }
}

include 'includes/header.php';
?>

<div class="outlet-picker">
  <section class="outlet-picker-hero mb-4">
    <div>
      <div class="section-title text-warning">Malaysia Outlets</div>
      <h2>Select Your Oriental Kopi Branch</h2>
      <p>
        Choose your state first, then select the branch where you want to make your reservation.
      </p>
    </div>
  </section>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-4">
      <div class="card ok-card p-4 h-100">
        <div class="section-title">Step 1</div>
        <h4>Choose State</h4>

        <div class="state-choice-list mt-3">
          <?php foreach ($states as $index => $state): ?>
            <button
              type="button"
              class="state-choice <?= $index === 0 ? 'active' : '' ?>"
              data-state-id="<?= h($state['state_id']) ?>">
              <span><?= h($state['state_name']) ?></span>
              <strong><?= h(count($state['outlets'])) ?> outlet(s)</strong>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="card ok-card p-4">
        <div class="section-title">Step 2</div>
        <h4>Choose Branch</h4>

        <form method="post" id="outletSelectForm">
          <?php foreach ($states as $index => $state): ?>
            <div
              class="branch-choice-group <?= $index === 0 ? '' : 'd-none' ?>"
              data-branch-state="<?= h($state['state_id']) ?>">
              <div class="branch-choice-heading">
                <strong><?= h($state['state_name']) ?></strong>
                <span><?= h(count($state['outlets'])) ?> branch(es)</span>
              </div>

              <div class="row g-3">
                <?php foreach ($state['outlets'] as $outlet): ?>
                  <div class="col-md-6">
                    <label class="branch-choice-card">
                      <input
                        type="radio"
                        name="outlet_id"
                        value="<?= h($outlet['outlet_id']) ?>"
                        required>
                      <span>
                        <strong><?= h($outlet['branch_name']) ?></strong>
                        <small><?= h($outlet['location_detail']) ?></small>
                        <?php if (!empty($outlet['contact_phone'])): ?>
                          <small>Phone: <?= h($outlet['contact_phone']) ?></small>
                        <?php endif; ?>
                        <?php if (!empty($outlet['map_url'])): ?>
                          <a
                            href="<?= h($outlet['map_url']) ?>"
                            target="_blank"
                            rel="noopener"
                            class="btn btn-sm btn-outline-dark mt-2"
                            onclick="event.stopPropagation();">
                            View Map
                          </a>
                        <?php endif; ?>
                      </span>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>

          <button class="btn btn-dark mt-4">
            Continue to Dashboard
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    const stateButtons = document.querySelectorAll('[data-state-id]');
    const branchGroups = document.querySelectorAll('[data-branch-state]');

    stateButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const stateId = button.dataset.stateId;

        stateButtons.forEach((item) => item.classList.toggle('active', item === button));
        branchGroups.forEach((group) => {
          const isCurrent = group.dataset.branchState === stateId;
          group.classList.toggle('d-none', !isCurrent);

          if (!isCurrent) {
            group.querySelectorAll('input[type="radio"]').forEach((input) => {
              input.checked = false;
            });
          }
        });
      });
    });
  });
</script>

<?php include 'includes/footer.php'; ?>
