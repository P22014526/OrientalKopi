<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();

$stmt = $pdo->prepare("
    SELECT
        r.review_id,
        r.rating,
        r.feedback,
        r.admin_reply,
        r.replied_at,
        r.created_at,
        u.full_name,
        admin_user.full_name AS replied_by_name
    FROM reviews r
    JOIN users u
      ON u.user_id = r.user_id
    LEFT JOIN users admin_user
      ON r.replied_by = admin_user.user_id
    ORDER BY r.created_at DESC
");

$stmt->execute();
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

            <?php if (!$reviews): ?>
                <div class="alert alert-info">
                    No customer reviews have been submitted yet.
                </div>
            <?php endif; ?>

            <?php foreach ($reviews as $r): ?>
                <?php
                $feedback = trim((string)$r['feedback']);
                $isLongReview = mb_strlen($feedback) > 180;
                $reviewTextId =
                    'review-text-' . (int)$r['review_id'];
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
                                    Read full review
                                </span>
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($r['admin_reply'])): ?>
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
                Math.ceil(lineHeight * 3);

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
                        'Read full review';
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
                        'Hide full review';
                }
            });
        });
    });
</script>

<?php include 'includes/footer.php'; ?>