<?php
session_start();

// Unset all of the session variables
$_SESSION = array();

// Ensure we destroy the cookie for the ENTIRE system domain by forcing the '/' path
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        '/', // Forces the path to root to wipe the global session
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Finally, destroy the session
session_destroy();

// Send back to their specific blue login portal
header("Location: index.php");
exit();
?>