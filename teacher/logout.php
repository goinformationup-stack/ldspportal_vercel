<?php
if (session_status() === PHP_SESSION_NONE) {
    // 1. Fixed the session name to match the teacher's session
    session_name('LDSP_TEACHER_SESSION');
    session_start();
}

// Unset all session variables
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// 2. Fixed the redirect path to stay in the current teacher directory
header("Location: index.php");
exit();
?>