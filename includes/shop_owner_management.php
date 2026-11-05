<?php
function save_shop_owner(PDO $pdo, int $id, array $input): int
{
    $name = trim((string)($input['full_name'] ?? ''));
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $phone = trim((string)($input['phone'] ?? ''));
    $password = (string)($input['password'] ?? '');
    if ($name === '' || strlen($name) > 100) throw new InvalidArgumentException('Enter a name of up to 100 characters.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) throw new InvalidArgumentException('Enter a valid email address.');
    if (strlen($phone) > 30) throw new InvalidArgumentException('Phone number must be 30 characters or fewer.');
    if (!$id || $password !== '') {
        if (strlen($password) < 8 || strlen($password) > 72 || !preg_match('/[A-Z]/', $password) || !preg_match('/[^A-Za-z0-9\s]/', $password)) throw new InvalidArgumentException('Password must be 8-72 characters with an uppercase letter and a special character.');
        if ($password !== ($input['confirm_password'] ?? '')) throw new InvalidArgumentException('Passwords do not match.');
    }
    $raw = $input['outlet_ids'] ?? [];
    if (!is_array($raw)) throw new InvalidArgumentException('Choose valid outlets.');
    foreach ($raw as $value) {
        if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value < 1) throw new InvalidArgumentException('Choose valid outlets.');
    }
    $outlets = array_values(array_unique(array_map('intval', $raw)));
    $pdo->beginTransaction();
    try {
        if ($id) {
            $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id=? AND role='shop_owner' FOR UPDATE");
            $stmt->execute([$id]);
            $oldEmail = $stmt->fetchColumn();
            if ($oldEmail === false) throw new InvalidArgumentException('Shop owner account not found.');
        }
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE LOWER(email)=? AND user_id<>?');
        $stmt->execute([$email, $id]);
        if ($stmt->fetchColumn()) throw new InvalidArgumentException('This email is already assigned to another account.');
        if ($outlets) {
            $stmt = $pdo->prepare('SELECT outlet_id FROM outlets WHERE outlet_id IN (' . implode(',', array_fill(0, count($outlets), '?')) . ') FOR UPDATE');
            $stmt->execute($outlets);
            if (count($stmt->fetchAll()) !== count($outlets)) throw new InvalidArgumentException('A selected outlet no longer exists. Refresh and try again.');
        }
        if ($id) {
            $pdo->prepare("UPDATE users SET full_name=?,email=?,phone=? WHERE user_id=? AND role='shop_owner'")->execute([$name,$email,$phone,$id]);
            if ($password !== '') $pdo->prepare('UPDATE users SET password_hash=? WHERE user_id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
            if ($password !== '' || strtolower($oldEmail) !== $email) $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=?')->execute([$id]);
        } else {
            $pdo->prepare("INSERT INTO users(full_name,email,phone,password_hash,role) VALUES (?,?,?,?,'shop_owner')")->execute([$name,$email,$phone,password_hash($password,PASSWORD_DEFAULT)]);
            $id = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM shop_owner_outlets WHERE user_id=?')->execute([$id]);
        $stmt = $pdo->prepare('INSERT INTO shop_owner_outlets(user_id,outlet_id) VALUES (?,?)');
        foreach ($outlets as $outlet) $stmt->execute([$id,$outlet]);
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
