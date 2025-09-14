<?php
$servername = "localhost";
$username = "root"; 
$password = ""; 
$dbname = "school_election";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (isset($_POST['register'])) {
    $name = $_POST['candidateName'];
    $studentId = $_POST['studentId'];
    $class = $_POST['class'];

    // for now, let’s make password = studentId
    $hashedPassword = password_hash($studentId, PASSWORD_DEFAULT);

    $sql = "INSERT INTO voters (full_name, student_id, class, voter_password) 
            VALUES ('$name', '$studentId', '$class', '$hashedPassword')";

    if ($conn->query($sql) === TRUE) {
        echo "✅ Voter Registered successfully!;";
    } else {
        echo "❌ Error: " . $conn->error;
    }
}

$conn->close();
?>
