<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_admin();
$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare('SELECT * FROM faqs WHERE faq_id=?');
$stmt->execute([$id]);
$faq = $stmt->fetch();
if (!$faq) {
  header('Location: manage_faqs.php');
  exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pdo->prepare('DELETE FROM faqs WHERE faq_id=?')->execute([$id]);
  header('Location: manage_faqs.php');
  exit;
}
?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
  <?php include 'staff_sidebar.php'; ?><main class="container py-4">
    <div class="card ok-card p-4">
      <h3>Confirm Deletion</h3>
      <p>Remove this FAQ?</p>
      <blockquote><?= h($faq['question']) ?></blockquote>
      <form method="post"><button class="btn btn-danger">Yes, Remove</button> <a class="btn btn-outline-dark" href="manage_faqs.php">Cancel</a></form>
    </div>
  </main>
</body>

</html>