<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_staff();

if (empty($_SESSION['voucher_redeem_csrf'])) {
    $_SESSION['voucher_redeem_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['voucher_redeem_csrf'];
$assignedOutletIds = staff_assigned_outlet_ids($pdo);
$outletSql = "
    SELECT o.outlet_id, o.branch_name, s.state_name
    FROM outlets o
    JOIN outlet_states s ON s.state_id = o.state_id
    WHERE o.is_active = 1
";
$outletParams = [];

if (is_array($assignedOutletIds)) {
    if (!$assignedOutletIds) {
        $outletSql .= ' AND 1=0';
    } else {
        $outletSql .= ' AND o.outlet_id IN (' . implode(',', array_fill(0, count($assignedOutletIds), '?')) . ')';
        $outletParams = $assignedOutletIds;
    }
}

$outletSql .= ' ORDER BY s.display_order, o.branch_name';
$outletStmt = $pdo->prepare($outletSql);
$outletStmt->execute($outletParams);
$outlets = $outletStmt->fetchAll();
$allowedOutletIds = array_map('intval', array_column($outlets, 'outlet_id'));

$voucherCode = strtoupper(trim($_POST['voucher_code'] ?? $_GET['code'] ?? ''));
$selectedOutletId = (int)($_POST['outlet_id'] ?? 0);
$error = '';
$redeemedVoucherCode = (string)($_SESSION['voucher_redeem_success'] ?? '');
unset($_SESSION['voucher_redeem_success']);
$success = $voucherCode !== '' && hash_equals($voucherCode, $redeemedVoucherCode);
$voucher = null;

if ($voucherCode !== '' && !preg_match('/^OK-[A-Z0-9]{6,32}$/', $voucherCode)) {
    $error = 'The scanned voucher code is not valid.';
    $voucherCode = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        $error = 'Your session could not be verified. Please refresh the page and try again.';
    } elseif ($voucherCode === '') {
        $error = 'Enter or scan a voucher code first.';
    } elseif (!in_array($selectedOutletId, $allowedOutletIds, true)) {
        $error = 'Select an outlet that you are allowed to manage.';
    } else {
        try {
            $pdo->beginTransaction();

            $voucherStmt = $pdo->prepare("
                SELECT rv.*, u.full_name, u.email
                FROM reward_vouchers rv
                JOIN users u ON u.user_id = rv.user_id
                WHERE rv.voucher_code = ?
                LIMIT 1
                FOR UPDATE
            ");
            $voucherStmt->execute([$voucherCode]);
            $lockedVoucher = $voucherStmt->fetch();

            if (!$lockedVoucher) {
                throw new DomainException('Voucher not found. Check the code and try again.');
            }

            if (!empty($lockedVoucher['is_used'])) {
                throw new DomainException('This voucher has already been redeemed.');
            }

            $updateStmt = $pdo->prepare("
                UPDATE reward_vouchers
                SET is_used = 1
                WHERE voucher_id = ? AND is_used = 0
            ");
            $updateStmt->execute([$lockedVoucher['voucher_id']]);

            if ($updateStmt->rowCount() !== 1) {
                throw new DomainException('This voucher could not be redeemed. Please try again.');
            }

            $pdo->commit();

            log_staff_activity(
                $pdo,
                'Redeemed reward voucher',
                ['outlet_id' => $selectedOutletId],
                $voucherCode . ' for ' . $lockedVoucher['full_name']
            );

            $_SESSION['voucher_redeem_success'] = $voucherCode;
            header('Location: redeem_voucher.php?' . http_build_query(['code' => $voucherCode]));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e instanceof DomainException
                ? $e->getMessage()
                : 'The voucher could not be redeemed right now. Please try again.';
        }
    }
}

if ($voucherCode !== '') {
    $voucherStmt = $pdo->prepare("
        SELECT rv.*, u.full_name, u.email
        FROM reward_vouchers rv
        JOIN users u ON u.user_id = rv.user_id
        WHERE rv.voucher_code = ?
        LIMIT 1
    ");
    $voucherStmt->execute([$voucherCode]);
    $voucher = $voucherStmt->fetch();

    if (!$voucher && $error === '') {
        $error = 'Voucher not found. Check the code and try again.';
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Voucher Scanner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
    <?php include 'staff_sidebar.php'; ?>

    <main class="container py-4">
        <a href="dashboard.php" class="btn-nav-icon mb-3">
            <span class="btn-nav-icon-symbol">&larr;</span>
            <span>Dashboard</span>
        </a>

        <section class="dashboard-panel" aria-labelledby="voucher-scanner-title">
            <div class="dashboard-section-heading">
                <div>
                    <div class="section-title">Customer Rewards</div>
                    <h3 id="voucher-scanner-title" class="mb-1">Voucher Scanner</h3>
                    <p class="muted-small mb-0">Scan, verify, and redeem a customer's reward voucher.</p>
                </div>
                <span class="dashboard-date-pill"><?= h(format_date_short(date('Y-m-d'))) ?></span>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success" role="status">
                    Voucher <strong><?= h($voucherCode) ?></strong> was redeemed successfully.
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= h($error) ?></div>
            <?php endif; ?>

            <div class="voucher-scanner-shell">
                <div class="voucher-scanner-panel">
                    <h5>Scan QR Code</h5>
                    <p class="muted-small">Use the staff device camera or select a saved QR image.</p>

                    <div id="voucher-reader" class="voucher-reader" aria-label="QR scanner camera preview"></div>
                    <div id="voucher-file-reader" class="d-none"></div>

                    <div class="voucher-scanner-actions mt-3">
                        <button type="button" class="btn btn-dark" id="start-voucher-camera">Start Camera</button>
                        <button type="button" class="btn btn-outline-dark d-none" id="stop-voucher-camera">Stop Camera</button>
                        <label class="btn btn-outline-dark mb-0" for="voucher-image-input">Choose QR Image</label>
                        <input class="visually-hidden" type="file" id="voucher-image-input" accept="image/*" capture="environment">
                    </div>

                    <p id="voucher-scanner-message" class="voucher-scanner-message mt-2 mb-0" aria-live="polite"></p>
                </div>

                <div class="voucher-lookup-panel">
                    <h5>Enter Voucher Code</h5>
                    <p class="muted-small">Use this when the camera is unavailable.</p>

                    <form method="get" class="mb-4">
                        <label class="form-label" for="voucher-code">Voucher code</label>
                        <div class="input-group">
                            <input
                                class="form-control text-uppercase"
                                id="voucher-code"
                                name="code"
                                value="<?= h($voucherCode) ?>"
                                placeholder="OK-XXXXXXXX"
                                maxlength="35"
                                autocomplete="off"
                                required>
                            <button class="btn btn-dark" type="submit">Check Voucher</button>
                        </div>
                    </form>

                    <?php if ($voucher): ?>
                        <div class="voucher-validation-card p-3">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                                <div>
                                    <div class="section-title">Voucher Details</div>
                                    <h5 class="mb-0"><?= h($voucher['voucher_name']) ?></h5>
                                </div>
                                <?php if (!empty($voucher['is_used'])): ?>
                                    <span class="badge bg-secondary">Used</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Available</span>
                                <?php endif; ?>
                            </div>

                            <dl class="voucher-detail-list">
                                <div>
                                    <dt>Customer</dt>
                                    <dd><?= h($voucher['full_name']) ?></dd>
                                </div>
                                <div>
                                    <dt>Email</dt>
                                    <dd><?= h($voucher['email']) ?></dd>
                                </div>
                                <div>
                                    <dt>Voucher code</dt>
                                    <dd><?= h($voucher['voucher_code']) ?></dd>
                                </div>
                                <div>
                                    <dt>Unlocked</dt>
                                    <dd><?= h(format_date_short($voucher['created_at'])) ?></dd>
                                </div>
                            </dl>

                            <?php if (empty($voucher['is_used'])): ?>
                                <form method="post" class="mt-3">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="voucher_code" value="<?= h($voucher['voucher_code']) ?>">

                                    <label for="redeem-outlet" class="form-label">Redeeming outlet</label>
                                    <select class="form-select mb-3" id="redeem-outlet" name="outlet_id" required>
                                        <option value="">Select outlet</option>
                                        <?php foreach ($outlets as $outlet): ?>
                                            <option
                                                value="<?= h($outlet['outlet_id']) ?>"
                                                <?= $selectedOutletId === (int)$outlet['outlet_id'] ? 'selected' : '' ?>>
                                                <?= h($outlet['state_name']) ?> - <?= h($outlet['branch_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <?php if (!$outlets): ?>
                                        <div class="alert alert-warning mb-0">No outlet is assigned to this account.</div>
                                    <?php else: ?>
                                        <button class="btn btn-success w-100" type="submit">
                                            Confirm and Redeem Voucher
                                        </button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>

    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const startButton = document.getElementById('start-voucher-camera');
            const stopButton = document.getElementById('stop-voucher-camera');
            const imageInput = document.getElementById('voucher-image-input');
            const message = document.getElementById('voucher-scanner-message');
            let cameraScanner = null;
            let cameraRunning = false;
            let scanHandled = false;

            function setMessage(text, isError) {
                message.textContent = text;
                message.classList.toggle('is-error', Boolean(isError));
            }

            function extractVoucherCode(decodedText) {
                const plainCode = decodedText.trim().toUpperCase();

                if (/^OK-[A-Z0-9]{6,32}$/.test(plainCode)) {
                    return plainCode;
                }

                try {
                    const scannedUrl = new URL(decodedText);
                    const urlCode = (scannedUrl.searchParams.get('code') || '').trim().toUpperCase();
                    return /^OK-[A-Z0-9]{6,32}$/.test(urlCode) ? urlCode : '';
                } catch (error) {
                    return '';
                }
            }

            async function stopCamera() {
                if (!cameraScanner || !cameraRunning) {
                    return;
                }

                try {
                    await cameraScanner.stop();
                    await cameraScanner.clear();
                } catch (error) {
                    // The camera may already have been released after a successful scan.
                }

                cameraRunning = false;
                startButton.classList.remove('d-none');
                stopButton.classList.add('d-none');
            }

            async function handleScan(decodedText) {
                if (scanHandled) {
                    return;
                }

                const voucherCode = extractVoucherCode(decodedText);

                if (!voucherCode) {
                    setMessage('This QR code is not an Oriental Kopi reward voucher.', true);
                    return;
                }

                scanHandled = true;
                setMessage('Voucher found. Opening verification...', false);
                await stopCamera();
                window.location.href = 'redeem_voucher.php?code=' + encodeURIComponent(voucherCode);
            }

            startButton.addEventListener('click', async function() {
                if (typeof Html5Qrcode === 'undefined') {
                    setMessage('The QR scanner could not be loaded. Enter the voucher code instead.', true);
                    return;
                }

                scanHandled = false;
                setMessage('Requesting camera access...', false);
                cameraScanner = cameraScanner || new Html5Qrcode('voucher-reader');

                try {
                    await cameraScanner.start(
                        { facingMode: 'environment' },
                        { fps: 10, qrbox: { width: 240, height: 240 } },
                        handleScan,
                        function() {}
                    );
                    cameraRunning = true;
                    startButton.classList.add('d-none');
                    stopButton.classList.remove('d-none');
                    setMessage('Point the camera at the customer voucher QR code.', false);
                } catch (error) {
                    setMessage('Camera access is unavailable. Allow camera permission or choose a QR image.', true);
                }
            });

            stopButton.addEventListener('click', async function() {
                await stopCamera();
                setMessage('Camera stopped.', false);
            });

            imageInput.addEventListener('change', async function() {
                const file = imageInput.files && imageInput.files[0];

                if (!file || typeof Html5Qrcode === 'undefined') {
                    return;
                }

                await stopCamera();
                scanHandled = false;
                setMessage('Reading QR image...', false);

                try {
                    const fileScanner = new Html5Qrcode('voucher-file-reader');
                    const decodedText = await fileScanner.scanFile(file, false);
                    await fileScanner.clear();
                    await handleScan(decodedText);
                } catch (error) {
                    setMessage('No valid voucher QR code was found in that image.', true);
                } finally {
                    imageInput.value = '';
                }
            });

            window.addEventListener('pagehide', stopCamera);
        });
    </script>
</body>

</html>
