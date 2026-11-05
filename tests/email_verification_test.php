<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/email_verification.php';
function verification_check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
// All test rows live in connection-local tables. No real accounts or emails are used.
$pdo->exec('CREATE TEMPORARY TABLE users (user_id INT PRIMARY KEY, email VARCHAR(120), full_name VARCHAR(100)) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE registration_verifications (
    user_id INT PRIMARY KEY, code_hash VARCHAR(255), expires_at DATETIME, attempts INT DEFAULT 0,
    last_sent_at DATETIME, window_started_at DATETIME, send_count INT DEFAULT 0, verified_at DATETIME
) ENGINE=InnoDB');
$pdo->exec("INSERT INTO users VALUES (1,'verification@example.test','Verification Test'), (2,'legacy@example.test','Legacy')");
$pdo->exec('INSERT INTO registration_verifications (user_id) VALUES (1)');
$code = '';
$send = static function ($email, $name, $value) use (&$code) { $code = $value; };
verification_check(registration_verification_pending($pdo, 1), 'New account pending');
verification_check(!registration_verification_pending($pdo, 2), 'Legacy account unaffected');
verification_check(send_registration_code($pdo, 1, $send) === '', 'Send code');
verification_check((bool)preg_match('/^[0-9]{6}$/', $code), 'Six-digit code');
$hash = $pdo->query('SELECT code_hash FROM registration_verifications')->fetchColumn();
verification_check($hash !== $code && password_verify($code, $hash), 'Code stored hashed');
verification_check(send_registration_code($pdo, 1, $send) !== '', 'Resend cooldown');
$wrong = $code === '123456' ? '654321' : '123456';
for ($i=0; $i<5; $i++) verification_check(verify_registration_code($pdo, 1, $wrong) !== '', 'Reject incorrect code');
verification_check(verify_registration_code($pdo, 1, $code) !== '', 'Five-attempt lockout');
$pdo->exec("UPDATE registration_verifications SET last_sent_at='2000-01-01', send_count=5");
verification_check(send_registration_code($pdo, 1, $send) !== '', 'Hourly cap');
$pdo->exec("UPDATE registration_verifications SET window_started_at='2000-01-01'");
verification_check(send_registration_code($pdo, 1, $send) === '', 'New hour resets cap');
$pdo->exec("UPDATE registration_verifications SET expires_at='2000-01-01'");
verification_check(verify_registration_code($pdo, 1, $code) !== '', 'Expired code rejected');
$pdo->exec("UPDATE registration_verifications SET last_sent_at='2000-01-01'");
verification_check(send_registration_code($pdo, 1, $send) === '', 'Fresh code replaces expired code');
$oldHash = $pdo->query('SELECT code_hash FROM registration_verifications')->fetchColumn();
$pdo->exec("UPDATE registration_verifications SET last_sent_at='2000-01-01'");
$failure = send_registration_code($pdo, 1, static function () { throw new RuntimeException('Simulated delivery failure'); });
verification_check($failure !== '' && strpos($failure, 'Simulated') === false, 'Safe delivery error');
verification_check($pdo->query('SELECT code_hash FROM registration_verifications')->fetchColumn() === $oldHash, 'Failed resend keeps working code');
verification_check(verify_registration_code($pdo, 1, $code) === '', 'Correct code activates account');
verification_check(!registration_verification_pending($pdo, 1), 'Verified account allowed');
verification_check(verify_registration_code($pdo, 1, $code) !== '', 'Code cannot be replayed');
verification_check($pdo->query('SELECT code_hash FROM registration_verifications')->fetchColumn() === null, 'Used code removed');
$_SESSION = [];
verification_check(!registration_csrf_valid('invalid'), 'Reject missing CSRF');
verification_check(registration_csrf_valid(registration_csrf_token()), 'Accept valid CSRF');
echo "Email verification tests passed. No production accounts changed or emails sent.\n";
