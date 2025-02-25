<?php
require 'token.php';
require 'db.php';
require 'vendor/autoload.php';

use GuzzleHttp\Client;

class TodoBot {
    private $pdo;
    private $apiUrl;
    private $client;

    public function __construct($pdo, $token) {
        $this->pdo = $pdo;
        $this->apiUrl = "https://api.telegram.org/bot$token/";
        $this->client = new Client();

        $this->initializeDatabase();
    }

    private function initializeDatabase() {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            task_text TEXT NOT NULL,
            status ENUM('pending', 'done') DEFAULT 'pending'
        )");
    }

    public function handleRequest() {
        $update = json_decode(file_get_contents("php://input"), true);
        if (!$update) exit("No data received!");

        $chatId = $update["message"]["chat"]["id"] ?? $update["callback_query"]["message"]["chat"]["id"] ?? null;
        $text = trim($update["message"]["text"] ?? "");
        $callbackData = $update["callback_query"]["data"] ?? null;

        if ($callbackData) {
            $this->handleCallback($chatId, $callbackData);
            exit();
        }

        $this->handleCommand($chatId, $text);
    }

    private function handleCommand($chatId, $text) {
        $commands = [
            "/start" => fn() => $this->sendMainMenu($chatId),
            "/add" => fn() => $this->addTask($chatId, $text),
            "/tasks" => fn() => $this->listTasks($chatId),
            "/delete" => fn() => $this->deleteTask($chatId, $text),
            "/edit" => fn() => $this->editTask($chatId, $text),
            "/done" => fn() => $this->toggleTaskStatus($chatId, $text)
        ];

        foreach ($commands as $cmd => $handler) {
            if (strpos($text, $cmd) === 0) {
                $handler();
                return;
            }
        }
    }

    private function handleCallback($chatId, $callbackData) {
        switch ($callbackData) {
            case "add_task":
                $this->sendMessage($chatId, "✍️ Yangi task qo‘shish uchun `/add Task nomi` yozing.");
                break;
            case "list_tasks":
                $this->listTasks($chatId);
                break;
            case "delete_task":
                $this->sendMessage($chatId, "🗑 O‘chirish uchun `/delete {ID}` yozing.");
                break;
        }
    }

    private function sendMainMenu($chatId) {
        $this->sendMessage($chatId, "📌 TODO Botga xush kelibsiz!", [
            [["text" => "➕ Yangi task", "callback_data" => "add_task"]],
            [["text" => "📋 Mening tasklarim", "callback_data" => "list_tasks"]],
            [["text" => "🗑 Taskni o‘chirish", "callback_data" => "delete_task"]]
        ]);
    }

    private function addTask($chatId, $text) {
        $taskText = trim(substr($text, 5));
        if (!$taskText) return $this->sendMessage($chatId, "⚠️ Iltimos, task matnini kiriting!");

        $this->pdo->prepare("INSERT INTO tasks (user_id, task_text) VALUES (:user_id, :task_text)")
            ->execute(["user_id" => $chatId, "task_text" => $taskText]);

        $this->sendMessage($chatId, "✅ Task qo‘shildi: $taskText");
    }

    private function listTasks($chatId) {
        $stmt = $this->pdo->prepare("SELECT id, task_text, status FROM tasks WHERE user_id = :user_id");
        $stmt->execute(["user_id" => $chatId]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response = $tasks ? "📋 *Sizning tasklaringiz:*\n" .
            implode("\n", array_map(fn($t) => "{$t['id']}. {$t['task_text']} " . ($t["status"] == "done" ? "✅" : "⏳"), $tasks))
            : "📝 Hozircha hech qanday task yo‘q.";

        $this->sendMessage($chatId, $response);
    }

    private function deleteTask($chatId, $text) {
        $taskId = (int) trim(substr($text, 8));
        if ($taskId <= 0) return $this->sendMessage($chatId, "⚠️ To‘g‘ri ID kiriting!");

        $stmt = $this->pdo->prepare("DELETE FROM tasks WHERE id = :id AND user_id = :user_id");
        $stmt->execute(["id" => $taskId, "user_id" => $chatId]);

        $this->sendMessage($chatId, $stmt->rowCount() ? "🗑 Task o‘chirildi: ID $taskId" : "⚠️ Task topilmadi!");
    }

    private function editTask($chatId, $text) {
        $args = explode(" ", $text, 3);
        if (count($args) < 3) return $this->sendMessage($chatId, "⚠️ To‘g‘ri formatda kiriting: `/edit {ID} {yangi matn}`");

        [$cmd, $taskId, $newText] = $args;
        $stmt = $this->pdo->prepare("UPDATE tasks SET task_text = :text WHERE id = :id AND user_id = :user_id");
        $stmt->execute(["text" => $newText, "id" => (int)$taskId, "user_id" => $chatId]);

        $this->sendMessage($chatId, "✅ Task yangilandi: ID $taskId\n📝 $newText");
    }

    private function sendMessage($chatId, $text, $buttons = null) {
        $data = ["chat_id" => $chatId, "text" => $text, "parse_mode" => "Markdown"];
        if ($buttons) $data["reply_markup"] = ["inline_keyboard" => $buttons];

        try {
            $this->client->post($this->apiUrl . "sendMessage", ["json" => $data]);
        } catch (Exception $e) {
            error_log("Guzzle Error: " . $e->getMessage());
        }
    }

    private function toggleTaskStatus($chatId, $text) {
        // Matndan ID ni olish
        $parts = explode(' ', $text);
        if (count($parts) < 2) {
            $this->sendMessage($chatId, "⚠ Taskni o'zgartirish uchun '/done {ID}' formatida kiriting.");
            return;
        }
    
        $taskId = (int) $parts[1];
    
        // Task mavjudligini tekshirish
        $stmt = $this->pdo->prepare("SELECT status FROM tasks WHERE id = :id");
        $stmt->execute(['id' => $taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$task) {
            $this->sendMessage($chatId, "❌ Bunday ID ga ega task topilmadi.");
            return;
        }
    
        // Holatni almashtirish (done <-> pending)
        $newStatus = ($task['status'] === 'done') ? 'pending' : 'done';
    
        // Baza yangilash
        $stmt = $this->pdo->prepare("UPDATE tasks SET status = :status WHERE id = :id");
        $stmt->execute(['status' => $newStatus, 'id' => $taskId]);
    
        // Foydalanuvchiga xabar yuborish
        $this->sendMessage($chatId, "✅ Task statusi \"$newStatus\" ga o‘zgartirildi.");
    }
    
}

$bot = new TodoBot($pdo, $token);
$bot->handleRequest();
// require 'token.php';
// require 'db.php';
// require 'vendor/autoload.php';


// use GuzzleHttp\Client;

// $pdo->exec("
//     CREATE TABLE IF NOT EXISTS tasks (
//         id INT AUTO_INCREMENT PRIMARY KEY,
//         user_id BIGINT NOT NULL,
//         task_text TEXT NOT NULL,
//         status ENUM('pending', 'done') DEFAULT 'pending'
//     )
// ");

// $apiUrl = "https://api.telegram.org/bot$token/";

// $update = json_decode(file_get_contents("php://input"), true);
// if (!$update) exit("No data received!");

// $chatId = $update["message"]["chat"]["id"] ?? null;
// $text = trim($update["message"]["text"] ?? "");

// $chatId = $update["message"]["chat"]["id"] ?? $update["callback_query"]["message"]["chat"]["id"] ?? null;
// $text = trim($update["message"]["text"] ?? "");
// $callbackData = $update["callback_query"]["data"] ?? null;


// $bot = [
//     "/start" => fn($chatId) => sendMessage($chatId, "📌 TODO Botga xush kelibsiz!", [
//         [["text" => "➕ Yangi task", "callback_data" => "add_task"]],
//         [["text" => "📋 Mening tasklarim", "callback_data" => "list_tasks"]],
//         [["text" => "🗑 Taskni o‘chirish", "callback_data" => "delete_task"]]
//     ]),

//     "/add" => function($chatId, $text) use ($pdo) {
//         $taskText = trim(substr($text, 5));
//         if (!$taskText) return sendMessage($chatId, "⚠️ Iltimos, task matnini kiriting!");

//         $pdo->prepare("INSERT INTO tasks (user_id, task_text) VALUES (:user_id, :task_text)")
//             ->execute(["user_id" => $chatId, "task_text" => $taskText]);

//         sendMessage($chatId, "✅ Task qo‘shildi: $taskText");
//     },

//     "/tasks" => function($chatId) use ($pdo) {
//         $stmt = $pdo->prepare("SELECT id, task_text, status FROM tasks WHERE user_id = :user_id");
//         $stmt->execute(["user_id" => $chatId]);
//         $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

//         $response = $tasks ? "📋 *Sizning tasklaringiz:*\n" . 
//             implode("\n", array_map(fn($t) => "{$t['id']}. {$t['task_text']} " . ($t["status"] == "done" ? "✅" : "⏳"), $tasks))
//             : "📝 Hozircha hech qanday task yo‘q.";

//         sendMessage($chatId, $response);
//     },

//     "/delete" => function($chatId, $text) use ($pdo) {
//         $taskId = (int) trim(substr($text, 8));
//         if ($taskId <= 0) return sendMessage($chatId, "⚠️ To‘g‘ri ID kiriting!");

//         $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = :id AND user_id = :user_id");
//         $stmt->execute(["id" => $taskId, "user_id" => $chatId]);

//         sendMessage($chatId, $stmt->rowCount() ? "🗑 Task o‘chirildi: ID $taskId" : "⚠️ Task topilmadi!");
//     },

//     "/edit" => function($chatId, $text) use ($pdo) {
//         $args = explode(" ", $text, 3);
//         if (count($args) < 3) return sendMessage($chatId, "⚠️ To‘g‘ri formatda kiriting: `/edit {ID} {yangi matn}`");

//         [$cmd, $taskId, $newText] = $args;
//         $taskId = (int) $taskId;
//         if ($taskId <= 0 || empty($newText)) return sendMessage($chatId, "⚠️ ID musbat son bo‘lishi va matn bo‘sh bo‘lmasligi kerak!");

//         $stmt = $pdo->prepare("UPDATE tasks SET task_text = :text WHERE id = :id AND user_id = :user_id");
//         $stmt->execute(["text" => $newText, "id" => $taskId, "user_id" => $chatId]);

//         sendMessage($chatId, $stmt->rowCount() ? "✅ Task yangilandi: ID $taskId\n📝 $newText" : "⚠️ O‘zgarish yo‘q.");
//     },

//     "/done" => function($chatId, $text) use ($pdo) {
//         $taskId = (int) trim(substr($text, 6));
//         if ($taskId <= 0) return sendMessage($chatId, "⚠️ ID musbat son bo‘lishi kerak!");

//         $stmt = $pdo->prepare("SELECT status FROM tasks WHERE id = :id AND user_id = :user_id");
//         $stmt->execute(["id" => $taskId, "user_id" => $chatId]);

//         $task = $stmt->fetch(PDO::FETCH_ASSOC);
//         if (!$task) return sendMessage($chatId, "❌ Task topilmadi!");

//         $newStatus = ($task['status'] === 'done') ? 'pending' : 'done';
//         $pdo->prepare("UPDATE tasks SET status = :status WHERE id = :id AND user_id = :user_id")
//             ->execute(["status" => $newStatus, "id" => $taskId, "user_id" => $chatId]);


//             sendMessage($chatId, "✅ Task holati o‘zgartirildi: ID $taskId → *$newStatus*");
//         }
//     ];
    
//     if ($callbackData) {
//         switch ($callbackData) {
//             case "add_task":
//                 sendMessage($chatId, "✍️ Yangi task qo‘shish uchun `/add Task nomi` yozing.");
//                 break;
    
//             case "list_tasks":
//                 $bot["/tasks"]($chatId);
//                 break;
    
//             case "delete_task":
//                 sendMessage($chatId, "🗑 O‘chirish uchun `/delete {ID}` yozing.");
//                 break;
//         }
//         exit();
//     }
    
//     // Komanda mavjud bo‘lsa, uni ishga tushirish
//     foreach ($bot as $cmd => $handler) {
//         if (strpos($text, $cmd) === 0) {
//             $handler($chatId, $text);
//             break;
//         }
//     }
    
    
//     function sendMessage($chatId, $text, $buttons = null) {
//         global $apiUrl;
    
//         $client = new Client();
//         $data = [
//             "chat_id" => $chatId,
//             "text" => $text,
//             "parse_mode" => "Markdown"
//         ];
    
//         if ($buttons) {
//             $data["reply_markup"] = ["inline_keyboard" => $buttons];
//         }
    
//         try{
//         $responce = $client->post($apiUrl . "sendMessage", [
//             "json" => $data
//         ]);
    
//         if ($responce -> getStatuscode()!== 200){
//             error_log("Telegram API error: " . $responce->getBody());
//         }
//     } catch (Exception $e) {
//         error_log("Guzzle Error : " . $e->getMessage());
//     }
//     }
    

    