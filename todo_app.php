<?php
require 'token.php';
require 'db.php';
require 'vendor/autoload.php';


use GuzzleHttp\Client;

$pdo->exec("
    CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        task_text TEXT NOT NULL,
        status ENUM('pending', 'done') DEFAULT 'pending'
    )
");

$apiUrl = "https://api.telegram.org/bot$token/";

$update = json_decode(file_get_contents("php://input"), true);
if (!$update) exit("No data received!");

$chatId = $update["message"]["chat"]["id"] ?? null;
$text = trim($update["message"]["text"] ?? "");

$chatId = $update["message"]["chat"]["id"] ?? $update["callback_query"]["message"]["chat"]["id"] ?? null;
$text = trim($update["message"]["text"] ?? "");
$callbackData = $update["callback_query"]["data"] ?? null;


$bot = [
    "/start" => fn($chatId) => sendMessage($chatId, "📌 TODO Botga xush kelibsiz!", [
        [["text" => "➕ Yangi task", "callback_data" => "add_task"]],
        [["text" => "📋 Mening tasklarim", "callback_data" => "list_tasks"]],
        [["text" => "🗑 Taskni o‘chirish", "callback_data" => "delete_task"]]
    ]),

    "/add" => function($chatId, $text) use ($pdo) {
        $taskText = trim(substr($text, 5));
        if (!$taskText) return sendMessage($chatId, "⚠️ Iltimos, task matnini kiriting!");

        $pdo->prepare("INSERT INTO tasks (user_id, task_text) VALUES (:user_id, :task_text)")
            ->execute(["user_id" => $chatId, "task_text" => $taskText]);

        sendMessage($chatId, "✅ Task qo‘shildi: $taskText");
    },

    "/tasks" => function($chatId) use ($pdo) {
        $stmt = $pdo->prepare("SELECT id, task_text, status FROM tasks WHERE user_id = :user_id");
        $stmt->execute(["user_id" => $chatId]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response = $tasks ? "📋 *Sizning tasklaringiz:*\n" . 
            implode("\n", array_map(fn($t) => "{$t['id']}. {$t['task_text']} " . ($t["status"] == "done" ? "✅" : "⏳"), $tasks))
            : "📝 Hozircha hech qanday task yo‘q.";

        sendMessage($chatId, $response);
    },

    "/delete" => function($chatId, $text) use ($pdo) {
        $taskId = (int) trim(substr($text, 8));
        if ($taskId <= 0) return sendMessage($chatId, "⚠️ To‘g‘ri ID kiriting!");

        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = :id AND user_id = :user_id");
        $stmt->execute(["id" => $taskId, "user_id" => $chatId]);

        sendMessage($chatId, $stmt->rowCount() ? "🗑 Task o‘chirildi: ID $taskId" : "⚠️ Task topilmadi!");
    },

    "/edit" => function($chatId, $text) use ($pdo) {
        $args = explode(" ", $text, 3);
        if (count($args) < 3) return sendMessage($chatId, "⚠️ To‘g‘ri formatda kiriting: `/edit {ID} {yangi matn}`");

        [$cmd, $taskId, $newText] = $args;
        $taskId = (int) $taskId;
        if ($taskId <= 0 || empty($newText)) return sendMessage($chatId, "⚠️ ID musbat son bo‘lishi va matn bo‘sh bo‘lmasligi kerak!");

        $stmt = $pdo->prepare("UPDATE tasks SET task_text = :text WHERE id = :id AND user_id = :user_id");
        $stmt->execute(["text" => $newText, "id" => $taskId, "user_id" => $chatId]);

        sendMessage($chatId, $stmt->rowCount() ? "✅ Task yangilandi: ID $taskId\n📝 $newText" : "⚠️ O‘zgarish yo‘q.");
    },

    "/done" => function($chatId, $text) use ($pdo) {
        $taskId = (int) trim(substr($text, 6));
        if ($taskId <= 0) return sendMessage($chatId, "⚠️ ID musbat son bo‘lishi kerak!");

        $stmt = $pdo->prepare("SELECT status FROM tasks WHERE id = :id AND user_id = :user_id");
        $stmt->execute(["id" => $taskId, "user_id" => $chatId]);

        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$task) return sendMessage($chatId, "❌ Task topilmadi!");

        $newStatus = ($task['status'] === 'done') ? 'pending' : 'done';
        $pdo->prepare("UPDATE tasks SET status = :status WHERE id = :id AND user_id = :user_id")
            ->execute(["status" => $newStatus, "id" => $taskId, "user_id" => $chatId]);


            sendMessage($chatId, "✅ Task holati o‘zgartirildi: ID $taskId → *$newStatus*");
        }
    ];
    
    if ($callbackData) {
        switch ($callbackData) {
            case "add_task":
                sendMessage($chatId, "✍️ Yangi task qo‘shish uchun `/add Task nomi` yozing.");
                break;
    
            case "list_tasks":
                $bot["/tasks"]($chatId);
                break;
    
            case "delete_task":
                sendMessage($chatId, "🗑 O‘chirish uchun `/delete {ID}` yozing.");
                break;
        }
        exit();
    }
    
    // Komanda mavjud bo‘lsa, uni ishga tushirish
    foreach ($bot as $cmd => $handler) {
        if (strpos($text, $cmd) === 0) {
            $handler($chatId, $text);
            break;
        }
    }
    
    
    function sendMessage($chatId, $text, $buttons = null) {
        global $apiUrl;
    
        $client = new Client();
        $data = [
            "chat_id" => $chatId,
            "text" => $text,
            "parse_mode" => "Markdown"
        ];
    
        if ($buttons) {
            $data["reply_markup"] = ["inline_keyboard" => $buttons];
        }
    
        try{
        $responce = $client->post($apiUrl . "sendMessage", [
            "json" => $data
        ]);
    
        if ($responce -> getStatuscode()!== 200){
            error_log("Telegram API error: " . $responce->getBody());
        }
    } catch (Exception $e) {
        error_log("Guzzle Error : " . $e->getMessage());
    }
    }
    
    ?>
    