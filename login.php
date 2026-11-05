<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/email_verification.php';
ensure_email_verification_table($pdo);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $password = $_POST['password'] ?? '';

  $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
  $stmt->execute([$email]);
  $user = $stmt->fetch();

  if ($user && password_verify($password, $user['password_hash'])) {
    session_regenerate_id(true);
    if (registration_verification_pending($pdo, (int)$user['user_id'])) {
      unset($_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['role']);
      $_SESSION['pending_verification_user_id'] = (int)$user['user_id'];
      header('Location: verify_email.php');
      exit;
    }
    unset($_SESSION['pending_verification_user_id']);
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    unset($_SESSION['selected_state_id'], $_SESSION['selected_outlet_id'], $_SESSION['selected_outlet_name']);

    if ($user['role'] === 'admin' || $user['role'] === 'shop_owner') {
      header('Location: admin/dashboard.php');
    } else {
      header('Location: select_outlet.php');
    }
    exit;
  }

  $error = 'Invalid email or password.';
}

include 'includes/header.php';
?>

<div class="login-page-wrapper">
  <div class="login-image-panel left-panel">
    <div class="login-image-overlay">
      <h4>Oriental Kopi</h4>
      <p>Reserve your seat before arriving.</p>
    </div>
  </div>

  <div class="login-form-panel">
    <div class="card ok-card login-card p-4">
      <div class="text-center mb-3">
        <div class="section-title">Welcome Back</div>
        <h3 class="mb-2">Login</h3>
        <p class="text-muted mb-0">Access your booking account</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
      <?php endif; ?>
      <?php if (!empty($_SESSION['email_verified_notice'])): ?>
        <div class="alert alert-success">Email verified. You can now log in.</div>
        <?php unset($_SESSION['email_verified_notice']); ?>
      <?php endif; ?>

      <form method="post">
        <label class="form-label">Email</label>
        <input name="email" type="email" class="form-control mb-3" placeholder="Enter your email" required>

        <label class="form-label">Password</label>
        <div class="input-group mb-2">
          <input name="password" id="passwordInput" type="password" class="form-control" placeholder="Enter your password" required>
          <button type="button" class="btn btn-outline-dark" id="togglePassword" aria-label="Show password">🙈</button>
        </div>

        <div class="text-end mb-3">
          <a href="forgot_password.php" class="btn btn-outline-dark btn-sm">
            Forgot Password?
          </a>
        </div>

        <button class="btn btn-dark w-100">Login</button>
      </form>

      <p class="mt-3 mb-0 text-center">
        No account? <a href="register.php">Register</a>
      </p>

      <hr>

    </div>
  </div>

  <div class="login-image-panel right-panel">
    <div class="login-image-overlay">
      <h4>Smart Booking</h4>
      <p>Check availability and book with ease.</p>
    </div>
  </div>
</div>

<script>
  document.getElementById('togglePassword').addEventListener('click', function() {
    const input = document.getElementById('passwordInput');

    if (input.type === 'password') {
      input.type = 'text';
      this.textContent = '👁';
      this.setAttribute('aria-label', 'Hide password');
    } else {
      input.type = 'password';
      this.textContent = '🙈';
      this.setAttribute('aria-label', 'Show password');
    }
  });
</script>

<?php include 'includes/footer.php'; ?>
