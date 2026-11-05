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
  $pdo->prepare('UPDATE faqs SET question=?, answer=?, keywords=? WHERE faq_id=?')->execute([$_POST['question'], $_POST['answer'], $_POST['keywords'], $id]);
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
  <?php include 'staff_sidebar.php'; ?><main class="container py-4"><a href="manage_faqs.php" class="btn-nav-icon mb-3"><span class="btn-nav-icon-symbol">←</span><span>FAQ List</span></a>
    <div class="card ok-card p-4">
      <h3>Edit FAQ</h3>
      <form method="post"><label>Question</label><input name="question" class="form-control mb-3" value="<?= h($faq['question']) ?>"><label>Answer</label><textarea name="answer" class="form-control mb-3"><?= h($faq['answer']) ?></textarea><label>Keywords</label><input name="keywords" class="form-control mb-3" value="<?= h($faq['keywords']) ?>"><button class="btn btn-dark">Save Changes</button> <a class="btn btn-outline-dark" href="manage_faqs.php">Cancel</a></form>
    </div>
  </main>
</body>

</html>