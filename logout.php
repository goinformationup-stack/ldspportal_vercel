<?php
$session_lifetime = 60 * 60 * 24 * 30;
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params($session_lifetime, '/');

// FIXED: Must exactly match the uppercase name from index.php
session_name('LDSP_STAFF_SESSION'); 
session_start();

// Unset all session variables
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        '/', 
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session data on the server
session_destroy();

// Redirect back to login
header("Location: index.php"); 
exit();
?>