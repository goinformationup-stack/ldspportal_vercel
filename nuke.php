<?php
include "dbconn.php"; // Make sure this points to your database connection file

// 1. Wipe out all test enrollment requests (This drops the activity tracker to 0)
$conn->query("TRUNCATE TABLE enrollment_requests");

// 2. Wipe out all dummy student accounts
$conn->query("DELETE FROM users WHERE role = 'student'");

// 3. Reset the class schedules just in case
$conn->query("TRUNCATE TABLE class_schedules");

echo "<h1 style='color: red; font-family: sans-serif; text-align: center; margin-top: 50px;'>DATABASE WIPED CLEAN!</h1>";
echo "<p style='text-align: center; font-family: sans-serif;'>All ghost students, test applications, and fake history have been permanently deleted.</p>";
echo "<p style='text-align: center; font-family: sans-serif;'><a href='registrar/dashboard.php' style='padding: 10px 20px; background: #0284c7; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Return to Registrar Dashboard</a></p>";
?>