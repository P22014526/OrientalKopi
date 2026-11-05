<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_admin();
$faqs = $pdo->query('SELECT * FROM faqs ORDER BY faq_id DESC')->fetchAll();
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
      <h3>AI Chatbot FAQ Manager</h3>
      <table class="table">
        <thead>
          <tr>
            <th>Question</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody><?php foreach ($faqs as $f): ?><tr>
              <td><?= h($f['question']) ?></td>
              <td><a class="btn btn-sm btn-outline-dark" href="edit_faq.php?id=<?= $f['faq_id'] ?>">Edit</a> <a class="btn btn-sm btn-outline-danger" href="delete_faq.php?id=<?= $f['faq_id'] ?>">Remove</a></td>
            </tr><?php endforeach; ?></tbody>
      </table><a class="btn btn-dark" href="add_faq.php">Add FAQ</a>
    </div>
  </main>
</body>

</html>