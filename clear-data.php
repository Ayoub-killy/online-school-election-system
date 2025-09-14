<?php
session_start();
include "db.php"; // connection

// --- Check if admin is logged in ---
// if (!isset($_SESSION['admin_id'])) {
//     echo "<h2 style='color:red;text-align:center;'>❌ Access denied! Only admins can clear data.</h2>";
//     exit();
// }

// --- Disable foreign key checks temporarily ---
$conn->query("SET FOREIGN_KEY_CHECKS = 0");

// --- Clear all system data ---
$conn->query("TRUNCATE TABLE votes");
$conn->query("TRUNCATE TABLE voters");
$conn->query("TRUNCATE TABLE candidates");

// --- Re-enable foreign key checks ---
$conn->query("SET FOREIGN_KEY_CHECKS = 1");

echo "<h2 style='color:green;text-align:center;'>✅ System data cleared successfully!</h2>";
echo "<div style='text-align:center; margin-top:20px;'>
        <a href='admin-panel.php' style='color:blue; font-weight:bold;'>🔙 Return to Dashboard</a>
      </div>";
?>
