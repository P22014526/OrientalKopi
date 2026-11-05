<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_staff();

$date = trim($_GET['date'] ?? '');
$rating = trim($_GET['rating'] ?? '');
$selectedOutletId = (int)($_GET['outlet_id'] ?? 0);
$replyMessage = trim($_GET['reply_message'] ?? '');
$replyError = '';
$assignedOutletIds = staff_assigned_outlet_ids($pdo);
$outletOptionsSql = "
    SELECT o.outlet_id, o.branch_name, s.state_name
    FROM outlets o
    JOIN outlet_states s ON s.state_id = o.state_id
    WHERE o.is_active = 1
";
$outletOptionsParams = [];

if (is_array($assignedOutletIds)) {
    if (!$assignedOutletIds) {
        $outletOptionsSql .= " AND 1=0";
    } else {
        $outletOptionsSql .= " AND o.outlet_id IN (" . implode(',', array_fill(0, count($assignedOutletIds), '?')) . ")";
        $outletOptionsParams = $assignedOutletIds;
    }
}

$outletOptionsSql .= " ORDER BY s.display_order, o.branch_name";
$outletOptionsStmt = $pdo->prepare($outletOptionsSql);
$outletOptionsStmt->execute($outletOptionsParams);
$outletOptions = $outletOptionsStmt->fetchAll();
$filterableOutletIds = array_map('intval', array_column($outletOptions, 'outlet_id'));

if ($selectedOutletId > 0 && !in_array($selectedOutletId, $filterableOutletIds, true)) {
    $selectedOutletId = 0;
}

/*
 * Save or update an administrator reply.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reviewId = (int)($_POST['review_id'] ?? 0);
    $adminReply = trim($_POST['admin_reply'] ?? '');

    $returnDate = trim($_POST['return_date'] ?? '');
    $returnRating = trim($_POST['return_rating'] ?? '');
    $returnOutletId = (int)($_POST['return_outlet_id'] ?? 0);

    if ($reviewId <= 0) {
        $replyError = 'Invalid review selected.';
    } elseif ($adminReply === '') {
        $replyError = 'Please enter a reply before saving.';
    } else {
        $check = $pdo->prepare("
            SELECT r.review_id, b.outlet_id
            FROM reviews r
            JOIN bookings b ON b.booking_id = r.booking_id
            WHERE r.review_id = ?
              AND b.status = 'Completed'
            LIMIT 1
        ");
        $check->execute([$reviewId]);
        $reviewAccess = $check->fetch();

        if (!$reviewAccess) {
            $replyError = 'The selected review could not be found.';
        } elseif (!staff_can_access_outlet($pdo, $reviewAccess['outlet_id'] ?? 0)) {
            $replyError = 'You do not have access to this outlet review.';
        } else {
            $stmt = $pdo->prepare("
                UPDATE reviews
                SET admin_reply = ?,
                    replied_at = NOW(),
                    replied_by = ?
                WHERE review_id = ?
            ");

            $stmt->execute([
                $adminReply,
                $_SESSION['user_id'],
                $reviewId
            ]);

            $query = http_build_query([
                'date' => $returnDate,
                'rating' => $returnRating,
                'outlet_id' => $returnOutletId,
                'reply_message' => 'Reply saved successfully.'
            ]);

            header('Location: view_reviews.php?' . $query);
            exit;
        }
    }
}

$where = [];
$params = [];

$where[] = "b.status = 'Completed'";
$where[] = "r.booking_id IS NOT NULL";

if ($date !== '') {
    $where[] = "DATE(r.created_at) = ?";
    $params[] = $date;
}

if ($rating !== '') {
    $where[] = "r.rating = ?";
    $params[] = $rating;
}

if (is_array($assignedOutletIds)) {
    if (!$assignedOutletIds) {
        $where[] = '1=0';
    } else {
        $where[] = 'b.outlet_id IN (' . implode(',', array_fill(0, count($assignedOutletIds), '?')) . ')';
        $params = array_merge($params, $assignedOutletIds);
    }
}

if ($selectedOutletId > 0) {
    $where[] = "b.outlet_id = ?";
    $params[] = $selectedOutletId;
}

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

$stmt = $pdo->prepare("
    SELECT
        r.*,
        u.full_name,
        u.email,
        b.booking_code,
        b.booking_date,
        b.booking_time,
        b.outlet_id,
        o.branch_name,
        o.location_detail,
        s.state_name,
        admin_user.full_name AS replied_by_name
    FROM reviews r
    JOIN users u
      ON r.user_id = u.user_id
    JOIN bookings b
      ON b.booking_id = r.booking_id
    LEFT JOIN outlets o
      ON o.outlet_id = b.outlet_id
    LEFT JOIN outlet_states s
      ON s.state_id = o.state_id
    LEFT JOIN users admin_user
      ON r.replied_by = admin_user.user_id
    $whereSql
    ORDER BY r.created_at DESC
");

$stmt->execute($params);
$reviews = $stmt->fetchAll();

$totalReviews = count($reviews);
$avgRating = 0;

if ($totalReviews > 0) {
    $sum = 0;

    foreach ($reviews as $review) {
        $sum += (int)$review['rating'];
    }

    $avgRating = $sum / $totalReviews;
}

$ratingCounts = [
    5 => 0,
    4 => 0,
    3 => 0,
    2 => 0,
    1 => 0
];

foreach ($reviews as $review) {
    $ratingCounts[(int)$review['rating']]++;
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customer Reviews</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link rel="stylesheet" href="../assets/style.css?v=51">
</head>

<body>
  <?php include 'staff_sidebar.php'; ?>
    <main class="container py-4">

        <a href="dashboard.php" class="btn-nav-icon mb-3">
            <span class="btn-nav-icon-symbol">←</span>
            <span>Dashboard</span>
        </a>

        <div class="card ok-card p-4 mb-4">
            <div class="section-title">Staff Area</div>
            <h3>Customer Reviews</h3>

            <p class="muted-small">
                View completed-dining feedback by outlet, filter reviews, and publish a staff reply.
            </p>

            <?php if ($replyMessage): ?>
                <div class="alert alert-success">
                    <?= h($replyMessage) ?>
                </div>
            <?php endif; ?>

            <?php if ($replyError): ?>
                <div class="alert alert-danger">
                    <?= h($replyError) ?>
                </div>
            <?php endif; ?>

            <form method="get" class="row g-3 mt-2">
                <div class="col-md-3">
                    <label class="form-label">
                        Filter by Date
                    </label>

                    <input
                        type="date"
                        name="date"
                        class="form-control"
                        value="<?= h($date) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">
                        Filter by Stars
                    </label>

                    <select name="rating" class="form-select">
                        <option value="">All Ratings</option>
                        <option value="5" <?= $rating === '5' ? 'selected' : '' ?>>
                            ⭐⭐⭐⭐⭐ 5 Stars
                        </option>
                        <option value="4" <?= $rating === '4' ? 'selected' : '' ?>>
                            ⭐⭐⭐⭐ 4 Stars
                        </option>
                        <option value="3" <?= $rating === '3' ? 'selected' : '' ?>>
                            ⭐⭐⭐ 3 Stars
                        </option>
                        <option value="2" <?= $rating === '2' ? 'selected' : '' ?>>
                            ⭐⭐ 2 Stars
                        </option>
                        <option value="1" <?= $rating === '1' ? 'selected' : '' ?>>
                            ⭐ 1 Star
                        </option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">
                        Filter by Outlet
                    </label>

                    <select name="outlet_id" class="form-select">
                        <option value="0">All Outlets</option>
                        <?php foreach ($outletOptions as $outlet): ?>
                            <option value="<?= h($outlet['outlet_id']) ?>" <?= $selectedOutletId === (int)$outlet['outlet_id'] ? 'selected' : '' ?>>
                                <?= h($outlet['state_name']) ?> - <?= h($outlet['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3 align-self-end">
                    <button class="btn btn-dark">
                        Apply Filter
                    </button>

                    <a
                        href="view_reviews.php"
                        class="btn btn-outline-dark">
                        Reset
                    </a>
                </div>
            </form>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card ok-card p-3">
                    Total Reviews:
                    <strong><?= h($totalReviews) ?></strong>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card ok-card p-3">
                    Average Rating:
                    <strong>
                        <?= $totalReviews > 0
                            ? h(number_format($avgRating, 1)) . ' / 5'
                            : '-' ?>
                    </strong>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card ok-card p-3">
                    Highest Rating Count:
                    <strong>
                        <?= h($ratingCounts[5]) ?> review(s)
                    </strong>
                </div>
            </div>
        </div>

        <div class="card ok-card p-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <div class="section-title">Review List</div>
                    <h4 class="mb-0">
                        Submitted Customer Feedback
                    </h4>
                </div>

                <?php if ($date || $rating || $selectedOutletId): ?>
                    <span class="badge bg-warning text-dark">
                        Filtered Results
                    </span>
                <?php endif; ?>
            </div>

            <?php if (!$reviews): ?>
                <div class="alert alert-info">
                    No reviews found for the selected filter.
                </div>
            <?php else: ?>

                <div class="row g-3">
                    <?php foreach ($reviews as $r): ?>
                        <?php
                        $feedback = trim((string)$r['feedback']);
                        $isLongReview = mb_strlen($feedback) > 180;
                        $reviewTextId = 'staff-review-text-' . (int)$r['review_id'];
                        ?>
                        <div class="col-12">
                            <div class="review-card p-3">
                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                    <div>
                                        <strong><?= h($r['full_name']) ?></strong>

                                        <div class="muted-small">
                                            <?= h($r['email']) ?>
                                        </div>

                                        <div class="muted-small mt-1">
                                            Booking:
                                            <strong><?= h($r['booking_code']) ?></strong>
                                            |
                                            <?= h(format_date_short($r['booking_date'])) ?>
                                            <?= h(format_time12($r['booking_time'])) ?>
                                        </div>
                                    </div>

                                    <small class="text-muted">
                                        <?= h(date(
                                            'd/m/Y h:i A',
                                            strtotime($r['created_at'])
                                        )) ?>
                                    </small>
                                </div>

                                <div class="review-stars my-2">
                                    <?= str_repeat('⭐', (int)$r['rating']) ?>
                                </div>

                                <div class="review-outlet-pill mb-3">
                                    <strong><?= h($r['branch_name'] ?? 'Unassigned Outlet') ?></strong>
                                    <span><?= h($r['state_name'] ?? '-') ?> - <?= h($r['location_detail'] ?? '-') ?></span>
                                </div>

                                <div class="border rounded p-3 bg-white mb-3">
                                    <div class="review-text-wrapper">
                                        <div
                                            id="<?= h($reviewTextId) ?>"
                                            class="review-text <?= $isLongReview ? 'is-collapsed' : '' ?>">
                                            <?= nl2br(h($feedback)) ?>
                                        </div>

                                        <?php if ($isLongReview): ?>
                                            <button
                                                type="button"
                                                class="review-toggle-button"
                                                data-review-target="<?= h($reviewTextId) ?>"
                                                aria-controls="<?= h($reviewTextId) ?>"
                                                aria-expanded="false">
                                                <span
                                                    class="review-toggle-arrow"
                                                    aria-hidden="true">
                                                    ⌄
                                                </span>

                                                <span class="review-toggle-label">
                                                    Show full comment
                                                </span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if (!empty($r['admin_reply'])): ?>
                                    <div class="alert alert-warning border-start border-4 mb-3">
                                        <div class="d-flex justify-content-between flex-wrap gap-2">
                                            <strong>Current Staff Reply</strong>

                                            <?php if (!empty($r['replied_at'])): ?>
                                                <small>
                                                    <?= h(date(
                                                        'd/m/Y h:i A',
                                                        strtotime($r['replied_at'])
                                                    )) ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>

                                        <p class="mb-1 mt-2">
                                            <?= nl2br(h($r['admin_reply'])) ?>
                                        </p>

                                        <?php if (!empty($r['replied_by_name'])): ?>
                                            <small class="text-muted">
                                                Replied by:
                                                <?= h($r['replied_by_name']) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <form method="post">
                                    <input
                                        type="hidden"
                                        name="review_id"
                                        value="<?= h($r['review_id']) ?>">

                                    <input
                                        type="hidden"
                                        name="return_date"
                                        value="<?= h($date) ?>">

                                    <input
                                        type="hidden"
                                        name="return_rating"
                                        value="<?= h($rating) ?>">

                                    <input
                                        type="hidden"
                                        name="return_outlet_id"
                                        value="<?= h($selectedOutletId) ?>">

                                    <label
                                        for="reply-<?= h($r['review_id']) ?>"
                                        class="form-label">
                                        <?= empty($r['admin_reply'])
                                            ? 'Reply to Review'
                                            : 'Update Staff Reply' ?>
                                    </label>

                                    <textarea
                                        id="reply-<?= h($r['review_id']) ?>"
                                        name="admin_reply"
                                        class="form-control"
                                        rows="3"
                                        maxlength="1000"
                                        placeholder="Write a professional response to the customer..."
                                        required><?= h($r['admin_reply'] ?? '') ?></textarea>

                                    <button class="btn btn-dark mt-2">
                                        <?= empty($r['admin_reply'])
                                            ? 'Send Reply'
                                            : 'Update Reply' ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <hr>

                <h5>Rating Summary</h5>

                <div class="row g-2">
                    <?php foreach ($ratingCounts as $star => $count): ?>
                        <div class="col-md-2 col-6">
                            <div class="stat-pill text-center">
                                <strong><?= h($star) ?> ⭐</strong><br>
                                <?= h($count) ?> review(s)
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>
        </div>

    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.review-toggle-button').forEach(function(button) {
                const reviewText = document.getElementById(button.dataset.reviewTarget);
                const label = button.querySelector('.review-toggle-label');

                if (!reviewText || !label) {
                    return;
                }

                const lineHeight = parseFloat(window.getComputedStyle(reviewText).lineHeight) || 26;
                const collapsedHeight = Math.ceil(lineHeight * 3);

                reviewText.style.maxHeight = collapsedHeight + 'px';

                button.addEventListener('click', function() {
                    const isExpanded = button.getAttribute('aria-expanded') === 'true';

                    if (isExpanded) {
                        reviewText.style.maxHeight = reviewText.scrollHeight + 'px';

                        requestAnimationFrame(function() {
                            reviewText.classList.remove('is-expanded');
                            reviewText.classList.add('is-collapsed');
                            reviewText.style.maxHeight = collapsedHeight + 'px';
                        });

                        button.setAttribute('aria-expanded', 'false');
                        label.textContent = 'Show full comment';
                    } else {
                        reviewText.classList.remove('is-collapsed');
                        reviewText.classList.add('is-expanded');
                        reviewText.style.maxHeight = reviewText.scrollHeight + 'px';
                        button.setAttribute('aria-expanded', 'true');
                        label.textContent = 'Hide comment';
                    }
                });
            });
        });
    </script>
</body>

</html>
