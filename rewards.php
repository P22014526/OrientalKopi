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

$rewardProgress = reward_progress($badgeCount);

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
                <?= h($rewardProgress['next_voucher']) ?><br>
                <span class="muted-small"><?= h($rewardProgress['remaining']) ?> more badge(s)</span>
            </div>
        </div>

        <div class="col-md-4">
            <div class="stat-pill">
                <strong>Reward Rule</strong><br>
                5 badges = RM10, another 10 = RM25.<br>
                <span class="muted-small">The cycle then repeats.</span>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <div class="d-flex justify-content-between flex-wrap gap-2">
            <strong>Progress to next voucher</strong>
            <span><?= h($rewardProgress['current']) ?> / <?= h($rewardProgress['target']) ?> badges</span>
        </div>
        <div class="progress mt-2" style="height: 22px;"
             role="progressbar" aria-label="Progress to <?= h($rewardProgress['next_voucher']) ?>"
             aria-valuemin="0" aria-valuemax="<?= h($rewardProgress['target']) ?>"
             aria-valuenow="<?= h($rewardProgress['current']) ?>">
            <div class="progress-bar bg-warning" style="width: <?= h($rewardProgress['percent']) ?>%;"></div>
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
                <?php $voucherIsUsed = !empty($voucher['is_used']); ?>
                <div class="voucher-card mb-3 <?= $voucherIsUsed ? 'is-used' : '' ?>">
                    <div class="voucher-card-copy">
                        <strong><?= h($voucher['voucher_name']) ?></strong>
                        <p class="mb-1 muted-small">
                            Code: <strong><?= h($voucher['voucher_code']) ?></strong>
                        </p>
                        <p class="mb-0 muted-small">
                            Earned at <?= h($voucher['badge_required']) ?> total badges
                        </p>

                        <?php if (!$voucherIsUsed): ?>
                            <p class="voucher-scan-note mb-0">
                                Show this QR code to staff when redeeming your voucher.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="voucher-card-visual">
                        <?php if ($voucherIsUsed): ?>
                            <div class="voucher-used-mark" aria-hidden="true">✓</div>
                            <span class="badge bg-secondary">Used</span>
                        <?php else: ?>
                            <div
                                class="voucher-qr"
                                data-voucher-code="<?= h($voucher['voucher_code']) ?>"
                                aria-label="QR code for voucher <?= h($voucher['voucher_code']) ?>"></div>
                            <span class="badge bg-success">Available</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($vouchers): ?>
    <script
        src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"
        integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA=="
        crossorigin="anonymous"
        referrerpolicy="no-referrer"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.voucher-qr').forEach(function(container) {
                const voucherCode = container.dataset.voucherCode;

                if (!voucherCode || typeof QRCode === 'undefined') {
                    container.textContent = 'Use voucher code';
                    container.classList.add('voucher-qr-fallback');
                    return;
                }

                const redeemUrl = new URL('admin/redeem_voucher.php', window.location.href);
                redeemUrl.searchParams.set('code', voucherCode);

                new QRCode(container, {
                    text: redeemUrl.href,
                    width: 132,
                    height: 132,
                    colorDark: '#0e2133',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.H
                });
            });
        });
    </script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
