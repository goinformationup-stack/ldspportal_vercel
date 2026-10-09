<?php
// 1. FORCE ERROR REPORTING
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$session_lifetime = 60 * 60 * 24 * 30;
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params($session_lifetime, '/');
session_name('LDSP_STAFF_SESSION'); 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

include "../dbconn.php";
require '../PHPMailer/Exception.php';
require '../PHPMailer/PHPMailer.php';
require '../PHPMailer/SMTP.php';
require "../env.php";

date_default_timezone_set('Asia/Manila');

$allowed_roles = ['registrar', 'admin'];
if (!isset($_SESSION['role'])) {
    header("Location: ../index.php");
    exit();
}
if (!in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../index.php");
    exit();
}

$can_edit = in_array($_SESSION['role'], ['registrar', 'admin']);
$success_msg = "";
$error_msg = "";

$settings = [];
$settings_q = $conn->query("SELECT * FROM portal_settings");
if ($settings_q) {
    while ($row = $settings_q->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

$active_semester = isset($settings['active_semester']) ? $settings['active_semester'] : '1st Semester';
$active_school_year = isset($settings['active_school_year']) ? $settings['active_school_year'] : '2024-2025';
$safe_sem = $conn->real_escape_string($active_semester);
$safe_sy = $conn->real_escape_string($active_school_year);

// =========================================================
// AUTO-MIGRATION: OFFICIAL STUDENT CORS VAULT & PROGRAM SHIFTS
// =========================================================
try {
    $conn->query("CREATE TABLE IF NOT EXISTS `official_student_cors` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `enrollment_request_id` int(11) NOT NULL,
      `school_year` varchar(50) NOT NULL,
      `semester` varchar(50) NOT NULL,
      `program` varchar(255) NOT NULL,
      `year_level` varchar(50) NOT NULL,
      `section` varchar(50) NOT NULL,
      `published_at` datetime DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_term_cor` (`user_id`, `school_year`, `semester`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    $conn->query("CREATE TABLE IF NOT EXISTS `program_shifts` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `student_identifier` varchar(100) NOT NULL,
      `previous_program` varchar(255) NOT NULL,
      `new_program` varchar(255) NOT NULL,
      `shift_year_level` varchar(50) NOT NULL,
      `shift_semester` varchar(50) NOT NULL,
      `effective_academic_year` varchar(50) NOT NULL,
      `processed_by` varchar(100) NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    $vault_check = $conn->query("SELECT id FROM official_student_cors LIMIT 1");
    if ($vault_check && $vault_check->num_rows === 0) {
        $conn->query("INSERT IGNORE INTO `official_student_cors` (`user_id`, `enrollment_request_id`, `school_year`, `semester`, `program`, `year_level`, `section`, `published_at`)
        SELECT `user_id`, `id`, `school_year`, `semester`, `program`, `year_level`, IFNULL(`assigned_section`, 'A'), IFNULL(`enrolled_at`, NOW())
        FROM `enrollment_requests`
        WHERE `final_status` = 'Enrolled' AND `user_id` IS NOT NULL");
    }
} catch (Exception $e) {}

// =========================================================
// AUTO-HEAL SCRIPT: RESCUE STUCK APPLICANTS
// =========================================================
$conn->query("
    UPDATE enrollment_requests er
    JOIN admissions a ON er.user_id = a.provisioned_user_id
    SET er.final_status = 'Pending Registrar'
    WHERE a.pushed_to_registrar = 1 
      AND er.final_status IN ('Pending', 'Pending Payment', 'Accounting Verification', 'Payment Rejected')
      AND er.semester = '$safe_sem' AND er.school_year = '$safe_sy'
");

// =========================================================
// EMAIL DISPATCHER FUNCTION
// =========================================================
function sendEnrollmentEmail($personal_email, $inst_email, $student_name, $program, $year_level, $section, $semester, $date) {
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
        $mail->setFrom(SMTP_USER, 'LDSP Registrar Office');

        if (!empty($personal_email)) { $mail->addAddress($personal_email, $student_name); }
        if (!empty($inst_email)) { $mail->addAddress($inst_email, $student_name); }

        $mail->isHTML(true);
        $mail->Subject = 'LDSP Official Enrollment Confirmation';

        $mail->Body = "
            <div style='font-family: Arial, sans-serif; color: #1f2937; max-width: 600px; margin: 0 auto; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; background-color: #ffffff;'>
                <div style='padding: 24px; border-bottom: 1px solid #e5e7eb; background-color: #eff6ff;'>
                    <span style='color: #1d4ed8; font-weight: bold; font-size: 16px;'>🎓 OFFICIAL ENROLLMENT CONFIRMATION</span>
                </div>
                <div style='padding: 32px; background-color: #f8fafc; border-radius: 8px; margin: 24px; border: 1px solid #e2e8f0;'>
                    <p style='font-size: 16px; margin-top:0;'>Dear <strong>$student_name</strong>,</p>
                    <p style='line-height: 1.6;'>Congratulations! You have been successfully evaluated and officially enrolled into the master roster by the Registrar's Office.</p>

                    <div style='background-color: #ffffff; padding: 20px; border: 1px solid #cbd5e1; border-radius: 8px; margin: 24px 0;'>
                        <h3 style='margin-top: 0; color: #00205b; font-size: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 15px;'>ENROLLMENT DETAILS</h3>
                        <table style='width: 100%; border-collapse: collapse; font-size: 14px; table-layout: fixed;'>
                            <tr><td style='padding: 6px 0; color: #64748b; width: 35%; vertical-align: top;'>Program:</td><td style='padding: 6px 0; font-weight: bold; color: #0f172a; word-break: break-word;'>$program</td></tr>
                            <tr><td style='padding: 6px 0; color: #64748b; vertical-align: top;'>Year Level:</td><td style='padding: 6px 0; font-weight: bold; color: #0f172a;'>$year_level</td></tr>
                            <tr><td style='padding: 6px 0; color: #64748b; vertical-align: top;'>Section:</td><td style='padding: 6px 0; font-weight: bold; color: #107c41;'>$section</td></tr>
                            <tr><td style='padding: 6px 0; color: #64748b; vertical-align: top;'>Term:</td><td style='padding: 6px 0; font-weight: bold; color: #0f172a;'>$semester</td></tr>
                            <tr><td colspan='2' style='border-top: 1px dashed #cbd5e1; margin: 10px 0; padding-top: 10px;'></td></tr>
                            <tr><td style='padding: 6px 0; color: #64748b; vertical-align: top;'>Date Enrolled:</td><td style='padding: 6px 0; font-weight: bold; color: #0f172a;'>$date</td></tr>
                        </table>
                    </div>
                    <p style='line-height: 1.6; font-size: 14px; color: #475569;'>You may now log in to your student portal to view your Digital Certificate of Registration (COR) and your class schedules.</p>
                    <p style='line-height: 1.6; font-size: 14px; color: #475569; font-weight: bold; margin-bottom: 0;'>Welcome to Lyceum de San Pablo!</p>
                </div>
            </div>
        ";
        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// =========================================================
// ACADEMIC ASCENT & PROGRESSION CALCULATOR
// =========================================================
function calculateStudentProgression($completed_cors, $active_semester = '1st Semester') {
    $term_weights = [
        '1st Year - 1st Semester' => 11, '1st Year - 2nd Semester' => 12, '1st Year - Summer' => 13,
        '2nd Year - 1st Semester' => 21, '2nd Year - 2nd Semester' => 22, '2nd Year - Summer' => 23,
        '3rd Year - 1st Semester' => 31, '3rd Year - 2nd Semester' => 32, '3rd Year - Summer' => 33,
        '4th Year - 1st Semester' => 41, '4th Year - 2nd Semester' => 42, '4th Year - Summer' => 43,
    ];
    
    $max_weight = 0;
    $latest_year = '1st Year';
    $latest_sem = '1st Semester';

    foreach ($completed_cors as $c) {
        $key = $c['year_level'] . ' - ' . $c['semester'];
        if (isset($term_weights[$key]) && $term_weights[$key] > $max_weight) {
            $max_weight = $term_weights[$key];
            $latest_year = $c['year_level'];
            $latest_sem = $c['semester'];
        }
    }

    if ($max_weight == 0) {
        return [
            'current_standing' => 'Incoming New',
            'current_year' => '1st Year',
            'current_sem' => '',
            'target_year' => '1st Year',
            'target_sem' => $active_semester,
            'target_term' => "1st Year - $active_semester",
            'has_completed' => false
        ];
    }

    $target_year = $latest_year;
    $target_sem = ($latest_sem === '1st Semester') ? '2nd Semester' : '1st Semester';
    
    if ($latest_sem === '2nd Semester' || $latest_sem === 'Summer') {
        if ($latest_year === '1st Year') $target_year = '2nd Year';
        elseif ($latest_year === '2nd Year') $target_year = '3rd Year';
        elseif ($latest_year === '3rd Year') $target_year = '4th Year';
        elseif ($latest_year === '4th Year') $target_year = 'Graduating';
        $target_sem = '1st Semester';
    } else {
        $target_year = $latest_year;
        $target_sem = '2nd Semester';
    }

    return [
        'current_standing' => "$latest_year ($latest_sem)",
        'current_year' => $latest_year,
        'current_sem' => $latest_sem,
        'target_year' => $target_year,
        'target_sem' => $target_sem,
        'target_term' => "$target_year - $target_sem",
        'has_completed' => true
    ];
}

try {
    $eval_cols = [
        'evaluated_program' => 'VARCHAR(150) NULL',
        'evaluated_year' => 'VARCHAR(50) NULL',
        'evaluated_section' => 'VARCHAR(50) NULL',
        'evaluated_status' => 'VARCHAR(50) NULL',
        'evaluated_admission_type' => 'VARCHAR(150) NULL',
        'evaluated_modality' => 'VARCHAR(50) NULL',
        'evaluated_subjects' => 'TEXT NULL',
        'registrar_evaluated' => 'TINYINT(1) DEFAULT 0'
    ];
    foreach ($eval_cols as $col => $type) {
        $check = $conn->query("SHOW COLUMNS FROM admissions LIKE '$col'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE admissions ADD COLUMN $col $type");
        }
    }
} catch (Exception $e) {}

$prog_list = [];
$prog_q = $conn->query("SELECT program_name FROM programs ORDER BY program_name ASC");
if ($prog_q) { while ($r = $prog_q->fetch_assoc()) { $prog_list[] = $r['program_name']; } }

$active_start = (int)explode('-', $safe_sy)[0];
if ($active_start < 2024) { $active_start = (int)date('Y'); }

$years_list = [];
$base_start = 2024;
$end_start = max($base_start, $active_start);

for ($y = $base_start; $y <= $end_start; $y++) {
    $next_y = $y + 1;
    $sy_format = $y . '-' . $next_y;
    $years_list[$sy_format] = $sy_format;
}

$years_list[$safe_sy] = $safe_sy;
krsort($years_list); 
$recent_years = array_slice($years_list, 0, 5, true);

$f_program = isset($_GET['f_program']) ? $_GET['f_program'] : 'All';
$f_year    = isset($_GET['f_year']) ? $_GET['f_year'] : $safe_sy;
$year_list = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

function getNextAvailableSection($conn, $semester, $school_year, $program, $year_level) {
    $def_q = $conn->query("SELECT setting_value FROM portal_settings WHERE setting_key='default_section_limit'");
    $global_limit = ($def_q && $def_q->num_rows > 0) ? (int)$def_q->fetch_assoc()['setting_value'] : 60;

    $sections = range('A', 'Z');
    foreach ($sections as $sec) {
        $limit_q = $conn->query("SELECT student_limit FROM section_settings WHERE semester='$semester' AND program='$program' AND year_level='$year_level' AND section_name='$sec'");
        $limit = ($limit_q && $limit_q->num_rows > 0) ? (int)$limit_q->fetch_assoc()['student_limit'] : $global_limit;

        $count_q = $conn->query("SELECT COUNT(id) as total FROM official_student_cors WHERE semester='$semester' AND school_year='$school_year' AND program='$program' AND year_level='$year_level' AND section='$sec'");
        $count = ($count_q && $count_q->num_rows > 0) ? (int)$count_q->fetch_assoc()['total'] : 0;

        if ($count < $limit) return $sec;
    }
    return 'A'; 
}

// =========================================================
// POST ACTIONS (EVALUATION, INLINE GRADE EDITS)
// =========================================================
if ($can_edit) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (isset($_POST['revoke_evaluation'])) {
            $id = (int)$_POST['record_id'];
            $type = $conn->real_escape_string($_POST['record_type']);

            if ($type === 'online') {
                $conn->query("DELETE FROM official_student_cors WHERE enrollment_request_id = $id");
                $conn->query("DELETE FROM enrollments WHERE enrollment_request_id = $id");
                $upd = $conn->query("UPDATE enrollment_requests SET final_status = 'Pending Registrar', enrolled_at = NULL, assigned_section = NULL WHERE id = $id");

                if ($upd) {
                    $success_msg = "Evaluation successfully revoked. Official COR has been deleted and student returned to pending queue.";
                } else {
                    $error_msg = "Error reverting evaluation: " . $conn->error;
                }
            } else {
                $upd = $conn->query("UPDATE admissions SET registrar_evaluated = 0, evaluated_program = NULL, evaluated_year = NULL, evaluated_section = NULL, evaluated_status = NULL, evaluated_subjects = NULL WHERE id = $id");
                if ($upd) {
                    $success_msg = "Evaluation successfully revoked. Applicant returned to pending queue.";
                } else {
                    $error_msg = "Error reverting evaluation: " . $conn->error;
                }
            }
        }

        if (isset($_POST['update_historical_grade'])) {
            $source = $conn->real_escape_string($_POST['source']);
            $record_id = (int)$_POST['record_id'];
            $grade = trim($_POST['grade']);
            $safe_grade = $grade === '' ? "NULL" : "'" . $conn->real_escape_string($grade) . "'";

            if ($source === 'enrollments') {
                $conn->query("UPDATE enrollments SET grade = $safe_grade WHERE id = $record_id");
            } elseif ($source === 'student_grades') {
                $conn->query("UPDATE student_grades SET grade = $safe_grade WHERE id = $record_id");
            }
            echo json_encode(['status' => 'success', 'message' => 'Grade updated successfully.']);
            exit();
        }

        if (isset($_POST['delete_historical_subject'])) {
            $source = $conn->real_escape_string($_POST['source']);
            $record_id = (int)$_POST['record_id'];

            if ($source === 'enrollments') {
                $conn->query("DELETE FROM enrollments WHERE id = $record_id");
            } elseif ($source === 'student_grades') {
                $conn->query("DELETE FROM student_grades WHERE id = $record_id");
            }
            echo json_encode(['status' => 'success', 'message' => 'Subject successfully removed from records.']);
            exit();
        }

        if (isset($_POST['evaluate_student'])) {
            $type = $conn->real_escape_string($_POST['record_type']);
            $id = (int)$_POST['record_id'];
            $student_identifier = $conn->real_escape_string($_POST['student_identifier'] ?? '');

            $prog = $conn->real_escape_string($_POST['eval_program']);
            $year = $conn->real_escape_string($_POST['eval_year']);
            
            // -------------------------------------------------------------
            // Automatically map "Shifter" back to "Irregular" for the DB 
            // -------------------------------------------------------------
            $raw_status = $_POST['student_status'] ?? 'Regular';
            if ($raw_status === 'Shifter' || $raw_status === 'Manual_Irregular' || $raw_status === 'Irregular') {
                $db_status = 'Irregular';
            } elseif ($raw_status === 'Manual_Regular') {
                $db_status = 'Regular';
            } else {
                $db_status = $raw_status;
            }
            $status = $conn->real_escape_string($db_status);
            
            $old_prog = $conn->real_escape_string($_POST['old_program'] ?? '');

            $base_adm = isset($_POST['base_admission_type']) ? $_POST['base_admission_type'] : 'New';
            $sub_adm = isset($_POST['sub_admission_type']) ? $_POST['sub_admission_type'] : []; 
            $all_adm_types = array_merge([$base_adm], (array)$sub_adm);

            if ($raw_status === 'Shifter' && !in_array('Change Course', $all_adm_types)) {
                $all_adm_types[] = 'Change Course';
            }

            $admission_type = $conn->real_escape_string(implode(', ', array_unique(array_filter($all_adm_types))));
            $modality = $conn->real_escape_string(isset($_POST['modality']) ? $_POST['modality'] : 'HYBRID');

            $sec = getNextAvailableSection($conn, $safe_sem, $safe_sy, $prog, $year);

            // Record credited subject grades
            if (!empty($_POST['credited_grades']) && is_array($_POST['credited_grades']) && !empty($student_identifier)) {
                foreach ($_POST['credited_grades'] as $code => $grade) {
                    $grade = trim($grade);
                    if ($grade !== '') { 
                        $safe_code = $conn->real_escape_string($code);
                        $safe_grade = $conn->real_escape_string($grade);
                        $desc = $conn->real_escape_string($_POST['credited_desc'][$code] ?? '');
                        $units = (float)($_POST['credited_units'][$code] ?? 0);

                        $chk = $conn->query("SELECT id FROM student_grades WHERE student_id='$student_identifier' AND subject_code='$safe_code'");
                        if ($chk && $chk->num_rows > 0) {
                            $conn->query("UPDATE student_grades SET grade='$safe_grade', subject_description='$desc', units='$units' WHERE student_id='$student_identifier' AND subject_code='$safe_code'");
                        } else {
                            $conn->query("INSERT INTO student_grades (student_id, subject_code, subject_description, units, teacher_name, grade, school_year, semester) VALUES ('$student_identifier', '$safe_code', '$desc', $units, 'Credited/External', '$safe_grade', 'Credited', 'Credited')");
                        }
                    }
                }
            }

            // Log official program shift
            if (($raw_status === 'Shifter' || (!empty($old_prog) && $prog !== $old_prog)) && !empty($student_identifier)) {
                $staff_name = $conn->real_escape_string($_SESSION['first_name'] ?? 'Registrar');
                $previous_program_safe = !empty($old_prog) ? $old_prog : 'Previous Program';
                $conn->query("INSERT INTO program_shifts (student_identifier, previous_program, new_program, shift_year_level, shift_semester, effective_academic_year, processed_by, created_at)
                    VALUES ('$student_identifier', '$previous_program_safe', '$prog', '$year', '$safe_sem', '$safe_sy', '$staff_name', NOW())");
            }

            if ($type === 'online') {
                $check_u_q = $conn->query("SELECT user_id FROM enrollment_requests WHERE id = $id");
                $uid = ($check_u_q && $check_u_q->num_rows > 0) ? (int)$check_u_q->fetch_assoc()['user_id'] : 0;

                if ($uid > 0) {
                    try {
                        $vault_insert = $conn->query("INSERT INTO official_student_cors (user_id, enrollment_request_id, school_year, semester, program, year_level, section, published_at) VALUES ($uid, $id, '$safe_sy', '$safe_sem', '$prog', '$year', '$sec', NOW())");
                        if (!$vault_insert) {
                            throw new Exception("Database constraint violation.");
                        }
                    } catch (Exception $e) {
                        $error_msg = "DATABASE BLOCK: This student already has an Official Digital COR saved in the vault for $safe_sy - $safe_sem.";
                        if (isset($_POST['ajax_post'])) {
                            header('Content-Type: application/json');
                            echo json_encode(['status' => 'error', 'message' => $error_msg]);
                            exit();
                        }
                    }

                    $conn->query("UPDATE enrollment_requests SET program='$prog', shifting_to=NULL, year_level='$year', assigned_section='$sec', student_status='$status', learning_mode='$modality', final_status='Enrolled', enrolled_at=NOW() WHERE id=$id");
                    $conn->query("DELETE FROM enrollments WHERE enrollment_request_id = $id");

                    $u_q = $conn->query("
                        SELECT er.user_id, u.email as personal_email, p.institutional_email, p.first_name, p.last_name 
                        FROM enrollment_requests er 
                        JOIN users u ON er.user_id = u.id 
                        LEFT JOIN user_profiles p ON u.id = p.user_id 
                        WHERE er.id=$id
                    ");

                    if ($u_q && $u_q->num_rows > 0) {
                        $u_data = $u_q->fetch_assoc();
                        $personal_email = $u_data['personal_email'];
                        $inst_email = $u_data['institutional_email'];
                        $student_name = trim(($u_data['first_name'] ?? '') . ' ' . ($u_data['last_name'] ?? ''));
                        if (empty($student_name)) { $student_name = "Student"; }

                        $conn->query("UPDATE user_profiles SET admission_type = '$admission_type', program = '$prog' WHERE user_id = $uid");

                        $subjects = [];
                        if ($raw_status === 'Irregular' || $raw_status === 'Shifter' || $raw_status === 'Manual_Irregular' || $raw_status === 'Manual_Regular') {
                            $subjects = isset($_POST['irregular_subjects']) ? $_POST['irregular_subjects'] : [];
                        } else {
                            $pid_q = $conn->query("SELECT program_id FROM programs WHERE program_name = '$prog'");
                            if ($pid_q && $pid_q->num_rows > 0) {
                                $pid = $pid_q->fetch_assoc()['program_id'];
                                $subj_q = $conn->query("SELECT id FROM prospectus WHERE program_id = $pid AND year_level = '$year' AND semester = '$safe_sem' AND IFNULL(is_archived, 0) = 0");
                                while ($s = $subj_q->fetch_assoc()) {
                                    $subjects[] = $s['id'];
                                }
                            }
                        }
                        foreach ($subjects as $sid) {
                            $sid_safe = (int)$sid;
                            
                            // 1. Insert/Update Enrollment and FORCE grade to NULL (wipe old grade for retake)
                            $conn->query("INSERT INTO enrollments (student_id, subject_id, enrollment_request_id, status, grade) 
                                          VALUES ($uid, $sid_safe, $id, 'Enrolled', NULL) 
                                          ON DUPLICATE KEY UPDATE enrollment_request_id = $id, status = 'Enrolled', grade = NULL");
                            
                            // 2. Remove the failed grade from permanent student_grades table to allow retake
                            if (!empty($student_identifier)) {
                                $c_q = $conn->query("SELECT course_code FROM prospectus WHERE id = $sid_safe");
                                if ($c_q && $c_q->num_rows > 0) {
                                    $c_code = $c_q->fetch_assoc()['course_code'];
                                    $conn->query("DELETE FROM student_grades WHERE student_id = '$student_identifier' AND subject_code = '$c_code'");
                                }
                            }
                        }

                        $date_enrolled = date('F j, Y h:i A');
                        $email_sent = sendEnrollmentEmail($personal_email, $inst_email, $student_name, $prog, $year, $sec, $safe_sem, $date_enrolled);
                        $email_note = $email_sent ? " Notification email sent." : " (Email failed to send).";

                        $success_msg = "Official COR generated and saved to vault." . $email_note;
                    }
                }
            } else {

                $subjects = [];
                if ($raw_status === 'Irregular' || $raw_status === 'Shifter' || $raw_status === 'Manual_Irregular' || $raw_status === 'Manual_Regular') {
                    $subjects = isset($_POST['irregular_subjects']) ? $_POST['irregular_subjects'] : [];
                } else {
                    $pid_q = $conn->query("SELECT program_id FROM programs WHERE program_name = '$prog'");
                    if ($pid_q && $pid_q->num_rows > 0) {
                        $pid = $pid_q->fetch_assoc()['program_id'];
                        $subj_q = $conn->query("SELECT id FROM prospectus WHERE program_id = $pid AND year_level = '$year' AND semester = '$safe_sem' AND IFNULL(is_archived, 0) = 0");
                        while ($s = $subj_q->fetch_assoc()) { $subjects[] = $s['id']; }
                    }
                }
                $subj_str = $conn->real_escape_string(implode(',', $subjects));

                $upd = $conn->query("UPDATE admissions SET evaluated_program='$prog', evaluated_year='$year', evaluated_section='$sec', evaluated_status='$status', evaluated_admission_type='$admission_type', evaluated_modality='$modality', student_type='$admission_type', evaluated_subjects='$subj_str', registrar_evaluated=1 WHERE id=$id");

            $a_q = $conn->query("SELECT provisioned_user_id FROM admissions WHERE id=$id");
                if ($a_q && $a_q->num_rows > 0) {
                    $p_uid = (int)$a_q->fetch_assoc()['provisioned_user_id'];
                    if ($p_uid > 0) {
                        $conn->query("UPDATE user_profiles SET admission_type = '$admission_type', program = '$prog' WHERE user_id = $p_uid");

                        // Wipe old grades for retakes
                        if (!empty($student_identifier) && count($subjects) > 0) {
                            foreach ($subjects as $sid) {
                                $sid_safe = (int)$sid;
                                // Wipe from active enrollments
                                $conn->query("UPDATE enrollments SET grade = NULL WHERE student_id = $p_uid AND subject_id = $sid_safe");
                                
                                // Wipe from permanent history
                                $c_q = $conn->query("SELECT course_code FROM prospectus WHERE id = $sid_safe");
                                if ($c_q && $c_q->num_rows > 0) {
                                    $c_code = $c_q->fetch_assoc()['course_code'];
                                    $conn->query("DELETE FROM student_grades WHERE student_id = '$student_identifier' AND subject_code = '$c_code'");
                                }
                            }
                        }
                    }
                }

                if ($upd) {
                    $success_msg = "Applicant evaluation saved successfully.";
                } else {
                    $error_msg = "Database Error: " . $conn->error;
                }
            }
        }

        if (isset($_POST['ajax_post'])) {
            header('Content-Type: application/json');
            $final_msg = empty($error_msg) ? $success_msg : $error_msg;
            $final_status = empty($error_msg) ? 'success' : 'error';
            echo json_encode(['status' => $final_status, 'message' => $final_msg]);
            exit();
        }
    }
}

// =========================================================
// API ENDPOINT: FETCH ACADEMIC HISTORY & PROGRESS ANALYZER
// =========================================================
if (isset($_GET['api_student_history'])) {
    while (ob_get_level()) { ob_end_clean(); }
    $student_id = $conn->real_escape_string(isset($_GET['student_id']) ? $_GET['student_id'] : '');
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

    $history = [];
    $completed_cors = [];

    if ($user_id > 0) {
        $cor_q = $conn->query("SELECT year_level, semester, school_year FROM official_student_cors WHERE user_id = $user_id ORDER BY school_year ASC, semester ASC");
        if ($cor_q) {
            while($c = $cor_q->fetch_assoc()) {
                $completed_cors[] = $c;
            }
        }

        $q1 = $conn->query("
            SELECT e.id as record_id, 'enrollments' as source, p.year_level, p.semester, p.course_code as subject_code, p.descriptive_title as subject_description, p.units, e.grade,
                   er.school_year, er.program 
            FROM enrollments e 
            JOIN prospectus p ON e.subject_id = p.id 
            JOIN enrollment_requests er ON e.enrollment_request_id = er.id
            WHERE e.student_id = $user_id
        ");
        if ($q1) {
            while($row = $q1->fetch_assoc()) {
                $history[] = $row;
            }
        }
    }

    if (!empty($student_id)) {
        $q2 = $conn->query("
            SELECT sg.id as record_id, 'student_grades' as source, COALESCE(p.year_level, 'Credited/Past') as year_level, COALESCE(sg.semester, p.semester, 'N/A') as semester, sg.subject_code, sg.subject_description, sg.units, sg.grade,
                   sg.school_year, 'Credited/External' as program
            FROM student_grades sg 
            LEFT JOIN prospectus p ON sg.subject_code = p.course_code 
            WHERE sg.student_id = '$student_id'
        ");
        if ($q2) {
            while($row = $q2->fetch_assoc()) {
                $history[] = $row;
            }
        }
    }

    $year_order = ['1st Year' => 1, '2nd Year' => 2, '3rd Year' => 3, '4th Year' => 4, 'Credited/Past' => 0];
    $sem_order = ['1st Semester' => 1, '2nd Semester' => 2, 'Summer' => 3, 'N/A' => 0];

    usort($history, function($a, $b) use ($year_order, $sem_order) {
        $yA = isset($year_order[$a['year_level']]) ? $year_order[$a['year_level']] : 99;
        $yB = isset($year_order[$b['year_level']]) ? $year_order[$b['year_level']] : 99;
        if ($yA === $yB) {
            $sA = isset($sem_order[$a['semester']]) ? $sem_order[$a['semester']] : 99;
            $sB = isset($sem_order[$b['semester']]) ? $sem_order[$b['semester']] : 99;
            if ($sA == $sB) return 0;
            return ($sA > $sB) ? 1 : -1;
        }
        if ($yA == $yB) return 0;
        return ($yA > $yB) ? 1 : -1;
    });

    $cor_badges_html = "";
    $locked_years = [];
    $year_tallies = [];
    $has_active_term_cor = false;

    foreach($completed_cors as $c) {
        $cor_badges_html .= "<span class='inline-flex items-center gap-1 bg-indigo-100 text-indigo-900 text-[8px] px-1.5 py-0.5 rounded font-black uppercase tracking-widest shadow-sm border border-indigo-200'><svg class='w-3 h-3' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'></path></svg> {$c['year_level']} - {$c['semester']}</span>";
        $year_tallies[$c['year_level']][] = $c['semester'];

        if ($c['semester'] === $safe_sem && $c['school_year'] === $safe_sy) {
            $has_active_term_cor = true;
        }
    }

    foreach($year_tallies as $yl => $sems) {
        if (in_array($safe_sem, $sems)) {
            $locked_years[] = $yl;
        }
        if (in_array('1st Semester', $sems) && in_array('2nd Semester', $sems)) {
            if (!in_array($yl, $locked_years)) {
                $locked_years[] = $yl;
            }
        }
    }

    if (empty($cor_badges_html)) {
        $cor_badges_html = "<span class='text-[9px] text-slate-400 font-bold italic uppercase tracking-widest'>No Official CORs collected yet.</span>";
    }

    $progression = calculateStudentProgression($completed_cors, $safe_sem);
    $recommended_year = $progression['target_year'];
    $recommended_term = $progression['target_term'];
    $current_standing = $progression['current_standing'];

    $failed_subjects = [];
    $has_failing = false;
    $has_missing = false;
    foreach ($history as $h) {
        $g = isset($h['grade']) ? trim((string)$h['grade']) : '';
        
        if ($g === '' || $g === 'Not Taken' || strtoupper($g) === 'INC' || strtoupper($g) === 'DRP') {
            $has_missing = true;
            if ($g !== '' && $g !== 'Not Taken') {
                $failed_subjects[] = $h['subject_code'];
            }
        } else {
            $gf = (float)$g;
            if ($gf > 3.0 || strtoupper($g) === 'FAILED' || $g === '5.00') {
                $has_failing = true;
                $failed_subjects[] = $h['subject_code'];
            }
        }
    }

    $banner_html = "";
    if ($progression['has_completed']) {
        if (count($failed_subjects) > 0) {
            $banner_html = "<div class='bg-rose-50 border-l-[3px] border-rose-500 p-2.5 rounded shadow-sm text-rose-700 flex items-start gap-2'>
                <svg class='w-4 h-4 mt-0.5 shrink-0' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'/></svg>
                <div>
                    <div class='text-[9px] font-black uppercase tracking-widest mb-0.5'>Academic Warning (Failed/Dropped Subjects)</div>
                    <div class='text-[9px] font-bold'>Current Standing: <strong>{$current_standing}</strong>. Advised to evaluate as Irregular. Recommended Target: <strong class='text-rose-900'>{$recommended_term}</strong></div>
                </div>
            </div>";
        } else {
            $banner_html = "<div class='bg-indigo-50 border-l-[3px] border-indigo-500 p-2.5 rounded shadow-sm text-indigo-800 flex items-start gap-2'>
                <svg class='w-4 h-4 mt-0.5 shrink-0' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'/></svg>
                <div>
                    <div class='text-[9px] font-black uppercase tracking-widest mb-0.5'>Academic Progression Clear</div>
                    <div class='text-[9px] font-bold'>Current Standing: <strong>{$current_standing}</strong>. <span class='ml-1 text-indigo-600 font-black'>Recommended Next Ascent: {$recommended_term}</span></div>
                </div>
            </div>";
        }
    } else {
        $banner_html = "<div class='bg-slate-100 border-l-[3px] border-slate-500 p-2.5 rounded shadow-sm text-slate-700 flex items-start gap-2'>
            <svg class='w-4 h-4 mt-0.5 shrink-0' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'/></svg>
            <div>
                <div class='text-[9px] font-black uppercase tracking-widest mb-0.5'>Incoming Student Record</div>
                <div class='text-[9px] font-bold'>No previous official CORs found. Proceed with standard placement: <strong>1st Year - {$safe_sem}</strong>.</div>
            </div>
        </div>";
    }

    $html = '';
    if (count($history) > 0) {
        $current_group = '';
        foreach($history as $h) {
            $group = htmlspecialchars($h['year_level']) . " - " . htmlspecialchars($h['semester']);
            
            // Book-style Group Header
            if ($group !== $current_group) {
                $current_group = $group;
                $colspan = $can_edit ? '5' : '4';
                $html .= "<tr><td colspan='{$colspan}' class='px-4 py-2.5 bg-slate-200/60 text-[#00205b] font-black text-[10px] uppercase tracking-widest border-y border-slate-300 shadow-inner'>{$group}</td></tr>";
            }

            $gradeVal = htmlspecialchars($h['grade'] ?? '');

            if ($can_edit) {
                $gradeDisplay = "<input type='text' value='{$gradeVal}' placeholder='Empty' class='w-full max-w-[50px] mx-auto block text-center border border-slate-300 rounded px-1 py-0.5 text-[9px] font-bold focus:border-indigo-500 focus:outline-none shadow-inner transition-colors' onchange='window.updateHistoricalGrade(this, \"{$h['source']}\", {$h['record_id']})'>";
            } else {
                $gColor = 'text-slate-400';
                if (!empty($gradeVal)) {
                    $gColor = 'text-emerald-600';
                    $val = (float)$gradeVal;
                    if ($val > 3.0 || strtoupper($gradeVal) == 'INC' || strtoupper($gradeVal) == 'DRP' || strtoupper($gradeVal) == 'FAILED' || $gradeVal == '5.00') { 
                        $gColor = 'text-rose-600'; 
                    }
                }
                $gradeDisplay = "<span class='{$gColor} font-black text-[10px]'>" . ($gradeVal !== '' ? $gradeVal : '-') . "</span>";
            }

            $context_display = htmlspecialchars($h['school_year'] ?? 'N/A') . " | " . htmlspecialchars($h['program'] ?? 'Credited/External');

            $html .= "<tr class='hover:bg-indigo-50/50 transition-colors border-b border-slate-200'>";
            $html .= "<td class='px-4 py-2 font-black text-indigo-900 border-r border-slate-200 text-[10px] uppercase tracking-wider'>".htmlspecialchars($h['subject_code'])."</td>";
            $html .= "<td class='px-4 py-2 text-slate-700 leading-tight border-r border-slate-200 text-[10px]'>".htmlspecialchars($h['subject_description'])."<div class='text-[7px] text-slate-400 mt-0.5 font-bold uppercase tracking-widest'>{$context_display}</div></td>";
            $html .= "<td class='px-4 py-2 text-center text-slate-600 border-r border-slate-200 font-bold text-[10px]'>".htmlspecialchars($h['units'])."</td>";
            $html .= "<td class='px-4 py-2 text-center font-mono border-r border-slate-200 align-middle'>{$gradeDisplay}</td>";

            if ($can_edit) {
                $html .= "<td class='px-2 py-2 text-center align-middle'>
                    <button type='button' onclick='window.deleteHistoricalSubject(this, \"{$h['source']}\", {$h['record_id']})' class='text-rose-400 hover:text-rose-600 p-1 bg-rose-50 hover:bg-rose-100 rounded transition-colors shadow-sm cursor-pointer mx-auto block' title='Delete Subject'>
                        <svg class='w-3 h-3 pointer-events-none' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M6 18L18 6M6 6l12 12'/></svg>
                    </button>
                </td>";
            }
            $html .= "</tr>";
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'html' => $html, 
        'count' => count($history), 
        'progress_banner' => $banner_html, 
        'current_standing' => $current_standing,
        'recommended_year' => $recommended_year,
        'recommended_term' => $recommended_term,
        'cor_badges_html' => $cor_badges_html,
        'locked_years' => $locked_years,
        'has_active_term_cor' => $has_active_term_cor,
        'is_irregular' => (count($failed_subjects) > 0),
        'has_failing' => $has_failing,
        'has_missing' => $has_missing
    ]);
    exit();
}

// =========================================================
// API ENDPOINT: FETCH CURRICULUM (REGULAR, IRREGULAR, SHIFTER)
// =========================================================
if (isset($_GET['api_curriculum'])) {
    $prog = $conn->real_escape_string($_GET['program']);
    $eval_status = isset($_GET['status']) ? $_GET['status'] : 'Irregular';
    $eval_year = $conn->real_escape_string(isset($_GET['year']) ? $_GET['year'] : '1st Year');
    $student_id = $conn->real_escape_string(isset($_GET['student_id']) ? $_GET['student_id'] : '');
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    $old_prog = $conn->real_escape_string(isset($_GET['old_program']) ? $_GET['old_program'] : '');

    $pid_q = $conn->query("SELECT program_id FROM programs WHERE program_name = '$prog'");
    $html = '';

    if ($pid_q && $pid_q->num_rows > 0) {
        $pid = $pid_q->fetch_assoc()['program_id'];

        // --- 1. REGULAR (STANDARD AUTO ROUTINE) ---
        if ($eval_status === 'Regular') {
            $subj_q = $conn->query("SELECT * FROM prospectus WHERE program_id = $pid AND year_level = '$eval_year' AND semester = '$safe_sem' AND IFNULL(is_archived,0)=0 ORDER BY course_code");

            $html .= '<div class="bg-indigo-50 border-l-[3px] border-indigo-500 p-3 rounded shadow-sm mb-4 flex items-start gap-2">
                        <div class="bg-indigo-100 p-1 rounded-full text-indigo-600 shrink-0 mt-0.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                        <div>
                            <h4 class="text-[9px] font-black uppercase tracking-widest text-indigo-900 mb-0.5">Standard Routine Activated</h4>
                            <p class="text-[9px] text-indigo-800 font-medium leading-tight">Student gets standard subjects for <strong>' . htmlspecialchars($eval_year) . ' - ' . htmlspecialchars($safe_sem) . '</strong>. Auto-Sectioning enabled.</p>
                        </div>
                      </div>';

            if ($subj_q && $subj_q->num_rows > 0) {
                $html .= "<div class='overflow-x-auto border border-slate-300 rounded shadow-sm bg-white'>";
                $html .= "  <table class='w-full text-left border-collapse min-w-[500px]'>";
                $html .= "    <thead class='bg-slate-100 text-[8px] uppercase tracking-widest text-indigo-900 border-b border-slate-300'>";
                $html .= "      <tr>";
                $html .= "        <th class='p-1.5 text-center border-r border-slate-300 w-8'>#</th>";                
                $html .= "        <th class='p-1.5 border-r border-slate-300 w-24'>Code</th>";
                $html .= "        <th class='p-1.5 border-r border-slate-300'>Descriptive Title</th>";
                $html .= "        <th class='p-1.5 border-r border-slate-300 w-12 text-center'>Units</th>";
                $html .= "        <th class='p-1.5 w-24 text-center'>Status</th>";
                $html .= "      </tr>";
                $html .= "    </thead>";
                $html .= "    <tbody class='divide-y divide-slate-200'>";

                while ($s = $subj_q->fetch_assoc()) {
                    $html .= "      <tr class='bg-indigo-50/20'>";
                    $html .= "        <td class='p-1.5 text-center border-r border-slate-300'><svg class='w-3 h-3 mx-auto text-indigo-500' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M5 13l4 4L19 7'/></svg></td>";
                    $html .= "        <td class='p-1.5 font-black text-indigo-900 border-r border-slate-300 text-[9px] uppercase tracking-wider'>{$s['course_code']}</td>";
                    $html .= "        <td class='p-1.5 text-slate-700 font-semibold border-r border-slate-300 text-[9px] leading-snug'>".htmlspecialchars($s['descriptive_title'])."</td>";
                    $html .= "        <td class='p-1.5 text-center font-black text-indigo-600 border-r border-slate-300 text-[9px]'>{$s['units']}</td>";
                    $html .= "        <td class='p-1.5 text-center align-middle'><div class='text-[8px] inline-block font-black uppercase tracking-widest text-indigo-700 bg-indigo-100 border border-indigo-200 rounded py-0.5 px-1 shadow-sm'>✓ AUTO</div></td>";
                    $html .= "      </tr>";
                }
                $html .= "    </tbody>";
                $html .= "  </table>";
                $html .= "</div>";
            } else {
                $html .= "<div class='text-center p-4 bg-rose-50 border border-rose-200 rounded text-rose-500 text-[9px] font-bold uppercase tracking-widest'>No subjects configured for {$eval_year} - {$safe_sem}.</div>";
            }

        // --- 2. SHIFTER (DUAL-BOX COMPARISON & CREDIT MAPPING) ---
        } elseif ($eval_status === 'Shifter') {
            
            // 2.A Collect subjects already taken in current/previous program
            $taken_subjects = [];
            $taken_grades = [];

            if ($user_id > 0) {
                $t_q1 = $conn->query("
                    SELECT p.course_code, p.descriptive_title, p.units, e.grade, er.year_level, er.semester, er.school_year, er.program 
                    FROM enrollments e 
                    JOIN prospectus p ON e.subject_id = p.id 
                    JOIN enrollment_requests er ON e.enrollment_request_id = er.id
                    WHERE e.student_id = $user_id
                    ORDER BY er.school_year ASC, er.semester ASC, p.course_code ASC
                ");
                if ($t_q1) {
                    while ($r = $t_q1->fetch_assoc()) {
                        $code_key = strtoupper(trim($r['course_code']));
                        $taken_subjects[$code_key] = $r;
                        if (!empty($r['grade']) && $r['grade'] !== 'Not Taken') {
                            $taken_grades[$code_key] = trim($r['grade']);
                        }
                    }
                }
            }

            if (!empty($student_id)) {
                $t_q2 = $conn->query("
                    SELECT sg.subject_code as course_code, sg.subject_description as descriptive_title, sg.units, sg.grade, 
                           COALESCE(p.year_level, 'Credited') as year_level, COALESCE(sg.semester, 'N/A') as semester, 
                           sg.school_year, 'Credited/External' as program
                    FROM student_grades sg 
                    LEFT JOIN prospectus p ON sg.subject_code = p.course_code 
                    WHERE sg.student_id = '$student_id'
                ");
                if ($t_q2) {
                    while ($r = $t_q2->fetch_assoc()) {
                        $code_key = strtoupper(trim($r['course_code']));
                        if (!isset($taken_subjects[$code_key])) {
                            $taken_subjects[$code_key] = $r;
                        }
                        if (!empty($r['grade']) && $r['grade'] !== 'Not Taken') {
                            $taken_grades[$code_key] = trim($r['grade']);
                        }
                    }
                }
            }

            // 2.B Collect Target New Program Curriculum
            $new_subj_q = $conn->query("SELECT * FROM prospectus WHERE program_id = $pid AND IFNULL(is_archived,0)=0 ORDER BY year_level, semester, course_code");
            $new_curr = [];
            if ($new_subj_q) {
                while ($s = $new_subj_q->fetch_assoc()) {
                    $new_curr[$s['year_level']][$s['semester']][] = $s;
                }
            }

            $current_display_prog = !empty($old_prog) ? $old_prog : 'Academic History';
            $box1_title = 'Origin Program';

            $html .= '<div class="bg-indigo-50 border-l-[3px] border-indigo-600 p-3 rounded shadow-sm mb-4">
                        <h4 class="text-[10px] font-black uppercase tracking-widest text-indigo-900 mb-0.5">Program Shifting Credit & Curriculum Evaluation</h4>
                        <p class="text-[9px] text-indigo-800 font-medium">Box 1 displays completed subjects and grades. Box 2 matches subjects for the target program. Pre-credited subjects with passed grades are automatically recognized.</p>
                      </div>';

            $html .= '<div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">';

            // --- BOX 1: TAKEN SUBJECTS & GRADES (LEFT BOX) ---
            $html .= '<div class="lg:col-span-5 bg-white rounded-lg border border-slate-300 shadow-sm overflow-hidden flex flex-col">';
            $html .= '  <div class="bg-slate-100 border-b border-slate-300 px-3 py-2 flex justify-between items-center">';
            $html .= '    <div>';
            $html .= '      <span class="text-[7px] font-black uppercase tracking-widest text-slate-400 block">' . $box1_title . '</span>';
            $html .= '      <h4 class="text-[10px] font-black uppercase text-[#00205b] truncate max-w-[220px]" title="'.htmlspecialchars($current_display_prog).'">'.htmlspecialchars($current_display_prog).'</h4>';
            $html .= '    </div>';
            $html .= '    <span class="text-[8px] font-black uppercase tracking-wider bg-slate-200 text-slate-700 px-2 py-0.5 rounded shadow-xs">'.count($taken_subjects).' Subjects Taken</span>';
            $html .= '  </div>';

            $html .= '  <div class="p-2 overflow-y-auto max-h-[520px] custom-scrollbar bg-slate-50">';
            if (empty($taken_subjects)) {
                $html .= "<div class='text-center py-12 text-slate-400 italic text-[9px] font-bold uppercase tracking-widest'>No completed subject records found.</div>";
            } else {
                $html .= "    <table class='w-full text-left border-collapse text-[9px]'>";
                $html .= "      <thead class='bg-slate-100 uppercase tracking-widest text-slate-600 border-b border-slate-300 text-[8px] sticky top-0 font-black z-10'>";
                $html .= "        <tr>";
                $html .= "          <th class='p-1.5 border-r border-slate-300'>Code</th>";
                $html .= "          <th class='p-1.5 border-r border-slate-300'>Description</th>";
                $html .= "          <th class='p-1.5 border-r border-slate-300 text-center w-8'>Units</th>";
                $html .= "          <th class='p-1.5 text-center w-14'>Grade</th>";
                $html .= "        </tr>";
                $html .= "      </thead>";
                $html .= "      <tbody class='divide-y divide-slate-200 bg-white font-medium'>";

                foreach ($taken_subjects as $code => $ts) {
                    $g = trim($ts['grade'] ?? '');
                    $g_disp = ($g !== '' && $g !== 'Not Taken') ? $g : '-';
                    $g_color = 'text-slate-400';
                    $status_badge = '';
                    $is_failed = false;

                    if ($g_disp !== '-') {
                        $gf = (float)$g_disp;
                        if ($gf > 0 && $gf <= 3.0) {
                            $g_color = 'text-emerald-700 font-black';
                            $status_badge = "<span class='text-[7px] font-black uppercase text-emerald-700 bg-emerald-50 border border-emerald-200 px-1 py-0.5 rounded ml-1'>PASS</span>";
                        } else {
                            $is_failed = true;
                            $g_color = 'text-rose-600 font-black';
                            $status_badge = "<span class='text-[7px] font-black uppercase text-rose-700 bg-rose-50 border border-rose-200 px-1 py-0.5 rounded ml-1' data-original-text='FAIL'>FAIL</span>";
                        }
                    }

                    $code_clean = preg_replace('/[^a-zA-Z0-9]/', '', $ts['course_code']);
                    $row_class = $is_failed ? "bg-rose-50 border-l-[3px] border-rose-500 history-failed-row history-row-{$code_clean}" : "hover:bg-slate-50 history-row-{$code_clean} transition-colors";

                    $html .= "        <tr id='history-row-{$code_clean}' class='{$row_class}'>";
                    $html .= "          <td class='p-1.5 font-black text-indigo-900 border-r border-slate-200'>".htmlspecialchars($ts['course_code'])."</td>";
                    $html .= "          <td class='p-1.5 text-slate-700 leading-tight border-r border-slate-200'>";
                    $html .= "            <div>".htmlspecialchars($ts['descriptive_title'])."</div>";
                    $html .= "            <div class='text-[7px] text-slate-400 mt-0.5 font-bold uppercase tracking-wider'>".htmlspecialchars($ts['year_level'])." - ".htmlspecialchars($ts['semester'])."</div>";
                    $html .= "          </td>";
                    $html .= "          <td class='p-1.5 text-center text-slate-600 border-r border-slate-200 font-bold'>".htmlspecialchars($ts['units'])."</td>";
                    $html .= "          <td class='p-1.5 text-center font-mono {$g_color}'>{$g_disp} {$status_badge}</td>";
                    $html .= "        </tr>";
                }
                $html .= "      </tbody>";
                $html .= "    </table>";
            }
            $html .= '  </div>';
            $html .= '</div>';

            // --- BOX 2: TARGET NEW PROGRAM SELECTION (RIGHT BOX) ---
            $html .= '<div class="lg:col-span-7 bg-white rounded-lg border border-indigo-300 shadow-sm overflow-hidden flex flex-col">';
            $html .= '  <div class="bg-indigo-900 border-b border-indigo-950 px-3 py-2 flex justify-between items-center text-white">';
            $html .= '    <div>';
            $html .= '      <span class="text-[7px] font-black uppercase tracking-widest text-indigo-300 block">Target Program Prospectus</span>';
            $html .= '      <h4 class="text-[10px] font-black uppercase text-white truncate max-w-[280px]" title="'.htmlspecialchars($prog).'">'.htmlspecialchars($prog).'</h4>';
            $html .= '    </div>';
            $html .= '    <span class="text-[8px] font-black uppercase tracking-wider bg-indigo-800 text-indigo-100 px-2 py-0.5 rounded shadow-xs">Credit & Enrollment Selector</span>';
            $html .= '  </div>';

            $html .= '  <div class="p-2 overflow-y-auto max-h-[520px] custom-scrollbar bg-slate-50 space-y-3">';

            $s_idx = 0;
            $target_term_html = '';
            $other_terms_html = '';

            foreach ($new_curr as $yl => $sems) {
                foreach ($sems as $sem => $subjs) {
                    $s_idx++;
                    $group_class = "shifter-group-{$s_idx}";
                    $is_target_term = ($yl === $eval_year && $sem === $safe_sem);

                    $term_html = "<div class='bg-white rounded border border-slate-300 shadow-sm overflow-hidden mb-3'>";
                    $term_html .= "  <div class='bg-slate-100 border-b border-slate-300 px-2.5 py-1.5 flex justify-between items-center'>";
                    $term_html .= "    <h5 class='font-black text-indigo-900 text-[9px] uppercase tracking-wider flex items-center gap-1.5'>$yl - $sem</h5>";
                    $term_html .= "    <div class='flex gap-1'>";
                    $term_html .= "      <button type='button' onclick=\"window.toggleSubjectGroup('.$group_class', true)\" class='text-[7px] font-bold uppercase tracking-wider text-indigo-800 bg-indigo-100 hover:bg-indigo-200 px-1.5 py-0.5 rounded border border-indigo-200 cursor-pointer'>All</button>";
                    $term_html .= "      <button type='button' onclick=\"window.toggleSubjectGroup('.$group_class', false)\" class='text-[7px] font-bold uppercase tracking-wider text-slate-600 bg-slate-200 hover:bg-slate-300 px-1.5 py-0.5 rounded border border-slate-300 cursor-pointer'>Clear</button>";
                    $term_html .= "    </div>";
                    $term_html .= "  </div>";

                    $term_html .= "  <table class='w-full text-left border-collapse text-[9px]'>";
                    $term_html .= "    <thead class='bg-slate-50 uppercase tracking-widest text-slate-500 border-b border-slate-200 text-[8px] font-black'>";
                    $term_html .= "      <tr>";
                    $term_html .= "        <th class='p-1 text-center w-7 border-r border-slate-200'>Take</th>";
                    $term_html .= "        <th class='p-1 border-r border-slate-200 w-16'>Code</th>";
                    $term_html .= "        <th class='p-1 border-r border-slate-200'>Descriptive Title</th>";
                    $term_html .= "        <th class='p-1 border-r border-slate-200 text-center w-8'>Units</th>";
                    $term_html .= "        <th class='p-1 text-center w-28'>Credit / Grade</th>";
                    $term_html .= "      </tr>";
                    $term_html .= "    </thead>";
                    $term_html .= "    <tbody class='divide-y divide-slate-200 font-medium'>";

                    foreach ($subjs as $s) {
                        $code_raw = strtoupper(trim($s['course_code']));
                        $code_clean = preg_replace('/[^a-zA-Z0-9]/', '', $code_raw);
                        $already_taken = isset($taken_grades[$code_raw]);
                        $prev_grade = $already_taken ? $taken_grades[$code_raw] : '';
                        $is_passed = false;
                        $is_failed = false;

                        if ($already_taken && is_numeric($prev_grade)) {
                            if ((float)$prev_grade <= 3.0) {
                                $is_passed = true;
                            } else {
                                $is_failed = true;
                            }
                        }

                        // Auto-check if not passed and in the target term
                        $is_checked = (!$is_passed && $is_target_term);

                        $term_html .= "      <tr class='hover:bg-indigo-50/40 transition-colors group cursor-pointer' onclick=\"if(event.target.type !== 'checkbox' && event.target.tagName !== 'INPUT') { let cb = this.querySelector('input[type=checkbox]'); cb.checked = !cb.checked; window.updateSubjectCardStyle(cb); }\">";
                        $term_html .= "        <td class='p-1 text-center border-r border-slate-200 bg-slate-50'>";
                        $term_html .= "          <input type='checkbox' name='irregular_subjects[]' value='{$s['id']}' data-subject-code='{$code_clean}' ".($is_checked ? 'checked' : '')." class='w-3.5 h-3.5 text-indigo-600 rounded border-slate-400 focus:ring-indigo-500 $group_class cursor-pointer shadow-inner' onchange='window.updateSubjectCardStyle(this)'>";
                        $term_html .= "        </td>";
                        $term_html .= "        <td class='p-1 font-black text-indigo-900 border-r border-slate-200 uppercase'>{$s['course_code']}</td>";
                        $term_html .= "        <td class='p-1 text-slate-700 leading-tight border-r border-slate-200'>".htmlspecialchars($s['descriptive_title'])."</td>";
                        $term_html .= "        <td class='p-1 text-center font-bold text-slate-600 border-r border-slate-200'>{$s['units']}</td>";
                        $term_html .= "        <td class='p-1 text-center align-middle'>";
                        $term_html .= "          <div class='".($is_checked ? 'inline-block' : 'hidden')." text-[7px] font-black uppercase tracking-widest text-emerald-700 bg-emerald-100 border border-emerald-200 rounded py-0.5 px-1 badge-enrolling shadow-xs'>✓ ENROLLING</div>";
                        $term_html .= "          <div class='".($is_checked ? 'hidden' : 'block')." badge-credited'>";
                        
                        if ($is_passed) {
                            $term_html .= "            <div class='text-[7px] inline-block font-black uppercase tracking-widest text-emerald-700 bg-emerald-50 border border-emerald-200 rounded px-1 mb-0.5'>MATCHED: {$prev_grade}</div>";
                        } elseif ($is_failed) {
                            $term_html .= "            <div class='text-[7px] inline-block font-black uppercase tracking-widest text-rose-700 bg-rose-50 border border-rose-200 rounded px-1 mb-0.5' title='Must retake this subject'>FAILED PREV: {$prev_grade}</div>";
                        }

                        $term_html .= "            <input type='text' name='credited_grades[{$s['course_code']}]' value='".($is_passed ? htmlspecialchars($prev_grade) : '')."' placeholder='Grade' class='w-full max-w-[45px] mx-auto block text-center text-[8px] font-bold border border-slate-300 rounded px-1 py-0.5 focus:border-indigo-500 focus:outline-none shadow-inner grade-input' onclick='event.stopPropagation();' oninput='window.isFormDirty=true;'>";
                        $term_html .= "            <input type='hidden' name='credited_desc[{$s['course_code']}]' value='".htmlspecialchars($s['descriptive_title'], ENT_QUOTES)."'>";
                        $term_html .= "            <input type='hidden' name='credited_units[{$s['course_code']}]' value='{$s['units']}'>";
                        $term_html .= "          </div>";
                        $term_html .= "        </td>";
                        $term_html .= "      </tr>";
                    }

                    $term_html .= "    </tbody>";
                    $term_html .= "  </table>";
                    $term_html .= "</div>";

                    if ($is_target_term) {
                        $target_term_html .= $term_html;
                    } else {
                        $other_terms_html .= $term_html;
                    }
                }
            }

            if (empty($target_term_html)) {
                $html .= "<div class='text-center p-4 bg-rose-50 border border-rose-200 rounded text-rose-500 text-[9px] font-bold uppercase tracking-widest mb-4'>Target term ($eval_year - $safe_sem) not found in curriculum.</div>";
            } else {
                $html .= $target_term_html;
            }

            $html .= '    <div class="mt-2 text-center">';
            $html .= '      <button type="button" onclick="document.getElementById(\'full_curr_overlay\').classList.remove(\'hidden\'); document.getElementById(\'full_curr_overlay\').classList.add(\'flex\');" class="bg-indigo-900 hover:bg-indigo-950 text-white text-[8px] font-black uppercase tracking-widest py-1.5 px-4 rounded shadow-sm transition-colors cursor-pointer w-full flex items-center justify-center gap-1.5"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg> View Master Prospectus (Other Terms)</button>';
            $html .= '    </div>';

            // FULL PROSPECTUS OVERLAY
            $html .= '<div id="full_curr_overlay" class="hidden absolute inset-0 z-[150] bg-slate-900/80 backdrop-blur-sm rounded-xl items-center justify-center p-2 md:p-4 transition-opacity">';
            $html .= '  <div class="bg-slate-50 w-full h-full max-h-full rounded-xl shadow-2xl flex flex-col border-t-[4px] border-t-indigo-900 overflow-hidden">';
            $html .= '    <div class="p-3 border-b border-slate-200 bg-white flex justify-between items-center shrink-0">';
            $html .= '       <h3 class="font-black text-indigo-900 uppercase tracking-widest text-[10px] flex items-center gap-1.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477-4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg> Master Prospectus</h3>';
            $html .= '       <button type="button" onclick="document.getElementById(\'full_curr_overlay\').classList.add(\'hidden\'); document.getElementById(\'full_curr_overlay\').classList.remove(\'flex\');" class="bg-white border border-slate-200 hover:bg-rose-50 text-slate-500 hover:text-rose-600 p-1 rounded transition-colors cursor-pointer"><svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>';
            $html .= '    </div>';
            $html .= '    <div class="p-4 overflow-y-auto custom-scrollbar flex-1">';
            if (empty($other_terms_html)) {
                $html .= "<div class='text-center p-6 text-slate-400 text-[9px] font-bold uppercase tracking-widest'>No other terms available.</div>";
            } else {
                $html .= $other_terms_html;
            }
            $html .= '    </div>';
            $html .= '    <div class="p-3 border-t border-slate-200 bg-white shrink-0 text-right">';
            $html .= '       <button type="button" onclick="document.getElementById(\'full_curr_overlay\').classList.add(\'hidden\'); document.getElementById(\'full_curr_overlay\').classList.remove(\'flex\');" class="bg-indigo-900 text-white px-5 py-1.5 rounded text-[9px] font-bold uppercase tracking-widest shadow-sm hover:bg-indigo-950 transition-colors cursor-pointer">Done</button>';
            $html .= '    </div>';
            $html .= '  </div>';
            $html .= '</div>';

            $html .= '  </div>';
            $html .= '</div>';
            $html .= '</div>';

        // --- 3. IRREGULAR (SHOPPING CART) ---
        } elseif ($eval_status === 'Irregular' || $eval_status === 'Manual_Irregular' || $eval_status === 'Manual_Regular') {
            
            // Collect taken subjects & grades
            $taken_grades = [];
            if ($user_id > 0) {
                $t_q1 = $conn->query("
                    SELECT p.course_code, e.grade 
                    FROM enrollments e 
                    JOIN prospectus p ON e.subject_id = p.id 
                    WHERE e.student_id = $user_id
                ");
                if ($t_q1) {
                    while ($r = $t_q1->fetch_assoc()) {
                        $code_key = strtoupper(trim($r['course_code']));
                        if (!empty($r['grade']) && $r['grade'] !== 'Not Taken') {
                            $taken_grades[$code_key] = trim($r['grade']);
                        }
                    }
                }
            }
            if (!empty($student_id)) {
                $t_q2 = $conn->query("
                    SELECT subject_code as course_code, grade 
                    FROM student_grades 
                    WHERE student_id = '$student_id'
                ");
                if ($t_q2) {
                    while ($r = $t_q2->fetch_assoc()) {
                        $code_key = strtoupper(trim($r['course_code']));
                        if (!empty($r['grade']) && $r['grade'] !== 'Not Taken') {
                            $taken_grades[$code_key] = trim($r['grade']);
                        }
                    }
                }
            }

            // Collect Target Program Curriculum
            $new_subj_q = $conn->query("SELECT * FROM prospectus WHERE program_id = $pid AND IFNULL(is_archived,0)=0 ORDER BY year_level, semester, course_code");
            $curr = [];
            if ($new_subj_q) {
                while ($s = $new_subj_q->fetch_assoc()) {
                    $curr[$s['year_level']][$s['semester']][] = $s;
                }
            }

            $html .= '<div class="bg-indigo-50 border-l-[3px] border-indigo-600 p-3 rounded shadow-sm mb-4">
                        <h4 class="text-[10px] font-black uppercase tracking-widest text-indigo-900 mb-0.5">Manual Irregular Setup (Shopping Cart)</h4>
                        <p class="text-[9px] text-indigo-800 font-medium">Select subjects from the master curriculum on the left. Failed subjects (5.00) are highlighted in red. Selected subjects will appear in your cart on the right.</p>
                      </div>';

            $html .= '<div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">';

            // --- LEFT BOX: Full Curriculum with checkboxes ---
            $html .= '<div class="lg:col-span-8 bg-white rounded-lg border border-slate-300 shadow-sm overflow-hidden flex flex-col">';
            $html .= '  <div class="bg-slate-100 border-b border-slate-300 px-3 py-2 flex justify-between items-center">';
            $html .= '    <div><span class="text-[7px] font-black uppercase tracking-widest text-slate-400 block">Master Curriculum</span><h4 class="text-[10px] font-black uppercase text-[#00205b]">'.htmlspecialchars($prog).'</h4></div>';
            $html .= '  </div>';
            $html .= '  <div class="p-2 overflow-y-auto max-h-[600px] custom-scrollbar bg-slate-50 space-y-3">';

            foreach ($curr as $yl => $sems) {
                foreach ($sems as $sem => $subjs) {
                    $html .= "<div class='bg-white rounded border border-slate-300 shadow-sm overflow-hidden mb-3'>";
                    $html .= "  <div class='bg-slate-100 border-b border-slate-300 px-2.5 py-1.5 flex justify-between items-center'><h5 class='font-black text-indigo-900 text-[9px] uppercase tracking-wider'>$yl - $sem</h5></div>";
                    $html .= "  <table class='w-full text-left border-collapse text-[9px]'>";
                    $html .= "    <thead class='bg-slate-50 uppercase tracking-widest text-slate-500 border-b border-slate-200 text-[8px] font-black'>";
                    $html .= "      <tr><th class='p-1 text-center w-7 border-r border-slate-200'>Take</th><th class='p-1 border-r border-slate-200 w-16'>Code</th><th class='p-1 border-r border-slate-200'>Descriptive Title</th><th class='p-1 border-r border-slate-200 text-center w-8'>Units</th><th class='p-1 text-center w-16'>Grade</th></tr>";
                    $html .= "    </thead>";
                    $html .= "    <tbody class='divide-y divide-slate-200 font-medium'>";
                    
                    foreach ($subjs as $s) {
                        $code_raw = strtoupper(trim($s['course_code']));
                        $code_clean = preg_replace('/[^a-zA-Z0-9]/', '', $code_raw);
                        $prev_grade = isset($taken_grades[$code_raw]) ? $taken_grades[$code_raw] : '';
                        
                        $is_passed = false;
                        $is_failed = false;
                        if ($prev_grade !== '' && is_numeric($prev_grade)) {
                            if ((float)$prev_grade <= 3.0) $is_passed = true;
                            else $is_failed = true;
                        }

                        $row_class = 'hover:bg-slate-50 transition-colors';
                        $grade_disp = '-';
                        if ($is_passed) {
                            $grade_disp = "<span class='text-emerald-700 font-black'>{$prev_grade}</span>";
                        } elseif ($is_failed) {
                            $grade_disp = "<span class='text-rose-700 font-black px-1.5 py-0.5 bg-rose-100 rounded' data-original-text='{$prev_grade}'>{$prev_grade}</span>";
                            $row_class = "bg-rose-50 border-l-[3px] border-rose-500 irregular-failed-row irregular-row-{$code_clean}";
                        }

                        $json_data = htmlspecialchars(json_encode([
                            'id' => $s['id'],
                            'code' => $s['course_code'],
                            'title' => $s['descriptive_title'],
                            'units' => $s['units']
                        ]), ENT_QUOTES, 'UTF-8');

                        $html .= "      <tr id='irregular-row-{$code_clean}' class='{$row_class} group cursor-pointer' onclick=\"if(event.target.type !== 'checkbox') { let cb = this.querySelector('input[type=checkbox]'); cb.checked = !cb.checked; window.updateCart(cb); }\">";
                        $html .= "        <td class='p-1 text-center border-r border-slate-200 bg-slate-50'><input type='checkbox' name='irregular_subjects[]' value='{$s['id']}' data-subject='{$json_data}' data-subject-code='{$code_clean}' class='w-3.5 h-3.5 text-indigo-600 rounded border-slate-400 focus:ring-indigo-500 cart-checkbox cursor-pointer shadow-inner' onchange='window.updateCart(this)'></td>";
                        $html .= "        <td class='p-1 font-black text-indigo-900 border-r border-slate-200 uppercase'>{$s['course_code']}</td>";
                        $html .= "        <td class='p-1 text-slate-700 leading-tight border-r border-slate-200'>".htmlspecialchars($s['descriptive_title'])."</td>";
                        $html .= "        <td class='p-1 text-center font-bold text-slate-600 border-r border-slate-200'>{$s['units']}</td>";
                        $html .= "        <td class='p-1 text-center align-middle'>{$grade_disp}</td>";
                        $html .= "      </tr>";
                    }
                    $html .= "    </tbody>";
                    $html .= "  </table>";
                    $html .= "</div>";
                }
            }
            $html .= '  </div>';
            $html .= '</div>';

            // --- RIGHT BOX: Selected Subjects Cart ---
            $html .= '<div class="lg:col-span-4 bg-white rounded-lg border border-emerald-300 shadow-sm overflow-hidden flex flex-col h-[600px]">';
            $html .= '  <div class="bg-emerald-700 border-b border-emerald-800 px-3 py-2 flex justify-between items-center text-white">';
            $html .= '    <div><span class="text-[7px] font-black uppercase tracking-widest text-emerald-200 block">Selected Subjects</span><h4 class="text-[10px] font-black uppercase text-white">Enrollment Cart</h4></div>';
            $html .= '    <span id="cart-total-units" class="text-[8px] font-black uppercase tracking-wider bg-emerald-800 text-white px-2 py-0.5 rounded shadow-xs">0 Units</span>';
            $html .= '  </div>';
            $html .= '  <div class="p-2 overflow-y-auto flex-1 custom-scrollbar bg-slate-50 flex flex-col relative">';
            $html .= '    <div id="shopping-cart-empty" class="text-center py-12 text-slate-400 italic text-[9px] font-bold uppercase tracking-widest my-auto flex flex-col items-center gap-2"><svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg> Cart is empty.<br>Check subjects to add them here.</div>';
            $html .= '    <ul id="shopping-cart-list" class="space-y-2 hidden"></ul>';
            $html .= '  </div>';
            $html .= '</div>';

            $html .= '</div>';
        }
    }

    if (empty($html)) $html = "<div class='text-center p-4 bg-rose-50 border border-rose-200 rounded text-rose-500 text-[9px] font-bold uppercase tracking-widest'>No curriculum found for this program.</div>";

    header('Content-Type: application/json');
    echo json_encode(['html' => $html]);
    exit();
}

// =========================================================
// DATA FETCHING: UNIFIED ARCHIVE & QUEUE
// =========================================================
function fetchEvaluationQueue($conn, $safe_sem, $f_program, $f_year, $safe_sy) {
    // Fast pre-load of all completed Official CORs grouped by user_id
    $cors_by_user = [];
    $cor_res = $conn->query("SELECT user_id, year_level, semester, school_year FROM official_student_cors ORDER BY school_year ASC, semester ASC");
    if ($cor_res) {
        while ($c = $cor_res->fetch_assoc()) {
            $cors_by_user[$c['user_id']][] = $c;
        }
    }

    $online_sql = "
        SELECT er.id as record_id, 'online' as record_type, er.program, er.year_level, er.learning_mode, 
               up.*, u.student_id as identifier, er.created_at as sort_date, u.email as personal_email,
               up.gender as u_gender, er.final_status, u.id as user_internal_id,
               IF(er.final_status='Enrolled', 1, 0) as registrar_evaluated,
               (SELECT uploaded_files FROM admissions WHERE email = u.email OR institutional_email = u.email ORDER BY id DESC LIMIT 1) as uploaded_files,
               (SELECT influence_source FROM admissions WHERE email = u.email OR institutional_email = u.email ORDER BY id DESC LIMIT 1) as influence_source,
               (SELECT institutional_email FROM admissions WHERE email = u.email OR institutional_email = u.email ORDER BY id DESC LIMIT 1) as institutional_email,
               er.school_year as record_sy,
               er.semester as record_sem,
               er.student_status as final_student_status,
               er.shifting_to
        FROM enrollment_requests er 
        JOIN users u ON er.user_id = u.id 
        JOIN user_profiles up ON u.id = up.user_id 
        WHERE er.final_status IN ('Pending Registrar', 'Enrolled') 
        AND er.semester = '" . $conn->real_escape_string($safe_sem) . "'
    ";

    if ($f_program !== 'All') {
        $online_sql .= " AND er.program = '" . $conn->real_escape_string($f_program) . "'";
    }
    if ($f_year !== 'All') {
        $online_sql .= " AND er.school_year = '" . $conn->real_escape_string($f_year) . "'";
    }

    $online_sql .= " GROUP BY er.id"; 

    $new_sql = "
        SELECT a.id as record_id, 'walkin' as record_type, a.program, '1st Year' as year_level, NULL as learning_mode,
               a.*, a.admission_number as identifier, a.created_at as sort_date, a.email as personal_email,
               a.gender as u_gender, a.provisioned_user_id as user_internal_id,
               (SELECT academic_year FROM exam_results WHERE student_id = a.admission_number ORDER BY id DESC LIMIT 1) as record_sy,
               a.enrollment_term as record_sem,
               a.evaluated_status as final_student_status,
               NULL as shifting_to
        FROM admissions a 
        WHERE a.pushed_to_accounting = 1 AND a.pushed_to_registrar = 1 
        AND a.enrollment_term LIKE '%" . $conn->real_escape_string($safe_sem) . "%'
    ";

    if ($f_program !== 'All') {
        $new_sql .= " AND a.program = '" . $conn->real_escape_string($f_program) . "'";
    }
    if ($f_year !== 'All') {
        $new_sql .= " AND a.admission_number IN (SELECT student_id FROM exam_results WHERE academic_year = '" . $conn->real_escape_string($f_year) . "')";
    }

    $new_sql .= " GROUP BY a.id";

    $combined = [];
    $online = $conn->query($online_sql);
    $new = $conn->query($new_sql);

    if ($online) { while ($r = $online->fetch_assoc()) { $combined[] = $r; } }
    if ($new) { while ($r = $new->fetch_assoc()) { $combined[] = $r; } }

    $unique_combined = [];
    $seen_ids = [];

    foreach ($combined as $item) {
        $id = $item['identifier'];
        if (!isset($seen_ids[$id])) {
            $seen_ids[$id] = true;

            $u_id = (int)($item['user_internal_id'] ?? 0);
            $user_cors = $cors_by_user[$u_id] ?? [];
            $progression = calculateStudentProgression($user_cors, $safe_sem);

            $item['current_standing'] = $progression['current_standing'];
            $item['target_year'] = $progression['target_year'];
            $item['target_sem'] = $progression['target_sem'];
            $item['target_term'] = $progression['target_term'];
            $item['has_completed_cors'] = $progression['has_completed'];
            $item['recommended_year'] = $progression['target_year'];

            $unique_combined[] = $item;
        }
    }

    usort($unique_combined, function($a, $b) {
        $timeA = isset($a['sort_date']) ? strtotime($a['sort_date']) : 0;
        $timeB = isset($b['sort_date']) ? strtotime($b['sort_date']) : 0;
        if ($timeB == $timeA) return 0;
        if ($timeB > $timeA) return 1;
        return -1;
    });

    return $unique_combined;
}

function renderUnifiedQueue($queue, $can_edit) {
    ob_start();
    if (count($queue) > 0): 

        foreach ($queue as $row): 
            $first = isset($row['first_name']) ? $row['first_name'] : '';
            $middle = isset($row['middle_name']) ? $row['middle_name'] : '';
            $last = isset($row['last_name']) ? $row['last_name'] . ', ' : '';
            $raw_name = trim($last . $first . ' ' . $middle);
            $applicant_name = htmlspecialchars($raw_name);
            $row['applicant_name'] = $raw_name;

            $is_online = ($row['record_type'] === 'online');
            $submit_date = !empty($row['sort_date']) ? date('M j, y', strtotime($row['sort_date'])) : 'N/A';

            $adm_type = isset($row['admission_type']) ? $row['admission_type'] : (isset($row['student_type']) ? $row['student_type'] : 'New');
            $clean_adm_type = preg_replace('/\s*\(.*?\)\s*/', '', $adm_type);
            $clean_adm_type = trim($clean_adm_type);
            if (empty($clean_adm_type)) $clean_adm_type = 'New';

            $mod = isset($row['learning_mode']) ? $row['learning_mode'] : 'HYBRID';
            $rec_sy = isset($row['record_sy']) ? $row['record_sy'] : 'N/A';
            $prog_disp = isset($row['evaluated_program']) ? $row['evaluated_program'] : (isset($row['program']) ? $row['program'] : 'N/A');
            $year_disp = isset($row['evaluated_year']) ? $row['evaluated_year'] : (isset($row['year_level']) ? $row['year_level'] : 'N/A');

            $current_prog_safe = addslashes($prog_disp);
            $is_evaluated = (!empty($row['registrar_evaluated']) && $row['registrar_evaluated'] == 1);

            $bg_color = $is_evaluated ? 'bg-emerald-50/10' : 'bg-white';
            $row_classes = "hover:bg-indigo-50/40 transition-colors border-b border-slate-200 " . $bg_color;

            $user_internal_id = isset($row['user_internal_id']) ? (int)$row['user_internal_id'] : 0;
            $final_student_status = isset($row['final_student_status']) ? $row['final_student_status'] : 'Regular';
            $shifting_to_safe = !empty($row['shifting_to']) ? $row['shifting_to'] : '';

            $current_standing = $row['current_standing'] ?? 'Incoming New';
            $target_term = $row['target_term'] ?? "$year_disp - $safe_sem";
            $recommended_year = $row['recommended_year'] ?? $year_disp;

            $profile_data = htmlspecialchars(json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

            $eval_data_json = htmlspecialchars(json_encode([
                "id" => $row['record_id'],
                "type" => $row['record_type'],
                "name" => $raw_name,
                "identifier" => $row['identifier'] ?? '',
                "current_program" => $current_prog_safe,
                "shifting_to" => $shifting_to_safe,
                "year" => $year_disp,
                "current_standing" => $current_standing,
                "recommended_year" => $recommended_year,
                "recommended_term" => $target_term,
                "admType" => $clean_adm_type,
                "modality" => $mod,
                "userId" => $user_internal_id,
                "finalStudentStatus" => $final_student_status
            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    ?>
            <tr class="<?= $row_classes ?>">
                <td class="px-2 py-1.5 align-middle border-r border-slate-300 w-[30%] truncate">
                    <div class="flex items-center gap-2">
                        <div class="font-black text-[#00205b] text-[11px] truncate searchable-name" title="<?= $applicant_name ?>"><?= $applicant_name ?></div>
                    </div>
                    <div class="text-[9px] font-mono font-bold text-slate-500 mt-0.5 searchable-id"><?= htmlspecialchars(isset($row['identifier']) ? $row['identifier'] : '') ?></div>
                </td>

                <td class="px-2 py-1.5 align-middle border-r border-slate-300 w-[35%] truncate">
                    <div class="flex items-center gap-1.5 flex-wrap">
                        <span class="text-[10px] font-bold text-[#00205b] uppercase tracking-wide truncate max-w-[220px]" title="<?= htmlspecialchars($prog_disp) ?>">
                            <?= htmlspecialchars($prog_disp) ?>
                        </span>
                        <?php if (!empty($shifting_to_safe) && $shifting_to_safe !== $prog_disp): ?>
                            <span class="bg-amber-100 text-amber-900 border border-amber-300 px-1.5 py-0.5 rounded text-[8px] font-black uppercase tracking-wider shadow-xs flex items-center gap-1">
                                <svg class="w-2.5 h-2.5 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                                Shift: <?= htmlspecialchars($shifting_to_safe) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Dynamic Ascent Indicator: Current Level ➔ Supposed Level -->
                    <div class="flex items-center gap-1.5 mt-1 text-[8px]">
                        <div class="bg-slate-100 border border-slate-300 text-slate-600 px-1.5 py-0.5 rounded font-bold shadow-xs truncate" title="Current Standing: <?= htmlspecialchars($current_standing) ?>">
                            <span class="text-slate-400 uppercase font-black">Current:</span> <?= htmlspecialchars($current_standing) ?>
                        </div>
                        <svg class="w-2.5 h-2.5 text-indigo-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                        <div class="bg-indigo-50 border border-indigo-200 text-indigo-900 px-1.5 py-0.5 rounded font-black shadow-xs truncate" title="Supposed Target Level: <?= htmlspecialchars($target_term) ?>">
                            <span class="text-indigo-500 uppercase">Target:</span> <?= htmlspecialchars($target_term) ?>
                        </div>
                        <span class="text-slate-300 font-bold">•</span>
                        <div class="text-[8px] font-black uppercase tracking-widest text-slate-500 bg-slate-50 border border-slate-200 px-1 py-0.5 rounded-sm"><?= htmlspecialchars($rec_sy) ?></div>
                    </div>
                </td>

                <td class="px-2 py-1.5 align-middle border-r border-slate-300 text-center w-[15%] truncate">
                    <span class="text-[8px] font-bold text-slate-600 tracking-widest block mb-1"><?= $submit_date ?></span>
                    <?php if ($is_evaluated): ?>
                        <div class="text-[7px] font-black uppercase tracking-widest text-emerald-700 bg-emerald-100 border border-emerald-300 px-1.5 py-0.5 rounded shadow-sm w-max mx-auto text-center truncate">Evaluated</div>
                    <?php else: ?>
                        <div class="text-[7px] font-black uppercase tracking-widest text-amber-600 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded shadow-sm w-max mx-auto text-center truncate">Pending</div>
                    <?php endif; ?>
                </td>

                <td class="px-2 py-1.5 align-middle text-center w-[20%]">
                    <div class="flex flex-col gap-1 w-full max-w-[120px] mx-auto">
                        <button type="button" onclick="window.openStudentModal(this)" data-profile="<?= $profile_data ?>" class="bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-300 text-[8px] font-bold uppercase tracking-widest py-1 px-2 rounded shadow-sm transition-colors cursor-pointer w-full flex items-center justify-center gap-1">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> Profile
                        </button>

                        <?php if ($can_edit): ?>
                            <?php if (!$is_evaluated): ?>
                                <button type="button" onclick="window.openEvalModal(this)" data-eval="<?= $eval_data_json ?>" class="bg-[#00205b] hover:bg-[#001233] text-white py-1 px-2 rounded text-[8px] uppercase tracking-widest font-black shadow-sm cursor-pointer transition-colors border border-[#001233] w-full flex items-center justify-center gap-1">
                                    <svg class="w-3 h-3 text-white/70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg> Eval
                                </button>
                            <?php else: ?>
                                <button type="button" onclick="window.revokeEvaluation(<?= $row['record_id'] ?>, '<?= $row['record_type'] ?>')" class="bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 py-1 px-2 rounded text-[8px] uppercase tracking-widest font-black shadow-sm cursor-pointer transition-colors w-full flex items-center justify-center gap-1">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Revoke
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
    <?php 
        endforeach; 

    else: ?>
        <tr><td colspan="4" class="px-4 py-12 text-center text-slate-400 text-[10px] font-bold uppercase tracking-[0.2em] bg-slate-50/50">Queue is empty. No records match your filters.</td></tr>
    <?php 
    endif;
    return ob_get_clean();
}

$queue_data = fetchEvaluationQueue($conn, $safe_sem, $f_program, $f_year, $safe_sy);

if (isset($_GET['api_refresh'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'updates' => [
            'unified_tbody' => renderUnifiedQueue($queue_data, $can_edit),
            'queue_count' => count($queue_data) . " RECORDS FOUND"
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
    <title>Verification Management - LDSP Registrar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css?v=<?= time(); ?>">
    <style>
        .modal-overlay { opacity: 0; transition: opacity 0.2s ease; pointer-events: none; }
        .modal-content { transform: scale(0.95); opacity: 0; transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); pointer-events: none; }
        .modal-active.modal-overlay { opacity: 1; pointer-events: auto; }
        .modal-active .modal-content { transform: scale(1); opacity: 1; pointer-events: auto; }

        .peer:checked ~ div .badge-enrolling { display: block; }
        .peer:checked ~ div .badge-credited { display: none; }
        input[type="checkbox"].peer { cursor: pointer; }
        
        .custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>

<body class="flex h-screen overflow-hidden antialiased bg-[#f4f6f9]">

    <div class="ambient-orb-1"></div>
    <div class="ambient-orb-2"></div>

    <?php include 'sidebar.php'; ?>

    <main id="mainScrollArea" class="flex-1 min-w-0 overflow-y-auto h-full w-full pt-16 md:pt-0 relative z-10 custom-scrollbar">
        <div class="p-4 md:p-6 max-w-[1500px] mx-auto relative z-20 fade-in-up">

            <header class="mb-5 border-b border-slate-300 pb-3 drop-shadow-sm flex justify-between items-end">
                <div>
                    <h1 class="text-2xl md:text-3xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">Verification Management</h1>
                    <p class="text-slate-500 text-xs flex items-center gap-1.5 font-medium mt-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.8)]"></span>
                        Logged in as <?= htmlspecialchars(isset($_SESSION['first_name']) ? $_SESSION['first_name'] : 'Registrar') ?>
                    </p>
                </div>
            </header>

            <div class="grid grid-cols-1 gap-6">
                <!-- EXCEL STYLE GRID QUEUE WITH FILTERS -->
                <div class="bg-white/95 backdrop-blur-md border border-slate-200 rounded-xl overflow-hidden z-20 relative shadow-sm border-t-[4px] border-t-[#00205b]">

                    <div class="px-4 py-3 border-b border-slate-300 bg-slate-50 flex flex-col md:flex-row justify-between md:items-center relative z-20 gap-3">
                        <div>
                            <h3 class="font-black text-[#00205b] text-xs uppercase tracking-widest flex items-center gap-2">
                                <div class="p-1 rounded-sm bg-[#00205b]/10"><svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg></div> 
                                Evaluation Archive & Queue
                            </h3>
                            <p id="queue_count" class="text-[9px] text-slate-500 font-bold mt-1 uppercase tracking-widest drop-shadow-sm ml-7"><?= count($queue_data) ?> RECORDS FOUND</p>
                        </div>

                        <!-- FILTER CONTROLS -->
                        <div class="flex flex-col sm:flex-row items-center gap-2 w-full md:w-auto">

                            <!-- Search -->
                            <div class="relative w-full sm:w-[220px]">
                                <input type="text" id="liveSearch" onkeyup="window.filterTable()" class="w-full py-1.5 pl-7 pr-3 text-[10px] font-semibold text-[#00205b] bg-white border border-slate-300 rounded shadow-sm focus:outline-none focus:border-[#00205b]" placeholder="Search applicant name...">
                                <svg class="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-[7px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            </div>

                            <!-- Program Filter -->
                            <select id="filter_program" onchange="window.triggerDynamicFetch(true)" class="cursor-pointer w-full sm:w-[160px] truncate py-1.5 px-3 text-[10px] font-semibold text-[#00205b] bg-white border border-slate-300 rounded shadow-sm focus:outline-none focus:border-[#00205b] focus:ring-1 focus:ring-[#00205b]/20" title="Filter Program">
                                <option value="All">All Programs</option>
                                <?php foreach ($prog_list as $prog): ?>
                                    <option value="<?= htmlspecialchars($prog) ?>" <?= ($f_program === $prog) ? 'selected' : '' ?>><?= htmlspecialchars($prog) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Academic Year Filter -->
                            <select id="filter_year" onchange="window.handleYearFilterChange(this)" data-last-value="<?= htmlspecialchars($f_year) ?>" class="cursor-pointer w-full sm:w-[130px] py-1.5 px-3 text-[10px] font-semibold text-[#00205b] bg-white border border-slate-300 rounded shadow-sm focus:outline-none focus:border-[#00205b] focus:ring-1 focus:ring-[#00205b]/20">
                                <option value="All" <?= ($f_year === 'All') ? 'selected' : '' ?>>All Years</option>
                                <?php foreach ($recent_years as $yr): ?>
                                    <option value="<?= htmlspecialchars($yr) ?>" <?= ($f_year === $yr) ? 'selected' : '' ?>><?= htmlspecialchars($yr) ?></option>
                                <?php endforeach; ?>

                                <?php if (!array_key_exists($f_year, $recent_years)): ?>
                                    <?php if ($f_year !== 'All'): ?>
                                        <option value="<?= htmlspecialchars($f_year) ?>" selected><?= htmlspecialchars($f_year) ?></option>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if (count($years_list) > 5): ?>
                                    <option value="open_all_years_modal" class="font-bold text-[#c5a02c]">↳ View All Years...</option>
                                <?php endif; ?>
                            </select>

                            <button type="button" onclick="window.resetFilters()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-300 text-[9px] font-bold uppercase tracking-widest py-1.5 px-4 rounded transition-colors w-full sm:w-auto shadow-sm flex items-center justify-center cursor-pointer">Reset</button>
                        </div>
                    </div>

                    <div class="overflow-x-auto min-h-[450px] bg-white relative z-20 custom-scrollbar">
                        <table class="w-full text-left border-collapse text-sm table-fixed border-b border-slate-300">
                            <thead class="bg-slate-100/95 backdrop-blur-sm text-slate-600 text-[9px] uppercase font-black tracking-widest sticky top-0 z-10 shadow-sm border-b-2 border-slate-300">
                                <tr>
                                    <th class="px-2 py-2 border-r border-slate-300 w-[30%] truncate">Applicant Identity</th>
                                    <th class="px-2 py-2 border-r border-slate-300 w-[35%] truncate">Academic Context & Progression</th>
                                    <th class="px-2 py-2 border-r border-slate-300 w-[15%] text-center truncate">Date & Status</th>
                                    <th class="px-2 py-2 border-slate-300 w-[20%] text-center truncate">Evaluation Console</th>
                                </tr>
                            </thead>
                            <tbody id="unified_tbody" class="bg-transparent">
                                <?= renderUnifiedQueue($queue_data, $can_edit) ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- ALL YEARS FLOATING CARD MODAL -->
    <div id="allYearsModal" class="fixed inset-0 z-[200] hidden items-center justify-center p-4 modal-overlay bg-slate-900/50 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-xl shadow-2xl border-t-4 border-t-[#00205b] w-full max-w-sm modal-content relative">
            <div class="px-5 py-3 border-b border-slate-200 flex justify-between items-center bg-white rounded-t-xl">
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
                    <button type="button" id="btn_year_All" onclick="window.selectYearFilter('All')" class="year-btn col-span-2 px-3 py-2 border <?= ($f_year === 'All') ? 'border-[#00205b] bg-blue-50 text-[#00205b]' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' ?> rounded-lg text-[10px] font-bold uppercase tracking-widest transition-colors shadow-sm cursor-pointer">
                        View All Years
                    </button>
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

    <!-- STUDENT PROFILE MODAL -->
    <div id="studentProfileModal" class="fixed inset-0 z-[150] hidden items-center justify-center p-4 modal-overlay bg-slate-900/70 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-xl shadow-2xl border-t-[4px] border-t-[#00205b] w-full max-w-4xl max-h-[95vh] flex flex-col modal-content relative" id="profileModalInner">
            <div class="bg-slate-50 border-b border-slate-200 p-4 flex justify-between items-center relative z-20 sticky top-0 rounded-t-xl shadow-sm">
                <h3 class="font-black text-[#00205b] text-xs uppercase tracking-[0.1em] drop-shadow-sm flex items-center gap-2"><div class="p-1.5 rounded-sm bg-[#00205b]/10"><svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477-4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg></div> Student Profile Records</h3>
                <button type="button" onclick="window.closeStudentModal()" class="text-slate-400 hover:text-rose-500 transition-colors bg-white hover:bg-rose-50 p-1.5 rounded border border-slate-200 focus:outline-none cursor-pointer"><svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
            </div>

            <div class="p-4 md:p-5 flex-1 overflow-y-auto custom-scrollbar bg-slate-50/90 space-y-4">
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

                <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm mt-2">
                    <h4 class="text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-1 mb-2">Submitted Documents</h4>
                    <div id="v_documents_list" class="flex flex-wrap gap-2"></div>
                </div>
            </div>

            <div class="p-3 border-t border-slate-200 bg-white sticky bottom-0 text-right rounded-b-xl z-20 shadow-[0_-4px_10px_rgba(0,0,0,0.02)]">
                <button type="button" onclick="window.closeStudentModal()" class="bg-white border border-slate-300 hover:bg-slate-50 text-slate-600 font-bold uppercase tracking-widest text-[9px] px-5 py-2 rounded transition-colors cursor-pointer shadow-sm">Close Profile</button>
            </div>
        </div>
    </div>

    <!-- EVALUATION MODAL -->
    <div id="evaluationModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 modal-overlay bg-slate-900/70 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-xl shadow-2xl border-t-[4px] border-t-indigo-900 w-full max-w-5xl max-h-[95vh] flex flex-col modal-content relative overflow-hidden">
            
            <div class="px-5 py-3 border-b border-slate-200 bg-slate-50 flex justify-between items-center relative z-20 shadow-sm shrink-0">
                <div class="flex items-center gap-3">
                    <div class="p-1.5 rounded bg-indigo-900/10"><svg class="w-4 h-4 text-indigo-900" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg></div>
                    <h3 class="text-xs font-black text-indigo-900 uppercase tracking-widest drop-shadow-sm">Curriculum Evaluation & Official Roster</h3>
                    <span class="text-slate-300 font-bold mx-1">|</span>
                    <p class="text-[9px] text-slate-500 font-bold uppercase tracking-widest">A.Y. <?= htmlspecialchars(isset($settings['active_school_year']) ? $settings['active_school_year'] : '') ?> • <?= htmlspecialchars($safe_sem) ?></p>
                </div>
                <button type="button" onclick="window.closeEvalModal()" class="text-slate-400 hover:text-rose-500 transition-colors bg-white hover:bg-rose-50 p-1.5 rounded border border-slate-200 focus:outline-none cursor-pointer">
                    <svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <form class="spa-form flex flex-col flex-1 overflow-hidden">
                <input type="hidden" name="evaluate_student" value="1">
                <input type="hidden" name="record_id" id="eval_id">
                <input type="hidden" name="record_type" id="eval_type">
                <input type="hidden" name="student_identifier" id="eval_student_identifier">
                <input type="hidden" name="old_program" id="eval_current_program_hidden">
                <input type="hidden" id="eval_user_internal_id">

                <div class="overflow-y-auto p-4 md:p-5 bg-slate-50/90 custom-scrollbar flex-1 relative z-10 space-y-4">

                    <!-- Compact Identity Card with Image Support -->
                    <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-indigo-900 text-white flex items-center justify-center text-lg font-black shadow-inner overflow-hidden relative shrink-0">
                                <span id="eval_initial">?</span>
                                <img id="eval_profile_pic" src="" class="hidden absolute inset-0 w-full h-full object-cover z-10" alt="Applicant Photo">
                            </div>
                            <div class="min-w-0">
                                <h4 class="text-sm font-black text-indigo-900 tracking-tight uppercase truncate" id="eval_name">NAME</h4>
                                <div class="text-[9px] font-mono text-indigo-600 font-bold tracking-wider truncate" id="eval_identifier">ID</div>
                            </div>
                        </div>

                        <!-- DYNAMIC PROGRESSION ACCORDION BADGE -->
                        <div class="bg-slate-50 border border-slate-200 px-3 py-1.5 rounded-lg flex items-center gap-2 shadow-xs shrink-0">
                            <div class="text-left">
                                <span class="text-[7px] uppercase font-black tracking-widest text-slate-400 block">Completed Standing</span>
                                <span class="text-[10px] font-black text-slate-700" id="eval_standing_text">---</span>
                            </div>
                            <svg class="w-3.5 h-3.5 text-indigo-600 shrink-0 mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                            <div class="text-left">
                                <span class="text-[7px] uppercase font-black tracking-widest text-indigo-500 block">Target Ascent</span>
                                <span class="text-[10px] font-black text-indigo-900" id="eval_target_text">---</span>
                            </div>
                        </div>
                    </div>

                    <!-- PROGRAM SHIFT ALERT -->
                    <div id="eval_shift_alert" class="hidden bg-amber-50 border-l-[3px] border-amber-500 p-3 rounded shadow-sm text-amber-900 items-start gap-2">
                        <svg class="w-4 h-4 shrink-0 text-amber-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                        <div>
                            <h4 class="text-[9px] font-black uppercase tracking-widest mb-0.5">Program Shift Requested</h4>
                            <p class="text-[9px] font-medium leading-tight">Student requested to shift from <strong id="eval_shift_from"></strong> to <strong id="eval_shift_to"></strong>. Evaluation mode has been set to <strong>Program Shifter</strong> below.</p>
                        </div>
                    </div>

                    <!-- UI LOCKOUT BANNER -->
                    <div id="eval_lockout_banner" class="hidden bg-rose-50 border-l-[3px] border-rose-600 p-3 rounded shadow-sm text-rose-800 items-start gap-2">
                        <svg class="w-4 h-4 shrink-0 mt-0.5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <div>
                            <h4 class="text-[9px] font-black uppercase tracking-widest mb-0.5">Evaluation Locked</h4>
                            <p class="text-[9px] font-medium leading-tight">This student already has an Official Digital COR for the current term.</p>
                        </div>
                    </div>

                    <!-- COLLECTED CORS VAULT UI -->
                    <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm hidden" id="eval_cor_vault_wrapper">
                        <h4 class="text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-1.5 mb-2 flex items-center gap-1.5">
                            <svg class="w-3 h-3 text-indigo-900" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Collected Official CORs (Vault)
                        </h4>
                        <div id="eval_collected_cors" class="flex flex-wrap gap-1.5"></div>
                    </div>

                    <!-- ACADEMIC HISTORY -->
                    <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm hidden" id="eval_history_wrapper">
                        <h4 class="text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-1.5 mb-2">Academic History</h4>
                        <div class="overflow-x-auto border border-slate-300 rounded max-h-[180px] custom-scrollbar">
                            <table class="w-full text-left text-xs border-collapse whitespace-nowrap">
                                <thead class="bg-slate-100 text-indigo-900 uppercase text-[8px] font-black tracking-widest sticky top-0 z-10 shadow-sm border-b border-slate-300">
                                    <tr>
                                        <th class="px-4 py-1.5 border-r border-slate-300 w-[15%]">Code</th>
                                        <th class="px-4 py-1.5 border-r border-slate-300 w-[55%]">Subject Description & Context</th>
                                        <th class="px-4 py-1.5 border-r border-slate-300 text-center w-[10%]">Units</th>
                                        <th class="px-4 py-1.5 border-r border-slate-300 text-center w-[15%]">Grade</th>
                                        <?php if($can_edit): ?><th class="px-2 py-1.5 text-center w-[5%]">Act</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody class="text-[9px] font-bold bg-white" id="eval_history_tbody"></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="bg-white p-4 rounded-lg border border-slate-200 shadow-sm">
                        <div id="eval_progress_banner" class="mb-3"></div>

                        <h4 class="text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-1.5 mb-3">Target Profile Setup</h4>

                        <div class="flex flex-col gap-4 mb-4">
                            <div class="grid grid-cols-1 md:grid-cols-12 gap-4 bg-slate-50/50 p-2 rounded border border-slate-100">
                                <div class="md:col-span-4">
                                    <label class="block text-[8px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-0.5">Category <span class="text-rose-500">*</span></label>
                                    <div class="grid grid-cols-2 gap-2">
                                        <label class="flex items-center justify-center gap-1.5 p-1.5 bg-white border border-slate-200 rounded shadow-sm cursor-pointer hover:bg-indigo-50 hover:border-indigo-300 transition-colors">
                                            <input type="radio" name="base_admission_type" value="New" required checked class="w-3.5 h-3.5 text-indigo-600 border-slate-300 focus:ring-indigo-500">
                                            <span class="text-[9px] font-bold text-slate-700 uppercase tracking-widest">New</span>
                                        </label>
                                        <label class="flex items-center justify-center gap-1.5 p-1.5 bg-white border border-slate-200 rounded shadow-sm cursor-pointer hover:bg-indigo-50 hover:border-indigo-300 transition-colors">
                                            <input type="radio" name="base_admission_type" value="Old" required class="w-3.5 h-3.5 text-indigo-600 border-slate-300 focus:ring-indigo-500">
                                            <span class="text-[9px] font-bold text-slate-700 uppercase tracking-widest">Old</span>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="md:col-span-8 md:border-l md:border-slate-200 md:pl-4">
                                    <label class="block text-[8px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-0.5">Sub-Status (Optional)</label>
                                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                        <?php
                                        $sub_options = ['Change Course', 'Cross-Enrollee', 'Returnee', 'Transferee', 'Special', 'Short Course'];
                                        foreach($sub_options as $opt):
                                        ?>
                                        <label class="flex items-center gap-1.5 p-1.5 bg-white border border-slate-200 rounded shadow-sm cursor-pointer hover:bg-indigo-50 hover:border-indigo-300 transition-colors">
                                            <input type="checkbox" name="sub_admission_type[]" value="<?= $opt ?>" class="w-3.5 h-3.5 text-indigo-600 rounded border-slate-300 focus:ring-indigo-500">
                                            <span class="text-[8px] font-bold text-slate-600 uppercase tracking-widest whitespace-nowrap truncate" title="<?= $opt ?>"><?= $opt ?></span>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Selects -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-[8px] font-bold text-indigo-900 uppercase tracking-widest mb-1 ml-0.5">Modality</label>
                                    <select name="modality" id="eval_modality" class="w-full bg-slate-50 border border-slate-300 rounded px-2 py-1.5 text-[10px] font-bold text-indigo-900 shadow-inner focus:outline-none focus:border-indigo-500 cursor-pointer">
                                        <option value="Modular">Modular</option>
                                        <option value="On-Line">On-Line</option>
                                        <option value="HYBRID">HYBRID</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[8px] font-bold text-indigo-900 uppercase tracking-widest mb-1 ml-0.5">Target Program</label>
                                    <select name="eval_program" id="eval_program" onchange="window.triggerCurriculumFetch()" class="w-full bg-slate-50 border border-slate-300 rounded px-2 py-1.5 text-[10px] font-bold text-indigo-900 shadow-inner focus:outline-none focus:border-indigo-500 cursor-pointer truncate">
                                        <?php foreach ($prog_list as $p): ?>
                                            <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[8px] font-bold text-indigo-900 uppercase tracking-widest mb-1 ml-0.5">Ascending Year Level</label>
                                    <select name="eval_year" id="eval_year" onchange="window.triggerCurriculumFetch()" class="w-full bg-slate-50 border border-slate-300 rounded px-2 py-1.5 text-[10px] font-bold text-indigo-900 shadow-inner focus:outline-none focus:border-indigo-500 cursor-pointer">
                                        <?php foreach ($year_list as $y): ?>
                                            <option value="<?= htmlspecialchars($y) ?>"><?= htmlspecialchars($y) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- THREE BUILD MODES: REGULAR, IRREGULAR, SHIFTER -->
                        <div class="border-t border-slate-200 pt-3">
                            <label class="block text-[8px] font-black text-indigo-900 uppercase tracking-widest mb-1.5 ml-0.5">Curriculum Build Mode <span class="text-rose-500">*</span></label>
                            <div class="grid grid-cols-1 sm:grid-cols-4 gap-2">
                                <label class="flex flex-col items-center justify-center p-2 bg-slate-50 rounded border border-slate-300 hover:border-indigo-500 hover:bg-indigo-50/30 transition-all cursor-pointer group text-center">
                                    <input type="radio" name="student_status" id="mode_regular" value="Regular" onchange="window.triggerCurriculumFetch()" required class="w-3.5 h-3.5 text-indigo-600 border-slate-400 focus:ring-indigo-500 mb-1">
                                    <span class="text-[9px] font-black text-indigo-900 uppercase tracking-widest">Regular (Auto)</span>
                                </label>
                                <label class="flex flex-col items-center justify-center p-2 bg-slate-50 rounded border border-slate-300 hover:border-indigo-500 hover:bg-indigo-50/30 transition-all cursor-pointer group text-center">
                                    <input type="radio" name="student_status" id="mode_manual_regular" value="Manual_Regular" onchange="window.triggerCurriculumFetch()" required class="w-3.5 h-3.5 text-indigo-600 border-slate-400 focus:ring-indigo-500 mb-1">
                                    <span class="text-[9px] font-black text-indigo-900 uppercase tracking-widest">Manual (Regular)</span>
                                </label>
                                <label class="flex flex-col items-center justify-center p-2 bg-slate-50 rounded border border-slate-300 hover:border-indigo-500 hover:bg-indigo-50/30 transition-all cursor-pointer group text-center">
                                    <input type="radio" name="student_status" id="mode_manual_irregular" value="Manual_Irregular" onchange="window.triggerCurriculumFetch()" required class="w-3.5 h-3.5 text-indigo-600 border-slate-400 focus:ring-indigo-500 mb-1">
                                    <span class="text-[9px] font-black text-indigo-900 uppercase tracking-widest">Manual (Irregular)</span>
                                </label>
                                <label class="flex flex-col items-center justify-center p-2 bg-slate-50 rounded border border-slate-300 hover:border-indigo-500 hover:bg-indigo-50/30 transition-all cursor-pointer group text-center">
                                    <input type="radio" name="student_status" id="mode_shifter" value="Shifter" onchange="window.triggerCurriculumFetch()" required class="w-3.5 h-3.5 text-indigo-600 border-slate-400 focus:ring-indigo-500 mb-1">
                                    <span class="text-[9px] font-black text-indigo-900 uppercase tracking-widest flex flex-col items-center gap-0.5">
                                        <svg class="w-3 h-3 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                                        Program Shifter
                                    </span>
                                </label>
                            </div>
                        </div>

                    </div>

                    <div id="curriculum_container" class="min-h-[60px] transition-all duration-300 relative">
                        <div class="p-4 bg-slate-100 border border-slate-300 border-dashed rounded text-center">
                            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">Select Build Mode to load curriculum</p>
                        </div>
                    </div>

                </div>

                <div class="px-5 py-3 border-t border-slate-200 bg-white z-30 shrink-0 flex gap-2 justify-end rounded-b-xl shadow-[0_-4px_10px_rgba(0,0,0,0.02)]">
                    <button type="button" onclick="window.closeEvalModal()" class="px-4 py-1.5 border border-slate-300 rounded text-[9px] font-bold text-slate-600 uppercase tracking-widest hover:bg-slate-50 transition-colors cursor-pointer shadow-sm">Cancel</button>
                    <button type="submit" class="bg-indigo-900 text-white border border-indigo-950 px-6 py-1.5 rounded text-[9px] font-bold uppercase tracking-widest flex items-center gap-1.5 cursor-pointer shadow-sm hover:bg-indigo-950 transition-colors" id="btnFinalizeEval">
                        <span id="btnFinalizeEvalText">Execute Eval</span> <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- DOCUMENT OVERLAY MODAL -->
    <div id="doc-modal" class="fixed inset-0 z-[9999] hidden flex-col items-center justify-center bg-black/95 backdrop-blur-md">
        <div class="relative w-full h-full flex items-center justify-center overflow-hidden" id="doc-container">
            <div id="docLoader" class="absolute flex flex-col items-center justify-center inset-0 z-10 hidden">
                <svg class="w-8 h-8 text-[#c5a02c] animate-spin mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                <span class="text-[10px] font-bold text-white uppercase tracking-widest">Loading Document...</span>
            </div>
            <div id="docError" class="hidden flex-col items-center justify-center text-center p-8 z-10">
                <div class="w-16 h-16 bg-rose-100 text-rose-500 rounded-full flex items-center justify-center mb-4 border border-rose-200">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <p class="text-rose-400 font-bold mb-2">Unsupported File Format</p>
                <p class="text-slate-400 text-xs mb-4 max-w-xs">This file type cannot be previewed in the browser. Please download it to view.</p>
                <a href="#" id="docFallbackLink" download class="px-4 py-2 border border-slate-600 rounded text-[10px] font-bold text-white uppercase tracking-widest hover:bg-slate-800 transition-colors">Download File Instead</a>
            </div>

            <img id="docImage" src="" class="max-w-[90vw] max-h-[90vh] object-contain transition-transform duration-100 hidden rounded-xl shadow-2xl border-4 border-white/10" onload="document.getElementById('docLoader').classList.add('hidden');">
            <iframe id="docIframe" src="" class="w-[90vw] h-[90vh] bg-white rounded-xl shadow-2xl hidden border-0" onload="document.getElementById('docLoader').classList.add('hidden');"></iframe>
        </div>

        <div class="absolute top-5 right-5 flex gap-3 z-[10000]">
            <a href="#" id="docDownloadBtn" download class="px-4 py-2 bg-[#00205b]/80 hover:bg-[#001233] text-white rounded-full text-[10px] font-bold uppercase tracking-widest shadow-sm flex items-center gap-1.5 transition-colors backdrop-blur-md border border-[#00205b]/30">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                <span class="hidden sm:inline">Download</span>
            </a>
            <button type="button" onclick="window.closeDocModal()" class="p-2 bg-rose-600/80 hover:bg-rose-500 text-white rounded-full transition-colors backdrop-blur-md border border-rose-400/30 shadow-lg cursor-pointer">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
    </div>

    <!-- TOAST NOTIFICATION CONTAINER -->
    <div id="toastContainer" class="fixed top-5 right-5 z-[100] flex flex-col gap-2 pointer-events-none"></div>

    <script src="registrar.js?v=<?= time() ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="verification_management.js?v=<?= time() ?>"></script>

    <script>
        document.addEventListener('DOMContentLoaded', () => {

            // Advanced Table Filter (Search)
            window.filterTable = function() {
                let input = document.getElementById("liveSearch");
                let filter = input ? input.value.toUpperCase() : "";
                let activeTable = document.getElementById('unified_tbody');

                if (activeTable) {
                    let tr = activeTable.getElementsByTagName("tr");
                    let visibleCount = 0;

                    for (let i = 0; i < tr.length; i++) {
                        let tdName = tr[i].querySelector('.searchable-name');
                        let tdId = tr[i].querySelector('.searchable-id');

                        if (tdName) { 
                            let txtValue = (tdName ? tdName.textContent : "") + " " + (tdId ? tdId.textContent : "");
                            let matchesSearch = txtValue.toUpperCase().indexOf(filter) > -1;

                            if (matchesSearch) {
                                tr[i].style.display = "";
                                visibleCount++;
                            } else {
                                tr[i].style.display = "none";
                            }
                        }
                    }

                    const countEl = document.getElementById('queue_count');
                    if (countEl) countEl.innerText = visibleCount + " RECORDS MATCHED";
                }
            };

            <?php if (!empty($success_msg)): ?> window.showToast("<?= addslashes($success_msg) ?>", 'success'); <?php endif; ?>
            <?php if (!empty($error_msg)): ?> window.showToast("<?= addslashes($error_msg) ?>", 'error'); <?php endif; ?>
        });
    </script>
</body>
</html>