<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 1. FORCE ERROR REPORTING & CATCH FATAL ERRORS VISUALLY
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo json_encode(['status' => 'error', 'message' => "Fatal PHP Error: " . htmlspecialchars($error['message'])]);
        exit;
    }
});

$session_lifetime = 60 * 60 * 24 * 30; // 30 days
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params($session_lifetime, '/');
session_name('LDSP_STAFF_SESSION'); 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Include DB, Mailer, and ENV
require '../PHPMailer/Exception.php';
require '../PHPMailer/PHPMailer.php';
require '../PHPMailer/SMTP.php';
include "../dbconn.php";
require "../env.php";

$allowed_roles = ['admin', 'admission'];

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../index.php");
    exit();
}

$can_edit = ($_SESSION['role'] === 'admission' || $_SESSION['role'] === 'admin');
$success_msg = "";
$error_msg = "";

// =========================================================
// GOOGLE WORKSPACE LIVE MAILBOX VERIFIER
// =========================================================
function verifyGoogleMailbox($email) {
    $domain = substr(strrchr($email, "@"), 1);
    $mxhosts = [];
    
    if (!getmxrr($domain, $mxhosts)) return true; 
    $mxHost = $mxhosts[0];
    
    $connect = @fsockopen($mxHost, 25, $errno, $errstr, 5);
    if (!$connect) return true; 

    fgets($connect, 1024); 
    fputs($connect, "HELO {$_SERVER['SERVER_NAME']}\r\n");
    fgets($connect, 1024);

    fputs($connect, "MAIL FROM: <verify@{$domain}>\r\n");
    fgets($connect, 1024);

    fputs($connect, "RCPT TO: <{$email}>\r\n");
    $response = fgets($connect, 1024);

    fputs($connect, "QUIT\r\n");
    fclose($connect);

    if (strpos($response, '550') !== false) {
        return false; 
    }
    return true; 
}

// =========================================================
// FETCH LIVE PORTAL SETTINGS & FEES
// =========================================================
$settings = [];
$settings_q = $conn->query("SELECT * FROM portal_settings");
if ($settings_q) {
    while ($row = $settings_q->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

// Live active term directly from admission portal settings
$active_admission_year  = isset($settings['academic_year']) ? $settings['academic_year'] : '2024-2025';
$active_enrollment_year = isset($settings['active_school_year']) ? $settings['active_school_year'] : '2024-2025';
$active_semester        = isset($settings['semester']) ? $settings['semester'] : (isset($settings['active_semester']) ? $settings['active_semester'] : '1st Semester');

// =========================================================
// AUTO-FAILSAFE: ENSURE ACADEMIC YEAR COLUMN & BACKFILL
// =========================================================
try {
    $col_check = $conn->query("SHOW COLUMNS FROM admissions LIKE 'academic_year'");
    if ($col_check && $col_check->num_rows === 0) {
        $conn->query("ALTER TABLE admissions ADD COLUMN academic_year VARCHAR(50) DEFAULT NULL AFTER program");
    }
    
    // Auto-backfill existing records so legacy applicants never disappear
    $conn->query("
        UPDATE admissions a
        LEFT JOIN exam_results er ON a.admission_number = er.student_id
        SET a.academic_year = COALESCE(
            NULLIF(er.academic_year, ''),
            NULLIF(a.academic_year, ''),
            '$active_admission_year'
        )
        WHERE a.academic_year IS NULL OR a.academic_year = ''
    ");
} catch (Exception $e) {}

// =========================================================
// STRICT ACADEMIC YEAR GENERATOR (Base: 2024-2025)
// =========================================================
$adm_start = (int)explode('-', $active_admission_year)[0];
$enr_start = (int)explode('-', $active_enrollment_year)[0];
$active_start = max($adm_start, $enr_start);
if ($active_start < 2024) {
    $active_start = (int)date('Y');
}

$years_list = [];
$base_start = 2024;
$end_start = max($base_start, $active_start);

for ($y = $base_start; $y <= $end_start; $y++) {
    $next_y = $y + 1;
    $sy_format = $y . '-' . $next_y;
    $years_list[$sy_format] = $sy_format;
}

$years_list[$active_admission_year] = $active_admission_year;
$years_list[$active_enrollment_year] = $active_enrollment_year;

// Query any other academic years actually stored in admissions table
$db_years = $conn->query("SELECT DISTINCT academic_year FROM admissions WHERE academic_year IS NOT NULL AND academic_year != ''");
if ($db_years) {
    while ($dy = $db_years->fetch_assoc()) {
        $years_list[$dy['academic_year']] = $dy['academic_year'];
    }
}

krsort($years_list); // Sort newest to oldest
$recent_years = array_slice($years_list, 0, 5, true);

// Default filter to the active admission year configured in portal_settings
$f_year = isset($_GET['f_year']) ? $_GET['f_year'] : $active_admission_year;
$safe_year = $conn->real_escape_string($f_year);

$base_misc_name = "Miscellaneous Fee";
$base_misc_amt = 10000;
$base_tuition_name = "Tuition Fee";
$base_tuition_amt = 24000;

try {
    $fee_q = $conn->query("SELECT fee_name, amount FROM accounting_fees WHERE fee_name LIKE '%Misc%' OR fee_name LIKE '%Tuition%' LIMIT 10");
    if ($fee_q && $fee_q->num_rows > 0) {
        while ($f = $fee_q->fetch_assoc()) {
            if (stripos($f['fee_name'], 'Misc') !== false) {
                $base_misc_name = $f['fee_name'];
                $base_misc_amt = (float)$f['amount'];
            }
            if (stripos($f['fee_name'], 'Tuition') !== false) {
                $base_tuition_name = $f['fee_name'];
                $base_tuition_amt = (float)$f['amount'];
            }
        }
    }
} catch (Exception $e) {}

$prog_list = []; 
try {
    $prog_q = $conn->query("SELECT program_name FROM programs ORDER BY program_name ASC");
    if ($prog_q) { while ($r = $prog_q->fetch_assoc()) { $prog_list[] = $r['program_name']; } }
} catch (Exception $e) {}

// =========================================================
// API ENDPOINT: FETCH NEXT STUDENT ID
// =========================================================
if (isset($_GET['api_next_id'])) {
    header('Content-Type: application/json');
    $year_prefix = date('y'); 
    $query = $conn->query("SELECT student_id FROM users WHERE student_id LIKE '$year_prefix-%' ORDER BY CAST(SUBSTRING(student_id, 4) AS UNSIGNED) DESC LIMIT 1");
    if ($query && $query->num_rows > 0) {
        $last_id = $query->fetch_assoc()['student_id'];
        $last_num = (int)substr($last_id, 3);
        $new_num = $last_num + 1;
    } else {
        $new_num = 1;
    }
    echo json_encode(['next_id' => $year_prefix . '-' . str_pad($new_num, 5, '0', STR_PAD_LEFT)]);
    exit();
}

// =========================================================
// POST ACTIONS (AJAX ENGINE)
// =========================================================
if ($can_edit && $_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // 1. INDIVIDUAL DOCUMENT VERIFICATION
    if (isset($_POST['save_doc_verification'])) {
        $adm_id = (int)$_POST['admission_id'];
        $doc_statuses = $_POST['doc_statuses']; 
        $overall_status = $_POST['overall_status']; 
        $denial_reason = $conn->real_escape_string($_POST['denial_reason'] ?? '');
        $safe_json = $conn->real_escape_string($doc_statuses);

        $adm_q = $conn->query("SELECT * FROM admissions WHERE id = $adm_id");
        $adm_row = $adm_q->fetch_assoc();
        $student_type = $adm_row['student_type'];
        $display_name = trim(ucwords(strtolower(($adm_row['first_name'] ?? '') . " " . ($adm_row['last_name'] ?? ''))));
        $adm_no = $adm_row['admission_number'];
        $email = $conn->real_escape_string($adm_row['email']);

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
            $mail->setFrom(SMTP_USER, 'LDSP Admissions Office');
            $mail->addAddress($email, $display_name); 
            $mail->isHTML(true);
        } catch (Exception $e) {}

        if ($overall_status === 'Accepted') {
            $update_sql = "UPDATE admissions SET document_statuses = '$safe_json', status = 'Accepted', accepted_at = NOW(), denial_reason = NULL";
            
            if (in_array($student_type, ['Freshman', 'Transferee'])) {
                
                // TRANSFEREE EXEMPTION BRANCH
                if ($student_type === 'Transferee') {
                    $template = "Dear $display_name,\n\nCongratulations! Your admission application at Lyceum de San Pablo has been officially ACCEPTED.\n\nAs a Transferee, you are exempt from the entrance examination. Please monitor your email for your Student Portal login credentials which will be sent shortly.\n\nWelcome to LDSP,\nLDSP Admissions Office";
                    $update_sql .= ", custom_message = '".$conn->real_escape_string($template)."'";
                    $success_msg = "Application Approved! Transferee bypasses exam.";

                    try {
                        $mail->Subject = 'LDSP Admissions - Documents Verified & Application Approved';
                        $mail->Body = "
                            <div style='font-family: Arial, sans-serif; color: #1f2937; max-width: 600px; margin: 0 auto; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; background-color: #ffffff;'>
                                <div style='padding: 24px; border-bottom: 1px solid #e5e7eb; background-color: #ecfdf5;'>
                                    <span style='color: #059669; font-weight: bold; font-size: 16px;'>✓ DOCUMENTS VERIFIED & APPROVED</span>
                                </div>
                                <div style='padding: 32px; background-color: #f8fafc; border-radius: 8px; margin: 24px; border: 1px solid #e2e8f0;'>
                                    <p style='font-size: 16px;'>Dear <strong>$display_name</strong>,</p>
                                    <p style='line-height: 1.6;'>Great news! Your submitted documents have been successfully <strong>verified and approved</strong>.</p>
                                    <p style='line-height: 1.6;'>Congratulations! Your admission application at Lyceum de San Pablo has been officially <strong>ACCEPTED</strong>.</p>
                                    <p style='line-height: 1.6; padding: 10px; background-color: #eff6ff; border-left: 4px solid #3b82f6; border-radius: 4px;'>As a Transferee, you are exempt from the entrance examination. Please wait for your official student portal credentials, which will be sent to this email shortly.</p>
                                    <p style='margin: 0; color: #64748b; margin-top: 30px;'>Welcome to LDSP,</p>
                                    <p style='margin: 0; color: #64748b;'><strong>LDSP Admissions Office</strong></p>
                                </div>
                            </div>
                        ";
                        $mail->send();
                    } catch (Exception $e) { $success_msg = "Application Approved (Email failed: " . $mail->ErrorInfo . ")"; }
                    
                } else {
                    // FRESHMAN EXAM BRANCH
                    $template = "Dear $display_name,\n\nCongratulations! Your admission application at Lyceum de San Pablo has been officially ACCEPTED.\n\nPlease check your email inbox for the examination link and criteria.\n\nGood luck,\nLDSP Admissions Office";
                    $update_sql .= ", custom_message = '".$conn->real_escape_string($template)."'";
                    $success_msg = "Application Approved & Exam Email Sent!";

                    $exam_link = APP_URL . "/student_exam_auth.php?adm_no=" . $adm_no;
                    try {
                        $mail->Subject = 'LDSP Admissions - Documents Verified & Application Approved';
                        $mail->Body = "
                            <div style='font-family: Arial, sans-serif; color: #1f2937; max-width: 600px; margin: 0 auto; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; background-color: #ffffff;'>
                                <div style='padding: 24px; border-bottom: 1px solid #e5e7eb; background-color: #ecfdf5;'>
                                    <span style='color: #059669; font-weight: bold; font-size: 16px;'>✓ DOCUMENTS VERIFIED & APPROVED</span>
                                </div>
                                <div style='padding: 32px; background-color: #f8fafc; border-radius: 8px; margin: 24px; border: 1px solid #e2e8f0;'>
                                    <p style='font-size: 16px;'>Dear <strong>$display_name</strong>,</p>
                                    <p style='line-height: 1.6;'>Great news! Your submitted documents have been successfully <strong>verified and approved</strong>.</p>
                                    <p style='line-height: 1.6;'>Congratulations! Your admission application at Lyceum de San Pablo has been officially <strong>ACCEPTED</strong>.</p>
                                    <p style='line-height: 1.6;'>Please prepare for your examination by ensuring you have a stable internet connection and a quiet environment.</p>
                                    <p style='margin: 0; color: #64748b; margin-top: 30px;'>Good luck,</p>
                                    <p style='margin: 0; color: #64748b;'><strong>LDSP Admissions Office</strong></p>
                                </div>
                                <div style='padding: 0 24px 32px 24px; text-align: center;'>
                                    <a href='{$exam_link}' style='background-color: #00205b; color: #ffffff; padding: 14px 28px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 14px; display: inline-block; text-transform: uppercase;'>Launch Entrance Exam →</a>
                                </div>
                            </div>
                        ";
                        $mail->send();
                    } catch (Exception $e) { $success_msg = "EMAIL ERROR: " . $mail->ErrorInfo; }
                }
            } else {
                $update_sql .= ", pushed_to_accounting = 1"; 
                if ((int)$adm_row['provisioned_user_id'] > 0) {
                    $conn->query("UPDATE enrollment_requests SET final_status = 'Pending Payment' WHERE user_id = " . (int)$adm_row['provisioned_user_id'] . " AND final_status = 'Pending Admission Verification'");
                } else {
                    $conn->query("UPDATE enrollment_requests SET final_status = 'Pending Payment' WHERE email = '$email' AND final_status = 'Pending Admission Verification'");
                }
                $success_msg = "Clearance verified! Student pushed to Accounting.";
            }
            $update_sql .= " WHERE id = $adm_id";
            $conn->query($update_sql);

        } else {
            $conn->query("UPDATE admissions SET document_statuses = '$safe_json', status = 'Denied', denial_reason = '$denial_reason' WHERE id = $adm_id");
            $success_msg = "Documents marked invalid. Applicant has been notified.";
            
            $track_link = APP_URL . "/track_status.php?adm_no=" . $adm_no;
            try {
                $mail->Subject = 'LDSP Admissions - Document Update Required';
                $mail->Body = "
                    <div style='font-family: Arial, sans-serif; color: #1f2937; max-width: 600px; margin: 0 auto; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; background-color: #ffffff;'>
                        <div style='padding: 24px; border-bottom: 1px solid #e5e7eb; background-color: #fff1f2;'>
                            <span style='color: #e11d48; font-weight: bold; font-size: 16px;'>⚠ UPDATE REQUIRED</span>
                        </div>
                        <div style='padding: 32px; background-color: #fff1f2; border-radius: 8px; margin: 24px; border: 1px solid #fecdd3;'>
                            <p style='font-size: 16px;'>Dear <strong>$display_name</strong>,</p>
                            <p style='line-height: 1.6;'>We have reviewed your admission application, but unfortunately, one or more of your submitted documents were marked as invalid.</p>
                            <p style='margin-top: 20px;'><strong>Reason for Deferral:</strong></p>
                            <p style='color: #be123c; font-weight: bold;'>$denial_reason</p>
                            <hr style='border-top: 1px dashed #fecdd3; margin: 20px 0;'>
                            <p style='margin: 0; color: #881337;'>Please log in to the tracking portal to upload the correct documents and resubmit your application.</p>
                        </div>
                        <div style='padding: 0 24px 32px 24px; text-align: center;'>
                            <a href='{$track_link}' style='background-color: #e11d48; color: #ffffff; padding: 14px 28px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 14px; display: inline-block; text-transform: uppercase;'>Update Documents →</a>
                        </div>
                    </div>
                ";
                $mail->send();
            } catch (Exception $e) {}
        }
        echo json_encode(['status' => 'success', 'message' => $success_msg]);
        exit;
    }

    // 2. ACCOUNT PROVISIONING ENGINE
    if (isset($_POST['provision_account'])) {
        try {
            $adm_id = (int)$_POST['admission_id'];
            $inst_email = $conn->real_escape_string(trim($_POST['inst_email'] ?? ''));
            
            if (!verifyGoogleMailbox($inst_email)) {
                throw new Exception("This institutional email ($inst_email) does not exist on the Google Workspace server. Please have IT create the actual Gmail account before linking it here.");
            }

            $classification = $conn->real_escape_string($_POST['classification']);
            $enrollment_term = $conn->real_escape_string($_POST['enrollment_term']);
            $initial_fee = $conn->real_escape_string($_POST['initial_fee']);
            $student_id = $conn->real_escape_string($_POST['student_id']);
            $raw_password = $_POST['pin'];
            
            $fname = $conn->real_escape_string(preg_replace('/[0-9]+/', '', $_POST['fname']));
            $lname = $conn->real_escape_string(preg_replace('/[0-9]+/', '', $_POST['lname']));
            $mname = $conn->real_escape_string(preg_replace('/[0-9]+/', '', $_POST['mname']));
            
            if (empty($inst_email)) throw new Exception("Institutional email is required.");
            
            $q = $conn->query("SELECT * FROM admissions WHERE id = $adm_id");
            if (!$q || $q->num_rows === 0) throw new Exception("Invalid Applicant: Does not exist.");
            $applicant = $q->fetch_assoc();
            
            $hashed_password = password_hash($raw_password, PASSWORD_DEFAULT);
            $personal_email = $conn->real_escape_string((string)($applicant['email'] ?? ''));
            
            $prog = isset($_POST['program']) ? $conn->real_escape_string($_POST['program']) : $conn->real_escape_string((string)($applicant['evaluated_program'] ?: $applicant['program']));
            
            $gender = $conn->real_escape_string((string)($applicant['gender'] ?? ''));
            $phone = $conn->real_escape_string((string)($applicant['phone'] ?? ''));
            $dob = $conn->real_escape_string((string)($applicant['dob'] ?? ''));
            $pob = $conn->real_escape_string((string)($applicant['pob'] ?? ''));
            $addr = $conn->real_escape_string((string)($applicant['address'] ?? ''));
            $school = $conn->real_escape_string((string)($applicant['school_last_attended'] ?? ''));
            $school_yr = $conn->real_escape_string((string)($applicant['school_year_attended'] ?? ''));
            $f_name = $conn->real_escape_string((string)($applicant['father_name'] ?? ''));
            $f_occ = $conn->real_escape_string((string)($applicant['father_occupation'] ?? ''));
            $f_con = $conn->real_escape_string((string)($applicant['father_contact'] ?? ''));
            $m_name = $conn->real_escape_string((string)($applicant['mother_name'] ?? ''));
            $m_occ = $conn->real_escape_string((string)($applicant['mother_occupation'] ?? ''));
            $m_con = $conn->real_escape_string((string)($applicant['mother_contact'] ?? ''));
            $em_name = $conn->real_escape_string((string)($applicant['emergency_contact_name'] ?? ''));
            $em_con = $conn->real_escape_string((string)($applicant['emergency_contact_number'] ?? ''));
            $src = $conn->real_escape_string((string)($applicant['influence_source'] ?? ''));
            
            $new_user_id = null;
            $check_existing = $conn->query("SELECT id FROM users WHERE email = '$inst_email' OR student_id = '$student_id'");
            
            if ($check_existing && $check_existing->num_rows > 0) {
                $new_user_id = $check_existing->fetch_assoc()['id'];
                $conn->query("UPDATE users SET password = '$hashed_password' WHERE id = $new_user_id"); 
            } else {
                $ins_user = $conn->query("INSERT INTO users (student_id, email, password, role, status) VALUES ('$student_id', '$inst_email', '$hashed_password', 'student', 'Active')");
                if ($ins_user) $new_user_id = $conn->insert_id;
                else throw new Exception("Users Table Error.");
            }
            
            if ($new_user_id !== null) {
                $check_prof = $conn->query("SELECT user_id FROM user_profiles WHERE user_id = $new_user_id");
                if ($check_prof && $check_prof->num_rows > 0) {
                    $conn->query("UPDATE user_profiles SET program='$prog', admission_type='$classification', school_last_attended='$school', school_year_attended='$school_yr', father_name='$f_name', father_occupation='$f_occ', father_contact='$f_con', mother_name='$m_name', mother_occupation='$m_occ', mother_contact='$m_con', emergency_contact_name='$em_name', emergency_contact_number='$em_con', source_of_info='$src' WHERE user_id = $new_user_id");
                } else {
                    $conn->query("INSERT INTO user_profiles (user_id, first_name, last_name, middle_name, program, admission_type, gender, phone, dob, pob, address, school_last_attended, school_year_attended, father_name, father_occupation, father_contact, mother_name, mother_occupation, mother_contact, emergency_contact_name, emergency_contact_number, source_of_info) VALUES ($new_user_id, '$fname', '$lname', '$mname', '$prog', '$classification', '$gender', '$phone', '$dob', '$pob', '$addr', '$school', '$school_yr', '$f_name', '$f_occ', '$f_con', '$m_name', '$m_occ', '$m_con', '$em_name', '$em_con', '$src')");
                }
                
                $target_adm_sy = !empty($applicant['academic_year']) ? $applicant['academic_year'] : $active_admission_year;
                $conn->query("UPDATE admissions SET provisioned_user_id = $new_user_id, institutional_email = '$inst_email', evaluated_admission_type = '$classification', enrollment_term = '$enrollment_term', assigned_initial_fee = '$initial_fee', account_request_pushed = 1, academic_year = '$target_adm_sy' WHERE id = $adm_id");
                
                $email_fee_display = $initial_fee;
                if ($initial_fee === $base_misc_name) {
                    $email_fee_display .= " (₱" . number_format($base_misc_amt, 2) . ")";
                } elseif ($initial_fee === $base_tuition_name) {
                    $email_fee_display .= " (₱" . number_format($base_tuition_amt, 2) . ")";
                }

                $mail = new PHPMailer(true);
                $display_name = trim("$fname $mname $lname");
                
                try {
                    $mail->isSMTP();
                    $mail->Host       = SMTP_HOST;
                    $mail->SMTPAuth   = true;
                    $mail->Username   = SMTP_USER;
                    $mail->Password   = SMTP_PASS;
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = SMTP_PORT;
                    $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
                    $mail->setFrom(SMTP_USER, 'LDSP Admissions Office');
                    
                    $mail->addAddress($personal_email, $display_name); 
                    $mail->addAddress($inst_email, $display_name); 
                    
                    $mail->isHTML(true);

                    $login_link = "http://localhost/LDSP_enrollment_system/student/index.php";

                    $mail->Subject = 'LDSP Admissions - Official Student Account Provisioned';
                    $mail->Body = "
                        <div style='font-family: Arial, sans-serif; color: #1f2937; max-width: 600px; margin: 0 auto; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; background-color: #ffffff;'>
                            <div style='padding: 24px; border-bottom: 1px solid #e5e7eb; background-color: #f0fdf4;'>
                                <span style='color: #059669; font-weight: bold; font-size: 16px;'>✓ STUDENT ACCOUNT CREATED</span>
                            </div>
                            <div style='padding: 32px; background-color: #f8fafc; border-radius: 8px; margin: 24px; border: 1px solid #e2e8f0;'>
                                <p style='font-size: 16px; margin-top:0;'>Dear <strong>$display_name</strong>,</p>
                                <p style='line-height: 1.6;'>Your official student account for Lyceum de San Pablo has been successfully provisioned. You can now access the Student Portal to proceed with your enrollment.</p>
                                
                                <div style='background-color: #ffffff; padding: 20px; border: 1px solid #cbd5e1; border-radius: 8px; margin: 24px 0;'>
                                    <h3 style='margin-top: 0; color: #00205b; font-size: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 15px;'>ACCOUNT DETAILS</h3>
                                    <table style='width: 100%; border-collapse: collapse; font-size: 14px;'>
                                        <tr><td style='padding: 6px 0; color: #64748b; width: 40%; vertical-align: top;'>Student ID:</td><td style='padding: 6px 0; font-weight: bold; color: #0f172a; word-break: break-word;'>$student_id</td></tr>
                                        <tr><td style='padding: 6px 0; color: #64748b; vertical-align: top;'>Institutional Email:</td><td style='padding: 6px 0; font-weight: bold; color: #00205b; word-break: break-all;'>$inst_email</td></tr>
                                        <tr><td style='padding: 6px 0; color: #64748b; vertical-align: top;'>Temporary PIN:</td><td style='padding: 6px 0; font-weight: bold; font-family: monospace; letter-spacing: 2px; color: #e11d48; word-break: break-word;'>$raw_password</td></tr>
                                        <tr><td colspan='2' style='border-top: 1px dashed #cbd5e1; margin: 10px 0; padding-top: 10px;'></td></tr>
                                        <tr><td style='padding: 6px 0; color: #64748b; vertical-align: top;'>Academic Program:</td><td style='padding: 6px 0; font-weight: bold; color: #0f172a; word-break: break-word;'>$prog</td></tr>
                                        <tr><td style='padding: 6px 0; color: #64748b; vertical-align: top;'>Classification:</td><td style='padding: 6px 0; font-weight: bold; color: #0f172a; word-break: break-word;'>$classification</td></tr>
                                        <tr><td style='padding: 6px 0; color: #64748b; vertical-align: top;'>Initial Fee:</td><td style='padding: 6px 0; font-weight: bold; color: #059669; word-break: break-word;'>$email_fee_display</td></tr>
                                    </table>
                                </div>
                                
                                <p style='line-height: 1.6; font-size: 14px; color: #475569;'>Please use your <strong>Institutional Email</strong> and <strong>Temporary PIN</strong> to log in. We recommend changing your PIN immediately after logging in.</p>
                            </div>
                            <div style='padding: 0 24px 32px 24px; text-align: center;'>
                                <a href='{$login_link}' style='background-color: #00205b; color: #ffffff; padding: 14px 28px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 14px; display: inline-block; text-transform: uppercase; letter-spacing: 1px;'>Login to Student Portal</a>
                            </div>
                        </div>
                    ";
                    $mail->send();
                    $success_msg = "Account successfully configured and linked! Credentials emailed to applicant.";
                } catch (Exception $e) {
                    $success_msg = "Account successfully configured, but failed to send email: " . $mail->ErrorInfo;
                }
            }
        } catch (Throwable $e) {
            $error_msg = $e->getMessage();
        }
        echo json_encode(['status' => $error_msg ? 'error' : 'success', 'message' => $error_msg ?: $success_msg]);
        exit;
    }

    // 3. PUSH TO ACCOUNTING ENGINE
    if (isset($_POST['push_to_accounting'])) {
        $adm_id = (int)$_POST['admission_id'];
        $q = $conn->query("SELECT * FROM admissions WHERE id = $adm_id");
        if ($q && $q->num_rows > 0) {
            $adm = $q->fetch_assoc();
            $prov_id = (int)$adm['provisioned_user_id'];
            $personal_email = $conn->real_escape_string($adm['email']);
            $prog = $conn->real_escape_string($adm['evaluated_program'] ?: $adm['program']);
            $classification = $conn->real_escape_string($adm['evaluated_admission_type'] ?: $adm['student_type']);
            
            $enrollment_term = $adm['enrollment_term'];
            $term_parts = explode(' - ', $enrollment_term);
            $r_yl = $conn->real_escape_string($term_parts[0] ?? $adm['year_level'] ?? '');
            $r_sem = $conn->real_escape_string($term_parts[1] ?? '1st Semester');
            
            $target_push_sy = !empty($adm['academic_year']) ? $adm['academic_year'] : $active_admission_year;
            
            if ($prov_id > 0) {
                $check = $conn->query("SELECT id FROM enrollment_requests WHERE user_id = $prov_id AND school_year = '$target_push_sy' AND semester = '$r_sem'");
                if ($check->num_rows == 0) {
                    $conn->query("INSERT INTO enrollment_requests (user_id, email, school_year, semester, year_level, program, student_status, final_status, created_at) VALUES ($prov_id, '$personal_email', '$target_push_sy', '$r_sem', '$r_yl', '$prog', '$classification', 'Pending Payment', NOW())");
                }
                
                $conn->query("UPDATE admissions SET pushed_to_accounting = 1 WHERE id = $adm_id");
                echo json_encode(['status' => 'success', 'message' => 'Applicant successfully pushed to Accounting.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Please provision an account first.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Application not found.']);
        }
        exit;
    }

    if (isset($_POST['delete_application'])) {
        $adm_id = $conn->real_escape_string($_POST['admission_id']);
        $conn->query("DELETE FROM admissions WHERE id = '$adm_id'");
        echo json_encode(['status' => 'success', 'message' => 'Application erased completely.']);
        exit;
    }
}

// ---------------------------------------------------------
// DATA FETCHING (Filtered by Academic Year)
// ---------------------------------------------------------
$where_clauses = ["a.status IN ('Pending', 'Accepted', 'Denied')"];

if ($f_year !== 'All') {
    // Guarantees legacy or unassigned records match the active school year
    $where_clauses[] = "(a.academic_year = '$safe_year' OR (COALESCE(a.academic_year, '') = '' AND '$safe_year' = '$active_admission_year'))";
}

$where_sql = implode(' AND ', $where_clauses);

$applications_query = $conn->query("
    SELECT a.*, u.student_id as actual_student_id 
    FROM admissions a 
    LEFT JOIN users u ON a.provisioned_user_id = u.id 
    WHERE $where_sql 
    ORDER BY CASE WHEN a.status = 'Pending' THEN 1 WHEN a.status = 'Denied' THEN 2 ELSE 3 END, a.created_at DESC
");

$total_applications = ($applications_query) ? $applications_query->num_rows : 0;

function renderQueueRows($query_res, $can_edit, $selected_sy = '') {
    global $conn, $active_admission_year;
    ob_start();
    if ($query_res && $query_res->num_rows > 0) { 
        while ($row = $query_res->fetch_assoc()) { 
            
            $first = !empty($row['first_name']) ? $row['first_name'] : '';
            $middle = !empty($row['middle_name']) ? ' ' . $row['middle_name'] : '';
            $last = !empty($row['last_name']) ? $row['last_name'] . ', ' : '';
            $raw_name = trim($last . $first . $middle);
            $applicant_name = htmlspecialchars($raw_name);
            
            $row['applicant_name'] = $raw_name; 
            $profile_data = htmlspecialchars(json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
            $display_id = !empty($row['actual_student_id']) ? $row['actual_student_id'] : $row['admission_number'];
            $is_provisioned = !empty($row['provisioned_user_id']);
            
            $row_class = 'bg-white';
            if ($row['status'] === 'Denied') $row_class = 'bg-rose-50/30';

            $is_transferee = ($row['student_type'] === 'Transferee');
            $is_old_student = in_array($row['student_type'], ['Regular', 'Irregular', 'Returnee']);

            // Document Tracker Logic
            $doc_json = json_decode($row['document_statuses'] ?? '{}', true) ?: [];
            $doc_req_count = in_array($row['student_type'], ['Freshman', 'Transferee']) ? 2 : 1;
            $accept_count = 0; $reject_count = 0;
            foreach ($doc_json as $d) {
                if (($d['status']??'') === 'accept') $accept_count++;
                if (($d['status']??'') === 'reject') $reject_count++;
            }
            
            $doc_color = 'amber'; $doc_text = 'Pending';
            if ($accept_count >= $doc_req_count) { $doc_color = 'emerald'; $doc_text = 'Approved'; }
            elseif ($accept_count > 0) { $doc_color = 'amber'; $doc_text = 'Partial'; }
            elseif ($reject_count > 0) { $doc_color = 'rose'; $doc_text = 'Invalid'; }

            // Exam Tracker
            $box1 = 'bg-slate-200 border-slate-300';
            $box2 = 'bg-slate-200 border-slate-300';
            $exam_passed = false;
            $exam_color = 'amber'; $exam_text = 'Pending';

            if ($is_transferee) {
                $exam_passed = true;
                if ($doc_color === 'emerald') {
                    $exam_color = 'amber'; 
                    $exam_text = 'Transferee';
                    $box1 = 'bg-amber-500 border-amber-600';
                    $box2 = 'bg-amber-500 border-amber-600';
                } else {
                    $exam_color = 'slate'; 
                    $exam_text = 'Waiting Docs';
                    $box1 = 'bg-slate-200 border-slate-300';
                    $box2 = 'bg-slate-200 border-slate-300';
                }
            } elseif ($is_old_student) {
                $exam_passed = true;
                $exam_color = 'emerald'; 
                $exam_text = 'N/A';
                $box1 = 'bg-slate-200 border-slate-300';
                $box2 = 'bg-slate-200 border-slate-300';
            } else {
                $attempts = [];
                $adm_number_safe = $conn->real_escape_string($row['admission_number']);
                $target_check_sy = !empty($row['academic_year']) ? $row['academic_year'] : (!empty($selected_sy) ? $selected_sy : $active_admission_year);
                $target_check_sy_safe = $conn->real_escape_string($target_check_sy);
                
                $ex_q = $conn->query("SELECT status FROM exam_results WHERE student_id = '$adm_number_safe' AND (academic_year = '$target_check_sy_safe' OR academic_year = '$active_admission_year') ORDER BY time_finished ASC LIMIT 2");
                if ($ex_q) {
                    while ($ex_r = $ex_q->fetch_assoc()) {
                        $attempts[] = $ex_r['status'];
                    }
                }
                
                if (isset($attempts[0])) {
                    $box1 = ($attempts[0] === 'Passed') ? 'bg-emerald-500 border-emerald-600' : 'bg-rose-500 border-rose-600';
                    if ($attempts[0] === 'Passed') $exam_passed = true;
                }
                if (isset($attempts[1])) {
                    $box2 = ($attempts[1] === 'Passed') ? 'bg-emerald-500 border-emerald-600' : 'bg-rose-500 border-rose-600';
                    if ($attempts[1] === 'Passed') $exam_passed = true;
                }

                if ($exam_passed) {
                    $exam_color = 'emerald'; $exam_text = 'Passed';
                } elseif (count($attempts) >= 2) {
                    $exam_color = 'rose'; $exam_text = 'Failed';
                } elseif (count($attempts) > 0) {
                    $exam_color = 'amber'; $exam_text = 'Retaking';
                }
            }

            // Gating Logic
            $is_unlocked = ($doc_color === 'emerald' && ($exam_color === 'emerald' || $is_old_student || $is_transferee));
            $prog_disp = $row['evaluated_program'] ?: $row['program'];
    ?>
            <tr class="hover:bg-blue-50/40 transition-colors duration-150 group border-b border-slate-300 <?= $row_class ?>">
                <!-- 1. APPLICANT DETAIL -->
                <td class="px-2 py-2 align-middle border-r border-slate-300 w-[25%] max-w-0">
                    <div class="w-full overflow-x-auto custom-scrollbar pb-1.5">
                        <div class="font-bold text-[#00205b] text-xs whitespace-nowrap searchable-name pr-2" title="<?= $applicant_name ?>"><?= $applicant_name ?></div>
                    </div>
                    <div class="pt-1.5 border-t border-slate-200">
                        <div class="text-[11px] text-slate-500 font-mono font-black tracking-widest truncate searchable-id flex items-center gap-1.5">
                            <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/></svg>
                            <?= htmlspecialchars($display_id) ?>
                        </div>
                    </div>
                </td>

                <!-- 2. PROGRESS TRACKER -->
                <td class="px-2 py-2 align-middle border-r border-slate-300 w-[25%] truncate">
                    <div class="flex flex-col gap-1.5 items-start w-full">
                        <div class="flex items-center gap-1.5 text-[9px] font-bold uppercase tracking-widest text-slate-600 bg-slate-50 border border-slate-200 px-2 py-1 rounded shadow-sm w-full">
                            <span class="relative flex h-2 w-2 shrink-0">
                              <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-<?= $doc_color ?>-400 opacity-75 <?= $doc_color === 'emerald' ? 'hidden' : '' ?>"></span>
                              <span class="relative inline-flex rounded-full h-2 w-2 bg-<?= $doc_color ?>-500"></span>
                            </span>
                            Docs: <span class="text-<?= $doc_color ?>-600 ml-auto"><?= $doc_text ?></span>
                        </div>
                        
                        <div class="flex items-center justify-between text-[9px] font-bold uppercase tracking-widest text-slate-600 bg-slate-50 border border-slate-200 px-2 py-1 rounded shadow-sm w-full">
                            <div class="flex items-center gap-1.5">
                                <span class="relative flex h-2 w-2 shrink-0">
                                  <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-<?= $exam_color ?>-400 opacity-75 <?= in_array($exam_color, ['emerald', 'rose', 'slate']) ? 'hidden' : '' ?>"></span>
                                  <span class="relative inline-flex rounded-full h-2 w-2 bg-<?= $exam_color ?>-500"></span>
                                </span>
                                Exam: <span class="text-<?= $exam_color ?>-600 ml-1"><?= $exam_text ?></span>
                            </div>
                            <?php if (!$is_old_student && !$is_transferee): ?>
                            <div class="flex items-center gap-1 border-l border-slate-200 pl-1.5">
                                <div class="w-2 h-2 rounded-[2px] <?= $box1 ?>" title="Attempt 1"></div>
                                <div class="w-2 h-2 rounded-[2px] <?= $box2 ?>" title="Attempt 2"></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>

                <!-- 3. DATE APPLIED -->
                <td class="px-2 py-2 align-middle border-r border-slate-300 w-[15%] text-center">
                    <span class="text-[9px] text-slate-600 font-bold uppercase tracking-widest"><?= !empty($row['created_at']) ? date('M j, Y', strtotime($row['created_at'])) : 'N/A' ?></span>
                </td>

                <!-- 4. DOCUMENTS -->
                <td class="px-2 py-2 align-middle border-r border-slate-300 text-center w-[15%]">
                    <div class="flex flex-col gap-1 w-full items-center justify-center h-full">
                        <?php 
                        if (!empty($row['uploaded_files'])) {
                            $files = explode(',', $row['uploaded_files']);
                            $doc_array = [];
                            $labels = in_array($row['student_type'], ['Freshman', 'Transferee']) ? ["2x2 ID Photo", "PSA Birth Certificate"] : ["Clearance Document"];
                            
                            foreach ($files as $i => $file) {
                                if (empty(trim($file))) continue;
                                $clean_file = ltrim(str_replace('../', '', trim($file)), '/');
                                $doc_array[] = ['label' => $labels[$i] ?? 'Document ' . ($i + 1), 'url' => '../' . $clean_file];
                            }
                            
                            if (count($doc_array) > 0) {
                                $docs_json = htmlspecialchars(json_encode($doc_array, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                                
                                $doc_statuses = !empty($row['document_statuses']) ? $row['document_statuses'] : '{}';
                                $doc_statuses_esc = htmlspecialchars($doc_statuses, ENT_QUOTES, 'UTF-8');
                                
                                $doc_count_text = count($doc_array) . " File" . (count($doc_array) > 1 ? "s" : "") . " Attached";
                                
                                if ($doc_color === 'emerald') {
                                    $btn_class = "bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100 hover:border-emerald-300";
                                    $btn_text  = "Reviewed";
                                    $icon_svg  = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />';
                                } elseif ($doc_color === 'rose') {
                                    $btn_class = "bg-rose-50 text-rose-700 border-rose-200 hover:bg-rose-100 hover:border-rose-300";
                                    $btn_text  = "Review Docs";
                                    $icon_svg  = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />';
                                } else {
                                    $btn_class = "bg-white text-blue-600 border-blue-200 hover:bg-blue-50 hover:border-blue-300";
                                    $btn_text  = "Review Docs";
                                    $icon_svg  = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />';
                                }

                                echo '<span class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-0.5">' . $doc_count_text . '</span>';
                                echo '<button type="button" onclick="window.openDocReviewModal(' . $row['id'] . ', \'' . addslashes($applicant_name) . '\', \'' . $docs_json . '\', \'' . $doc_statuses_esc . '\')" class="' . $btn_class . ' border w-full max-w-[115px] mx-auto text-[9px] uppercase tracking-widest py-1.5 px-2 rounded-md font-bold shadow-sm transition-all flex items-center justify-center gap-1.5 group">
                                    <svg class="w-3.5 h-3.5 group-hover:scale-110 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">' . $icon_svg . '</svg> 
                                    ' . $btn_text . '
                                </button>';
                            } else {
                                echo '<span class="text-[8px] text-slate-400 italic text-center w-full block">No Files Found</span>';
                            }
                        } else {
                            echo '<span class="text-[8px] text-slate-400 italic text-center w-full block">No Files Found</span>';
                        }
                        ?>
                    </div>
                </td>

                <!-- 5. ACTIONS (PROFILE BUTTON MATCHING REGISTRAR VERIFICATION STYLE) -->
                <td class="px-2 py-2 align-middle border-slate-300 text-center w-[20%]">
                    <div class="flex items-center justify-center gap-1.5 w-full">
                        <button type="button" onclick="window.openStudentModal(this)" data-profile="<?= $profile_data ?>" class="bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-300 text-[8px] font-bold uppercase tracking-widest py-1 px-2 rounded shadow-sm transition-colors cursor-pointer w-full max-w-[80px] flex items-center justify-center gap-1" title="View Full Profile">
                            <svg class="w-3 h-3 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> Profile
                        </button>

                        <?php if ($can_edit): ?>
                            <?php if ($is_provisioned && $row['pushed_to_accounting'] == 1): ?>
                                <span class="w-full text-center px-1 py-1.5 rounded bg-amber-50 shadow-inner text-amber-600 text-[9px] font-black tracking-widest border border-amber-200 block uppercase truncate">Pushed</span>
                            <?php elseif ($is_provisioned && $row['pushed_to_accounting'] == 0): ?>
                                <form method="POST" class="m-0 p-0 spa-form w-full">
                                    <input type="hidden" name="push_to_accounting" value="1">
                                    <input type="hidden" name="admission_id" value="<?= $row['id'] ?>">
                                    <button type="submit" class="w-full bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-400 hover:to-indigo-500 text-white border-indigo-600 shadow-sm cursor-pointer hover:-translate-y-0.5 border px-1 py-1.5 rounded transition-all font-bold text-[9px] uppercase tracking-widest truncate flex items-center justify-center gap-1">
                                        Push
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                                    </button>
                                </form>
                            <?php else: ?>
                                <button type="button" 
                                    <?= $is_unlocked ? 'onclick="window.openProvisionModal('.$row['id'].', \''.addslashes($display_id).'\', \''.addslashes($row['last_name']).'\', \''.addslashes($row['first_name']).'\', \''.addslashes($row['middle_name']).'\', \''.addslashes($row['email']).'\', \''.addslashes($row['student_type']).'\', \''.addslashes($row['year_level']).'\', \''.addslashes($prog_disp).'\')"' : 'disabled' ?> 
                                    class="w-full <?= $is_unlocked ? 'bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-400 hover:to-emerald-500 text-white border-emerald-600 shadow-sm cursor-pointer hover:-translate-y-0.5' : 'bg-slate-100 text-slate-400 border-slate-200 cursor-not-allowed' ?> border px-1 py-1.5 rounded transition-all font-bold text-[9px] uppercase tracking-widest truncate flex items-center justify-center gap-1">
                                    Provision
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="w-full text-center px-1 py-1 rounded bg-slate-50 shadow-inner text-slate-400 text-[9px] font-black tracking-widest border border-slate-200 block uppercase">READ ONLY</span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
    <?php 
        } 
    } else { ?>
        <tr><td colspan="5" class="bg-slate-50/50 px-4 py-16 text-center text-slate-400 text-[10px] font-bold uppercase tracking-[0.2em] border border-slate-300">No applications found for this academic year.</td></tr>
    <?php 
    }
    return ob_get_clean();
}

// ASYNC REFRESH ENDPOINT
if (isset($_GET['api_refresh'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'updates' => [
            'queue_tbody_Applications' => renderQueueRows($applications_query, $can_edit, $safe_year)
        ],
        'counts' => [
            'Applications' => $total_applications
        ]
    ]);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Management - LDSP Admissions</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css?v=<?= time(); ?>">
    <style>
        .modal-overlay { opacity: 0; transition: opacity 0.2s ease; pointer-events: none; }
        .modal-content { transform: scale(0.95); opacity: 0; transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); pointer-events: none; }
        .modal-active.modal-overlay { opacity: 1; pointer-events: auto; }
        .modal-active .modal-content { transform: scale(1); opacity: 1; pointer-events: auto; }
        
        .custom-scrollbar::-webkit-scrollbar { height: 4px; width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>

<body class="flex h-screen overflow-hidden antialiased bg-[#f4f6f9]">

    <div class="ambient-orb-1"></div>
    <div class="ambient-orb-2"></div>

    <?php include 'sidebar.php'; ?>

    <main class="flex-1 min-w-0 overflow-y-auto h-full w-full pt-16 md:pt-0 relative z-10 custom-scrollbar">
        <div class="p-4 md:p-6 max-w-[1500px] mx-auto relative z-20 fade-in-up">
            
            <!-- HEADER WITH YEAR FILTER (STRICTLY CONNECTED TO PORTAL SETTINGS) -->
            <header class="mb-6 border-b border-slate-300/60 pb-4 drop-shadow-sm flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3 mb-1">
                        <h1 class="text-2xl md:text-3xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">Application Management</h1>
                        <span class="px-2.5 py-0.5 bg-blue-100 text-blue-700 border border-blue-200 text-[10px] font-bold rounded-full shadow-sm"><span id="count-Applications"><?= $total_applications ?></span> <?= htmlspecialchars($f_year) ?></span>
                        <?php if (!$can_edit): ?>
                            <span class="px-2 py-0.5 bg-gradient-to-b from-white to-slate-50 text-slate-500 border border-slate-300 text-[9px] font-bold rounded uppercase tracking-widest shadow-[0_2px_4px_rgba(0,0,0,0.02)]">READ ONLY</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-slate-500 text-xs flex items-center gap-1.5 font-medium mt-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.8)]"></span> Logged in as <?= htmlspecialchars($_SESSION['first_name'] ?? 'Staff') ?>
                    </p>
                </div>

                <!-- FILTER FORM (IDENTICAL BEHAVIOR TO REGISTRAR MASTER LIST) -->
                <form id="filterForm" class="bg-white/80 backdrop-blur-md border border-slate-200 rounded-xl p-2.5 flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto shadow-sm">
                    <span class="text-[10px] font-black text-[#00205b] uppercase tracking-widest pl-1 drop-shadow-sm">Academic Year:</span>
                    
                    <select id="f_year" onchange="window.handleYearFilterChange(this)" data-last-value="<?= htmlspecialchars($f_year) ?>" class="bg-white border border-slate-300 rounded-lg px-3 py-1.5 text-xs font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b] cursor-pointer w-full sm:w-auto h-[38px]">
                        <?php foreach ($recent_years as $yr): ?>
                            <option value="<?= htmlspecialchars($yr) ?>" <?= ($f_year === $yr) ? 'selected' : '' ?>><?= htmlspecialchars($yr) ?></option>
                        <?php endforeach; ?>
                        
                        <?php if (!array_key_exists($f_year, $recent_years)): ?>
                            <option value="<?= htmlspecialchars($f_year) ?>" selected><?= htmlspecialchars($f_year) ?></option>
                        <?php endif; ?>

                        <?php if (count($years_list) > 5): ?>
                            <option value="open_all_years_modal" class="font-bold text-[#c5a02c]">↳ View All Years...</option>
                        <?php endif; ?>
                    </select>
                </form>
            </header>

            <!-- EXCEL STYLE GRID QUEUES -->
            <div class="bg-white border border-slate-300 rounded-lg overflow-hidden mb-8 border-t-[4px] border-t-[#00205b] shadow-sm relative z-20">
                <div class="mb-2 mt-3 px-4 relative w-full sm:w-[300px]">
                    <input type="text" id="liveSearch1" onkeyup="window.filterTable(this)" class="w-full py-1.5 pl-8 pr-3 text-[10px] font-semibold text-[#00205b] bg-white border border-slate-300 rounded shadow-sm focus:outline-none focus:border-[#00205b]" placeholder="Search applicant name or ID...">
                    <svg class="w-3.5 h-3.5 text-slate-400 absolute left-6 top-[7px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>
                <div class="overflow-x-auto min-h-[450px] custom-scrollbar">
                    <table class="w-full text-left border-collapse text-sm table-fixed border-b border-slate-300">
                        <thead class="bg-slate-100 text-slate-600 text-[9px] uppercase font-black tracking-widest sticky top-0 z-10 shadow-sm border-b-2 border-slate-300">
                            <tr>
                                <th class="px-2 py-2 border-r border-slate-300 w-[25%]">Applicant Detail</th>
                                <th class="px-2 py-2 border-r border-slate-300 w-[25%]">Progress Tracker</th>
                                <th class="px-2 py-2 border-r border-slate-300 w-[15%] text-center">Date Applied</th>
                                <th class="px-2 py-2 border-r border-slate-300 w-[15%] text-center">Documents</th>
                                <th class="px-2 py-2 border-slate-300 w-[20%] text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="queue_tbody_Applications" class="bg-white">
                            <?= renderQueueRows($applications_query, $can_edit, $safe_year) ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <!-- ALL YEARS FLOATING CARD MODAL (PULLS ALL REAL YEARS FROM DB & SETTINGS) -->
    <div id="allYearsModal" class="fixed inset-0 z-[200] hidden items-center justify-center p-4 modal-overlay bg-slate-900/50 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-xl shadow-2xl border-t-4 border-t-[#00205b] w-full max-w-sm modal-content relative">
            <div class="px-5 py-4 border-b border-slate-200 flex justify-between items-center bg-white rounded-t-xl">
                <h3 class="font-black text-[#00205b] text-[11px] uppercase tracking-widest flex items-center gap-2">
                    <svg class="w-4 h-4 text-[#c5a02c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2-2v12a2 2 0 002 2z"/></svg>
                    Academic Years Archive
                </h3>
                <button type="button" onclick="window.closeAllYearsModal()" class="text-slate-400 hover:text-rose-500 bg-slate-50 hover:bg-rose-50 p-1.5 rounded transition-colors focus:outline-none cursor-pointer">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="p-5 max-h-[60vh] overflow-y-auto custom-scrollbar">
                <div class="grid grid-cols-2 gap-3">
                    <?php foreach ($years_list as $yr): 
                        $safe_yr = htmlspecialchars($yr);
                        $id_yr = str_replace('-', '_', $safe_yr);
                        $isActive = ($f_year === $safe_yr);
                    ?>
                        <button type="button" id="btn_year_<?= $id_yr ?>" onclick="window.selectYearFilter('<?= $safe_yr ?>')" class="year-btn px-3 py-2 border <?= $isActive ? 'border-[#00205b] bg-blue-50 text-[#00205b]' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' ?> rounded-lg text-xs font-bold transition-colors shadow-sm cursor-pointer">
                            <?= $safe_yr ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- STUDENT PROFILE MODAL (EXACT VERIFICATION MANAGEMENT STYLE WITH PHOTO & SUBMITTED DOCUMENTS) -->
    <div id="studentProfileModal" class="fixed inset-0 z-[150] hidden items-center justify-center p-4 modal-overlay bg-slate-900/70 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-xl shadow-2xl border-t-[4px] border-t-[#00205b] w-full max-w-4xl max-h-[95vh] flex flex-col modal-content relative" id="profileModalInner">
            <div class="bg-slate-50 border-b border-slate-200 p-4 flex justify-between items-center relative z-20 sticky top-0 rounded-t-xl shadow-sm">
                <h3 class="font-black text-[#00205b] text-xs uppercase tracking-[0.1em] drop-shadow-sm flex items-center gap-2">
                    <div class="p-1.5 rounded-sm bg-[#00205b]/10">
                        <svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477-4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    </div> 
                    Student Profile Records
                </h3>
                <button type="button" onclick="window.closeStudentModal()" class="text-slate-400 hover:text-rose-500 transition-colors bg-white hover:bg-rose-50 p-1.5 rounded border border-slate-200 focus:outline-none cursor-pointer">
                    <svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <div class="p-4 md:p-5 flex-1 overflow-y-auto custom-scrollbar bg-slate-50/90 space-y-4">
                <!-- TOP IDENTITY CARD -->
                <div class="flex flex-col md:flex-row items-start gap-4 border-b border-slate-200 pb-4">
                    <div class="w-20 h-20 bg-[#00205b] text-white rounded-full flex items-center justify-center text-3xl font-black shadow-md border-[3px] border-white overflow-hidden relative shrink-0">
                        <span id="v_initial">?</span>
                        <img id="v_profile_pic" src="" class="hidden absolute inset-0 w-full h-full object-cover z-10" alt="Applicant Photo">
                    </div>
                    <div class="flex-1 w-full">
                        <div class="flex flex-col md:flex-row md:justify-between md:items-start gap-2">
                            <div>
                                <h4 class="text-xl font-black text-[#00205b] tracking-tight uppercase" id="v_detail_name">Student Name</h4>
                                <div class="flex flex-wrap items-center gap-2 mt-1">
                                    <span class="text-xs font-bold text-[#c5a02c] font-mono tracking-wider" id="v_detail_id">ID: Pending</span>
                                    <span class="bg-indigo-100 text-indigo-700 border border-indigo-200 px-1.5 py-0.5 rounded text-[8px] uppercase font-black" id="v_detail_status">Status</span>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-2">
                                <div class="bg-white p-2.5 rounded border border-slate-200 text-left md:text-right w-full md:w-auto shadow-sm">
                                    <p class="text-[8px] font-black uppercase tracking-widest text-slate-400">Current Placement</p>
                                    <p class="text-[10px] font-bold text-[#00205b]" id="v_detail_placement">Program / Year / Sec</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm space-y-2">
                        <h5 class="text-[9px] font-black uppercase tracking-widest text-slate-400 border-b border-slate-100 pb-1 mb-2">Personal Details</h5>
                        <div class="grid grid-cols-3 gap-1"><span class="text-[10px] font-bold text-slate-500">Email:</span><span class="col-span-2 text-[10px] font-semibold text-slate-800 break-all" id="v_email"></span></div>
                        <div class="grid grid-cols-3 gap-1"><span class="text-[10px] font-bold text-slate-500">Inst Email:</span><span class="col-span-2 text-[10px] font-semibold text-slate-800 break-all" id="v_inst_email"></span></div>
                        <div class="grid grid-cols-3 gap-1"><span class="text-[10px] font-bold text-slate-500">Phone:</span><span class="col-span-2 text-[10px] font-semibold text-slate-800 font-mono" id="v_phone"></span></div>
                        <div class="grid grid-cols-3 gap-1"><span class="text-[10px] font-bold text-slate-500">Gender:</span><span class="col-span-2 text-[10px] font-semibold text-slate-800" id="v_gender"></span></div>
                        <div class="grid grid-cols-3 gap-1"><span class="text-[10px] font-bold text-slate-500">DOB:</span><span class="col-span-2 text-[10px] font-semibold text-slate-800" id="v_dob"></span></div>
                        <div class="grid grid-cols-3 gap-1"><span class="text-[10px] font-bold text-slate-500 col-span-3">Place of Birth:</span>
                        <span class="font-bold text-slate-800 text-[10px] col-span-3 pb-1 border-b border-slate-50" id="v_pob"></span>
                        <span class="text-slate-500 font-semibold text-[10px] col-span-3 mt-1">Address:</span>
                        <span class="font-bold text-slate-800 text-[10px] col-span-3 leading-relaxed" id="v_address"></span></div>
                    </div>

                    <div class="space-y-4">
                        <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm border-l-[3px] border-l-[#00205b]">
                            <h4 class="text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-1 mb-2">Academic Intent</h4>
                            <div class="grid grid-cols-1 gap-y-2 text-[10px]">
                                <div><span class="text-slate-500 font-semibold block text-[8px] uppercase tracking-wider mb-0.5">Program</span> <span class="font-bold text-slate-800" id="v_program"></span></div>
                                <div class="grid grid-cols-2 gap-2 mt-1">
                                    <div><span class="text-slate-500 font-semibold block text-[8px] uppercase tracking-wider mb-0.5">Type</span> <span class="font-bold text-slate-800" id="v_type"></span></div>
                                    <div><span class="text-slate-500 font-semibold block text-[8px] uppercase tracking-wider mb-0.5">Year Level</span> <span class="font-bold text-slate-800" id="v_year_level"></span></div>
                                </div>
                                <div class="mt-1 border-t border-slate-50 pt-2"><span class="text-slate-500 font-semibold block text-[8px] uppercase tracking-wider mb-0.5">Last School</span> <span class="font-bold text-slate-800" id="v_school"></span></div>
                                <div><span class="text-slate-500 font-semibold block text-[8px] uppercase tracking-wider mb-0.5">S.Y. Attended</span> <span class="font-bold text-slate-800 font-mono" id="v_school_year"></span></div>
                                <div class="mt-1 border-t border-slate-50 pt-2"><span class="text-slate-500 font-semibold block text-[8px] uppercase tracking-wider mb-0.5">Source of Info</span> <span class="font-bold text-slate-800" id="v_influence"></span></div>
                            </div>
                        </div>
                    </div>

                    <div class="md:col-span-2 bg-white p-3 rounded-lg border border-slate-200 shadow-sm">
                        <h4 class="text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-1 mb-2">Family & Emergency</h4>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <p class="text-[8px] uppercase tracking-widest font-black text-[#00205b] mb-1">Father's Info</p>
                                <div class="text-[10px] font-semibold text-slate-800" id="v_father_name"></div>
                                <div class="text-[9px] text-slate-500" id="v_father_occ"></div>
                                <div class="text-[9px] text-slate-500 font-mono" id="v_father_con"></div>
                            </div>
                            <div>
                                <p class="text-[8px] uppercase tracking-widest font-black text-[#00205b] mb-1">Mother's Info</p>
                                <div class="text-[10px] font-semibold text-slate-800" id="v_mother_name"></div>
                                <div class="text-[9px] text-slate-500" id="v_mother_occ"></div>
                                <div class="text-[9px] text-slate-500 font-mono" id="v_mother_con"></div>
                            </div>
                            <div class="bg-rose-50 p-2 rounded border border-rose-100">
                                <p class="text-[8px] uppercase tracking-widest font-black text-rose-600 mb-1">Emergency Contact</p>
                                <div class="text-[10px] font-bold text-slate-800" id="v_em_name"></div>
                                <div class="text-[10px] font-mono text-rose-700 font-bold mt-0.5" id="v_em_con"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SUBMITTED DOCUMENTS SECTION (VIEWABLE FILES & IMAGES) -->
                <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm mt-2">
                    <h4 class="text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-1 mb-2">Submitted Documents</h4>
                    <div id="v_documents_list" class="flex flex-wrap gap-2">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>

            </div>

            <div class="p-3 border-t border-slate-200 bg-white sticky bottom-0 text-right rounded-b-xl z-20 shadow-[0_-4px_10px_rgba(0,0,0,0.02)]">
                <button type="button" onclick="window.closeStudentModal()" class="bg-white border border-slate-300 hover:bg-slate-50 text-slate-600 font-bold uppercase tracking-widest text-[9px] px-5 py-2 rounded transition-colors cursor-pointer shadow-sm">Close Profile</button>
            </div>
        </div>
    </div>

    <!-- DOCUMENT REVIEW MODAL -->
    <div id="docReviewModal" class="fixed inset-0 z-[105] hidden items-center justify-center p-4 modal-overlay bg-slate-900/60 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl modal-content flex flex-col overflow-hidden border-t-[3px] border-t-[#00205b]">
            <div class="px-5 py-4 border-b border-slate-200 flex justify-between items-center bg-slate-50 z-20">
                <h3 class="text-sm font-bold text-[#00205b] uppercase tracking-wider flex items-center gap-2">
                    <svg class="w-4 h-4 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Official Document Verification
                </h3>
                <button type="button" onclick="window.closeDocReviewModal()" class="text-slate-400 hover:text-rose-500 transition-colors p-1.5 rounded focus:outline-none hover:bg-slate-200">
                    <svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
            
            <div class="p-5 flex-1 max-h-[75vh] overflow-y-auto custom-scrollbar relative z-20 bg-white">
                <div class="mb-5 flex items-center gap-2">
                    <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Applicant:</span>
                    <span class="text-sm font-bold text-[#00205b] uppercase" id="review_student_name"></span>
                </div>
                
                <form id="docReviewForm" onsubmit="window.submitDocReview(event)">
                    <input type="hidden" id="review_adm_id">
                    
                    <div id="docReviewList"></div>
                    
                    <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-slate-100">
                        <button type="button" onclick="window.closeDocReviewModal()" class="px-4 py-2 border border-slate-300 bg-white rounded text-xs font-semibold text-slate-600 uppercase tracking-wider hover:bg-slate-50 transition-colors cursor-pointer">Cancel</button>
                        <button type="submit" id="submit_review_btn" class="px-5 py-2 bg-[#00205b] hover:bg-[#003882] text-white rounded text-xs font-semibold uppercase tracking-wider shadow-sm flex items-center gap-2 transition-colors cursor-pointer">
                            Submit Verification
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- DOCUMENT OVERLAY MODAL -->
    <div id="documentModal" class="fixed inset-0 z-[160] hidden items-center justify-center p-2 sm:p-4 modal-overlay bg-slate-900/80 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-xl w-full max-w-4xl h-[90vh] sm:h-[85vh] flex flex-col modal-content shadow-2xl border-t-4 border-t-[#c5a02c]">
            <div class="px-5 py-4 border-b border-slate-200/80 bg-white/95 flex justify-between items-center relative z-20 shrink-0 shadow-sm rounded-t-xl">
                <h3 class="text-[13px] font-black text-[#00205b] uppercase tracking-[0.1em] flex items-center gap-2 drop-shadow-sm">
                    <div class="p-1 rounded-md bg-[#00205b]/10"><svg class="w-4 h-4 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg></div>
                    Document Viewer
                </h3>
                <div class="flex gap-2">
                    <a href="#" id="docDownloadBtn" download class="px-3 py-1.5 bg-emerald-500 hover:bg-emerald-600 text-white rounded text-[10px] font-bold uppercase tracking-widest shadow-sm flex items-center gap-1.5 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                        <span class="hidden sm:inline">Download</span>
                    </a>
                    <button type="button" onclick="window.closeDocModal()" class="text-slate-400 hover:text-rose-500 transition-colors bg-slate-100 hover:bg-rose-50 p-1.5 rounded-lg focus:outline-none border border-slate-200 cursor-pointer">
                        <svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </div>
            
            <div class="flex-1 bg-slate-100 relative overflow-hidden flex items-center justify-center p-4 z-20" id="doc-container">
                <img id="docImage" src="" alt="Document Preview" class="max-w-[90vw] max-h-[90vh] object-contain transition-transform duration-100 hidden"
                     onload="document.getElementById('docLoader').classList.add('hidden');"
                     onerror="this.classList.add('hidden'); document.getElementById('docLoader').classList.add('hidden'); document.getElementById('docError').classList.remove('hidden'); document.getElementById('docError').classList.add('flex');"
                     onmousedown="startDrag(event)">
                <iframe id="docIframe" src="" class="w-[90vw] h-[90vh] bg-white rounded-xl shadow-2xl hidden border-0"></iframe>
                
                <div id="docLoader" class="absolute flex flex-col items-center justify-center inset-0 z-10 pointer-events-none">
                    <svg class="w-8 h-8 text-slate-400 animate-spin mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Loading Document...</span>
                </div>

                <div id="docError" class="hidden flex-col items-center justify-center text-center p-8 bg-white rounded-xl shadow-2xl z-20">
                    <div class="w-16 h-16 bg-rose-100 text-rose-500 rounded-full flex items-center justify-center mb-4 border border-rose-200">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                    <p class="text-rose-700 font-bold mb-2 uppercase tracking-widest text-[11px]">File Missing or Unsupported</p>
                    <p class="text-slate-500 text-xs max-w-xs">The file could not be loaded. Please ensure the file was correctly uploaded.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- PROVISION ACCOUNT STAGE MODAL -->
    <div id="provisionModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 modal-overlay bg-slate-900/70 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl modal-content flex flex-col overflow-hidden border-t-4 border-t-emerald-500">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white z-20">
                <h3 class="text-sm font-black text-emerald-800 uppercase tracking-widest flex items-center gap-3">
                    <div class="p-1.5 rounded bg-emerald-50 text-emerald-600">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4"/></svg>
                    </div>
                    Provision Student Account
                </h3>
                <button type="button" onclick="window.closeProvisionModal()" class="text-slate-400 hover:text-rose-500 transition-colors bg-slate-50 hover:bg-rose-50 p-2 rounded-lg focus:outline-none">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
            <form method="POST" class="spa-form flex flex-col relative z-20 max-h-[85vh] overflow-y-auto custom-scrollbar">
                <input type="hidden" name="admission_id" id="prov_adm_id">
                <input type="hidden" name="provision_account" value="1">
                <div class="p-6 bg-slate-50/50 flex-1 space-y-6">

                    <div class="flex items-center gap-3 bg-white border border-blue-200 rounded-lg p-3 shadow-sm">
                        <div class="p-2 bg-blue-50 text-blue-600 rounded">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <div>
                            <span class="block text-[9px] font-black text-[#00205b] uppercase tracking-widest">Linked Tracking Number</span>
                            <span class="block text-sm font-black text-blue-600 tracking-wider" id="prov_adm_no">ADM-XXXX-XXXX</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1.5 ml-1">Last Name</label>
                            <input type="text" id="prov_lname" name="lname" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-semibold text-slate-700 focus:outline-none focus:border-emerald-500" oninput="this.value = this.value.replace(/[0-9]/g, '')" required>
                        </div>
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1.5 ml-1">First Name</label>
                            <input type="text" id="prov_fname" name="fname" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-semibold text-slate-700 focus:outline-none focus:border-emerald-500" oninput="this.value = this.value.replace(/[0-9]/g, '')" required>
                        </div>
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1.5 ml-1">Middle Name</label>
                            <input type="text" id="prov_mname" name="mname" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-semibold text-slate-700 focus:outline-none focus:border-emerald-500" oninput="this.value = this.value.replace(/[0-9]/g, '')">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1.5 ml-1">Personal Email (Reference)</label>
                            <input type="email" id="prov_personal_email" class="w-full bg-slate-100 border border-slate-200 rounded-lg px-3 py-2 text-xs font-semibold text-slate-500 cursor-not-allowed" readonly>
                        </div>
                        <div>
                            <label class="block text-[9px] font-black text-blue-600 uppercase tracking-widest mb-1.5 ml-1">Institutional Email (New)</label>
                            <input type="email" name="inst_email" id="prov_inst_email" placeholder="student@ldsp.edu.ph" class="w-full bg-white border border-blue-300 rounded-lg px-3 py-2 text-xs font-bold text-blue-800 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 shadow-inner" required>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1.5 ml-1">Academic Program</label>
                            <select name="program" id="prov_program" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-semibold text-slate-800 focus:outline-none focus:border-emerald-500" required>
                                <option value="" disabled>Select Program...</option>
                                <?php foreach ($prog_list as $p): ?>
                                    <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1.5 ml-1">Student Classification</label>
                            <select name="classification" id="prov_class" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-semibold text-slate-800 focus:outline-none focus:border-emerald-500">
                                <option value="Regular">Regular</option>
                                <option value="Irregular">Irregular</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1.5 ml-1">Initial Enrollment Term</label>
                            <select name="enrollment_term" id="prov_term" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs font-semibold text-slate-800 focus:outline-none focus:border-emerald-500">
                                <option value="1st Year - 1st Semester">1st Year - 1st Semester</option>
                                <option value="1st Year - 2nd Semester">1st Year - 2nd Semester</option>
                                <option value="2nd Year - 1st Semester">2nd Year - 1st Semester</option>
                                <option value="2nd Year - 2nd Semester">2nd Year - 2nd Semester</option>
                                <option value="3rd Year - 1st Semester">3rd Year - 1st Semester</option>
                                <option value="3rd Year - 2nd Semester">3rd Year - 2nd Semester</option>
                                <option value="4th Year - 1st Semester">4th Year - 1st Semester</option>
                                <option value="4th Year - 2nd Semester">4th Year - 2nd Semester</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2 ml-1">Assign Initial Fees <span class="text-rose-500">*</span></label>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <label class="flex items-center gap-3 p-3 bg-white border border-slate-200 rounded-lg cursor-pointer hover:border-emerald-400 transition-colors">
                                <input type="radio" name="initial_fee" value="<?= htmlspecialchars($base_misc_name) ?>" class="w-4 h-4 text-blue-600 focus:ring-blue-500" required>
                                <div>
                                    <span class="block text-[10px] font-black text-slate-700 uppercase tracking-wider"><?= htmlspecialchars($base_misc_name) ?></span>
                                    <span class="block text-[11px] font-black text-emerald-600">₱<?= number_format($base_misc_amt, 2) ?></span>
                                </div>
                            </label>
                            <label class="flex items-center gap-3 p-3 bg-white border border-slate-200 rounded-lg cursor-pointer hover:border-emerald-400 transition-colors">
                                <input type="radio" name="initial_fee" value="<?= htmlspecialchars($base_tuition_name) ?>" class="w-4 h-4 text-blue-600 focus:ring-blue-500" required>
                                <div>
                                    <span class="block text-[10px] font-black text-slate-700 uppercase tracking-wider"><?= htmlspecialchars($base_tuition_name) ?></span>
                                    <span class="block text-[11px] font-black text-emerald-600">₱<?= number_format($base_tuition_amt, 2) ?></span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-white border border-emerald-300 rounded-lg p-4 shadow-sm relative">
                            <label class="flex items-center gap-2 text-[9px] font-black text-emerald-600 uppercase tracking-widest mb-2">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/></svg>
                                Assign Student ID
                            </label>
                            <input type="text" name="student_id" id="prov_student_id" class="w-full text-center text-sm font-black text-emerald-800 tracking-widest focus:outline-none bg-transparent" placeholder="26-00001" readonly required>
                        </div>
                        <div class="bg-white border border-rose-300 rounded-lg p-4 shadow-sm relative">
                            <label class="flex items-center gap-2 text-[9px] font-black text-rose-600 uppercase tracking-widest mb-2">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2-2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                Assign 9+ Digit PIN
                            </label>
                            <input type="text" name="pin" id="prov_pin" minlength="9" class="w-full text-center text-sm font-black text-slate-600 tracking-[0.3em] focus:outline-none bg-transparent" placeholder="Type PIN..." required oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        </div>
                    </div>

                </div>
                <div class="px-6 py-4 border-t border-slate-200 flex justify-end gap-3 bg-slate-50 rounded-b-xl z-20">
                    <button type="button" onclick="window.closeProvisionModal()" class="px-6 py-2.5 bg-white border border-slate-300 rounded-lg text-[10px] font-bold text-slate-600 uppercase tracking-widest hover:bg-slate-50 transition-colors shadow-sm cursor-pointer">Cancel</button>
                    <button type="submit" class="px-6 py-2.5 bg-emerald-500 hover:bg-emerald-400 text-white rounded-lg text-[10px] font-bold uppercase tracking-widest flex items-center justify-center gap-2 shadow-md transition-colors border border-emerald-600 cursor-pointer">
                        Provision Account <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- TOAST NOTIFICATION CONTAINER -->
    <div id="toastContainer" class="fixed top-5 right-5 z-[100] flex flex-col gap-2 pointer-events-none"></div>

    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="application_management.js?v=<?= time() ?>"></script>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            window.filterTable = function(inputEl) {
                let filter = inputEl.value.toUpperCase();
                let activeTable = document.querySelector('#queue_tbody_Applications');
                if (activeTable) {
                    let tr = activeTable.getElementsByTagName("tr");
                    for (let i = 0; i < tr.length; i++) {
                        let tdName = tr[i].querySelector('.searchable-name');
                        let tdId = tr[i].querySelector('.searchable-id');
                        if (tdName || tdId) { 
                            let txtValue = (tdName ? tdName.textContent || tdName.innerText : "") + " " + (tdId ? tdId.textContent || tdId.innerText : "");
                            if (txtValue.toUpperCase().indexOf(filter) > -1) { tr[i].style.display = ""; } else { tr[i].style.display = "none"; }
                        }
                    }
                }
            };
        });
    </script>
</body>
</html>