<?php
$host = "localhost";
$user = "s68160199";
$pass = "CJPr6X7s";
$dbname = "s68160199";

$conn = mysqli_connect($host, $user, $pass, $dbname);

if (!$conn) {
    die('Database connection failed: ' . mysqli_connect_error());
}

mysqli_set_charset($conn, 'utf8mb4');
?>