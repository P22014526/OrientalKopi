<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function require_admin() {
    if (!is_logged_in() || ($_SESSION['role'] ?? '') !== 'admin') {
        header('Location: ../login.php');
        exit;
    }
}

function require_staff() {
    if (!is_logged_in() || !in_array(($_SESSION['role'] ?? ''), ['admin', 'shop_owner'], true)) {
        header('Location: ../login.php');
        exit;
    }
}

function current_user_name() {
    return $_SESSION['full_name'] ?? 'User';
}
?>
