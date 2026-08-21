<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();

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

include 'includes/header.php';
?>


<div class="card ok-card p-4">
    <div class="section-title">Customer Area</div>
    <h3>Oriental Kopi Menu</h3>
    <p class="muted-small">
        View available menu items before making a booking. Use the category filter to browse Signature items, beverages, snacks, and desserts or pastries.
    </p>

    <div class="menu-filter-bar mb-4">
        <a href="menu.php"
            class="btn <?= $selectedCategory === '' ? 'btn-dark' : 'btn-outline-dark' ?>">
            All
        </a>

        <?php foreach ($allowedCategories as $category): ?>
            <a href="menu.php?category=<?= urlencode($category) ?>"
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

    <div class="row g-3 mt-2">
        <?php foreach ($items as $item): ?>
            <div class="col-md-4 col-lg-3">
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

                    <div class="menu-category mt-2"><?= h($item['category']) ?></div>
                    <h5><?= h($item['item_name']) ?></h5>

                    <?php if ((float)$item['price'] > 0): ?>
                        <p class="mb-0">RM <?= h(number_format($item['price'], 2)) ?></p>
                    <?php else: ?>
                        <p class="mb-0 text-muted">Price not set</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>