<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$email = trim($_GET['email'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'available' => false,
        'message' => 'Please enter a valid email.'
    ]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM users
    WHERE LOWER(email) = LOWER(?)
");
$stmt->execute([$email]);
$exists = (int)$stmt->fetchColumn() > 0;

echo json_encode([
    'available' => !$exists,
    'message' => $exists
        ? 'Email already exists. Please login or use another email.'
        : 'Email is available.'
]);
