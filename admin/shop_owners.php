<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/shop_owner_management.php';
require_admin();
prevent_dynamic_page_cache();
if (empty($_SESSION['owner_csrf'])) $_SESSION['owner_csrf'] = bin2hex(random_bytes(32));
$error = '';
$success = $_SESSION['owner_success'] ?? '';
unset($_SESSION['owner_success']);
$editId = max(0, (int)($_GET['edit_id'] ?? 0));
$showForm = $editId > 0 || isset($_GET['new']);
$form = ['full_name'=>'','email'=>'','phone'=>'','outlet_ids'=>[]];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = max(0, (int)($_POST['owner_id'] ?? 0));
    $showForm = true;
    foreach (['full_name','email','phone'] as $field) $form[$field] = trim((string)($_POST[$field] ?? ''));
    $form['outlet_ids'] = is_array($_POST['outlet_ids'] ?? null) ? array_map('intval', $_POST['outlet_ids']) : [];
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['owner_csrf'], $_POST['csrf'])) {
        $error = 'Your session expired. Please refresh and try again.';
    } else {
        try {
            $savedId = save_shop_owner($pdo, $editId, $_POST);
            $_SESSION['owner_success'] = $editId ? 'Shop owner details and outlet assignments updated.' : 'Shop owner account created.';
            header('Location: shop_owners.php?edit_id=' . $savedId . '#owner-form');
            exit;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Shop owner save failed: ' . $e->getMessage());
            $error = 'Unable to save the account. Check that the email is not already registered and try again.';
        }
    }
} elseif ($editId) {
    $stmt = $pdo->prepare("SELECT full_name,email,phone FROM users WHERE user_id=? AND role='shop_owner'");
    $stmt->execute([$editId]);
    $owner = $stmt->fetch();
    if (!$owner) {
        $error = 'Shop owner account not found.';
        $showForm = false;
    } else {
        $form = array_merge($form,$owner);
        $stmt = $pdo->prepare('SELECT outlet_id FROM shop_owner_outlets WHERE user_id=?');
        $stmt->execute([$editId]);
        $form['outlet_ids'] = array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
$outlets = $pdo->query('SELECT o.outlet_id,o.branch_name,o.contact_phone,o.location_detail,o.is_active,s.state_id,s.state_name FROM outlets o JOIN outlet_states s ON s.state_id=o.state_id ORDER BY s.display_order,o.branch_name')->fetchAll();
$states = [];
foreach ($outlets as $outlet) $states[$outlet['state_name']][] = $outlet;
$owners = $pdo->query("SELECT user_id,full_name,email,phone FROM users WHERE role='shop_owner' ORDER BY full_name")->fetchAll();
$assignments = [];
$rows = $pdo->query('SELECT a.user_id,o.branch_name,o.contact_phone,o.location_detail,o.is_active,s.state_name FROM shop_owner_outlets a JOIN outlets o ON o.outlet_id=a.outlet_id JOIN outlet_states s ON s.state_id=o.state_id ORDER BY s.display_order,o.branch_name')->fetchAll();
foreach ($rows as $row) $assignments[(int)$row['user_id']][] = $row;
?>
<!doctype html>
<html lang="en">
<head>
 <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
 <title>Shop Owner Accounts</title>
 <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
 <link rel="stylesheet" href="../assets/style.css">
 <link rel="stylesheet" href="../assets/shop_owners.css">
</head>
<body>
<?php include 'staff_sidebar.php'; ?>
<main class="container py-4 owner-management">
 <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
  <h2 class="mb-0">Shop Owner Accounts</h2><a href="shop_owners.php?new=1#owner-form" class="btn btn-dark">Add Shop Owner</a>
 </div>
 <?php if ($success): ?><div class="alert alert-success" role="status"><?= h($success) ?></div><?php endif; ?>
 <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>
 <?php if ($showForm): ?>
 <section id="owner-form" class="border-top border-bottom py-4 mb-4">
  <h3><?= $editId ? 'Edit Shop Owner' : 'New Shop Owner' ?></h3>
  <div class="owner-section-label">Account Details</div>
  <form method="post" action="shop_owners.php#owner-form">
   <input type="hidden" name="csrf" value="<?= h($_SESSION['owner_csrf']) ?>">
   <input type="hidden" name="owner_id" value="<?= $editId ?>">
   <div class="row g-3 mb-4">
    <div class="col-md-6"><label for="owner-name" class="form-label">Full Name</label><input id="owner-name" name="full_name" class="form-control" maxlength="100" value="<?= h($form['full_name']) ?>" required></div>
    <div class="col-md-6"><label for="owner-email" class="form-label">Login Email</label><input id="owner-email" name="email" type="email" class="form-control" maxlength="120" value="<?= h($form['email']) ?>" required></div>
    <div class="col-md-6"><label for="owner-phone" class="form-label">Owner's Personal Phone (optional)</label><input id="owner-phone" name="phone" type="tel" class="form-control" maxlength="30" value="<?= h($form['phone'] ?? '') ?>"></div>
    <div class="col-md-6"><label class="form-label">Role</label><div class="form-control bg-light">Shop Owner</div></div>
    <div class="col-md-6"><label for="owner-password" class="form-label"><?= $editId ? 'New Password (optional)' : 'Password' ?></label><input id="owner-password" name="password" type="password" class="form-control" autocomplete="new-password" minlength="8" maxlength="72" <?= $editId ? '' : 'required' ?>><div class="small text-muted mt-1">8-72 characters, including an uppercase letter and special character.</div></div>
    <div class="col-md-6"><label for="owner-confirm" class="form-label">Confirm Password</label><input id="owner-confirm" name="confirm_password" type="password" class="form-control" autocomplete="new-password" maxlength="72" <?= $editId ? '' : 'required' ?>></div>
   </div>
   <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><h4 class="mb-0">Outlet Access</h4><span id="selection-count" class="badge bg-success" aria-live="polite"></span></div>
   <label for="outlet-search" class="form-label">Find an Outlet</label>
   <input type="search" id="outlet-search" class="form-control mb-3" placeholder="Branch or state">
   <p id="outlet-empty" class="text-muted" hidden>No matching outlets.</p>
   <div class="row g-3 mb-4 owner-outlet-groups">
    <?php foreach ($states as $stateName=>$branches): ?>
     <?php $stateOpen = (bool)array_intersect(array_column($branches,'outlet_id'), $form['outlet_ids']); $panelId = 'state-outlets-' . (int)$branches[0]['state_id']; ?>
     <div class="col-12 owner-state" data-state="<?= h($stateName) ?>"><div class="owner-disclosure">
      <div class="owner-disclosure-heading"><button type="button" class="owner-expand" aria-expanded="<?= $stateOpen ? 'true' : 'false' ?>" aria-controls="<?= h($panelId) ?>" data-label="<?= h($stateName) ?> outlets" aria-label="<?= $stateOpen ? 'Collapse' : 'Expand' ?> <?= h($stateName) ?> outlets" title="<?= $stateOpen ? 'Collapse' : 'Expand' ?> outlets"></button><div class="owner-disclosure-text"><strong><?= h($stateName) ?></strong><span class="state-count small text-muted"></span></div></div>
      <fieldset id="<?= h($panelId) ?>" class="pt-3" <?= $stateOpen ? '' : 'hidden' ?>><legend class="visually-hidden"><?= h($stateName) ?> outlets</legend>
      <div class="form-check mb-2"><input type="checkbox" class="form-check-input state-toggle" id="state-<?= (int)$branches[0]['state_id'] ?>"><label class="form-check-label small" for="state-<?= (int)$branches[0]['state_id'] ?>">All Listed Outlets</label></div>
      <div class="owner-branches-grid owner-access-grid">
      <?php foreach ($branches as $branch): ?>
       <div class="owner-outlet-option" data-branch="<?= h($branch['branch_name']) ?>">
        <div class="form-check"><input type="checkbox" class="form-check-input outlet-choice" name="outlet_ids[]" id="outlet-<?= (int)$branch['outlet_id'] ?>" value="<?= (int)$branch['outlet_id'] ?>" <?= in_array((int)$branch['outlet_id'],$form['outlet_ids'],true) ? 'checked' : '' ?>><label class="form-check-label fw-semibold" for="outlet-<?= (int)$branch['outlet_id'] ?>"><?= h($branch['branch_name']) ?><?= !$branch['is_active'] ? ' (Inactive)' : '' ?></label></div>
        <div class="owner-branch-meta"><span class="small text-muted"><?= h($branch['location_detail']) ?></span>
        <?php if (!empty($branch['contact_phone'])): ?><a class="owner-branch-phone" href="tel:<?= h(phone_href($branch['contact_phone'])) ?>"><?= h($branch['contact_phone']) ?></a><?php else: ?><span class="small text-muted">Branch phone not set</span><?php endif; ?></div>
       </div>
      <?php endforeach; ?>
      </div>
     </fieldset></div></div>
    <?php endforeach; ?>
   </div>
   <div class="d-flex flex-wrap gap-2 owner-save-bar"><button class="btn btn-dark"><?= $editId ? 'Save Changes' : 'Create Account' ?></button><a href="shop_owners.php" class="btn btn-outline-dark">Cancel</a></div>
  </form>
 </section>
 <?php endif; ?>
 <div class="owner-directory-heading"><h3 class="mb-0">Accounts <span class="small text-muted">(<?= count($owners) ?>)</span></h3><div><label for="owner-search" class="visually-hidden">Search accounts</label><input id="owner-search" type="search" class="form-control" placeholder="Name, email, branch or state"></div></div>
 <div class="table-responsive"><table class="table table-hover align-middle owner-directory">
  <thead><tr><th>Shop owner</th><th>Login email</th><th>Branches &amp; contacts</th><th>Action</th></tr></thead>
  <tbody><?php foreach ($owners as $owner): ?><tr class="owner-row">
   <td data-label="Shop Owner"><strong><?= h($owner['full_name']) ?></strong></td><td data-label="Login Email" class="text-break"><?= h($owner['email']) ?></td>
   <td data-label="Branches & Contact Numbers">
    <?php if (empty($assignments[$owner['user_id']])): ?><span class="text-muted">No outlet access</span><?php endif; ?>
    <?php if (!empty($assignments[$owner['user_id']])): $branchCount = count($assignments[$owner['user_id']]); $panelId = 'owner-branches-' . (int)$owner['user_id']; ?><div class="owner-assigned owner-disclosure"><div class="owner-disclosure-heading"><button type="button" class="owner-expand" aria-expanded="false" aria-controls="<?= h($panelId) ?>" data-label="<?= h($owner['full_name']) ?> branches" aria-label="Expand <?= h($owner['full_name']) ?> branches" title="Expand branches"></button><div class="owner-disclosure-text"><strong><?= $branchCount ?> <?= $branchCount === 1 ? 'outlet' : 'outlets' ?></strong><span><?= h(implode(', ',array_unique(array_column($assignments[$owner['user_id']],'state_name')))) ?></span></div></div></div><?php endif; ?>
   </td>
   <td data-label="Action"><a class="btn btn-sm btn-outline-dark" aria-label="Edit <?= h($owner['full_name']) ?>" href="shop_owners.php?edit_id=<?= (int)$owner['user_id'] ?>#owner-form">Edit Account</a></td>
  </tr>
  <?php if (!empty($assignments[$owner['user_id']])): ?>
   <tr id="<?= h($panelId) ?>" class="owner-branches-row" hidden><td colspan="4">
    <div class="owner-branches-grid">
     <?php foreach ($assignments[$owner['user_id']] as $branch): ?><div class="owner-assigned-branch"><strong><?= h($branch['branch_name']) ?></strong><div class="small text-muted"><?= h($branch['state_name']) ?> - <?= h($branch['location_detail']) ?><?= !$branch['is_active'] ? ' (Inactive)' : '' ?></div>
      <?php if (!empty($branch['contact_phone'])): ?><a class="owner-branch-phone" href="tel:<?= h(phone_href($branch['contact_phone'])) ?>"><?= h($branch['contact_phone']) ?></a><?php else: ?><span class="small text-muted">Branch phone not set</span><?php endif; ?>
     </div><?php endforeach; ?>
    </div>
   </td></tr>
  <?php endif; ?>
  <?php endforeach; ?>
  <?php if (!$owners): ?><tr><td colspan="4" class="text-muted text-center">No shop owner accounts.</td></tr><?php endif; ?>
  </tbody>
 </table></div>
 <p id="owner-empty" class="text-muted py-3" hidden>No matching accounts.</p>
</main>
<script>
 function setOwnerExpanded(button, expanded) {
  button.setAttribute('aria-expanded', String(expanded));
  button.setAttribute('aria-label', `${expanded ? 'Collapse' : 'Expand'} ${button.dataset.label}`);
  button.title = button.getAttribute('aria-label');
  document.getElementById(button.getAttribute('aria-controls')).hidden = !expanded;
 }
 document.querySelectorAll('.owner-expand').forEach(button => {
  button.addEventListener('click', () => setOwnerExpanded(button, button.getAttribute('aria-expanded') !== 'true'));
 });
 document.querySelectorAll('fieldset').forEach(group => {
  const toggle = group.querySelector('.state-toggle');
  const outlets = [...group.querySelectorAll('.outlet-choice')];
  const sync = () => {
   const count = outlets.filter(input => input.checked).length;
   toggle.checked = count === outlets.length;
   toggle.indeterminate = count > 0 && count < outlets.length;
   group.closest('.owner-state').querySelector('.state-count').textContent = `${count} / ${outlets.length} selected`;
   document.getElementById('selection-count').textContent = `${document.querySelectorAll('.outlet-choice:checked').length} outlets selected`;
  };
  toggle.addEventListener('change', () => { outlets.forEach(input => { input.checked = toggle.checked; }); sync(); });
  outlets.forEach(input => input.addEventListener('change',sync));
  sync();
 });
 document.getElementById('outlet-search')?.addEventListener('input', event => {
  const query = event.target.value.trim().toLowerCase();
  let visible = 0;
  document.querySelectorAll('.owner-state').forEach(group => {
   const rows = [...group.querySelectorAll('.owner-outlet-option')];
   rows.forEach(row => { row.hidden = !`${group.dataset.state} ${row.dataset.branch}`.toLowerCase().includes(query); });
   group.hidden = rows.every(row => row.hidden);
   if (!group.hidden) visible++;
   if (query && !group.hidden) setOwnerExpanded(group.querySelector('.owner-expand'), true);
   group.querySelector('.state-toggle').disabled = query !== '';
  });
  document.getElementById('outlet-empty').hidden = visible > 0;
 });
 document.getElementById('owner-search').addEventListener('input', event => {
  const query = event.target.value.trim().toLowerCase();
  const rows = [...document.querySelectorAll('.owner-row')];
  rows.forEach(row => {
   const button = row.querySelector('.owner-expand');
   const panel = button ? document.getElementById(button.getAttribute('aria-controls')) : null;
   row.hidden = !(row.textContent + (panel?.textContent ?? '')).toLowerCase().includes(query);
   if (panel) panel.hidden = row.hidden || button.getAttribute('aria-expanded') !== 'true';
  });
  document.getElementById('owner-empty').hidden = rows.some(row => !row.hidden);
 });
</script>
</body>
</html>
