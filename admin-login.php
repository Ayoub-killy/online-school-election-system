<?php
// Database connection
$host = "localhost";
$user = "root";   // change if you use another username
$pass = "";       // enter your MySQL password
$db   = "school_election";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get form data
$admin_name = $_POST['admin_name'];
$admin_password = $_POST['admin_password'];

// Query database
$sql = "SELECT * FROM admins WHERE admin_name = ? AND admin_password = MD5(?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $admin_name, $admin_password);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    // Login successful → redirect to admin panel
    header("Location: admin-panel.php");
    exit();
} else {
    echo "<h3>❌ Invalid name or password!</h3>";
}

$stmt->close();
$conn->close();
?>
