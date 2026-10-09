<?php 
// 1. ISOLATED SESSION HANDLER FOR STAFF (Prevents cross-logout bug)
$session_lifetime = 60 * 60 * 24 * 30; // 30 days
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params($session_lifetime, '/');
session_name('LDSP_STAFF_SESSION'); 
session_start();

include "dbconn.php";

if (isset($_POST['email']) && isset($_POST['password'])) {

    $email = trim($_POST['email']);
    $pass = trim($_POST['password']);

    $stmt = $conn->prepare("SELECT * FROM users WHERE email=?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {

        $row = $result->fetch_assoc();

        if (password_verify($pass, $row['password']) || $pass === $row['password']) {

            /* BLOCK STUDENTS FROM STAFF LOGIN */
            if ($row['role'] === 'student') {
                header("Location: stulogin.php?error=Please use the student login page");
                exit();
            }

            $_SESSION['email'] = $row['email'];
            $_SESSION['firstname'] = $row['firstname'] ?? $row['first_name'] ?? 'Staff';
            $_SESSION['aid'] = $row['aid'] ?? $row['id'] ?? null;
            $_SESSION['role'] = $row['role'];

            /* STAFF REDIRECTION */

            if ($row['role'] === 'admin') {
                header("Location: admin/dashboard.php");
            }
            elseif ($row['role'] === 'admission') {
                // Pointing to the main admission app management file
                header("Location: admission/application_management.php");
            }
            elseif ($row['role'] === 'registrar') {
                // FIXED: Directing registrar to their new core module
                header("Location: registrar/verification_management.php");
            }
            elseif ($row['role'] === 'accounting') {
                // Pointing to the main accounting verification file
                header("Location: accounting/payment_verification_management.php");
            }
            elseif ($row['role'] === 'programhead') {
                header("Location: programhead/dashboard.php");
            }

            exit();

        } else {
            header("Location: index.php?error=Password Mismatch");
            exit();
        }

    } else {
        header("Location: index.php?error=User Not Found");
        exit();
    }
}
?>