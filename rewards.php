<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM reward_badges
    WHERE user_id = ?
");
$stmt->execute([$userId]);
$badgeCount = (int)$stmt->fetch()['total'];

$stmt = $pdo->prepare("
    SELECT *
    FROM reward_badges
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$badges = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT *
    FROM reward_vouchers
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$vouchers = $stmt->fetchAll();

$nextTarget = $badgeCount < 5 ? 5 : 10;
$progress = min(100, ($badgeCount / $nextTarget) * 100);

include 'includes/header.php';
?>

<a href="view_profile.php" class="btn-nav-icon mb-3">
    <span class="btn-nav-icon-symbol">←</span>
    <span>Profile</span>
</a>

<div class="card ok-card p-4 mb-4">
    <div class="section-title">Customer Rewards</div>
    <h3>My Rewards</h3>
    <p class="muted-small">
        Earn 1 badge each time your booking is completed. Collect badges to unlock vouchers.
    </p>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="stat-pill">
                <strong>Total Badges</strong><br>
                🏅 <?= h($badgeCount) ?> badge(s)
            </div>
        </div>

        <div class="col-md-4">
            <div class="stat-pill">
                <strong>Next Reward Target</strong><br>
                <?= h($nextTarget) ?> badges
            </div>
        </div>

        <div class="col-md-4">
            <div class="stat-pill">
                <strong>Reward Rule</strong><br>
                5 badges = RM10 voucher
            </div>
        </div>
    </div>

    <div class="mt-4">
        <strong>Progress to next voucher</strong>
        <div class="progress mt-2" style="height: 22px;">
            <div class="progress-bar bg-warning text-dark fw-bold" style="width: <?= h($progress) ?>%;">
                <?= h($badgeCount) ?> / <?= h($nextTarget) ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card ok-card p-4">
            <div class="section-title">Badges</div>
            <h4>Badge Collection</h4>

            <?php if (!$badges): ?>
                <div class="alert alert-info">No badges earned yet.</div>
            <?php endif; ?>

            <?php foreach ($badges as $badge): ?>
                <div class="reward-card mb-3">
                    <div class="reward-icon">🏅</div>
                    <div>
                        <strong><?= h($badge['badge_name']) ?></strong>
                        <p class="mb-0 muted-small">
                            Earned on <?= h($badge['created_at']) ?>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card ok-card p-4">
            <div class="section-title">Vouchers</div>
            <h4>My Vouchers</h4>

            <?php if (!$vouchers): ?>
                <div class="alert alert-info">No vouchers unlocked yet.</div>
            <?php endif; ?>

            <?php foreach ($vouchers as $voucher): ?>
                <div class="voucher-card mb-3">
                    <div>
                        <strong><?= h($voucher['voucher_name']) ?></strong>
                        <p class="mb-1 muted-small">
                            Code: <strong><?= h($voucher['voucher_code']) ?></strong>
                        </p>
                        <p class="mb-0 muted-small">
                            Required badges: <?= h($voucher['badge_required']) ?>
                        </p>
                    </div>

                    <?php if ($voucher['is_used']): ?>
                        <span class="badge bg-secondary">Used</span>
                    <?php else: ?>
                        <span class="badge bg-success">Available</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
