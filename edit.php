<?php
require 'db.php';

if (!isset($_GET['task_id'])) {
    die("Xatolik: task_id berilmagan!");
}

$task_id = $_GET['task_id'];

// Ma'lumotni olish
$stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = :id");
$stmt->execute(['id' => $task_id]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    die("Xatolik: Task topilmadi!");
}
?>

<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vazifani tahrirlash</title>
</head>
<body>
    <h2>Vazifani tahrirlash</h2>
    
    <form action="update.php" method="POST">
        <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
        <input type="text" name="task_text" value="<?= htmlspecialchars($task['task_text']) ?>" required>
        <button type="submit">Saqlash</button>
    </form>

    <br>
    <a href="index.php">Bekor qilish</a>
</body>
</html>
