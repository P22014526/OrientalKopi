<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/email_verification.php';
require_once 'includes/mailer.php';
prevent_dynamic_page_cache();
ensure_email_verification_table($pdo);
$userId = (int)($_SESSION['pending_verification_user_id'] ?? 0);
if (!$userId || !registration_verification_pending($pdo, $userId)) {
    unset($_SESSION['pending_verification_user_id']);
    header('Location: login.php');
    exit;
}
$stmt = $pdo->prepare('SELECT email FROM users WHERE user_id=?');
$stmt->execute([$userId]);
$email = (string)$stmt->fetchColumn();
$error = $_SESSION['verification_send_error'] ?? '';
$message = !empty($_SESSION['verification_sent']) ? 'Verification code sent. Check your inbox and spam folder.' : '';
unset($_SESSION['verification_send_error'], $_SESSION['verification_sent']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = '';
    if (!registration_csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif (($_POST['action'] ?? '') === 'resend') {
        $error = send_registration_code($pdo, $userId);
        if ($error === '') $message = 'A new code has been sent. Only the latest code will work.';
    } else {
        $error = verify_registration_code($pdo, $userId, trim((string)($_POST['code'] ?? '')));
        if ($error === '') {
            unset($_SESSION['pending_verification_user_id'], $_SESSION['registration_csrf']);
            $_SESSION['email_verified_notice'] = true;
            session_regenerate_id(true);
            header('Location: login.php');
            exit;
        }
    }
}
include 'includes/header.php';
?>
<div class="row justify-content-center">
  <div class="col-12 col-md-7 col-lg-5">
    <div class="card ok-card p-4">
      <h3>Verify Your Email</h3>
      <p class="text-muted">Confirm your email to activate your account.</p>
      <p class="fw-semibold text-break"><?= h($email) ?></p>
      <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>
      <?php if ($message): ?><div class="alert alert-success" role="status"><?= h($message) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(registration_csrf_token()) ?>">
        <label for="code" class="form-label">Verification Code</label>
        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" class="form-control mb-2" aria-describedby="code-help" required>
        <p id="code-help" class="small text-muted">Six digits. Expires after 10 minutes.</p>
        <button class="btn btn-dark w-100" name="action" value="verify">Verify Email</button>
      </form>
      <form method="post" class="mt-3">
        <input type="hidden" name="csrf" value="<?= h(registration_csrf_token()) ?>">
        <button class="btn btn-outline-dark w-100" name="action" value="resend">Resend Code</button>
      </form>
      <p class="small text-muted mt-2">Resend once per minute, up to five times per hour.</p>
      <a href="login.php" class="btn btn-outline-secondary w-100">Back to Login</a>
    </div>
  </div>
</div>
<?php include 'includes/footer.php'; ?>
