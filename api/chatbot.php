<?php
require_once '../includes/db.php';
header('Content-Type: application/json');
$q = strtolower(trim($_POST['question'] ?? ''));
$answer = "Sorry, I could not find an answer. Please contact the restaurant staff.";
$stmt = $pdo->query('SELECT * FROM faqs');
foreach ($stmt->fetchAll() as $faq) {
    $keywords = array_map('trim', explode(',', strtolower($faq['keywords'])));
    foreach ($keywords as $kw) {
        if ($kw !== '' && str_contains($q, $kw)) {
            $answer = $faq['answer'];
            echo json_encode(['answer' => $answer]);
            exit;
        }
    }
}
echo json_encode(['answer' => $answer]);
