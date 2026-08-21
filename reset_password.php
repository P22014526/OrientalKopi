<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/mailer.php';

$email = strtolower(trim($_POST['email'] ?? $_GET['email'] ?? ''));
$code = preg_replace('/\s+/', '', trim($_POST['verification_code'] ?? ''));
$error = '';
$success = '';

function find_valid_reset_code(PDO $pdo, string $email, string $plainCode): ?array
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    if (!preg_match('/^\d{6}$/', $plainCode)) {
        return null;
    }

    $userStmt = $pdo->prepare("
        SELECT user_id, full_name, email
        FROM users
        WHERE LOWER(email) = LOWER(?)
        LIMIT 1
    ");
    $userStmt->execute([$email]);
    $user = $userStmt->fetch();

    if (!$user) {
        return null;
    }

    $tokenHash = hash('sha256', $user['user_id'] . ':' . $plainCode);
    $now = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        SELECT
            prt.reset_id,
            prt.user_id,
            prt.expires_at,
            u.full_name,
            u.email
        FROM password_reset_tokens prt
        JOIN users u
          ON u.user_id = prt.user_id
        WHERE prt.user_id = ?
          AND prt.token_hash = ?
          AND prt.used_at IS NULL
          AND prt.expires_at > ?
        ORDER BY prt.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([
        $user['user_id'],
        $tokenHash,
        $now
    ]);
    $row = $stmt->fetch();

    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $resetRequest = find_valid_reset_code($pdo, $email, $code);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter the email address registered to your account.';
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $error = 'Please enter the 6-digit verification code from your email.';
    } elseif (!$resetRequest) {
        $error = 'This verification code is invalid, expired, or has already been used.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must contain at least 8 characters.';
    } elseif (preg_match('/[A-Z]/', $password) !== 1) {
        $error = 'Password must contain at least one uppercase letter.';
    } elseif (preg_match('/[^A-Za-z0-9\s]/', $password) !== 1) {
        $error = 'Password must contain at least one special character.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $now = date('Y-m-d H:i:s');
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE users
                SET password_hash = ?
                WHERE user_id = ?
            ");
            $stmt->execute([
                $passwordHash,
                $resetRequest['user_id']
            ]);

            $stmt = $pdo->prepare("
                UPDATE password_reset_tokens
                SET used_at = ?
                WHERE user_id = ?
                  AND used_at IS NULL
            ");
            $stmt->execute([
                $now,
                $resetRequest['user_id']
            ]);

            $pdo->commit();
            $success = 'Your password has been reset successfully. You may now log in using the new password.';

            try {
                [$mailReady] = mail_service_status();

                if ($mailReady) {
                    send_password_changed_email(
                        $resetRequest['email'],
                        $resetRequest['full_name']
                    );
                }
            } catch (Throwable $e) {
                error_log('Password changed confirmation email failed: ' . $e->getMessage());
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Password reset failed: ' . $e->getMessage());
            $error = 'The password could not be reset. Please try again.';
        }
    }
}

include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card ok-card p-4">
            <div class="section-title">Account Recovery</div>
            <h3>Create New Password</h3>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?= h($success) ?>
                </div>

                <a href="login.php" class="btn btn-dark w-100">
                    Go to Login
                </a>
            <?php else: ?>
                <p class="muted-small">
                    Enter the code from your email and choose a new password.
                </p>

                <form method="post" id="resetPasswordForm">
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

                    <label for="verificationCode" class="form-label">
                        Verification Code
                    </label>

                    <input
                        id="verificationCode"
                        name="verification_code"
                        type="text"
                        inputmode="numeric"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        class="form-control mb-3"
                        value="<?= h($code) ?>"
                        placeholder="Enter 6-digit code"
                        autocomplete="one-time-code"
                        required>

                    <label for="newPassword" class="form-label">
                        New Password
                    </label>

                    <input
                        id="newPassword"
                        name="password"
                        type="password"
                        class="form-control mb-2"
                        minlength="8"
                        autocomplete="new-password"
                        required>

                    <div class="border rounded bg-light p-3 mb-3">
                        <div class="small fw-bold mb-1">
                            Password requirements:
                        </div>

                        <ul class="small mb-0 ps-3">
                            <li id="resetLength" class="text-danger">
                                At least 8 characters
                            </li>
                            <li id="resetUppercase" class="text-danger">
                                At least 1 uppercase letter
                            </li>
                            <li id="resetSpecial" class="text-danger">
                                At least 1 special character
                            </li>
                        </ul>
                    </div>

                    <label for="confirmNewPassword" class="form-label">
                        Confirm New Password
                    </label>

                    <input
                        id="confirmNewPassword"
                        name="confirm_password"
                        type="password"
                        class="form-control mb-2"
                        minlength="8"
                        autocomplete="new-password"
                        required>

                    <div
                        id="resetMatchMessage"
                        class="small text-danger mb-3 d-none">
                        Passwords do not match.
                    </div>

                    <button class="btn btn-dark w-100">
                        Reset Password
                    </button>
                </form>

                <p class="text-center mt-3 mb-0">
                    <a href="forgot_password.php" class="btn btn-outline-dark">
                        Request New Code
                    </a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$success): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('newPassword');
            const confirmInput = document.getElementById('confirmNewPassword');
            const lengthItem = document.getElementById('resetLength');
            const uppercaseItem = document.getElementById('resetUppercase');
            const specialItem = document.getElementById('resetSpecial');
            const matchMessage = document.getElementById('resetMatchMessage');

            function updateItem(element, valid) {
                element.classList.toggle('text-success', valid);
                element.classList.toggle('text-danger', !valid);
            }

            function validateMatch() {
                const hasConfirmation = confirmInput.value !== '';
                const matches = passwordInput.value === confirmInput.value;

                confirmInput.setCustomValidity(
                    !hasConfirmation || matches ? '' : 'Passwords do not match.'
                );

                matchMessage.classList.toggle(
                    'd-none',
                    !hasConfirmation || matches
                );
            }

            function validatePassword() {
                const value = passwordInput.value;
                const validLength = value.length >= 8;
                const validUppercase = /[A-Z]/.test(value);
                const validSpecial = /[^A-Za-z0-9\s]/.test(value);

                updateItem(lengthItem, validLength);
                updateItem(uppercaseItem, validUppercase);
                updateItem(specialItem, validSpecial);

                passwordInput.setCustomValidity(
                    validLength && validUppercase && validSpecial ?
                    '' :
                    'Use at least 8 characters, one uppercase letter, and one special character.'
                );

                validateMatch();
            }

            passwordInput.addEventListener('input', validatePassword);
            confirmInput.addEventListener('input', validateMatch);
            validatePassword();
        });
    </script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
