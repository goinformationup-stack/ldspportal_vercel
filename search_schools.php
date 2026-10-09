<?php
// search_schools.php
header('Content-Type: application/json');
include "dbconn.php"; // Make sure this points to your database connection

// Ensure the table exists
$conn->query("CREATE TABLE IF NOT EXISTS tbl_schools (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_name VARCHAR(255) NOT NULL UNIQUE,
    is_verified TINYINT(1) DEFAULT 0
)");

$query = isset($_GET['q']) ? mysqli_real_escape_string($conn, trim($_GET['q'])) : '';

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

// Search the database for any school matching the typed letters
// Limit to 15 results so it doesn't overwhelm the screen
$sql = "SELECT school_name FROM tbl_schools WHERE school_name LIKE '%$query%' LIMIT 15";
$result = $conn->query($sql);

$schools = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $schools[] = $row['school_name'];
    }
}

// Return the results to the javascript
echo json_encode($schools);
exit;
?>