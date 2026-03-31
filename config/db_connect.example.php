<?php
$host = 'localhost';
$db   = 'erp_financiero';
$user = '';
$pass = '';
$pdo  = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);  

return $pdo;                  
?>