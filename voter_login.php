<?php
session_start();

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "school_election";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] == "POST") {
    $full_name = $_POST['full_name'];
    $password = $_POST['password'];

    // Find voter by name
    $sql = "SELECT * FROM voters WHERE full_name='$full_name'";
    $result = $conn->query($sql);

    if ($result->num_rows == 1) {
        $voter = $result->fetch_assoc();

        // Check password (student ID is default password, hashed)
        if (password_verify($password, $voter['voter_password'])) {
            
            if ($voter['has_voted'] == 1) {
                echo "❌ You have already voted! You cannot vote twice.";
            } else {
                // Store voter in session
                $_SESSION['voter_id'] = $voter['voter_id'];
                $_SESSION['full_name'] = $voter['full_name'];

                // Redirect to voting page
                header("Location: voteplace.php");
                exit();
            }
        } else {
            echo "❌ Wrong password!";
        }
    } else {
        echo "❌ Voter not found!";
    }
}

$conn->close();
?>
