<?php
require_once 'includes/db.php'; require_once 'includes/auth.php'; require_once 'includes/functions.php'; require_login();
$id=$_GET['id']??0;
$stmt=$pdo->prepare('SELECT * FROM bookings WHERE booking_id=? AND user_id=?'); $stmt->execute([$id,$_SESSION['user_id']]); $b=$stmt->fetch();
if(!$b) { header('Location: my_bookings.php'); exit; }
if($_SERVER['REQUEST_METHOD']==='POST') { $pdo->prepare("UPDATE bookings SET status='Cancelled' WHERE booking_id=? AND user_id=?")->execute([$id,$_SESSION['user_id']]); header('Location: my_bookings.php'); exit; }
include 'includes/header.php';
?>
<div class="row justify-content-center"><div class="col-md-6"><div class="card ok-card p-4">
<div class="section-title">Customer cancellation</div><h3>Cancel Booking Confirmation</h3>
<p>Booking ID: <strong><?=h($b['booking_code'])?></strong></p>
<p>Date: <?=h(format_date_short($b['booking_date']))?> | Time: <?=h(format_time12($b['booking_time']))?></p>
<p>Are you sure you want to cancel booking?</p>
<form method="post"><button class="btn btn-danger">Yes, Cancel</button> <a href="my_bookings.php" class="btn btn-outline-dark">Keep Booking</a></form>
</div></div></div>
<?php include 'includes/footer.php'; ?>
