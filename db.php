<?php
$pdo = new PDO("mysql:host=localhost;dbname=todo_app", "root", "root1223", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);
