<?php
require_once 'includes/db.php'; require_once 'includes/auth.php'; require_once 'includes/functions.php'; require_login();
$date = $_GET['date'] ?? date('Y-m-d', strtotime('+7 day')); $time = $_GET['time'] ?? '19:00'; $pax=(int)($_GET['pax']??8); $openTime='09:30'; $closeTime='22:30'; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 if (($_POST['booking_time'] ?? '') < $openTime || ($_POST['booking_time'] ?? '') > $closeTime) {
  $error='Booking time must be between 9:30 AM and 10:30 PM.';
 } else {
  $stmt=$pdo->prepare("INSERT INTO bookings (user_id,customer_name,phone,booking_date,booking_time,pax,status,request_type,event_type,special_request,reward_text) VALUES (?,?,?,?,?,?, 'Pending','Party',?,?,?)");
  $reward=reward_for_date($_POST['booking_date']);
  $stmt->execute([$_SESSION['user_id'],$_POST['customer_name'],$_POST['phone'],$_POST['booking_date'],$_POST['booking_time'],$_POST['pax'],$_POST['event_type'],$_POST['special_request'],$reward]);
  $id=$pdo->lastInsertId(); $pdo->prepare('UPDATE bookings SET booking_code=? WHERE booking_id=?')->execute([booking_code($id),$id]);
  header('Location: my_bookings.php'); exit;
 }
}
include 'includes/header.php';
?>
<a href="booking.php" class="btn-nav-icon mb-3">
 <span class="btn-nav-icon-symbol">←</span>
 <span>Booking</span>
</a>
<div class="card ok-card p-4">
<div class="section-title">Large group</div><h3>Party Reservation Request</h3>
<p class="muted-small">Party requests are sent to admin for approval. You can edit this request while it is pending.</p>
<?php if($error): ?><div class="alert alert-danger"><?=h($error)?></div><?php endif; ?>
<form method="post">
 <div class="row g-3">
  <div class="col-md-6"><label>Name</label><input name="customer_name" class="form-control" value="<?=h(current_user_name())?>" required></div>
  <div class="col-md-6"><label>Phone</label><input name="phone" class="form-control" required></div>
  <div class="col-md-4"><label>Event Type</label><select name="event_type" class="form-select"><option>Birthday</option><option>Gathering</option><option>Company Meal</option><option>Others</option></select></div>
  <div class="col-md-2"><label>Pax</label><input name="pax" class="form-control" value="<?=h($pax)?>" type="number" min="7"></div>
  <div class="col-md-3"><label>Date</label><input name="booking_date" type="date" class="form-control" value="<?=h($date)?>"></div>
  <div class="col-md-3"><label>Time</label><input name="booking_time" type="time" class="form-control" value="<?=h($time)?>" min="<?=h($openTime)?>" max="<?=h($closeTime)?>"><div class="muted-small mt-1">Opening Hours: 9:30 AM - 10:30 PM</div></div>
 </div>
 <label class="mt-3">Special Request</label><textarea name="special_request" class="form-control" rows="4" placeholder="Decorations / Cake / Seating"></textarea>
 <button class="btn btn-dark mt-3">Submit Request</button>
</form>
</div>
<?php include 'includes/footer.php'; ?>
