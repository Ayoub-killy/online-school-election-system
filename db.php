<?php
$host = "localhost";   // your server (usually localhost)
$user = "root";        // default user for XAMPP
$pass = "";            // default password is empty
$db   = "school_election"; // use the name of your database

$conn = new mysqli($host, $user, $pass, $db);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
