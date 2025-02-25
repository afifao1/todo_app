<?php
require 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $task_id = $_POST['task_id'];
    $task_text = trim($_POST['task_text']);

    if (!empty($task_text)) {
        $stmt = $pdo->prepare("UPDATE tasks SET task_text = :task_text WHERE id = :id");
        $stmt->execute(['task_text' => $task_text, 'id' => $task_id]);
    }
}

header("Location: index.php");
exit;
?>
