<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();

$success = '';
$error = '';

$stmt = $pdo->prepare("SELECT full_name, email, phone FROM users WHERE user_id=?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $fullName = trim($_POST['full_name'] ?? '');
  $phone = trim($_POST['phone'] ?? '');

  if ($fullName === '') {
    $error = 'Full name is required.';
  } else {
    $stmt = $pdo->prepare("UPDATE users SET full_name=?, phone=? WHERE user_id=?");
    $stmt->execute([$fullName, $phone, $_SESSION['user_id']]);

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
        <label class="form-label">Full Name</label>
        <input name="full_name" class="form-control mb-3" value="<?= h($user['full_name']) ?>" required>

        <label class="form-label">Email</label>
        <input class="form-control mb-3" value="<?= h($user['email']) ?>" disabled>

        <label class="form-label">Phone</label>
        <input name="phone" class="form-control mb-3" value="<?= h($user['phone']) ?>">

        <button class="btn btn-dark">Save Changes</button>
        <a href="view_profile.php" class="btn btn-outline-dark">View Profile</a>
      </form>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>