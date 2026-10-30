<?php
$host = "localhost";
$user = "user";
$pass = "password";
$dbname = "databasename";

$conn = mysqli_connect($host, $user, $pass, $dbname);

if (!$conn) {
    die('Database connection failed: ' . mysqli_connect_error());
}

mysqli_set_charset($conn, 'utf8mb4');
?>