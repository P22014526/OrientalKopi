<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();

$stmt = $pdo->prepare("SELECT full_name, email, phone, role, created_at FROM users WHERE user_id=?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

include 'includes/header.php';
?>


<div class="row justify-content-center">
  <div class="col-md-8">
    <div class="card ok-card p-4 profile-bg-card">
      <div class="section-title">Customer Profile</div>

      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
          <h3 class="mb-0">My Profile</h3>
          <p class="muted-small mb-0">Manage your account, bookings, and logout from here.</p>
        </div>

        <a href="edit_profile.php" class="btn btn-outline-dark">Edit Profile</a>
      </div>

      <table class="table table-bordered mt-3">
        <tr>
          <th style="width:35%">Full Name</th>
          <td><?= h($user['full_name']) ?></td>
        </tr>
        <tr>
          <th>Email</th>
          <td><?= h($user['email']) ?></td>
        </tr>
        <tr>
          <th>Phone</th>
          <td><?= h($user['phone'] ?: '-') ?></td>
        </tr>
        <tr>
          <th>Role</th>
          <td><?= h($user['role']) ?></td>
        </tr>
        <tr>
          <th>Registered At</th>
          <td><?= h($user['created_at']) ?></td>
        </tr>
      </table>

      <hr>

      <div class="row g-3">
        <div class="col-md-6">
          <a href="my_bookings.php" class="profile-action-card">
            <div class="profile-action-icon">📋</div>
            <div>
              <strong>My Bookings</strong>
              <p>View your reservations, preorder menu, cancel, or reschedule bookings.</p>
            </div>
          </a>
        </div>

        <div class="col-md-6">
          <a href="rewards.php" class="profile-action-card">
            <div class="profile-action-icon">🏅</div>
            <div>
              <strong>My Rewards</strong>
              <p>View your badges, vouchers, and reward progress.</p>
            </div>
          </a>
        </div>

        <div class="col-md-6">
          <a href="logout.php" class="profile-action-card logout-profile-card">
            <div class="profile-action-icon">🚪</div>
            <div>
              <strong>Logout</strong>
              <p>Sign out from your customer account securely.</p>
            </div>
          </a>
        </div>
      </div>

    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>