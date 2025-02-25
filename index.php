<?php

require 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $task_text = trim($_POST["task_text"]);

    if (!empty($task_text)) {
        $stmt = $pdo->prepare("INSERT INTO tasks (task_text, status) VALUES (:task_text, 'pending')");
        $stmt->execute(['task_text' => $task_text]);

        header("Location: index.php");
        exit;
    }
}

// Tasklarni olish
$stmt = $pdo->query("SELECT * FROM tasks ORDER BY created_at DESC");
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TODO App</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .task { padding: 10px; border-bottom: 1px solid #ddd; }
        .done { text-decoration: line-through; color: gray; }
    </style>
</head>
<body>

    <h2>TODO List</h2>

<!-- Task qo‘shish formasi -->
<form action="" method="POST">
    <input type="text" name="task_text" required placeholder="Yangi vazifa...">
    <button type="submit">Qo‘shish</button>
</form>

<ul>
    <?php foreach ($tasks as $task): ?>
        <li class="task <?= $task['status'] == 'done' ? 'done' : '' ?>">
            <?= htmlspecialchars($task['task_text']) ?>

            <!-- ✅ Agar pending bo‘lsa -->
            <?php if ($task['status'] == 'pending'): ?>
                <form action="done.php" method="POST" style="display:inline;">
                    <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                    <button type="submit">✅</button>
                </form>
            <?php else: ?>
                <form action="undo.php" method="POST" style="display:inline;">
                    <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                    <button type="submit">↩️</button>
                </form>
            <?php endif; ?>

            <!-- ✏️ Tahrirlash tugmasi -->
            <form action="edit.php" method="GET" style="display:inline;">
                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                <button type="submit">✏️</button>
            </form>

            <!-- ❌ O‘chirish tugmasi -->
            <form action="delete.php" method="POST" style="display:inline;">
                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                <button type="submit">❌</button>
            </form>
        </li>
    <?php endforeach; ?>
</ul>
</body>
</html>
