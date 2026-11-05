<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_staff();

$dataset = $_GET['dataset'] ?? 'performance';
$format = $_GET['format'] ?? 'csv';
$allowedDatasets = ['performance', 'reviews'];

if (!in_array($dataset, $allowedDatasets, true)) {
    $dataset = 'performance';
}

if (!in_array($format, ['csv', 'print'], true)) {
    $format = 'csv';
}

$assignedOutletIds = staff_assigned_outlet_ids($pdo);

function dashboard_scope_clause(?array $assignedOutletIds, string $alias, array &$params): string
{
    if ($assignedOutletIds === null) {
        return '';
    }

    if (!$assignedOutletIds) {
        return ' AND 1=0';
    }

    $params = array_merge($params, $assignedOutletIds);
    return ' AND ' . $alias . '.outlet_id IN (' . implode(',', array_fill(0, count($assignedOutletIds), '?')) . ')';
}

function export_rows(PDO $pdo, string $dataset, ?array $assignedOutletIds): array
{
    $params = [];

    if ($dataset === 'performance') {
        $scope = dashboard_scope_clause($assignedOutletIds, 'o', $params);
        $stmt = $pdo->prepare("
            SELECT s.state_name AS State,
                   o.branch_name AS Outlet,
                   COUNT(DISTINCT b.booking_id) AS Total_Bookings,
                   COUNT(DISTINCT CASE WHEN b.status = 'Completed' THEN b.booking_id END) AS Completed,
                   COUNT(DISTINCT CASE WHEN b.status = 'Cancelled' THEN b.booking_id END) AS Cancelled,
                   COUNT(DISTINCT CASE WHEN b.status = 'Declined' THEN b.booking_id END) AS Declined,
                   COUNT(DISTINCT CASE WHEN p.booking_id IS NOT NULL THEN b.booking_id END) AS Preorder_Bookings,
                   ROUND(AVG(CASE WHEN b.status = 'Completed' THEN r.rating END), 1) AS Average_Rating
            FROM outlets o
            JOIN outlet_states s ON s.state_id = o.state_id
            LEFT JOIN bookings b
              ON b.outlet_id = o.outlet_id
             AND b.booking_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            LEFT JOIN (
              SELECT DISTINCT booking_id
              FROM preorders
              WHERE status = 'Submitted'
            ) p ON p.booking_id = b.booking_id
            LEFT JOIN reviews r
              ON r.booking_id = b.booking_id
             AND b.status = 'Completed'
            WHERE o.is_active = 1
              $scope
            GROUP BY o.outlet_id
            ORDER BY s.display_order, o.branch_name
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    if ($dataset === 'reviews') {
        $scope = dashboard_scope_clause($assignedOutletIds, 'b', $params);
        $stmt = $pdo->prepare("
            SELECT r.created_at AS Review_Date,
                   u.full_name AS Customer,
                   b.booking_code AS Booking_ID,
                   s.state_name AS State,
                   o.branch_name AS Outlet,
                   r.rating AS Rating,
                   r.feedback AS Feedback,
                   r.admin_reply AS Staff_Reply
            FROM reviews r
            JOIN users u ON u.user_id = r.user_id
            JOIN bookings b ON b.booking_id = r.booking_id
            LEFT JOIN outlets o ON o.outlet_id = b.outlet_id
            LEFT JOIN outlet_states s ON s.state_id = o.state_id
            WHERE 1=1
              AND b.status = 'Completed'
              $scope
            ORDER BY r.created_at DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    return [];
}

$rows = export_rows($pdo, $dataset, $assignedOutletIds);
$filename = 'oriental-kopi-' . $dataset . '-' . date('Ymd-His');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

    $output = fopen('php://output', 'w');

    if ($rows) {
        fputcsv($output, array_keys($rows[0]));

        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
    } else {
        fputcsv($output, ['No records found']);
    }

    fclose($output);
    exit;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(ucfirst($dataset)) ?> Export</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
  <?php include 'staff_sidebar.php'; ?>
    <main class="container py-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print">
            <a href="dashboard.php" class="btn-nav-icon"><span class="btn-nav-icon-symbol">←</span><span>Dashboard</span></a>
            <button class="btn btn-dark" onclick="window.print()">Print / Save as PDF</button>
        </div>

        <section class="dashboard-panel">
            <div class="print-heading d-block mb-3">
                <h3>Oriental Kopi <?= h(ucfirst($dataset)) ?> Report</h3>
                <p><?= h(date('d/m/Y g:i A')) ?></p>
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <?php if ($rows): ?>
                        <thead>
                            <tr>
                                <?php foreach (array_keys($rows[0]) as $heading): ?>
                                    <th><?= h(str_replace('_', ' ', $heading)) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <?php foreach ($row as $value): ?>
                                        <td><?= h($value) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php else: ?>
                        <tbody>
                            <tr>
                                <td class="text-center text-muted">No records found.</td>
                            </tr>
                        </tbody>
                    <?php endif; ?>
                </table>
            </div>
        </section>
    </main>
</body>

</html>
