<?php
require_once 'includes/db.php'; require_once 'includes/auth.php'; require_once 'includes/functions.php'; require_login();
require_once 'includes/malaysian_phone.php';
prevent_dynamic_page_cache();
$phone = account_malaysian_mobile($pdo, (int)$_SESSION['user_id']);
$id=$_GET['id']??0;
$openTime='09:30'; $closeTime='22:30'; $error='';
$stmt=$pdo->prepare("SELECT * FROM bookings WHERE booking_id=? AND user_id=? AND request_type='Party' AND status='Pending'");
$stmt->execute([$id,$_SESSION['user_id']]); $b=$stmt->fetch();
if(!$b){ header('Location: my_bookings.php'); exit; }
if($_SERVER['REQUEST_METHOD']==='POST'){
  $newDate = $_POST['booking_date'] ?? '';
  $newTime = $_POST['booking_time'] ?? '';
  if ($phone === null) {
    $error='Please update your profile with a valid Malaysian mobile number before saving.';
  } elseif ($newDate < date('Y-m-d')) {
    $error='Past booking dates are not allowed.';
  } elseif ($newTime < $openTime || $newTime > $closeTime) {
    $error='Booking time must be between 9:30 AM and 10:30 PM.';
  } elseif ($policyError = customer_booking_policy_violation($pdo, (int)$_SESSION['user_id'], $newDate, $newTime, (int)$id)) {
    $error=$policyError;
  } else {
    $reward=reward_for_date($_POST['booking_date']);
    $pdo->prepare("UPDATE bookings SET customer_name=?, phone=?, booking_date=?, booking_time=?, pax=?, event_type=?, special_request=?, reward_text=? WHERE booking_id=? AND user_id=?")
        ->execute([$_POST['customer_name'],$phone,$_POST['booking_date'],$_POST['booking_time'],$_POST['pax'],$_POST['event_type'],$_POST['special_request'],$reward,$id,$_SESSION['user_id']]);
    header('Location: my_bookings.php'); exit;
  }
}
include 'includes/header.php';
?>
<a href="my_bookings.php" class="btn-nav-icon mb-3">
 <span class="btn-nav-icon-symbol">←</span>
 <span>My Bookings</span>
</a>
<div class="card ok-card p-4">
<div class="section-title">Pending party request</div><h3>View / Edit Party Request</h3>
<?php if($error): ?><div class="alert alert-danger"><?=h($error)?></div><?php endif; ?>
<form method="post">
 <p>Booking ID: <strong><?=h($b['booking_code'])?></strong></p>
 <div class="row g-3">
  <div class="col-md-6"><label>Name</label><input name="customer_name" class="form-control" value="<?=h($b['customer_name'])?>" required></div>
  <div class="col-md-6"><?php render_malaysian_phone_field('party-phone', $phone ?? '', true, true); ?>
   <?php if ($phone === null): ?><div class="text-danger small mb-2">A valid Malaysian mobile number is required before saving.</div><?php endif; ?>
   <a href="edit_profile.php" class="btn btn-sm btn-outline-dark">Update Profile Phone</a></div>
  <div class="col-md-4"><label>Event Type</label><select name="event_type" class="form-select"><?php foreach(['Birthday','Gathering','Company Meal','Others'] as $opt): ?><option <?=($b['event_type']===$opt?'selected':'')?>><?=h($opt)?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label>Pax</label><input name="pax" class="form-control" value="<?=h($b['pax'])?>" type="number" min="7"></div>
  <div class="col-md-3"><label>Date</label><input name="booking_date" type="date" class="form-control" value="<?=h($b['booking_date'])?>"></div>
  <div class="col-md-3"><label>Time</label><input name="booking_time" type="time" class="form-control" value="<?=h(substr($b['booking_time'],0,5))?>" min="<?=h($openTime)?>" max="<?=h($closeTime)?>"><div class="muted-small mt-1">Opening Hours: 9:30 AM - 10:30 PM</div></div>
 </div>
 <label class="mt-3">Special Request</label><textarea name="special_request" class="form-control" rows="4"><?=h($b['special_request'])?></textarea>
 <button class="btn btn-dark mt-3">Save Request</button> <a class="btn btn-outline-dark mt-3" href="my_bookings.php">Cancel</a>
</form>
</div>
<?php include 'includes/footer.php'; ?>
