<?php
session_start();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (!isset($_SESSION['aid']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php?error=Login required");
    exit();
}
?>