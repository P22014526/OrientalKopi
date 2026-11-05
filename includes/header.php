<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
?>

<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Oriental Kopi Smart Booking System</title>
  <?php if (function_exists('render_malaysian_phone_field')): ?><link rel="stylesheet" href="assets/malaysian_phone.css?v=2"><?php endif; ?>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <?php
  $stylePath = __DIR__ . '/../assets/style.css';
  $styleVersion = file_exists($stylePath)
    ? filemtime($stylePath)
    : time();
  $headerCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');
  $showCustomerSidebar = is_logged_in()
    && (($_SESSION['role'] ?? '') === 'customer')
    && $headerCurrentPage !== 'select_outlet.php';
  ?>

  <link
    rel="stylesheet"
    href="assets/style.css?v=<?= $styleVersion ?>">
</head>

<body class="customer-site">
  <?php if ($showCustomerSidebar): ?>
    <?php include __DIR__ . '/customer_sidebar.php'; ?>
  <?php else: ?>
    <nav class="navbar navbar-expand-lg sticky-top">
      <div class="container">
        <a class="navbar-brand brand d-flex align-items-center" href="index.php">
          <span class="logo-mark">☕</span>
          Oriental Kopi Smart Booking
        </a>

        <div class="d-flex gap-2 align-items-center">
          <?php if (is_logged_in()): ?>

            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
              <a class="btn btn-outline-dark btn-sm" href="admin/dashboard.php">Admin</a>
              <a class="btn btn-dark btn-sm" href="logout.php">Logout</a>

            <?php elseif (($_SESSION['role'] ?? '') === 'shop_owner'): ?>
              <a class="btn btn-outline-dark btn-sm" href="admin/dashboard.php">Shop Owner</a>
              <a class="btn btn-dark btn-sm" href="logout.php">Logout</a>

            <?php else: ?>
              <?php $selectedOutletName = $_SESSION['selected_outlet_name'] ?? 'Choose Outlet'; ?>
              <a class="btn btn-outline-light btn-sm" href="select_outlet.php">
                <?= h($selectedOutletName) ?>
              </a>
              <a class="btn btn-outline-light btn-sm" href="notifications.php">🔔 Notifications</a>
              <a class="btn btn-outline-light btn-sm profile-navbar-btn" href="view_profile.php">
                <?= h(current_user_name()) ?>
              </a>
            <?php endif; ?>

          <?php else: ?>
            <a class="btn btn-outline-dark btn-sm" href="login.php">Login</a>
            <a class="btn btn-dark btn-sm" href="register.php">Register</a>
          <?php endif; ?>
        </div>
      </div>
    </nav>
  <?php endif; ?>

  <main class="container py-4">
