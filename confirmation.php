<?php
require_once 'includes/db.php'; require_once 'includes/auth.php'; require_once 'includes/functions.php'; require_login();
$stmt=$pdo->prepare('SELECT * FROM bookings WHERE booking_id=? AND user_id=?'); $stmt->execute([$_GET['id']??0,$_SESSION['user_id']]); $b=$stmt->fetch();
if(!$b){ header('Location: my_bookings.php'); exit; }
include 'includes/header.php';
?>
<div class="row justify-content-center"><div class="col-md-7"><div class="card ok-card p-4 text-center">
<div class="section-title">Reservation confirmed</div><h3>Booking Confirmed ✅</h3><hr>
<p>Booking ID: <strong><?=h($b['booking_code'])?></strong></p>
<p>Name: <?=h($b['customer_name'])?></p>
<p>Date: <?=h(format_date_short($b['booking_date']))?></p>
<p>Time: <?=h(format_time12($b['booking_time']))?></p>
<p>Pax: <?=h($b['pax'])?></p>
<?php if($b['reward_text']): ?><p>Reward Earned: <?=h($b['reward_text'])?> 🎁</p><?php endif; ?>
<p class="text-warning">Please arrive on time. Booking may be released after 15 minutes.</p>
<div class="d-flex justify-content-center flex-wrap gap-2">
<a class="btn btn-gold" href="preorder_menu.php?id=<?= h($b['booking_id']) ?>">Preorder Food Now</a>
<a class="btn btn-outline-dark" href="my_bookings.php">View My Bookings</a>
</div>
</div></div></div>
<?php include 'includes/footer.php'; ?>