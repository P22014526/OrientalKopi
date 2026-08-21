<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

require_login();

$id = $_GET['id'] ?? 0;
$bookingId = $_GET['booking'] ?? 0;

$stmt = $pdo->prepare("
    DELETE FROM preorders
    WHERE preorder_id = ?
    AND user_id = ?
");
$stmt->execute([$id, $_SESSION['user_id']]);

$stmt = $pdo->prepare("
    UPDATE preorders
    SET status = 'Draft',
        submitted_at = NULL
    WHERE booking_id = ?
    AND user_id = ?
");
$stmt->execute([$bookingId, $_SESSION['user_id']]);

header('Location: preorder_menu.php?id=' . $bookingId);
exit;