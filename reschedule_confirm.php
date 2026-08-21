<?php
require_once 'includes/db.php'; require_once 'includes/auth.php'; require_once 'includes/functions.php'; require_login();
$stmt=$pdo->prepare('SELECT * FROM bookings WHERE booking_id=? AND user_id=?'); $stmt->execute([$_GET['id']??0,$_SESSION['user_id']]); $b=$stmt->fetch(); if(!$b){header('Location: my_bookings.php'); exit;}
include 'includes/header.php';
?>
<div class="card ok-card p-4 text-center">
<div class="section-title">Request submitted</div><h3>Reschedule Request Submitted</h3>
<p>Old Booking: <?=h(date('d/m',strtotime($b['old_booking_date'])))?> | <?=h(format_time12($b['old_booking_time']))?></p>
<p>New Request: <?=h(date('d/m',strtotime($b['booking_date'])))?> | <?=h(format_time12($b['booking_time']))?></p>
<p class="text-warning">Status: Pending Admin Approval. You will be notified after approval.</p>
</div>
<div class="text-center mt-3">
<a href="my_bookings.php" class="btn-nav-icon">
<span class="btn-nav-icon-symbol">←</span>
<span>My Bookings</span>
</a>
</div>
<?php include 'includes/footer.php'; ?>
