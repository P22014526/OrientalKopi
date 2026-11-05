<?php

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Load the private SMTP configuration.
 */
function get_mail_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $configPath = __DIR__ . '/mail_config.php';

    if (!file_exists($configPath)) {
        throw new RuntimeException(
            'Missing includes/mail_config.php. Copy mail_config.example.php and fill in the SMTP values.'
        );
    }

    $loaded = require $configPath;

    if (!is_array($loaded)) {
        throw new RuntimeException('The mail configuration file must return an array.');
    }

    $config = $loaded;
    return $config;
}

/**
 * Check whether PHPMailer and the SMTP configuration are ready.
 */
function mail_service_status(): array
{
    $autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';

    if (!file_exists($autoloadPath)) {
        return [
            false,
            'PHPMailer is not installed. Run composer require phpmailer/phpmailer in the project folder.'
        ];
    }

    try {
        $config = get_mail_config();
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }

    $required = [
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'from_email',
        'from_name',
        'app_url'
    ];

    foreach ($required as $key) {
        if (!isset($config[$key]) || trim((string)$config[$key]) === '') {
            return [false, "Mail configuration value '{$key}' is missing."];
        }
    }

    if (
        str_contains((string)$config['smtp_username'], 'your-email')
        || str_contains((string)$config['smtp_password'], 'your-16-character')
    ) {
        return [false, 'Replace the example SMTP username and password in includes/mail_config.php.'];
    }

    return [true, ''];
}

/**
 * Create a configured PHPMailer instance.
 */
function create_mailer(): PHPMailer
{
    $autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';

    if (!file_exists($autoloadPath)) {
        throw new RuntimeException('PHPMailer is not installed.');
    }

    require_once $autoloadPath;

    $config = get_mail_config();
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = (string)$config['smtp_host'];
    $mail->Port = (int)$config['smtp_port'];
    $mail->SMTPAuth = true;
    $mail->Username = (string)$config['smtp_username'];
    $mail->Password = (string)$config['smtp_password'];
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 20;

    $encryption = strtolower((string)($config['smtp_encryption'] ?? 'tls'));

    if ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($encryption === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    $mail->setFrom(
        (string)$config['from_email'],
        (string)$config['from_name']
    );

    return $mail;
}

/**
 * Build the absolute reset URL from the configured application URL.
 */
function password_reset_url(string $token): string
{
    $config = get_mail_config();
    $baseUrl = rtrim((string)$config['app_url'], '/');

    return $baseUrl . '/reset_password.php?token=' . urlencode($token);
}

/**
 * Send the account activation code.
 */
function send_registration_verification_email(string $email, string $name, string $code): void
{
    $mail = create_mailer();
    $mail->addAddress($email, $name);
    $mail->Subject = 'Verify your Oriental Kopi email';
    $mail->isHTML(true);
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $mail->Body = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#0e2133">'
        . '<h2>Verify Your Email</h2><p>Hello ' . $safeName . ',</p>'
        . '<p>Enter this code to activate your Oriental Kopi booking account:</p>'
        . '<p style="font-size:28px;font-weight:bold">' . $safeCode . '</p>'
        . '<p>This code expires in 10 minutes and can only be used once.</p>'
        . '<p>If you did not register, ignore this email. Do not share this code.</p></div>';
    $mail->AltBody = "Hello $name,\nYour Oriental Kopi email verification code is: $code\n"
        . 'It expires in 10 minutes and can only be used once. If you did not register, ignore this email. Do not share this code.';
    $mail->send();
}

/** Send the password-reset link. */
function send_password_reset_email(
    string $recipientEmail,
    string $recipientName,
    string $resetUrl
): void {
    $mail = create_mailer();
    $safeName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

    $mail->addAddress($recipientEmail, $recipientName);
    $mail->isHTML(true);
    $mail->Subject = 'Reset your Oriental Kopi booking password';
    $mail->Body = <<<HTML
<!doctype html>
<html lang="en">
<body style="font-family:Arial,sans-serif;color:#1b2430;line-height:1.6">
  <h2 style="color:#0e2133">Password Reset Request</h2>
  <p>Hello {$safeName},</p>
  <p>We received a request to reset the password for your Oriental Kopi Smart Booking account.</p>
  <p>
    <a href="{$safeUrl}" style="display:inline-block;background:#0e2133;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:bold">
      Reset Password
    </a>
  </p>
  <p>This link expires in 30 minutes and can only be used once.</p>
  <p>If you did not request a password reset, you can ignore this email.</p>
</body>
</html>
HTML;

    $mail->AltBody =
        "Hello {$recipientName},\n\n" .
        "Use the following link to reset your password. It expires in 30 minutes and can only be used once:\n" .
        $resetUrl .
        "\n\nIf you did not request this reset, ignore this email.";

    $mail->send();
}

/**
 * Send a verification code for password reset.
 */
function send_password_reset_code_email(
    string $recipientEmail,
    string $recipientName,
    string $verificationCode
): void {
    $mail = create_mailer();
    $safeName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($verificationCode, ENT_QUOTES, 'UTF-8');

    $mail->addAddress($recipientEmail, $recipientName);
    $mail->isHTML(true);
    $mail->Subject = 'Your Oriental Kopi password reset code';
    $mail->Body = <<<HTML
<!doctype html>
<html lang="en">
<body style="font-family:Arial,sans-serif;color:#1b2430;line-height:1.6">
  <h2 style="color:#0e2133">Password Reset Code</h2>
  <p>Hello {$safeName},</p>
  <p>Use this verification code to reset your Oriental Kopi Smart Booking password:</p>
  <p style="font-size:28px;letter-spacing:6px;font-weight:bold;color:#0e2133">{$safeCode}</p>
  <p>This code expires in 30 minutes and can only be used once.</p>
  <p>If you did not request a password reset, you can ignore this email.</p>
</body>
</html>
HTML;

    $mail->AltBody =
        "Hello {$recipientName},\n\n" .
        "Use this verification code to reset your Oriental Kopi Smart Booking password:\n" .
        $verificationCode .
        "\n\nThis code expires in 30 minutes and can only be used once.\n" .
        "If you did not request this reset, ignore this email.";

    $mail->send();
}

/**
 * Send a confirmation after the password has been changed.
 */
function send_password_changed_email(
    string $recipientEmail,
    string $recipientName
): void {
    $mail = create_mailer();
    $safeName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');

    $mail->addAddress($recipientEmail, $recipientName);
    $mail->isHTML(true);
    $mail->Subject = 'Your Oriental Kopi password was changed';
    $mail->Body = <<<HTML
<!doctype html>
<html lang="en">
<body style="font-family:Arial,sans-serif;color:#1b2430;line-height:1.6">
  <h2 style="color:#0e2133">Password Changed</h2>
  <p>Hello {$safeName},</p>
  <p>Your Oriental Kopi Smart Booking account password has been changed successfully.</p>
  <p>If you did not make this change, contact the system administrator immediately.</p>
</body>
</html>
HTML;

    $mail->AltBody =
        "Hello {$recipientName},\n\n" .
        "Your Oriental Kopi Smart Booking account password has been changed successfully.\n" .
        "If you did not make this change, contact the system administrator immediately.";

    $mail->send();
}
