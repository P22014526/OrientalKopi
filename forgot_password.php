<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/mailer.php';

$email = '';
$error = '';
$message = '';

[$mailReady, $mailStatusMessage] = mail_service_status();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!$mailReady) {
        $error = 'The password reset email service is not configured yet.';
    } else {
        /*
         * Keep this response identical whether or not the account exists.
         */
        $message = 'If an account exists for this email, a password reset code has been sent.';

        $stmt = $pdo->prepare("
            SELECT user_id, full_name, email
            FROM users
            WHERE email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $now = date('Y-m-d H:i:s');

            /* Allow only one reset email per account per minute. */
            $rateLimit = $pdo->prepare("
                SELECT reset_id
                FROM password_reset_tokens
                WHERE user_id = ?
                  AND created_at >= ?
                LIMIT 1
            ");
            $rateLimit->execute([
                $user['user_id'],
                date('Y-m-d H:i:s', time() - 60)
            ]);

            if (!$rateLimit->fetch()) {
                $plainCode = (string)random_int(100000, 999999);
                $tokenHash = hash('sha256', $user['user_id'] . ':' . $plainCode);
                $expiresAt = date('Y-m-d H:i:s', time() + 1800);
                $requestedIp = $_SERVER['REMOTE_ADDR'] ?? null;

                $insert = $pdo->prepare("
                    INSERT INTO password_reset_tokens (
                        user_id,
                        token_hash,
                        expires_at,
                        requested_ip
                    )
                    VALUES (?, ?, ?, ?)
                ");

                $insert->execute([
                    $user['user_id'],
                    $tokenHash,
                    $expiresAt,
                    $requestedIp
                ]);

                $newResetId = (int)$pdo->lastInsertId();

                try {
                    send_password_reset_code_email(
                        $user['email'],
                        $user['full_name'],
                        $plainCode
                    );

                    /* Invalidate older unused reset codes after the new email succeeds. */
                    $invalidate = $pdo->prepare("
                        UPDATE password_reset_tokens
                        SET used_at = ?
                        WHERE user_id = ?
                          AND reset_id <> ?
                          AND used_at IS NULL
                    ");
                    $invalidate->execute([
                        $now,
                        $user['user_id'],
                        $newResetId
                    ]);
                } catch (Throwable $e) {
                    $pdo->prepare("
                        DELETE FROM password_reset_tokens
                        WHERE reset_id = ?
                    ")->execute([$newResetId]);

                    error_log('Password reset code email failed: ' . $e->getMessage());

                    $message = '';
                    $error = 'Email could not be sent: ' . $e->getMessage();
                }
            }
        }
    }
}

include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card ok-card p-4">
            <div class="section-title">Account Recovery</div>
            <h3>Forgot Password</h3>

            <p class="muted-small">
                Enter the email address registered to your account. A temporary verification code will be sent by email.
            </p>

            <?php if (!$mailReady): ?>
                <div class="alert alert-warning">
                    <strong>Setup required:</strong><br>
                    <?= h($mailStatusMessage) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="alert alert-success">
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <label for="resetEmail" class="form-label">
                    Registered Email
                </label>

                <input
                    id="resetEmail"
                    name="email"
                    type="email"
                    class="form-control mb-3"
                    value="<?= h($email) ?>"
                    placeholder="Enter your email"
                    autocomplete="email"
                    required>

                <button
                    class="btn btn-dark w-100"
                    <?= !$mailReady ? 'disabled' : '' ?>>
                    Send Verification Code
                </button>
            </form>

            <?php if ($message): ?>
                <a
                    href="reset_password.php?email=<?= h(urlencode($email)) ?>"
                    class="btn btn-gold w-100 mt-3">
                    Enter Verification Code
                </a>
            <?php endif; ?>

            <p class="text-center mt-3 mb-0">
                <a href="login.php" class="btn btn-outline-dark">Return to Login</a>
            </p>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
