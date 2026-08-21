<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$error = '';

$name = '';
$email = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['full_name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $password = $_POST['password'] ?? '';
  $confirm = $_POST['confirm_password'] ?? '';

  $hasMinimumLength = strlen($password) >= 8;
  $hasUppercase = preg_match('/[A-Z]/', $password) === 1;
  $hasSpecialCharacter =
    preg_match('/[^A-Za-z0-9\s]/', $password) === 1;

  if (!$hasMinimumLength) {
    $error = 'Password must contain at least 8 characters.';
  } elseif (!$hasUppercase) {
    $error = 'Password must contain at least one uppercase letter.';
  } elseif (!$hasSpecialCharacter) {
    $error = 'Password must contain at least one special character.';
  } elseif ($password !== $confirm) {
    $error = 'Passwords do not match.';
  } else {
    $checkEmail = $pdo->prepare("
      SELECT COUNT(*)
      FROM users
      WHERE LOWER(email) = LOWER(?)
    ");
    $checkEmail->execute([$email]);

    if ((int)$checkEmail->fetchColumn() > 0) {
      $error = 'Email already exists. Please use another email or login.';
    }
  }

  if ($error === '') {
    try {
      $stmt = $pdo->prepare("
                INSERT INTO users (
                    full_name,
                    email,
                    phone,
                    password_hash,
                    role
                )
                VALUES (?, ?, ?, ?, 'customer')
            ");

      $stmt->execute([
        $name,
        $email,
        $phone,
        password_hash($password, PASSWORD_DEFAULT)
      ]);

      header('Location: login.php');
      exit;
    } catch (PDOException $e) {
      $error = 'Email already exists.';
    }
  }
}

include 'includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-md-6">
    <div class="card ok-card p-4">
      <h3 class="mb-3">Register Account</h3>

      <?php if ($error): ?>
        <div class="alert alert-danger">
          <?= h($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" id="registerForm">
        <input
          name="full_name"
          class="form-control mb-3"
          placeholder="Full Name"
          value="<?= h($name) ?>"
          autocomplete="name"
          required>

        <input
          id="emailInput"
          name="email"
          type="email"
          class="form-control"
          placeholder="Email"
          value="<?= h($email) ?>"
          autocomplete="email"
          required>

        <div
          id="emailAvailabilityMessage"
          class="small mb-3"
          aria-live="polite">
        </div>

        <input
          name="phone"
          class="form-control mb-3"
          placeholder="Phone"
          value="<?= h($phone) ?>"
          autocomplete="tel"
          required>

        <label for="password" class="form-label">
          Password
        </label>

        <div class="input-group">
          <input
            id="password"
            name="password"
            type="password"
            class="form-control"
            placeholder="Password"
            minlength="8"
            autocomplete="new-password"
            aria-describedby="passwordRequirements"
            required>
          <button
            type="button"
            class="btn btn-outline-dark password-toggle"
            data-password-target="password"
            aria-label="Show password">
            🙈
          </button>
        </div>

        <div
          id="passwordRequirements"
          class="border rounded p-3 mt-2 mb-3 bg-light">
          <div class="small fw-bold mb-1">
            Password requirements:
          </div>

          <ul class="small mb-0 ps-3">
            <li id="lengthRequirement" class="text-danger">
              At least 8 characters
            </li>
            <li id="uppercaseRequirement" class="text-danger">
              At least 1 uppercase letter
            </li>
            <li id="specialRequirement" class="text-danger">
              At least 1 special character
            </li>
          </ul>
        </div>

        <label for="confirmPassword" class="form-label">
          Confirm Password
        </label>

        <div class="input-group mb-2">
          <input
            id="confirmPassword"
            name="confirm_password"
            type="password"
            class="form-control"
            placeholder="Confirm Password"
            minlength="8"
            autocomplete="new-password"
            required>
          <button
            type="button"
            class="btn btn-outline-dark password-toggle"
            data-password-target="confirmPassword"
            aria-label="Show confirm password">
            🙈
          </button>
        </div>

        <div
          id="passwordMatchMessage"
          class="small text-danger mb-3 d-none">
          Passwords do not match.
        </div>

        <p>Role: Customer</p>

        <button class="btn btn-dark w-100">
          Create Account
        </button>
      </form>

      <p class="mt-3 mb-0">
        Already have an account?
        <a href="login.php">Login</a>
      </p>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    const passwordInput =
      document.getElementById('password');

    const emailInput =
      document.getElementById('emailInput');

    const emailAvailabilityMessage =
      document.getElementById('emailAvailabilityMessage');

    const confirmInput =
      document.getElementById('confirmPassword');

    const lengthRequirement =
      document.getElementById('lengthRequirement');

    const uppercaseRequirement =
      document.getElementById('uppercaseRequirement');

    const specialRequirement =
      document.getElementById('specialRequirement');

    const passwordMatchMessage =
      document.getElementById('passwordMatchMessage');

    function updateRequirement(element, passed) {
      element.classList.toggle('text-success', passed);
      element.classList.toggle('text-danger', !passed);
      element.textContent =
        (passed ? '✓ ' : '') +
        element.textContent.replace(/^✓\s*/, '');
    }

    function validatePassword() {
      const password = passwordInput.value;

      const hasMinimumLength =
        password.length >= 8;

      const hasUppercase =
        /[A-Z]/.test(password);

      const hasSpecialCharacter =
        /[^A-Za-z0-9\s]/.test(password);

      updateRequirement(
        lengthRequirement,
        hasMinimumLength
      );

      updateRequirement(
        uppercaseRequirement,
        hasUppercase
      );

      updateRequirement(
        specialRequirement,
        hasSpecialCharacter
      );

      const passwordIsValid =
        hasMinimumLength &&
        hasUppercase &&
        hasSpecialCharacter;

      passwordInput.setCustomValidity(
        passwordIsValid ?
        '' :
        'Password must have at least 8 characters, one uppercase letter, and one special character.'
      );

      validatePasswordMatch();
    }

    function validatePasswordMatch() {
      const confirmationEntered =
        confirmInput.value !== '';

      const passwordsMatch =
        passwordInput.value === confirmInput.value;

      confirmInput.setCustomValidity(
        !confirmationEntered || passwordsMatch ?
        '' :
        'Passwords do not match.'
      );

      passwordMatchMessage.classList.toggle(
        'd-none',
        !confirmationEntered || passwordsMatch
      );
    }

    document.querySelectorAll('.password-toggle').forEach(function(button) {
      button.addEventListener('click', function() {
        const input =
          document.getElementById(button.dataset.passwordTarget);

        if (!input) {
          return;
        }

        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        button.textContent = isHidden ? '👁' : '🙈';
        button.setAttribute(
          'aria-label',
          isHidden ? 'Hide password' : 'Show password'
        );
      });
    });

    let emailCheckTimer;

    async function validateEmailAvailability() {
      const email = emailInput.value.trim();

      emailInput.setCustomValidity('');
      emailAvailabilityMessage.textContent = '';
      emailAvailabilityMessage.className = 'small mb-3';

      if (!email || !emailInput.checkValidity()) {
        return;
      }

      emailAvailabilityMessage.textContent = 'Checking email...';
      emailAvailabilityMessage.classList.add('text-muted');

      try {
        const response = await fetch(
          `api/check_email.php?email=${encodeURIComponent(email)}`
        );

        const data = await response.json();

        emailAvailabilityMessage.classList.remove('text-muted');
        emailAvailabilityMessage.classList.toggle(
          'text-success',
          data.available === true
        );
        emailAvailabilityMessage.classList.toggle(
          'text-danger',
          data.available !== true
        );
        emailAvailabilityMessage.textContent = data.message;

        emailInput.setCustomValidity(
          data.available === true ?
          '' :
          'Email already exists.'
        );
      } catch (error) {
        emailAvailabilityMessage.className =
          'small mb-3 text-warning';
        emailAvailabilityMessage.textContent =
          'Unable to check email right now.';
      }
    }

    function queueEmailCheck() {
      clearTimeout(emailCheckTimer);
      emailCheckTimer = setTimeout(
        validateEmailAvailability,
        350
      );
    }

    passwordInput.addEventListener(
      'input',
      validatePassword
    );

    emailInput.addEventListener(
      'input',
      queueEmailCheck
    );

    emailInput.addEventListener(
      'blur',
      validateEmailAvailability
    );

    confirmInput.addEventListener(
      'input',
      validatePasswordMatch
    );

    validatePassword();
    validateEmailAvailability();
  });
</script>

<?php include 'includes/footer.php'; ?>
