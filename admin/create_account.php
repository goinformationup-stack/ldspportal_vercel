<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 1. UNIFIED STAFF SESSION CONFIGURATION
$session_lifetime = 60 * 60 * 24 * 30; // 30 days
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params($session_lifetime, '/');
session_name('LDSP_STAFF_SESSION'); 
session_start();

// 2. CACHE BUSTING
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// PHPMailer & DB & Environment Setup
require '../PHPMailer/Exception.php';
require '../PHPMailer/PHPMailer.php';
require '../PHPMailer/SMTP.php';
include "../dbconn.php";
require "../env.php";

// =========================================================
// STRICT ADMIN-ONLY ACCESS 
// =========================================================
$allowed_roles = ['admin'];

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../index.php");
    exit();
}

function getSafeCount($conn, $query) {
    try {
        $result = $conn->query($query);
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return (int)($row['total'] ?? 0);
        }
    } catch (Exception $e) {}
    return 0;
}

$prog_list = []; 
try {
    $prog_q = $conn->query("SELECT program_name FROM programs ORDER BY program_name ASC");
    if ($prog_q) { while ($r = $prog_q->fetch_assoc()) { $prog_list[] = $r['program_name']; } }
} catch (Exception $e) {}

// =========================================================
// AJAX ASYNC HANDLERS (NO PAGE REFRESH)
// =========================================================

// 1. ACTION LINKS (Archive, Restore, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $id = (int)($_POST['id'] ?? 0);

    try {
        if ($action === 'archive') {
            if ($id == 1 || $id == $_SESSION['id']) {
                echo json_encode(['status' => 'error', 'message' => 'Action denied. Cannot archive this account.']);
                exit;
            }
            $conn->query("UPDATE users SET is_archived = 1 WHERE id = $id");
            echo json_encode(['status' => 'success', 'message' => 'Account has been moved to the Archive.']);
        
        } elseif ($action === 'restore') {
            $conn->query("UPDATE users SET is_archived = 0 WHERE id = $id");
            echo json_encode(['status' => 'success', 'message' => 'Account successfully restored to active status.']);
        
        } elseif ($action === 'delete') {
            if ($id == 1 || $id == $_SESSION['id']) {
                echo json_encode(['status' => 'error', 'message' => 'Action denied. Cannot delete this account.']);
                exit;
            }
            $conn->query("DELETE FROM user_profiles WHERE user_id = $id");
            $conn->query("DELETE FROM users WHERE id = $id");
            echo json_encode(['status' => 'success', 'message' => 'Account has been permanently deleted.']);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database action failed. Ensure there are no linked records before deleting.']);
    }
    exit;
}

// 2. FORM ACTIONS (Create Staff Account - Direct Activation + Email)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] == 'create') {
        $last_name = ucwords(strtolower(trim($_POST['last_name'] ?? '')));
        $first_name = ucwords(strtolower(trim($_POST['first_name'] ?? '')));
        $middle_name = ucwords(strtolower(trim($_POST['middle_name'] ?? '')));
        $email = strtolower(trim($_POST['email'] ?? '')); 
        $role = $_POST['role'] ?? '';
        $raw_password = $_POST['password'] ?? '';
        $program = NULL;

        if ($role === 'student') {
            echo json_encode(['status' => 'error', 'message' => 'Student accounts must be provisioned by the Admission Office.']); 
            exit();
        }

        if (empty($raw_password)) {
            echo json_encode(['status' => 'error', 'message' => 'A password is required to provision this account.']); 
            exit();
        }

        $hashed_password = password_hash($raw_password, PASSWORD_BCRYPT);

        try {
            $stmt = $conn->prepare("INSERT INTO users (email, password, role, status, created_at) VALUES (?, ?, ?, 'Active', NOW())");
            $stmt->bind_param("sss", $email, $hashed_password, $role);
            $stmt->execute();
            $uid = $conn->insert_id;

            $stmt_p = $conn->prepare("INSERT INTO user_profiles (user_id, last_name, first_name, middle_name, program) VALUES (?, ?, ?, ?, ?)");
            $stmt_p->bind_param("issss", $uid, $last_name, $first_name, $middle_name, $program);
            $stmt_p->execute();

            // Explicitly set the login link based on role
            $base_url = "http://localhost/LDSP_enrollment_system";
            $login_link = ($role === 'teacher') ? $base_url . "/teacher/index.php" : $base_url . "/index.php"; 
            $display_role = strtoupper($role);

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = SMTP_PORT;

                $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
                $mail->addAddress($email, $first_name . ' ' . $last_name); 

                $mail->isHTML(true);
                $mail->Subject = 'Welcome to the LDSP Portal - Your Account Details';
                
                $mail->Body = "
                    <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto;'>
                        <h2 style='color: #00205b;'>Welcome to LDSP, $first_name $last_name!</h2>
                        <p>An administrative account has been provisioned for you in the enrollment system.</p>
                        
                        <div style='background-color: #f8fafc; border-left: 4px solid #00205b; padding: 15px; margin: 20px 0; border-radius: 4px;'>
                            <h3 style='margin-top: 0; color: #00205b; font-size: 16px;'>Your Account Details</h3>
                            <p style='margin: 8px 0;'><strong>Role:</strong> $display_role</p>
                            <p style='margin: 8px 0;'><strong>Email Address:</strong> $email</p>
                            <p style='margin: 8px 0;'><strong>Temporary Password:</strong> <span style='background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-family: monospace;'>$raw_password</span></p>
                        </div>

                        <p>You can log in to the portal immediately. For quick and secure access, we recommend using the <b>Sign in with Google</b> button. Alternatively, you may log in manually using the email and temporary password provided above.</p>
                        
                        <p style='text-align: center; margin: 30px 0;'>
                            <a href='{$login_link}' style='padding: 12px 24px; background-color: #00205b; color: white; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: bold; text-transform: uppercase; font-size: 14px;'>Access Portal Now</a>
                        </p>
                        
                        <p style='font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 15px;'>
                            <i>Security Note: If you choose to log in manually rather than using Google Sign-in, we strongly recommend changing your password after your first login.</i>
                        </p>
                    </div>
                ";
                
                $mail->AltBody = "Welcome to LDSP, $first_name $last_name!\n\nAn administrative account has been provisioned for you.\n\nACCOUNT DETAILS:\nRole: $display_role\nEmail: $email\nTemporary Password: $raw_password\n\nAccess the portal here: $login_link\n\nNote: If you do not use Google Sign-in, please change this password after your first login.";

                $mail->send();
                
                echo json_encode(['status' => 'success', 'message' => "Account created and welcome email sent to $email!"]); 
                exit();
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Account created, but welcome email failed: ' . $mail->ErrorInfo]);
                exit();
            }

        } catch (mysqli_sql_exception $e) {
            $msg = ($e->getCode() == 1062) ? "The email provided is already registered in the system." : "Database Error.";
            echo json_encode(['status' => 'error', 'message' => $msg]); 
            exit();
        }
    }
}

// =========================================================
// FILTER & QUERY SETUP
// =========================================================
$show_status = $_GET['status'] ?? 'teachers';
$active_view = $_GET['view'] ?? '';
$f_dept     = $_GET['f_dept'] ?? '';

$staff_filters = ""; 
if ($f_dept !== '' && $f_dept !== 'All') {
    $staff_filters .= " AND u.role = '" . $conn->real_escape_string($f_dept) . "'";
}

$teacher_sql_base = "FROM users u LEFT JOIN user_profiles p ON u.id = p.user_id WHERE u.role = 'teacher' AND (u.is_archived = 0 OR u.is_archived IS NULL)";
$staff_sql_base = "FROM users u LEFT JOIN user_profiles p ON u.id = p.user_id WHERE u.role NOT IN ('student', 'teacher') AND (u.is_archived = 0 OR u.is_archived IS NULL) " . $staff_filters;
$archived_sql_base = "FROM users u LEFT JOIN user_profiles p ON u.id = p.user_id WHERE u.is_archived = 1 AND u.role != 'student'";

// =========================================================
// HTML ROW RENDERING TEMPLATES
// =========================================================

function renderTeacherRow($row) {
    $display_name = trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . (!empty($row['middle_name']) ? ' ' . $row['middle_name'] : ''));
    $created_date = !empty($row['created_at']) ? date('M j, Y', strtotime($row['created_at'])) : 'N/A';
    $system_id = 'UID-' . str_pad($row['id'], 5, '0', STR_PAD_LEFT);
    
    ob_start();
    ?>
    <tr class="hover:bg-[#00205b]/5 transition-colors group">
        <td class="px-4 py-3">
            <span class="font-bold text-[#00205b] text-sm"><?= htmlspecialchars($display_name) ?></span><br>
            <span class="text-[#00205b]/70 text-[11px] mt-0.5 inline-block"><?= htmlspecialchars($row['email'] ?? '') ?></span>
        </td>
        <td class="px-4 py-3">
            <div class="font-mono text-[#c5a02c] mb-1.5 text-[11px] font-bold drop-shadow-sm"><?= $system_id ?></div>
            <span class="px-2 py-0.5 rounded bg-indigo-50 shadow-sm text-[8px] font-black text-indigo-700 border border-indigo-200 uppercase tracking-widest">Teacher</span>
        </td>
        <td class="px-4 py-3 text-xs text-[#00205b]/70 font-medium"><?= $created_date ?></td>
        <td class="px-4 py-3 text-right opacity-90 group-hover:opacity-100 transition-opacity whitespace-nowrap">
            <button type="button" onclick="handleAction('archive', <?= $row['id'] ?>, 'Archive Teacher access?')" title="Archive Account" class="text-[#00205b]/60 hover:text-white bg-white hover:bg-[#00205b] border border-[#00205b]/20 hover:border-[#00205b] p-1.5 rounded-lg inline-flex items-center transition-all shadow-sm">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>
            </button>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

function renderStaffRow($row) {
    $current_user_id = $_SESSION['id'] ?? 0;
    
    $role_colors = [ 'admin' => 'bg-rose-50 text-rose-600 border-rose-200 shadow-sm', 'admission' => 'bg-emerald-50 text-emerald-600 border-emerald-200 shadow-sm', 'registrar' => 'bg-indigo-50 text-indigo-600 border-indigo-200 shadow-sm', 'accounting' => 'bg-amber-50 text-amber-600 border-amber-200 shadow-sm' ];
    $role_labels = [ 'admin' => 'System Admin', 'admission' => 'Admission Staff', 'registrar' => 'Registrar', 'accounting' => 'Accounting Staff' ];
    $badge_class = $role_colors[$row['role']] ?? 'bg-[#00205b]/10 text-[#00205b]/80 border-[#00205b]/20 shadow-sm';
    $role_name   = $role_labels[$row['role']] ?? strtoupper($row['role']);
    $display_name = trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . (!empty($row['middle_name']) ? ' ' . $row['middle_name'] : ''));
    $created_date = !empty($row['created_at']) ? date('M j, Y', strtotime($row['created_at'])) : 'N/A';
    $system_id = 'UID-' . str_pad($row['id'], 5, '0', STR_PAD_LEFT);
    
    ob_start();
    ?>
    <tr class="hover:bg-[#00205b]/5 transition-colors group">
        <td class="px-4 py-3">
            <span class="font-bold text-[#00205b] text-sm"><?= htmlspecialchars($display_name) ?></span><br>
            <span class="text-[#00205b]/70 text-[11px] mt-0.5 inline-block"><?= htmlspecialchars($row['email'] ?? '') ?></span>
        </td>
        <td class="px-4 py-3 align-middle">
            <div class="font-mono text-[#00205b]/70 mb-1.5 text-[11px] font-bold drop-shadow-sm"><?= $system_id ?></div>
            <span class="px-2 py-0.5 rounded <?= $badge_class ?> text-[8px] font-black border uppercase tracking-widest"><?= htmlspecialchars($role_name) ?></span>
        </td>
        <td class="px-4 py-3 text-xs text-[#00205b]/70 font-medium"><?= $created_date ?></td>
        <td class="px-4 py-3 text-right opacity-90 group-hover:opacity-100 transition-opacity">
            <?php if ($row['id'] == 1): ?>
                <span class="text-[#00205b]/50 text-[8px] uppercase font-black tracking-[0.2em] border border-[#00205b]/20 bg-[#00205b]/10 px-2 py-1 rounded shadow-sm">Root Admin</span>
            <?php elseif ($row['id'] == $current_user_id): ?>
                <span class="text-indigo-400 text-[8px] uppercase font-black tracking-[0.2em] border border-indigo-200 bg-indigo-50 px-2 py-1 rounded shadow-sm">Current User</span>
            <?php else: ?>
                <button type="button" onclick="handleAction('archive', <?= $row['id'] ?>, 'Archive staff access?')" title="Archive Account" class="text-amber-600 hover:text-white bg-white hover:bg-amber-500 border border-amber-200 hover:border-amber-600 p-1.5 rounded-lg inline-flex items-center transition-all shadow-sm">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>
                </button>
            <?php endif; ?>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

function renderArchivedRow($row) {
    $display_name = trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . (!empty($row['middle_name']) ? ' ' . $row['middle_name'] : ''));
    $created_date = !empty($row['created_at']) ? date('M j, Y', strtotime($row['created_at'])) : 'N/A';
    $system_id = 'UID-' . str_pad($row['id'], 5, '0', STR_PAD_LEFT);
    
    ob_start();
    ?>
    <tr class="hover:bg-[#00205b]/5 transition-colors group">
        <td class="px-4 py-3 text-[#00205b]/70">
            <span class="font-bold text-sm line-through text-[#00205b]/50 drop-shadow-sm"><?= htmlspecialchars($display_name) ?></span><br>
            <span class="text-[11px] mt-0.5 inline-block text-[#00205b]/50"><?= htmlspecialchars($row['email'] ?? '') ?></span>
        </td>
        <td class="px-4 py-3 align-middle">
            <div class="font-mono text-[#00205b]/50 mb-1.5 text-[11px] font-bold drop-shadow-sm"><?= $system_id ?></div>
            <span class="px-2 py-0.5 rounded bg-[#00205b]/10 shadow-sm text-[8px] font-black text-[#00205b]/50 border border-[#00205b]/20 uppercase tracking-widest"><?= htmlspecialchars($row['role'] ?? '') ?></span>
        </td>
        <td class="px-4 py-3 text-xs text-[#00205b]/50 font-medium"><?= $created_date ?></td>
        <td class="px-4 py-3 text-right opacity-90 group-hover:opacity-100 transition-opacity whitespace-nowrap">
            <div class="flex items-center justify-end gap-2">
                <button type="button" onclick="handleAction('restore', <?= $row['id'] ?>, 'Restore this account to active status?')" title="Restore Account" class="text-emerald-600 hover:text-white bg-white hover:bg-emerald-500 border border-emerald-200 hover:border-emerald-600 p-1.5 rounded-lg inline-flex items-center transition-all shadow-sm">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" /></svg>
                </button>
                <button type="button" onclick="handleAction('delete', <?= $row['id'] ?>, 'PERMANENTLY delete this account? This action cannot be undone.')" title="Permanently Delete" class="text-rose-600 hover:text-white bg-white hover:bg-rose-600 border border-rose-200 hover:border-rose-600 p-1.5 rounded-lg inline-flex items-center transition-all shadow-sm">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                </button>
            </div>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

// ---------------------------------------------------------
// ASYNC JSON DATA ENDPOINT (FOR LIVE REFRESH)
// ---------------------------------------------------------
if (isset($_GET['api_refresh'])) {
    header('Content-Type: application/json');
    $html = '';
    $count_text = '';

    if ($show_status === 'teachers') {
        $total_teachers = getSafeCount($conn, "SELECT COUNT(u.id) as total " . $teacher_sql_base);
        $count_text = $total_teachers . " TOTAL";
        
        $query = "SELECT u.*, p.first_name, p.last_name, p.middle_name, p.program " . $teacher_sql_base . " ORDER BY u.created_at DESC";
        if ($active_view !== 'teachers') $query .= " LIMIT 15";
        $res = $conn->query($query);

        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $html .= renderTeacherRow($row);
            }
        } else {
            $html = '<tr><td colspan="4" class="py-8 px-4 text-center text-[#00205b]/50 text-xs font-bold uppercase tracking-[0.2em] bg-white/50">No active teachers found</td></tr>';
        }
    }
    elseif ($show_status === 'staff') {
        $total_staff = getSafeCount($conn, "SELECT COUNT(u.id) as total " . $staff_sql_base);
        $count_text = $total_staff . " TOTAL";

        $query = "SELECT u.*, p.first_name, p.last_name, p.middle_name " . $staff_sql_base . " ORDER BY u.role ASC, u.created_at DESC";
        if ($active_view !== 'staff') $query .= " LIMIT 15";
        $res = $conn->query($query);

        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $html .= renderStaffRow($row);
            }
        } else {
            $html = '<tr><td colspan="4" class="py-8 px-4 text-center text-[#00205b]/50 text-xs font-bold uppercase tracking-[0.2em] bg-white/50">No active system staff found for this department</td></tr>';
        }
    }
    elseif ($show_status === 'archived') {
        $total_archived = getSafeCount($conn, "SELECT COUNT(u.id) as total " . $archived_sql_base);
        $count_text = $total_archived > 0 ? $total_archived . " Total" : "";

        $query = "SELECT u.*, p.first_name, p.last_name, p.middle_name " . $archived_sql_base . " ORDER BY u.created_at DESC";
        if ($active_view !== 'archived') $query .= " LIMIT 15";
        $res = $conn->query($query);

        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $html .= renderArchivedRow($row);
            }
        } else {
            $html = '<tr><td colspan="4" class="py-8 px-4 text-center text-[#00205b]/50 text-xs font-bold uppercase tracking-[0.2em] bg-white/50"><div class="flex flex-col items-center justify-center gap-2"><svg class="w-8 h-8 text-[#00205b]/30 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0l-3-3m3 3l3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>No archived accounts</div></td></tr>';
        }
    }

    echo json_encode([
        'html' => $html,
        'count_text' => $count_text
    ]);
    exit();
}

$total_teachers = getSafeCount($conn, "SELECT COUNT(u.id) as total " . $teacher_sql_base);
$total_staff = getSafeCount($conn, "SELECT COUNT(u.id) as total " . $staff_sql_base);
$total_archived = getSafeCount($conn, "SELECT COUNT(u.id) as total " . $archived_sql_base);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Management - LDSP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css?v=<?php echo time(); ?>">
</head>
<body class="flex h-screen overflow-hidden antialiased">
    <div class="ambient-orb-1"></div>
    <div class="ambient-orb-2"></div>

    <?php include 'sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto h-full w-full pt-20 md:pt-0 relative z-10 custom-scrollbar">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1200px] mx-auto">
            
            <header class="mb-6">
                <div class="flex items-center gap-2 text-[10px] font-bold text-[#00205b]/70 uppercase tracking-widest mb-1 drop-shadow-sm">
                    <span>Administrator</span> <span class="text-[#00205b]/50">/</span> <span class="text-[#00205b]">Account Management</span>
                </div>
                <h1 class="text-2xl md:text-3xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">Manage Account</h1>
            </header>

            <div class="flex items-center gap-2 mb-4 pb-2 border-b border-[#00205b]/20 overflow-x-auto whitespace-nowrap drop-shadow-sm custom-scrollbar">
                <a href="create_account.php?status=teachers" class="px-4 py-2 rounded-lg text-[10px] font-bold uppercase tracking-widest transition-all <?= ($show_status === 'teachers') ? 'bg-[#00205b] text-white shadow-[0_4px_10px_-2px_rgba(0,32,91,0.4),inset_0_1px_0_rgba(255,255,255,0.3)] border border-[#001233]' : 'bg-white/80 backdrop-blur-sm border border-[#00205b]/20 text-[#00205b]/70 hover:bg-white hover:text-[#00205b] shadow-sm hover:shadow-md' ?>">Teachers</a>
                <a href="create_account.php?status=staff" class="px-4 py-2 rounded-lg text-[10px] font-bold uppercase tracking-widest transition-all <?= ($show_status === 'staff') ? 'bg-[#00205b] text-white shadow-[0_4px_10px_-2px_rgba(0,32,91,0.4),inset_0_1px_0_rgba(255,255,255,0.3)] border border-[#001233]' : 'bg-white/80 backdrop-blur-sm border border-[#00205b]/20 text-[#00205b]/70 hover:bg-white hover:text-[#00205b] shadow-sm hover:shadow-md' ?>">System Staff</a>
                <a href="create_account.php?status=archived" class="px-4 py-2 rounded-lg text-[10px] font-bold uppercase tracking-widest transition-all flex items-center gap-1.5 <?= ($show_status === 'archived') ? 'bg-[#00205b] text-white shadow-[0_4px_10px_-2px_rgba(0,32,91,0.4),inset_0_1px_0_rgba(255,255,255,0.3)] border border-[#001233]' : 'bg-white/80 backdrop-blur-sm border border-[#00205b]/20 text-[#00205b]/70 hover:bg-white hover:text-[#00205b] shadow-sm hover:shadow-md' ?>"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg> Archive</a>
            </div>

            <?php if ($show_status === 'teachers'): ?>
                <div class="glossy-panel mb-6">
                    <div class="glossy-panel-header" style="background-color: #00205b;"></div>
                    <div class="p-3 md:p-4 flex flex-col md:flex-row items-start md:items-center justify-between gap-3">
                        <div class="flex items-center gap-2 px-2 w-full md:w-auto">
                            <div class="bg-[#00205b]/10 p-2 rounded-lg text-[#00205b] border border-[#00205b]/20 shadow-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg></div>
                            <h3 class="font-black text-[#00205b] text-xs uppercase tracking-[0.15em] drop-shadow-sm">Manage Teachers</h3>
                        </div>
                        <div class="flex flex-wrap items-center justify-end gap-2 w-full md:w-auto px-2 md:px-0">
                            <button onclick="openStaffModal('teacher')" type="button" class="btn-glossy-animated bg-[#00205b] px-4 py-2 rounded-lg font-bold text-[9px] uppercase tracking-widest flex items-center gap-1.5 shadow-sm text-white border-[#001233] hover:shadow-[0_8px_20px_-6px_rgba(0,32,91,0.5)]">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                Add Teacher
                            </button>
                        </div>
                    </div>
                </div>

                <div class="glossy-panel mb-6" id="teachers">
                    <div class="p-4 bg-white/60 border-b border-[#00205b]/20 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 z-20">
                        <h3 class="font-bold text-[#00205b] text-[11px] uppercase tracking-[0.1em] flex items-center gap-2 drop-shadow-sm">Faculty / Teachers Directory</h3>
                        <span id="teachers_count" class="bg-white border border-[#00205b]/20 text-[#00205b] px-3 py-1 rounded-md text-[9px] uppercase tracking-widest font-black shadow-[0_2px_10px_rgba(0,32,91,0.1)]">
                            <?= $total_teachers ?> TOTAL
                        </span>
                    </div>

                    <div class="overflow-x-auto z-20 bg-white/40">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-white/80 backdrop-blur-md text-[#00205b]/70 uppercase text-[9px] font-bold tracking-widest border-b border-[#00205b]/20">
                                <tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">System ID & Role</th><th class="px-4 py-3">Date Created</th><th class="px-4 py-3 text-right">Action</th></tr>
                            </thead>
                            <tbody id="teachers_tbody" class="divide-y divide-[#00205b]/10">
                                <tr><td colspan="4" class="py-8 px-4 text-center text-[#00205b]/50 text-[10px] font-bold uppercase tracking-[0.2em] bg-white/50">Loading data...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <?php
                    $get_params = $_GET; $get_params['status'] = 'teachers'; $get_params['view'] = 'teachers';
                    $view_all_link = '?' . http_build_query($get_params) . '#teachers';
                    $get_params['view'] = null; $collapse_link = '?' . http_build_query($get_params) . '#teachers';

                    if ($active_view !== 'teachers' && $total_teachers > 15): ?>
                        <div class="bg-white/80 backdrop-blur-sm p-3 text-center border-t border-[#00205b]/20">
                            <a href="<?= $view_all_link ?>" class="text-[#00205b] hover:text-[#00205b]/80 text-[9px] font-black uppercase tracking-widest transition-colors">View All <?= $total_teachers ?> Records &darr;</a>
                        </div>
                    <?php elseif ($active_view === 'teachers' && $total_teachers > 15): ?>
                        <div class="bg-white/80 backdrop-blur-sm p-3 text-center border-t border-[#00205b]/20">
                            <a href="<?= $collapse_link ?>" class="text-[#00205b]/50 hover:text-[#00205b]/70 text-[9px] font-black uppercase tracking-widest transition-colors">Collapse View &uarr;</a>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($show_status === 'staff'): ?>
                <div class="glossy-panel mb-6">
                    <div class="glossy-panel-header" style="background-color: #00205b;"></div>
                    <div class="p-3 md:p-4 flex flex-col md:flex-row items-start md:items-center justify-between gap-3">
                        <div class="flex items-center gap-2 px-2 w-full md:w-auto">
                            <div class="bg-[#00205b]/10 p-2 rounded-lg text-[#00205b] border border-[#00205b]/20 shadow-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" /></svg></div>
                            <h3 class="font-black text-[#00205b] text-xs uppercase tracking-[0.15em] drop-shadow-sm">Filter Staff</h3>
                        </div>
                        <div class="flex flex-wrap items-center justify-end gap-2 w-full md:w-auto px-2 md:px-0">
                            <button onclick="openStaffModal('staff')" type="button" class="btn-glossy-animated bg-[#00205b] px-4 py-2 text-[9px] uppercase tracking-widest flex items-center gap-1.5 h-auto text-white border-[#001233]">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                Add Staff
                            </button>
                            <form id="filterForm" onsubmit="event.preventDefault();" class="flex flex-wrap items-center gap-2">
                                <select name="f_dept" id="f_dept" onchange="triggerDynamicFetch()" class="input-glossy-smooth !py-1.5 !px-3 !text-[11px] w-full sm:w-auto cursor-pointer font-semibold text-[#00205b] hover:!bg-white">
                                    <option value="" disabled hidden <?= $f_dept === '' ? 'selected' : '' ?>>Select Department</option>
                                    <option value="All" <?= $f_dept === 'All' ? 'selected' : '' ?>>All Departments</option>
                                    <option value="admin" <?= $f_dept === 'admin' ? 'selected' : '' ?>>System Admin</option>
                                    <option value="admission" <?= $f_dept === 'admission' ? 'selected' : '' ?>>Admission Staff</option>
                                    <option value="registrar" <?= $f_dept === 'registrar' ? 'selected' : '' ?>>Registrar Staff</option>
                                    <option value="accounting" <?= $f_dept === 'accounting' ? 'selected' : '' ?>>Accounting Staff</option>
                                </select>
                                <button type="button" onclick="document.getElementById('f_dept').value='All'; triggerDynamicFetch();" class="bg-white hover:bg-rose-50 text-[#00205b] hover:text-rose-600 font-bold text-[9px] px-4 py-2 rounded-lg transition-all uppercase border border-[#00205b]/20 hover:border-rose-200 w-full sm:w-auto text-center shadow-sm">Reset</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="glossy-panel mb-6" id="staff">
                    <div class="p-4 bg-white/60 border-b border-[#00205b]/20 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 z-20">
                        <h3 class="font-bold text-[#00205b] text-[11px] uppercase tracking-[0.1em] flex items-center gap-2 drop-shadow-sm">Created Accounts Per Department</h3>
                        <span id="staff_count" class="bg-white border border-[#00205b]/20 text-[#00205b] px-3 py-1 rounded-md text-[9px] uppercase tracking-widest font-black shadow-[0_2px_10px_rgba(0,32,91,0.05)]">
                            <?= $total_staff ?> TOTAL
                        </span>
                    </div>

                    <div class="overflow-x-auto z-20 bg-white/40">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-white/80 backdrop-blur-md text-[#00205b]/70 uppercase text-[9px] font-bold tracking-widest border-b border-[#00205b]/20">
                                <tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">System ID & Role</th><th class="px-4 py-3">Date Created</th><th class="px-4 py-3 text-right">Action</th></tr>
                            </thead>
                            <tbody id="staff_tbody" class="divide-y divide-[#00205b]/10">
                                <tr><td colspan="4" class="py-8 px-4 text-center text-[#00205b]/50 text-[10px] font-bold uppercase tracking-[0.2em] bg-white/50">Loading data...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <?php
                    $get_params = $_GET; $get_params['status'] = 'staff'; $get_params['view'] = 'staff';
                    $view_all_link = '?' . http_build_query($get_params) . '#staff';
                    $get_params['view'] = null; $collapse_link = '?' . http_build_query($get_params) . '#staff';

                    if ($active_view !== 'staff' && $total_staff > 15): ?>
                        <div class="bg-white/80 backdrop-blur-sm p-3 text-center border-t border-[#00205b]/20">
                            <a href="<?= $view_all_link ?>" class="text-[#00205b] hover:text-[#00205b]/80 text-[9px] font-black uppercase tracking-widest transition-colors">View All <?= $total_staff ?> Records &darr;</a>
                        </div>
                    <?php elseif ($active_view === 'staff' && $total_staff > 15): ?>
                        <div class="bg-white/80 backdrop-blur-sm p-3 text-center border-t border-[#00205b]/20">
                            <a href="<?= $collapse_link ?>" class="text-[#00205b]/50 hover:text-[#00205b]/70 text-[9px] font-black uppercase tracking-widest transition-colors">Collapse View &uarr;</a>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($show_status === 'archived'): ?>
                <div class="glossy-panel mb-6" id="archived">
                    <div class="glossy-panel-header" style="background-color: #00205b;"></div>
                    <div class="p-4 bg-white/60 border-b border-[#00205b]/20 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 z-20">
                        <h3 class="font-bold text-[#00205b] text-[11px] uppercase tracking-[0.1em] flex items-center gap-2 drop-shadow-sm">
                            Archived / Deactivated Accounts
                        </h3>
                        <?php if ($total_archived > 0): ?>
                            <span id="archived_count" class="bg-white border border-[#00205b]/20 text-[#00205b] px-3 py-1 rounded-md text-[9px] uppercase tracking-widest font-black shadow-sm">
                                <?= $total_archived ?> Total
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="overflow-x-auto z-20 bg-white/40">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-white/80 backdrop-blur-md text-[#00205b]/70 uppercase text-[9px] font-bold tracking-widest border-b border-[#00205b]/20">
                                <tr>
                                    <th class="px-4 py-3">Name</th>
                                    <th class="px-4 py-3">System ID & Role</th>
                                    <th class="px-4 py-3">Date Created</th>
                                    <th class="px-4 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody id="archived_tbody" class="divide-y divide-[#00205b]/10">
                                <tr><td colspan="4" class="py-8 px-4 text-center text-[#00205b]/50 text-[10px] font-bold uppercase tracking-[0.2em] bg-white/50">Loading data...</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <?php
                    $get_params = $_GET; $get_params['status'] = 'archived'; $get_params['view'] = 'archived';
                    $view_all_link = '?' . http_build_query($get_params) . '#archived';
                    $get_params['view'] = null; $collapse_link = '?' . http_build_query($get_params) . '#archived';

                    if ($active_view !== 'archived' && $total_archived > 15): ?>
                        <div class="bg-white/80 backdrop-blur-sm p-3 text-center border-t border-[#00205b]/20">
                            <a href="<?= $view_all_link ?>" class="text-[#00205b]/70 hover:text-[#00205b] text-[9px] font-black uppercase tracking-widest transition-colors">View All <?= $total_archived ?> Records &darr;</a>
                        </div>
                    <?php elseif ($active_view === 'archived' && $total_archived > 15): ?>
                        <div class="bg-white/80 backdrop-blur-sm p-3 text-center border-t border-[#00205b]/20">
                            <a href="<?= $collapse_link ?>" class="text-[#00205b]/50 hover:text-[#00205b]/70 text-[9px] font-black uppercase tracking-widest transition-colors">Collapse View &uarr;</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?> 
        </div>
    </main>

    <!-- STAFF/TEACHER PROVISION MODAL -->
    <div id="staffAccountModal" class="fixed inset-0 z-[60] hidden items-center justify-center p-4 modal-overlay bg-[#00205b]/80 backdrop-blur-sm">
        <div class="glossy-panel w-full max-w-lg max-h-[95vh] flex flex-col modal-content border-t-4 border-t-[#00205b]">
            <div class="p-4 sm:p-5 border-b border-[#00205b]/20 flex justify-between items-center bg-white/90 shrink-0">
                <h3 class="text-xs font-black uppercase tracking-[0.15em] flex items-center gap-2 drop-shadow-sm text-[#00205b]">
                    <div class="p-1.5 rounded-md bg-[#00205b]/10"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg></div>
                    <span id="staff_modal_title">Provision Account</span>
                </h3>
                <button type="button" onclick="closeStaffModal()" class="text-[#00205b]/50 hover:text-rose-500 bg-[#00205b]/5 hover:bg-rose-50 p-2 rounded-lg transition-colors shadow-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
            </div>
            <form onsubmit="handleFormSubmit(event, this)" class="p-4 sm:p-5 grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-4 bg-white/90 flex-1 overflow-y-auto custom-scrollbar">
                <input type="hidden" name="action" value="create">
                
                <div class="md:col-span-2 grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="text-[9px] font-bold text-[#00205b]/70 uppercase tracking-widest mb-1.5 block">Last Name</label>
                        <input type="text" name="last_name" required class="input-glossy-smooth !py-1.5 !text-[11px] capitalize" placeholder="Dela Cruz">
                    </div>
                    <div>
                        <label class="text-[9px] font-bold text-[#00205b]/70 uppercase tracking-widest mb-1.5 block">First Name</label>
                        <input type="text" name="first_name" required class="input-glossy-smooth !py-1.5 !text-[11px] capitalize" placeholder="Juan">
                    </div>
                    <div>
                        <label class="text-[9px] font-bold text-[#00205b]/70 uppercase tracking-widest mb-1.5 block">Middle Name</label>
                        <input type="text" name="middle_name" class="input-glossy-smooth !py-1.5 !text-[11px] capitalize" placeholder="Optional">
                    </div>
                </div>

                <div>
                    <label class="text-[9px] font-bold text-[#00205b]/70 uppercase tracking-widest mb-1.5 block">Email Address</label>
                    <input type="email" name="email" required class="input-glossy-smooth !py-1.5 !text-[11px]" placeholder="staff@ldsp.edu.ph" oninput="this.value = this.value.toLowerCase();">
                </div>
                
                <div id="staff_role_container">
                    <label class="text-[9px] font-bold text-[#00205b]/70 uppercase tracking-widest mb-1.5 block">System Role</label>
                    <select name="role" id="staff_role_select" class="input-glossy-smooth !py-1.5 !text-[11px] cursor-pointer font-bold text-[#00205b]">
                    </select>
                </div>

                <div class="md:col-span-2">
                    <label class="text-[9px] font-bold text-[#00205b]/70 uppercase tracking-widest mb-1.5 block">Initial Password</label>
                    <input type="text" name="password" required class="input-glossy-smooth !py-1.5 !text-[11px] w-full" placeholder="Enter temporary password for the user">
                    <p class="text-[8px] text-[#00205b]/50 mt-1 font-semibold uppercase tracking-widest">User can log in instantly with this password.</p>
                </div>

                <div class="md:col-span-2 flex flex-col-reverse sm:flex-row justify-end gap-2 mt-1 pt-4 border-t border-[#00205b]/20">
                    <button type="button" onclick="closeStaffModal()" class="w-full sm:w-auto px-6 py-2 rounded-lg text-[10px] font-bold uppercase tracking-widest text-[#00205b] bg-white border border-[#00205b]/30 hover:bg-[#00205b]/5 transition-colors shadow-sm text-center">Cancel</button>
                    <button type="submit" class="w-full sm:w-auto justify-center btn-glossy-animated bg-[#00205b] text-white !px-6 !py-2 !text-[10px] !tracking-widest flex items-center gap-1.5 border border-[#001233]" id="staff_submit_btn">
                        Create & Email
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const currentTab = '<?= $show_status ?>';
    </script>
    <script src="admin.js?v=<?php echo time(); ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="create_account.js?v=<?php echo time(); ?>"></script>
</body>
</html>