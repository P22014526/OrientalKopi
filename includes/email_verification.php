<?php

function ensure_email_verification_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS registration_verifications (
        user_id INT PRIMARY KEY,
        code_hash VARCHAR(255) NULL,
        expires_at DATETIME NULL,
        attempts INT NOT NULL DEFAULT 0,
        last_sent_at DATETIME NULL,
        window_started_at DATETIME NULL,
        send_count INT NOT NULL DEFAULT 0,
        verified_at DATETIME NULL,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function registration_verification_pending(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT user_id FROM registration_verifications WHERE user_id=? AND verified_at IS NULL');
    $stmt->execute([$userId]);
    return (bool)$stmt->fetchColumn();
}

function registration_csrf_token(): string
{
    if (empty($_SESSION['registration_csrf'])) $_SESSION['registration_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['registration_csrf'];
}

function registration_csrf_valid($token): bool
{
    return is_string($token) && isset($_SESSION['registration_csrf'])
        && hash_equals($_SESSION['registration_csrf'], $token);
}

function send_registration_code(PDO $pdo, int $userId, ?callable $sender = null): string
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT v.*, u.email, u.full_name FROM registration_verifications v
            JOIN users u ON u.user_id=v.user_id WHERE v.user_id=? FOR UPDATE');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        $now = time();
        if (!$row || $row['verified_at']) {
            $pdo->rollBack();
            return 'This account does not need verification. Please log in.';
        }
        if ($row['last_sent_at'] && strtotime($row['last_sent_at']) > $now - 60) {
            $pdo->rollBack();
            return 'Please wait 60 seconds between code requests.';
        }
        $newWindow = !$row['window_started_at'] || strtotime($row['window_started_at']) <= $now - 3600;
        if (!$newWindow && (int)$row['send_count'] >= 5) {
            $pdo->rollBack();
            return 'Too many code requests. Please try again in one hour.';
        }
        // Reserve the sending attempt even if SMTP fails, to prevent repeated retries.
        $pdo->prepare('UPDATE registration_verifications SET last_sent_at=?, window_started_at=?, send_count=? WHERE user_id=?')
            ->execute([date('Y-m-d H:i:s', $now), $newWindow ? date('Y-m-d H:i:s', $now) : $row['window_started_at'],
                $newWindow ? 1 : (int)$row['send_count'] + 1, $userId]);
        $code = (string)random_int(100000, 999999);
        try {
            ($sender ?? 'send_registration_verification_email')($row['email'], $row['full_name'], $code);
        } catch (Throwable $e) {
            $pdo->commit();
            error_log('Registration verification email failed: ' . $e->getMessage());
            return 'We could not send your code. Please wait a minute and try again. If this continues, contact the restaurant.';
        }
        $pdo->prepare('UPDATE registration_verifications SET code_hash=?, expires_at=?, attempts=0 WHERE user_id=?')
            ->execute([password_hash($code, PASSWORD_DEFAULT), date('Y-m-d H:i:s', time() + 600), $userId]);
        $pdo->commit();
        return '';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function verify_registration_code(PDO $pdo, int $userId, string $code): string
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM registration_verifications WHERE user_id=? FOR UPDATE');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        $error = '';
        if (!$row || $row['verified_at']) {
            $error = 'This account does not need verification. Please log in.';
        } elseif (!$row['code_hash'] || !$row['expires_at'] || strtotime($row['expires_at']) <= time()) {
            $error = 'Your code has expired or has not been sent. Please request a new code.';
        } elseif ((int)$row['attempts'] >= 5) {
            $error = 'Too many incorrect attempts. Please request a new code.';
        } elseif (!preg_match('/^[0-9]{6}$/', $code) || !password_verify($code, $row['code_hash'])) {
            $pdo->prepare('UPDATE registration_verifications SET attempts=attempts+1 WHERE user_id=?')->execute([$userId]);
            $remaining = 4 - (int)$row['attempts'];
            $error = $remaining ? "Incorrect code. $remaining attempts remaining." : 'Too many incorrect attempts. Please request a new code.';
        } else {
            $pdo->prepare('UPDATE registration_verifications SET verified_at=?, code_hash=NULL, expires_at=NULL WHERE user_id=?')
                ->execute([date('Y-m-d H:i:s'), $userId]);
        }
        $pdo->commit();
        return $error;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
