<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();

$bookingId = (int)($_GET['id'] ?? $_POST['booking_id'] ?? 0);
$error = '';
$success = '';

$stmt = $pdo->prepare("
    SELECT b.*, o.branch_name, o.location_detail, s.state_name
    FROM bookings b
    LEFT JOIN outlets o ON o.outlet_id = b.outlet_id
    LEFT JOIN outlet_states s ON s.state_id = o.state_id
    WHERE b.booking_id = ?
      AND b.user_id = ?
    LIMIT 1
");
$stmt->execute([$bookingId, $_SESSION['user_id']]);
$booking = $stmt->fetch();

if (!$booking) {
    header('Location: my_bookings.php');
    exit;
}

if ($booking['status'] !== 'Completed') {
    header('Location: my_bookings.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT *
    FROM reviews
    WHERE booking_id = ?
      AND user_id = ?
    LIMIT 1
");
$stmt->execute([$bookingId, $_SESSION['user_id']]);
$existingReview = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existingReview) {
    $rating = (int)($_POST['rating'] ?? 0);
    $feedback = trim($_POST['feedback'] ?? '');

    if ($rating < 1 || $rating > 5) {
        $error = 'Please select a rating between 1 and 5.';
    } elseif ($feedback === '') {
        $error = 'Please write your feedback.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO reviews (user_id, booking_id, rating, feedback)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $bookingId,
            $rating,
            $feedback
        ]);

        $success = 'Thank you! Your review has been submitted.';

        $stmt = $pdo->prepare("
            SELECT *
            FROM reviews
            WHERE booking_id = ?
              AND user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$bookingId, $_SESSION['user_id']]);
        $existingReview = $stmt->fetch();
    }
}

include 'includes/header.php';
?>

<a href="my_bookings.php" class="btn-nav-icon mb-3">
    <span class="btn-nav-icon-symbol">←</span>
    <span>My Bookings</span>
</a>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card ok-card p-4">
            <div class="section-title">Completed Booking</div>
            <h3>Write a Review</h3>

            <p class="muted-small">
                You can review this dining experience because the booking has been completed.
            </p>

            <div class="border rounded p-3 bg-light mb-4">
                <div class="row g-2">
                    <div class="col-md-6">
                        <strong>Booking ID:</strong>
                        <?= h($booking['booking_code']) ?>
                    </div>

                    <div class="col-md-6">
                        <strong>Status:</strong>
                        <span class="badge bg-success">Completed</span>
                    </div>

                    <div class="col-md-6">
                        <strong>Date:</strong>
                        <?= h(date('d/m/Y', strtotime($booking['booking_date']))) ?>
                    </div>

                    <div class="col-md-6">
                        <strong>Time:</strong>
                        <?= h(format_time12($booking['booking_time'])) ?>
                    </div>

                    <div class="col-md-6">
                        <strong>Pax:</strong>
                        <?= h($booking['pax']) ?>
                    </div>

                    <div class="col-md-12">
                        <strong>Outlet:</strong>
                        <?= h(($booking['branch_name'] ?? 'Unassigned Outlet') . ' - ' . ($booking['state_name'] ?? '-')) ?>
                        <div class="muted-small">
                            <?= h($booking['location_detail'] ?? '') ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?= h($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($existingReview): ?>
                <div class="review-display-card">
                    <strong>Your Submitted Review</strong>

                    <div class="review-stars my-2">
                        <?= str_repeat('⭐', (int)$existingReview['rating']) ?>
                    </div>

                    <p class="mb-0">
                        <?= nl2br(h($existingReview['feedback'])) ?>
                    </p>
                </div>

                <div class="mt-3 d-flex flex-wrap gap-2">
                    <a href="review.php" class="btn btn-outline-dark">
                        View Customer Reviews
                    </a>

                    <a href="my_bookings.php" class="btn btn-dark">
                        Return to My Bookings
                    </a>
                </div>
            <?php else: ?>
                <form method="post">
                    <input
                        type="hidden"
                        name="booking_id"
                        value="<?= h($bookingId) ?>">

                    <label class="form-label">Rating</label>
                    <select name="rating" class="form-select mb-3" required>
                        <option value="">Select rating</option>
                        <option value="5">⭐⭐⭐⭐⭐ Excellent</option>
                        <option value="4">⭐⭐⭐⭐ Good</option>
                        <option value="3">⭐⭐⭐ Average</option>
                        <option value="2">⭐⭐ Poor</option>
                        <option value="1">⭐ Very Poor</option>
                    </select>

                    <label class="form-label">Feedback</label>
                    <textarea
                        name="feedback"
                        class="form-control mb-3"
                        rows="5"
                        placeholder="Share your dining experience..."
                        required></textarea>

                    <button class="btn btn-success">
                        Submit Review
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
