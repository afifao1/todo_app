<?php
require 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $task_id = $_POST['task_id'];

    $stmt = $pdo->prepare("UPDATE tasks SET status = 'pending' WHERE id = :id");
    $stmt->execute(['id' => $task_id]);

    header("Location: index.php");
    exit;
}
?>
