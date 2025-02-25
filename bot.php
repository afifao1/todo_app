<?php
require 'token.php';
require 'db.php';
require 'vendor/autoload.php';

use GuzzleHttp\Client;

$apiUrl = "https://api.telegram.org/bot$token/";

$update = json_decode(file_get_contents("php://input"), true);
if (!$update) exit("No data received!");

$chatId = $update["message"]["chat"]["id"] ?? $update["callback_query"]["message"]["chat"]["id"] ?? null;
$text = trim($update["message"]["text"] ?? "");
$callbackData = $update["callback_query"]["data"] ?? null;

require 'bot_logic.php'; // Asosiy bot logikasi shu faylda bo‘ladi

?>
