<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();

$selectedOutletId = (int)($_GET['outlet_id'] ?? 0);
$outletOptions = all_states_with_outlets($pdo);
$flatOutletOptions = [];

foreach ($outletOptions as $state) {
    foreach ($state['outlets'] as $outlet) {
        $flatOutletOptions[] = [
            'outlet_id' => (int)$outlet['outlet_id'],
            'state_name' => $state['state_name'],
            'branch_name' => $outlet['branch_name']
        ];
    }
}

$filterableOutletIds = array_map('intval', array_column($flatOutletOptions, 'outlet_id'));

if ($selectedOutletId > 0 && !in_array($selectedOutletId, $filterableOutletIds, true)) {
    $selectedOutletId = 0;
}

$where = ["b.status = 'Completed'", "r.booking_id IS NOT NULL"];
$params = [];

if ($selectedOutletId > 0) {
    $where[] = "b.outlet_id = ?";
    $params[] = $selectedOutletId;
}

$stmt = $pdo->prepare("
    SELECT
        r.review_id,
        r.rating,
        r.feedback,
        r.admin_reply,
        r.replied_at,
        r.created_at,
        u.full_name,
        b.booking_code,
        o.branch_name,
        o.location_detail,
        s.state_name,
        admin_user.full_name AS replied_by_name
    FROM reviews r
    JOIN users u
      ON u.user_id = r.user_id
    JOIN bookings b
      ON b.booking_id = r.booking_id
    LEFT JOIN outlets o
      ON o.outlet_id = b.outlet_id
    LEFT JOIN outlet_states s
      ON s.state_id = o.state_id
    LEFT JOIN users admin_user
      ON r.replied_by = admin_user.user_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY r.created_at DESC
");

$stmt->execute($params);
$reviews = $stmt->fetchAll();

include 'includes/header.php';
?>


<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="card ok-card p-4">
            <div class="section-title">Recent Reviews</div>
            <h3>Customer Feedback</h3>

            <p class="muted-small">
                Reviews can only be written from My Bookings after a reservation has been marked as Completed.
            </p>

            <form method="get" class="row g-2 mb-4">
                <div class="col-md-8">
                    <label class="form-label">Filter by Outlet</label>
                    <select name="outlet_id" class="form-select">
                        <option value="0">All Outlets</option>
                        <?php foreach ($flatOutletOptions as $outlet): ?>
                            <option value="<?= h($outlet['outlet_id']) ?>" <?= $selectedOutletId === (int)$outlet['outlet_id'] ? 'selected' : '' ?>>
                                <?= h($outlet['state_name']) ?> - <?= h($outlet['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4 align-self-end">
                    <button class="btn btn-dark">Apply Filter</button>
                    <a href="review.php" class="btn btn-outline-dark">Reset</a>
                </div>
            </form>

            <?php if (!$reviews): ?>
                <div class="alert alert-info">
                    No customer reviews have been submitted yet.
                </div>
            <?php endif; ?>

            <?php foreach ($reviews as $r): ?>
                <?php
                $feedback = trim((string)$r['feedback']);
                $adminReply = trim((string)($r['admin_reply'] ?? ''));
                $isLongReview = mb_strlen($feedback . ' ' . $adminReply) > 180;
                $reviewContentId =
                    'review-content-' . (int)$r['review_id'];
                ?>

                <article class="review-card review-list-card mb-3">
                    <div class="d-flex justify-content-between flex-wrap gap-2">
                        <strong><?= h($r['full_name']) ?></strong>

                        <small class="text-muted">
                            <?= h(date(
                                'd/m/Y h:i A',
                                strtotime($r['created_at'])
                            )) ?>
                        </small>
                    </div>

                    <div
                        class="review-stars my-2"
                        aria-label="<?= h($r['rating']) ?> out of 5 stars">
                        <?= str_repeat('⭐', (int)$r['rating']) ?>
                    </div>

                    <div class="review-outlet-pill mb-3">
                        <strong><?= h($r['branch_name'] ?? 'Unassigned Outlet') ?></strong>
                        <span><?= h($r['state_name'] ?? '-') ?> - <?= h($r['location_detail'] ?? '-') ?></span>
                    </div>

                    <div class="review-text-wrapper">
                        <div
                            id="<?= h($reviewContentId) ?>"
                            class="review-content <?= $isLongReview ? 'is-collapsed' : '' ?>">
                            <div class="review-text">
                                <?= nl2br(h($feedback)) ?>
                            </div>

                            <?php if ($adminReply !== ''): ?>
                                <div class="alert alert-warning border-start border-4 mt-3 mb-0">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                        <strong>
                                            Oriental Kopi Response
                                        </strong>

                                        <?php if (!empty($r['replied_at'])): ?>
                                            <small class="text-muted">
                                                <?= h(date(
                                                    'd/m/Y h:i A',
                                                    strtotime($r['replied_at'])
                                                )) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>

                                    <p class="mb-1 mt-2">
                                        <?= nl2br(h($adminReply)) ?>
                                    </p>

                                    <?php if (!empty($r['replied_by_name'])): ?>
                                        <small class="text-muted">
                                            Replied by:
                                            <?= h($r['replied_by_name']) ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($isLongReview): ?>
                            <button
                                type="button"
                                class="review-toggle-button"
                                data-review-target="<?= h($reviewContentId) ?>"
                                aria-controls="<?= h($reviewContentId) ?>"
                                aria-expanded="false">

                                <span
                                    class="review-toggle-arrow"
                                    aria-hidden="true">
                                    ⌄
                                </span>

                                <span class="review-toggle-label">
                                    Show full review
                                </span>
                            </button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.review-toggle-button').forEach(function(button) {
            const reviewText = document.getElementById(
                button.dataset.reviewTarget
            );

            const label = button.querySelector(
                '.review-toggle-label'
            );

            if (!reviewText || !label) {
                return;
            }

            const lineHeight =
                parseFloat(
                    window.getComputedStyle(reviewText).lineHeight
                ) || 26;

            const collapsedHeight =
                Math.ceil(lineHeight * 4);

            reviewText.style.maxHeight =
                collapsedHeight + 'px';

            button.addEventListener('click', function() {
                const isExpanded =
                    button.getAttribute('aria-expanded') === 'true';

                if (isExpanded) {
                    reviewText.style.maxHeight =
                        reviewText.scrollHeight + 'px';

                    requestAnimationFrame(function() {
                        reviewText.classList.remove('is-expanded');
                        reviewText.classList.add('is-collapsed');
                        reviewText.style.maxHeight =
                            collapsedHeight + 'px';
                    });

                    button.setAttribute(
                        'aria-expanded',
                        'false'
                    );

                    label.textContent =
                        'Show full review';
                } else {
                    reviewText.classList.remove('is-collapsed');
                    reviewText.classList.add('is-expanded');

                    reviewText.style.maxHeight =
                        reviewText.scrollHeight + 'px';

                    button.setAttribute(
                        'aria-expanded',
                        'true'
                    );

                    label.textContent =
                        'Hide review';
                }
            });
        });
    });
</script>

<?php include 'includes/footer.php'; ?>
