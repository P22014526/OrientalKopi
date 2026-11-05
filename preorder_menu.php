<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();

$bookingId = $_GET['id'] ?? 0;
$success = '';
$error = '';

function reset_preorder_to_draft(PDO $pdo, int $bookingId, int $userId): void
{
    $stmt = $pdo->prepare("
        UPDATE preorders
        SET status = 'Draft',
            submitted_at = NULL
        WHERE booking_id = ?
        AND user_id = ?
    ");
    $stmt->execute([$bookingId, $userId]);
}

$stmt = $pdo->prepare("
    SELECT *
    FROM bookings
    WHERE booking_id = ?
    AND user_id = ?
");
$stmt->execute([$bookingId, $_SESSION['user_id']]);
$booking = $stmt->fetch();

if (!$booking) {
    header('Location: my_bookings.php');
    exit;
}

if ($booking['status'] !== 'Confirmed') {
    $error = 'Preorder is only available for confirmed bookings.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $booking['status'] === 'Confirmed') {

    $action = $_POST['action'] ?? 'add';

    if ($action === 'submit_preorder') {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total
            FROM preorders
            WHERE booking_id = ?
            AND user_id = ?
        ");
        $stmt->execute([$bookingId, $_SESSION['user_id']]);
        $total = $stmt->fetch()['total'];

        if ($total <= 0) {
            $error = 'Please add at least one menu item before submitting.';
        } else {
            $stmt = $pdo->prepare("
                UPDATE preorders
                SET status = 'Submitted',
                    submitted_at = NOW()
                WHERE booking_id = ?
                AND user_id = ?
            ");
            $stmt->execute([$bookingId, $_SESSION['user_id']]);

            $success = 'Your preorder has been submitted successfully.';
        }
    } elseif ($action === 'update_quantity') {
        $preorderId = (int)($_POST['preorder_id'] ?? 0);
        $newQuantity = (int)($_POST['new_quantity'] ?? 1);

        if ($newQuantity < 1) {
            $newQuantity = 1;
        }

        $stmt = $pdo->prepare("
            SELECT preorder_id
            FROM preorders
            WHERE preorder_id = ?
            AND booking_id = ?
            AND user_id = ?
        ");
        $stmt->execute([$preorderId, $bookingId, $_SESSION['user_id']]);
        $preorder = $stmt->fetch();

        if (!$preorder) {
            $error = 'Selected preorder item could not be found.';
        } else {
            $stmt = $pdo->prepare("
                UPDATE preorders
                SET quantity = ?
                WHERE preorder_id = ?
                AND booking_id = ?
                AND user_id = ?
            ");
            $stmt->execute([
                $newQuantity,
                $preorderId,
                $bookingId,
                $_SESSION['user_id']
            ]);

            $success = 'Item quantity updated.';
            reset_preorder_to_draft($pdo, (int)$bookingId, (int)$_SESSION['user_id']);
        }
    } elseif ($action === 'remove_item') {
        $preorderId = (int)($_POST['preorder_id'] ?? 0);

        $stmt = $pdo->prepare("
            DELETE FROM preorders
            WHERE preorder_id = ?
            AND booking_id = ?
            AND user_id = ?
        ");
        $stmt->execute([$preorderId, $bookingId, $_SESSION['user_id']]);

        if ($stmt->rowCount() > 0) {
            $success = 'Item removed from your preorder list.';
            reset_preorder_to_draft($pdo, (int)$bookingId, (int)$_SESSION['user_id']);
        } else {
            $error = 'Selected preorder item could not be found.';
        }
    } else {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 1);

        if ($quantity < 1) {
            $quantity = 1;
        }

        $check = $pdo->prepare("SELECT * FROM menu_items WHERE item_id=? AND is_available=1");
        $check->execute([$itemId]);
        $item = $check->fetch();

        if (!$item) {
            $error = 'Selected menu item is not available.';
        } else {
            $existing = $pdo->prepare("
                SELECT *
                FROM preorders
                WHERE booking_id=? AND user_id=? AND item_id=?
            ");
            $existing->execute([$bookingId, $_SESSION['user_id'], $itemId]);
            $row = $existing->fetch();

            if ($row) {
                $stmt = $pdo->prepare("
                    UPDATE preorders
                    SET quantity = quantity + ?,
                        status = 'Draft',
                        submitted_at = NULL
                    WHERE preorder_id = ?
                ");
                $stmt->execute([$quantity, $row['preorder_id']]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO preorders (booking_id, user_id, item_id, quantity, status)
                    VALUES (?, ?, ?, ?, 'Draft')
                ");
                $stmt->execute([$bookingId, $_SESSION['user_id'], $itemId, $quantity]);
            }

            reset_preorder_to_draft($pdo, (int)$bookingId, (int)$_SESSION['user_id']);
            $success = 'Menu item added to preorder list. Please submit preorder when ready.';
        }
    }
}

$allowedCategories = ['Signature Menu', 'Dessert / Pastry', 'Toast', 'Snack', 'Beverage', 'Dessert / Beverage'];
$categoryLabels = [
    'Signature Menu' => 'Signature Menu',
    'Dessert / Pastry' => 'Dessert / Pastry',
    'Toast' => 'Toast',
    'Snack' => 'Snack',
    'Beverage' => 'Beverage',
    'Dessert / Beverage' => 'Dessert / Beverage'
];
$selectedCategory = trim($_GET['category'] ?? '');

if (!in_array($selectedCategory, $allowedCategories, true)) {
    $selectedCategory = '';
}

$sql = "
    SELECT *
    FROM menu_items
    WHERE is_available = 1
";
$params = [];

if ($selectedCategory !== '') {
    $sql .= " AND category = ?";
    $params[] = $selectedCategory;
}

$sql .= " ORDER BY category, item_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT p.*, m.item_name, m.category, m.price, m.image_path
    FROM preorders p
    JOIN menu_items m ON p.item_id = m.item_id
    WHERE p.booking_id=? AND p.user_id=?
    ORDER BY p.created_at DESC
");
$stmt->execute([$bookingId, $_SESSION['user_id']]);
$cart = $stmt->fetchAll();

include 'includes/header.php';
?>

<a href="my_bookings.php" class="btn-nav-icon mb-3">
    <span class="btn-nav-icon-symbol">←</span>
    <span>My Bookings</span>
</a>

<div id="preorder-menu" class="row g-4 food-catalog">
    <div class="col-lg-7">
        <div class="card ok-card p-4">
            <div class="section-title">Menu Preorder</div>
            <h3>Choose Food for Booking <?= h($booking['booking_code']) ?></h3>
            <a class="btn btn-outline-dark d-lg-none mb-3" href="#preorder-cart">
                View Food List (<?= h(count($cart)) ?>)
            </a>

            <p class="muted-small">
                Booking Date:
                <strong><?= h(date('d/m/Y', strtotime($booking['booking_date']))) ?></strong>
                |
                Time:
                <strong><?= h(format_time12($booking['booking_time'])) ?></strong>
                |
                Pax:
                <strong><?= h($booking['pax']) ?></strong>
            </p>

            <div class="alert alert-info preorder-notice">
                <strong>Food preorder is optional.</strong>
                Submitted items will be <strong>reviewed</strong> with you when you arrive before being sent to the kitchen.
            </div>

            <?php if (isset($_GET['new_booking']) && $_GET['new_booking'] === '1'): ?>
                <div class="alert alert-success">
                    Your booking has been confirmed. You may now choose food for this reservation.
                    Items remain as <strong>Draft</strong> until you click <strong>Submit Preorder</strong>.
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= h($success) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif; ?>

            <div class="menu-filter-bar mb-4">
                <a href="preorder_menu.php?id=<?= h($bookingId) ?>"
                    class="btn <?= $selectedCategory === '' ? 'btn-dark' : 'btn-outline-dark' ?>">
                    All
                </a>

                <?php foreach ($allowedCategories as $category): ?>
                    <a href="preorder_menu.php?id=<?= h($bookingId) ?>&category=<?= urlencode($category) ?>"
                        class="btn <?= $selectedCategory === $category ? 'btn-dark' : 'btn-outline-dark' ?>">
                        <?= h($categoryLabels[$category] ?? $category) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (!$items): ?>
                <div class="alert alert-info">
                    No available menu items were found under
                    <strong><?= h($selectedCategory ?: 'the selected category') ?></strong>.
                </div>
            <?php endif; ?>

            <div class="row g-3">
                <?php foreach ($items as $item): ?>
                    <div class="col-md-4">
                        <div class="menu-item-card">

                            <?php if (!empty($item['image_path'])): ?>
                                <img
                                    src="<?= h($item['image_path']) ?>"
                                    alt="<?= h($item['item_name']) ?>"
                                    class="menu-item-img">
                            <?php else: ?>
                                <div class="menu-item-img menu-placeholder">
                                    No Image
                                </div>
                            <?php endif; ?>

                            <div class="menu-category mt-3"><?= h($item['category']) ?></div>
                            <h5><?= h($item['item_name']) ?></h5>

                            <?php if ((float)$item['price'] > 0): ?>
                                <p class="mb-2">RM <?= h(number_format($item['price'], 2)) ?></p>
                            <?php else: ?>
                                <p class="mb-2 text-muted">Price not set</p>
                            <?php endif; ?>

                            <form method="post" class="d-flex gap-2">
                                <input type="hidden" name="item_id" value="<?= h($item['item_id']) ?>">
                                <input type="number" name="quantity" class="form-control form-control-sm" min="1" value="1" style="max-width:90px;" aria-label="Quantity for <?= h($item['item_name']) ?>">
                                <button class="btn btn-sm btn-dark">Add</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="muted-small mt-3 mb-0">
                Menu item names are based on Oriental Kopi’s official menu page. Final item availability and prices may depend on the restaurant.
            </p>
        </div>
    </div>

    <div id="preorder-cart" class="col-lg-5">
        <div class="card ok-card p-4">
            <div class="section-title">Preorder Cart</div>
            <h4>Your Food List</h4>
            <a class="btn btn-outline-dark d-lg-none mb-3" href="#preorder-menu">Back to Menu</a>

            <?php if (!$cart): ?>
                <div class="alert alert-info">No food selected yet.</div>
            <?php endif; ?>

            <?php foreach ($cart as $c): ?>
                <div class="preorder-row">
                    <?php if (!empty($c['image_path'])): ?>
                        <img
                            src="<?= h($c['image_path']) ?>"
                            alt="<?= h($c['item_name']) ?>"
                            class="preorder-cart-img">
                    <?php else: ?>
                        <div class="preorder-cart-img preorder-cart-placeholder">
                            No Image
                        </div>
                    <?php endif; ?>

                    <div class="preorder-item-main">
                        <strong><?= h($c['item_name']) ?></strong>
                        <div class="muted-small"><?= h($c['category']) ?></div>
                        <div class="muted-small">Quantity: <?= h($c['quantity']) ?></div>
                    </div>

                    <div class="preorder-actions">
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-danger preorder-remove-toggle"
                            data-remove-target="remove-options-<?= h($c['preorder_id']) ?>"
                            aria-controls="remove-options-<?= h($c['preorder_id']) ?>"
                            aria-expanded="false">
                            Remove
                        </button>
                    </div>

                    <div
                        id="remove-options-<?= h($c['preorder_id']) ?>"
                        class="preorder-remove-panel d-none">
                        <form method="post" class="preorder-quantity-form">
                            <input type="hidden" name="action" value="update_quantity">
                            <input type="hidden" name="preorder_id" value="<?= h($c['preorder_id']) ?>">
                            <label class="muted-small mb-0" for="new-quantity-<?= h($c['preorder_id']) ?>">
                                Edit Qty
                            </label>
                            <input
                                id="new-quantity-<?= h($c['preorder_id']) ?>"
                                type="number"
                                name="new_quantity"
                                class="form-control form-control-sm"
                                min="1"
                                value="<?= h($c['quantity']) ?>">
                            <button class="btn btn-sm btn-outline-dark">Save</button>
                        </form>

                        <form method="post">
                            <input type="hidden" name="action" value="remove_item">
                            <input type="hidden" name="preorder_id" value="<?= h($c['preorder_id']) ?>">
                            <button class="btn btn-sm btn-outline-danger">Remove All</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>

            <hr>

            <?php if ($cart): ?>
                <?php
                $currentStatus = $cart[0]['status'] ?? 'Draft';
                $submittedAt = $cart[0]['submitted_at'] ?? null;
                ?>

                <div class="alert <?= $currentStatus === 'Submitted' ? 'alert-success' : 'alert-warning' ?>">
                    <strong>Preorder Status:</strong> <?= h($currentStatus) ?>

                    <?php if ($submittedAt): ?>
                        <br>
                        <small>Last submitted at: <?= h($submittedAt) ?></small>
                    <?php endif; ?>
                </div>

                <form method="post">
                    <input type="hidden" name="action" value="submit_preorder">
                    <button class="btn btn-gold w-100">
                        Submit Preorder
                    </button>
                </form>
            <?php endif; ?>

            <p class="mb-0 muted-small mt-3">
                This preorder list helps the restaurant prepare your selected food choices earlier.
                You may remove items and submit again to update your preorder.
            </p>
        </div>
    </div>
</div>

<script>
    document.querySelectorAll('.preorder-remove-toggle').forEach(function(button) {
        button.addEventListener('click', function() {
            const panel = document.getElementById(button.dataset.removeTarget);

            if (!panel) {
                return;
            }

            const isOpen = !panel.classList.contains('d-none');
            panel.classList.toggle('d-none', isOpen);
            button.setAttribute('aria-expanded', String(!isOpen));
        });
    });
</script>

<?php include 'includes/footer.php'; ?>
