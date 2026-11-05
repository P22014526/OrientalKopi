<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pdo->prepare('INSERT INTO faqs (question,answer,keywords,created_by) VALUES (?,?,?,?)')->execute([$_POST['question'], $_POST['answer'], $_POST['keywords'], $_SESSION['user_id']]);
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
  <?php include 'staff_sidebar.php'; ?><main class="container py-4"><a href="dashboard.php" class="btn-nav-icon mb-3"><span class="btn-nav-icon-symbol">←</span><span>Dashboard</span></a>
    <div class="card ok-card p-4">
      <h3>Add New FAQ</h3>
      <form method="post"><label>Question</label><input name="question" class="form-control mb-3" required><label>Answer</label><textarea name="answer" class="form-control mb-3" required></textarea><label>Keywords (comma-separated)</label><input name="keywords" class="form-control mb-3" placeholder="opening,hours,time" required><button class="btn btn-dark">Save FAQ</button> <a class="btn btn-outline-dark" href="manage_faqs.php">Cancel</a></form>
    </div>
  </main>
</body>

</html>