<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/malaysian_phone.php';

require_login();
if (empty($_SESSION['profile_csrf'])) $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));

$success = '';
$error = '';

$stmt = $pdo->prepare("SELECT full_name, email, phone FROM users WHERE user_id=?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $fullName = trim($_POST['full_name'] ?? '');
  $phone = submitted_malaysian_phone($_POST);

  if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['profile_csrf'], $_POST['csrf'])) {
    $error = 'Your session expired. Please refresh and try again.';
  } elseif ($fullName === '') {
    $error = 'Full name is required.';
  } elseif (normalize_malaysian_mobile($phone) === null) {
    $error = MY_PHONE_ERROR;
  } else {
    $stmt = $pdo->prepare("UPDATE users SET full_name=?, phone=? WHERE user_id=?");
    $stmt->execute([$fullName, normalize_malaysian_mobile($phone), $_SESSION['user_id']]);

    $_SESSION['full_name'] = $fullName;

    $success = 'Profile updated successfully.';

    $stmt = $pdo->prepare("SELECT full_name, email, phone FROM users WHERE user_id=?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
  }
}

include 'includes/header.php';
?>


<div class="row justify-content-center">
  <div class="col-md-7">
    <div class="card ok-card p-4">
      <div class="section-title">Customer Profile</div>
      <h3>Edit Profile</h3>

      <?php if ($success): ?>
        <div class="alert alert-success"><?= h($success) ?></div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['profile_csrf']) ?>">
        <label class="form-label">Full Name</label>
        <input name="full_name" class="form-control mb-3" value="<?= h($user['full_name']) ?>" required>

        <label class="form-label">Email</label>
        <input class="form-control mb-3" value="<?= h($user['email']) ?>" disabled>

        <?php render_malaysian_phone_field('profile-phone', $error ? $phone : ($user['phone'] ?? '')); ?>

        <button class="btn btn-dark">Save Changes</button>
        <a href="view_profile.php" class="btn btn-outline-dark">View Profile</a>
      </form>
    </div>
  </div>
</div>

<script src="assets/malaysian_phone.js?v=2"></script>
<?php include 'includes/footer.php'; ?>
