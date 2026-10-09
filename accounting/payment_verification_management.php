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

$allowed_roles = ['admin', 'accounting'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../index.php");
    exit();
}

$can_edit = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'accounting');
$success_msg = "";
$error_msg = "";

// FETCH AUTHORIZED BANKS GLOBALLY FOR THE MODAL
$authorized_banks = [];
$banks_q = $conn->query("SELECT * FROM bank_accounts ORDER BY id ASC");
if ($banks_q && $banks_q->num_rows > 0) {
    while ($b = $banks_q->fetch_assoc()) {
        $authorized_banks[] = $b;
    }
}

// FETCH ACTIVE SEMESTER & YEAR FOR STRICT LOGGING
$sys_settings = [];
$set_q = $conn->query("SELECT setting_key, setting_value FROM portal_settings WHERE setting_key IN ('active_school_year', 'active_semester')");
if ($set_q) { while ($r = $set_q->fetch_assoc()) { $sys_settings[$r['setting_key']] = $r['setting_value']; } }
$global_active_sy = $sys_settings['active_school_year'] ?? '2024-2025';
$global_active_sem = $sys_settings['active_semester'] ?? '1st Semester';

// =========================================================
// FEE BREAKDOWN CALCULATOR ENGINE (FOR EMAILS)
// =========================================================
function calculateUnpaidFeesHtml($conn, $user_internal_id, $req_id, $new_total_paid, $program, $year_level, $semester, $student_status, $assigned_initial_fee, $retake_fee = 0) {
    $student_program_safe = $conn->real_escape_string($program ?? '');
    $safe_year_lvl = $conn->real_escape_string($year_level ?? '');
    $safe_sem = $conn->real_escape_string($semester ?? '');
    $adm_initial_fee = $assigned_initial_fee ?: 'Miscellaneous Fee';
    
    $fee_breakdown = [];
    
    // 1. General Fees
    $gen_fees = $conn->query("SELECT fee_name, amount, payment_rule, first_payment, second_payment FROM accounting_fees WHERE (target_program = 'All' OR target_program = '$student_program_safe' OR target_program LIKE '%$student_program_safe%') AND (target_year = 'All' OR target_year = '$safe_year_lvl') AND (target_semester = 'All' OR target_semester = '$safe_sem' OR target_semester IS NULL)");
    if ($gen_fees && $gen_fees->num_rows > 0) {
        while ($f = $gen_fees->fetch_assoc()) {
            $is_tuition = (stripos($f['fee_name'], 'Tuition') !== false);
            $is_misc = (stripos($f['fee_name'], 'Misc') !== false);
            if ($student_status === 'Regular' && $is_tuition) continue; 
            if ($is_tuition && stripos($adm_initial_fee, 'Misc') !== false) continue;
            if ($is_misc && stripos($adm_initial_fee, 'Tuition') !== false) continue;

            $has_installments = ($f['payment_rule'] === 'Installments Allowed' && (float)$f['first_payment'] > 0);
            if ($has_installments) {
                $fee_breakdown[] = [
                    'name' => $f['fee_name'], 
                    'amount' => (float)$f['amount'],
                    'is_misc_installment' => true,
                    'first_amount' => (float)$f['first_payment'],
                    'second_amount' => (float)$f['second_payment']
                ];
            } else {
                $fee_breakdown[] = ['name' => $f['fee_name'], 'amount' => (float)$f['amount']]; 
            }
        }
    }

    // 2. Subject Fees
    $enrolled_subj_ids = [];
    if ($req_id > 0) {
        $e_q = $conn->query("SELECT subject_id FROM enrollments WHERE enrollment_request_id = " . $req_id);
        if ($e_q && $e_q->num_rows > 0) {
            while($r = $e_q->fetch_assoc()) { $enrolled_subj_ids[] = $r['subject_id']; }
        }
    }
    
    // Fallback: Check Admissions evaluated subjects
    if (empty($enrolled_subj_ids)) {
        $a_q = $conn->query("SELECT evaluated_subjects, enrollment_term FROM admissions WHERE provisioned_user_id = $user_internal_id LIMIT 1");
        if ($a_q && $a_q->num_rows > 0) {
            $adm_row = $a_q->fetch_assoc();
            $current_term_string = $year_level . ' - ' . $semester;
            if ($adm_row['enrollment_term'] === $current_term_string && !empty($adm_row['evaluated_subjects'])) {
                $enrolled_subj_ids = array_map('intval', explode(',', $adm_row['evaluated_subjects']));
            }
        }
    }
    
    // Fallback: Pull from prospectus directly
    if (empty($enrolled_subj_ids)) {
        $p_id_q = $conn->query("SELECT program_id FROM programs WHERE program_name = '$student_program_safe' LIMIT 1");
        if ($p_id_q && $p_id_q->num_rows > 0) {
            $student_program_id = (int)$p_id_q->fetch_assoc()['program_id'];
            $p_q = $conn->query("SELECT id FROM prospectus WHERE program_id = $student_program_id AND year_level = '$safe_year_lvl' AND semester = '$safe_sem' AND IFNULL(is_archived, 0) = 0");
            if ($p_q && $p_q->num_rows > 0) {
                while($r = $p_q->fetch_assoc()) { $enrolled_subj_ids[] = $r['id']; }
            }
        }
    }

    if (!empty($enrolled_subj_ids)) {
        $id_str = implode(',', array_filter($enrolled_subj_ids));
        if (!empty($id_str)) {
            $subj_fees = $conn->query("SELECT course_code, descriptive_title, subject_fee FROM prospectus WHERE id IN ($id_str) AND subject_fee > 0");
            if ($subj_fees && $subj_fees->num_rows > 0) {
                while ($s = $subj_fees->fetch_assoc()) { 
                    $fee_breakdown[] = ['name' => $s['course_code'] . ' - ' . $s['descriptive_title'], 'amount' => (float)$s['subject_fee']];
                }
            }
        }
    }

    // 3. Retake fee
    if ($retake_fee > 0) {
        $fee_breakdown[] = ['name' => 'Failed/Retake Subject Fees', 'amount' => (float)$retake_fee];
    }

    // Allocate Payments (Waterfall)
    $remaining_to_allocate = $new_total_paid;
    foreach ($fee_breakdown as &$fee) {
        $apply = min($fee['amount'], max(0, $remaining_to_allocate));
        $fee['amount_covered'] = $apply;
        $remaining_to_allocate -= $apply;
    }
    unset($fee);

    // Build the UI rows for the email
    $unpaid_fees_html = "";
    $actual_balance = 0;
    foreach ($fee_breakdown as $fee) {
        if (!empty($fee['is_misc_installment'])) {
            $first_amt = $fee['first_amount'];
            $second_amt = $fee['second_amount'];
            $covered = $fee['amount_covered'];
            
            $first_covered = min($first_amt, $covered);
            $unpaid_first = $first_amt - $first_covered;
            if ($unpaid_first > 0.01) {
                $unpaid_fees_html .= "<tr><td style='padding: 6px 0; color: #475569; font-size: 13px; font-weight: 500;'>{$fee['name']} <span style='font-size: 11px; color: #94a3b8;'>(1st Installment)</span></td><td style='padding: 6px 0; font-weight: bold; color: #00205b; text-align: right;'>₱" . number_format($unpaid_first, 2) . "</td></tr>";
                $actual_balance += $unpaid_first;
            }
            
            $second_covered = min($second_amt, $covered - $first_covered);
            $unpaid_second = $second_amt - $second_covered;
            if ($unpaid_second > 0.01) {
                $unpaid_fees_html .= "<tr><td style='padding: 6px 0; color: #475569; font-size: 13px; font-weight: 500;'>{$fee['name']} <span style='font-size: 11px; color: #94a3b8;'>(2nd Installment)</span></td><td style='padding: 6px 0; font-weight: bold; color: #00205b; text-align: right;'>₱" . number_format($unpaid_second, 2) . "</td></tr>";
                $actual_balance += $unpaid_second;
            }
        } else {
            $unpaid = $fee['amount'] - $fee['amount_covered'];
            if ($unpaid > 0.01) {
                $unpaid_fees_html .= "<tr><td style='padding: 6px 0; color: #475569; font-size: 13px; font-weight: 500;'>{$fee['name']}</td><td style='padding: 6px 0; font-weight: bold; color: #00205b; text-align: right;'>₱" . number_format($unpaid, 2) . "</td></tr>";
                $actual_balance += $unpaid;
            }
        }
    }
    
    return ['html' => $unpaid_fees_html, 'balance' => $actual_balance];
}

// =========================================================
// EMAIL DISPATCHER FUNCTION
// =========================================================
function sendPaymentReceiptEmail($personal_email, $inst_email, $student_name, $or_number, $amount, $payment_details, $date, $program, $balance = 0, $unpaid_fees_html = '') {
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
        $mail->setFrom(SMTP_USER, 'LDSP Accounting Office');
        
        if (!empty($personal_email)) $mail->addAddress($personal_email, $student_name);
        if (!empty($inst_email)) $mail->addAddress($inst_email, $student_name);
        
        $mail->isHTML(true);
        $mail->Subject = 'LDSP Official Receipt - Payment Verified (O.R. #' . $or_number . ')';
        
        $amount_formatted = number_format((float)$amount, 2);
        
        $balance_section_html = '';
        if ($balance > 0.01 && !empty($unpaid_fees_html)) {
            $balance_section_html = "
                <div style='background-color: #f8fafc; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; margin: 20px 0;'>
                    <h3 style='margin-top: 0; color: #00205b; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; border-bottom: 1px solid #cbd5e1; padding-bottom: 8px; margin-bottom: 12px;'>Remaining Balance Breakdown</h3>
                    <table style='width: 100%; border-collapse: collapse; font-size: 13px;'>
                        $unpaid_fees_html
                        <tr><td colspan='2' style='border-top: 1px dashed #cbd5e1; margin: 8px 0; padding-top: 8px;'></td></tr>
                        <tr>
                            <td style='padding: 6px 0; color: #64748b; font-weight: bold; text-transform: uppercase; font-size: 11px;'>Total Balance Due:</td>
                            <td style='padding: 6px 0; font-weight: 900; color: #e11d48; text-align: right; font-size: 16px;'>₱" . number_format($balance, 2) . "</td>
                        </tr>
                    </table>
                </div>
            ";
        } else {
            $balance_section_html = "
                <div style='background-color: #f0fdf4; padding: 14px; border: 1px solid #bbf7d0; border-radius: 8px; margin: 20px 0; text-align: center;'>
                    <span style='color: #166534; font-weight: bold; font-size: 13px; text-transform: uppercase; letter-spacing: 1px;'>✓ Account Cleared (No Balance Due)</span>
                </div>
            ";
        }
        
        // This generates a completely unique string and current time to trick Gmail's threaded trimmed content
        $anti_trim_hash = md5(uniqid(time(), true));
        $generated_time = date('Y-m-d H:i:s');

        $mail->Body = "
            <div style='font-family: \"Inter\", Arial, sans-serif; color: #1f2937; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background-color: #ffffff;'>
                <div style='padding: 24px; background-color: #00205b; text-align: center;'>
                    <h2 style='color: #ffffff; margin: 0; font-size: 18px; letter-spacing: 2px; text-transform: uppercase;'>Online Transaction</h2>
                    <p style='color: #93c5fd; font-size: 11px; margin: 4px 0 0; text-transform: uppercase; letter-spacing: 1px;'>Lyceum de San Pablo Accounting Office</p>
                </div>
                
                <div style='padding: 28px;'>
                    <p style='font-size: 14px; margin-top: 0; color: #334155;'>Dear <strong>$student_name</strong>,</p>
                    <p style='line-height: 1.6; color: #475569; font-size: 13px;'>Your recent payment has been verified by the Accounting Office. Below are the official transaction details:</p>
                    
                    <div style='background-color: #ffffff; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; margin: 20px 0;'>
                        <table style='width: 100%; border-collapse: collapse; font-size: 13px;'>
                            <tr>
                                <td style='padding: 6px 0; color: #64748b; width: 40%;'>Program:</td>
                                <td style='padding: 6px 0; font-weight: bold; color: #0f172a;'>$program</td>
                            </tr>
                            <tr>
                                <td style='padding: 6px 0; color: #64748b;'>Date Verified:</td>
                                <td style='padding: 6px 0; font-weight: bold; color: #0f172a;'>$date</td>
                            </tr>
                            <tr>
                                <td style='padding: 6px 0; color: #64748b;'>Description:</td>
                                <td style='padding: 6px 0; font-weight: bold; color: #0f172a;'>$payment_details</td>
                            </tr>
                            <tr>
                                <td style='padding: 6px 0; color: #64748b;'>O.R. Number:</td>
                                <td style='padding: 6px 0; font-weight: bold; color: #00205b; font-family: monospace;'>$or_number</td>
                            </tr>
                            <tr><td colspan='2' style='border-top: 1px dashed #cbd5e1; margin: 8px 0; padding-top: 8px;'></td></tr>
                            <tr>
                                <td style='padding: 6px 0; color: #64748b;'>Amount Paid:</td>
                                <td style='padding: 6px 0; font-weight: 900; color: #00205b; font-size: 16px;'>₱$amount_formatted</td>
                            </tr>
                        </table>
                    </div>
                    
                    $balance_section_html
                    
                    <!-- ACTION BUTTON: Only View Ledger is available for students -->
                    <div style='text-align: center; margin: 28px 0 10px;'>
                        <a href='http://localhost/LDSP_enrollment_system/student/payment_fees.php' target='_blank' style='display: inline-block; background-color: #00205b; color: #ffffff; padding: 12px 20px; margin: 6px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 12px; letter-spacing: 1px; text-transform: uppercase; white-space: nowrap;'>View Ledger</a>
                    </div>

                    <p style='line-height: 1.5; font-size: 11px; color: #94a3b8; text-align: center; margin-top: 24px;'>
                        This is an official system-generated electronic receipt from Lyceum de San Pablo.<br>
                        <span style='color: #cbd5e1; font-size: 9px;'>Generated on: $generated_time</span>
                    </p>
                </div>
            </div>
            <!-- Anti-Trim Code to force Gmail to display the full email -->
            <div style='display: none; white-space: nowrap; font: 15px courier; line-height: 0; color: transparent; opacity: 0; max-height: 0; overflow: hidden;'>
                Tracking Ref: $anti_trim_hash
                &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; 
                &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; 
                &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;
            </div>
        ";
        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// =========================================================
// POST ACTIONS (SPA SUBMISSIONS)
// =========================================================
if ($can_edit && $_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ONLINE PAYMENTS LOGIC
    if (isset($_POST['approve_payment'])) {
        $req_id = (int)$_POST['request_id'];
        
        $q = $conn->query("
            SELECT er.user_id, er.payment_amount, er.enrolled_at, er.payment_or_number, er.payment_type, er.program, 
                   er.assessed_fee, er.total_paid_accumulated, er.year_level, er.semester, er.student_status, er.retake_fee, er.school_year,
                   a.email as personal_email, u.email as institutional_email, p.first_name, p.last_name, a.assigned_initial_fee
            FROM enrollment_requests er 
            JOIN users u ON er.user_id = u.id
            LEFT JOIN user_profiles p ON u.id = p.user_id
            LEFT JOIN admissions a ON u.id = a.provisioned_user_id
            WHERE er.id = $req_id LIMIT 1
        ");
        
        if ($q && $q->num_rows > 0) {
            $req_data = $q->fetch_assoc();
            
            $payment_amt = (float)$req_data['payment_amount'];
            $new_total_paid = (float)($req_data['total_paid_accumulated'] ?? 0) + $payment_amt;
            
            // Mathematically calculate the real unpaid fees breakdown for the email
            $unpaid_calc = calculateUnpaidFeesHtml(
                $conn, 
                $req_data['user_id'], 
                $req_id, 
                $new_total_paid, 
                $req_data['program'], 
                $req_data['year_level'], 
                $req_data['semester'], 
                $req_data['student_status'], 
                $req_data['assigned_initial_fee'], 
                (float)($req_data['retake_fee'] ?? 0)
            );
            
            $new_balance = $unpaid_calc['balance'];
            $unpaid_fees_html = $unpaid_calc['html'];
            
            $is_already_enrolled = !empty($req_data['enrolled_at']);
            $next_status = $is_already_enrolled ? 'Enrolled' : 'Pending Registrar';
            $notes = $is_already_enrolled ? 'Balance Payment Verified' : 'Verified by Accounting';
            
            $update_sql = "UPDATE enrollment_requests SET 
                total_paid_accumulated = $new_total_paid,
                balance = $new_balance,
                final_status = '$next_status', 
                payment_notes = '$notes' 
                WHERE id = $req_id";
                
            if ($conn->query($update_sql)) {
                $conn->query("INSERT IGNORE INTO transaction_history (student_email, or_number, payment_type, amount, transaction_date, program, academic_year, semester)
                              SELECT u.email, er.payment_or_number, er.payment_type, er.payment_amount, er.transaction_date, er.program, er.school_year, er.semester
                              FROM enrollment_requests er JOIN users u ON er.user_id = u.id WHERE er.id = $req_id");
                
                $student_name = trim(($req_data['first_name'] ?? '') . ' ' . ($req_data['last_name'] ?? ''));
                $email_sent = sendPaymentReceiptEmail(
                    $req_data['personal_email'] ?? '', 
                    $req_data['institutional_email'] ?? '', 
                    $student_name, 
                    $req_data['payment_or_number'] ?? '', 
                    $payment_amt, 
                    $req_data['payment_type'] ?? '', 
                    date('F j, Y h:i A'), 
                    $req_data['program'] ?? 'Unassigned Program',
                    $new_balance,
                    $unpaid_fees_html
                );
                
                $email_note = $email_sent ? " e-Receipt sent." : " (Email failed).";
                $success_msg = ($is_already_enrolled ? "Balance Payment Approved." : "Online Payment Approved. Forwarded to Registrar.") . $email_note;
            } else { $error_msg = "Database Error: Could not approve."; }
        }
    }

    elseif (isset($_POST['reject_payment'])) {
        $req_id = (int)$_POST['request_id'];
        $reason = $conn->real_escape_string($_POST['reject_reason'] ?? '');
        $update_sql = "UPDATE enrollment_requests SET final_status = 'Payment Rejected', payment_notes = '$reason' WHERE id = $req_id";
        if ($conn->query($update_sql)) { $success_msg = "Payment rejected/held."; } else { $error_msg = "Database Error."; }
    }

    // NEW APPLICANT PAYMENTS LOGIC
    elseif (isset($_POST['process_new_payment'])) {
        $adm_id = (int)$_POST['admission_id'];
        $fee_assigned = $conn->real_escape_string($_POST['fee_assigned'] ?? ''); 
        
        $base_payment_type = $conn->real_escape_string($_POST['payment_type'] ?? 'Full');
        $paid_through = $conn->real_escape_string($_POST['paid_through'] ?? '');
        $payment_type_with_channel = $base_payment_type . " (via " . $paid_through . ")";
        
        $amount_paid = (float)str_replace(',', '', $_POST['amount_paid'] ?? '0');
        $or_number = $conn->real_escape_string(trim($_POST['or_number'] ?? ''));
        
        if (!preg_match('/^\d{18}$/', $or_number)) {
            $error_msg = "Invalid O.R. Number. It must contain exactly 18 numbers with no letters or special characters.";
        } else {
            $adm_info = $conn->query("SELECT email, institutional_email, first_name, last_name, program, initial_or_number, provisioned_user_id FROM admissions WHERE id = $adm_id")->fetch_assoc();
            
            $adm_email = $conn->real_escape_string($adm_info['email'] ?? '');
            $inst_email = $conn->real_escape_string($adm_info['institutional_email'] ?? '');
            $adm_prog = $conn->real_escape_string($adm_info['program'] ?? '');
            
            $student_name = trim(($adm_info['first_name'] ?? '') . ' ' . ($adm_info['last_name'] ?? ''));
            $old_or = $conn->real_escape_string($adm_info['initial_or_number'] ?? '');
            $prov_id = (int)($adm_info['provisioned_user_id'] ?? 0);

            if (!empty($old_or)) {
                $conn->query("DELETE FROM transaction_history WHERE or_number = '$old_or' AND student_email = '$adm_email'");
            }

            $update_sql = "UPDATE admissions SET 
                pushed_to_registrar = 1, 
                initial_fee_assigned = '$fee_assigned', 
                initial_payment_type = '$payment_type_with_channel',
                initial_amount_paid = $amount_paid, 
                initial_or_number = '$or_number' 
                WHERE id = $adm_id";
            
            if ($conn->query($update_sql)) {
                $ptype = "Initial Fee - " . $fee_assigned . " (" . $payment_type_with_channel . ")";
                $conn->query("INSERT INTO transaction_history (student_email, or_number, payment_type, amount, transaction_date, program, academic_year, semester) VALUES ('$adm_email', '$or_number', '$ptype', $amount_paid, NOW(), '$adm_prog', '$global_active_sy', '$global_active_sem')");
                
                if ($prov_id > 0) {
                    $conn->query("UPDATE enrollment_requests SET 
                        final_status = 'Pending Registrar', 
                        payment_or_number = '$or_number', 
                        payment_type = '$payment_type_with_channel', 
                        payment_amount = $amount_paid, 
                        payment_notes = 'Verified by Accounting (On-Site)',
                        total_paid_accumulated = IFNULL(total_paid_accumulated, 0) + $amount_paid
                        WHERE user_id = $prov_id AND final_status IN ('Pending', 'Pending Payment', 'Payment Rejected', 'Accounting Verification')");
                }
                
                $email_sent = sendPaymentReceiptEmail(
                    $adm_email, 
                    $inst_email, 
                    $student_name, 
                    $or_number, 
                    $amount_paid, 
                    $ptype, 
                    date('F j, Y h:i A'), 
                    $adm_info['program'] ?? 'Unassigned Program',
                    0, 
                    '' 
                );
                
                $email_note = $email_sent ? " e-Receipt sent." : " (Email failed).";
                $success_msg = "New Applicant Payment recorded & verified! Pushed to Registrar." . $email_note;
            } else {
                $error_msg = "Database Error: Could not process payment.";
            }
        }
    }

    if (isset($_POST['ajax_post'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => $error_msg ? 'error' : 'success', 'message' => $error_msg ?: $success_msg]);
        exit();
    }
}

// =========================================================
// DATA FETCHING & LIVE QUEUE API
// =========================================================

$initial_fees = [];
try {
    $if_q = $conn->query("SELECT fee_name, amount, first_payment FROM accounting_fees WHERE fee_name LIKE '%Tuition%' OR fee_name LIKE '%Misc%' ORDER BY fee_name ASC");
    if ($if_q) { while ($r = $if_q->fetch_assoc()) { $initial_fees[] = $r; } }
} catch (Exception $e) {}

// NEW ADDITION: The 24-Hour Rule
function fetchOnlineQueue($conn) {
    return $conn->query("
        SELECT er.*, up.*, up.program as up_program, u.student_id as s_id, 
               a.email as personal_email, u.email as institutional_email,
               a.school_last_attended as a_school, a.school_year_attended as a_school_year,
               a.father_name as a_father, a.father_occupation as a_father_occ, a.father_contact as a_father_con,
               a.mother_name as a_mother, a.mother_occupation as a_mother_occ, a.mother_contact as a_mother_con,
               a.emergency_contact_name as a_em_name, a.emergency_contact_number as a_em_con,
               a.evaluated_modality as a_modality, a.influence_source, a.place_of_birth,
               a.uploaded_files, a.student_type, a.admission_number
        FROM enrollment_requests er 
        JOIN users u ON er.user_id = u.id 
        JOIN user_profiles up ON u.id = up.user_id 
        LEFT JOIN admissions a ON u.id = a.provisioned_user_id
        WHERE er.final_status IN ('Accounting Verification', 'Payment Rejected')
           OR (
               er.final_status IN ('Pending Registrar', 'Enrolled') 
               AND EXISTS (
                   SELECT 1 FROM transaction_history th 
                   WHERE th.or_number = er.payment_or_number 
                     AND th.approved_at >= NOW() - INTERVAL 24 HOUR
               )
           )
        ORDER BY er.created_at DESC
    ");
}

function fetchNewQueue($conn) {
    return $conn->query("
        SELECT a.*, r.academic_year, u.student_id as actual_student_id,
               a.email as personal_email, u.email as institutional_email
        FROM admissions a 
        LEFT JOIN exam_results r ON a.admission_number = r.student_id 
        LEFT JOIN users u ON a.provisioned_user_id = u.id
        WHERE a.pushed_to_accounting = 1 
        AND a.registrar_evaluated = 0 
        AND (
            (a.pushed_to_registrar = 1 AND EXISTS (
                   SELECT 1 FROM transaction_history th 
                   WHERE th.or_number = a.initial_or_number 
                     AND th.approved_at >= NOW() - INTERVAL 24 HOUR
            ))
            OR 
            (a.pushed_to_registrar = 0 AND NOT EXISTS (
                SELECT 1 FROM enrollment_requests er 
                WHERE er.user_id = a.provisioned_user_id 
                AND er.final_status IN ('Accounting Verification', 'Payment Rejected', 'Pending Registrar', 'Enrolled')
            ))
        )
        ORDER BY a.pushed_to_registrar ASC, a.id ASC
    ");
}

function renderOnlineQueue($query_res, $can_edit) {
    ob_start();
    if ($query_res && $query_res->num_rows > 0): 
        while ($row = $query_res->fetch_assoc()): 
            $row['record_type'] = 'online'; 
            $first = !empty($row['first_name']) ? $row['first_name'] : '';
            $middle = !empty($row['middle_name']) ? ' ' . $row['middle_name'] : '';
            $last = !empty($row['last_name']) ? $row['last_name'] . ', ' : '';
            $student_name = htmlspecialchars(trim($last . $first . $middle));
            $row['applicant_name'] = $student_name;
            
            $amount = number_format((float)$row['payment_amount'], 2);
            $fee_type_raw = $row['payment_type'] ?? 'Standard Payment';
            $or_num = htmlspecialchars($row['payment_or_number'] ?? '');
            
            $is_rejected = ($row['final_status'] === 'Payment Rejected');
            $is_approved = in_array($row['final_status'], ['Pending Registrar', 'Enrolled']);
            
            $submit_date = !empty($row['created_at']) ? date('Y-m-d', strtotime($row['created_at'])) : 'N/A';
            $bank_channel = htmlspecialchars($row['paid_through'] ?? 'Online');
    ?>
            <tr class="hover:bg-blue-50/40 transition-colors <?= $is_approved ? 'bg-emerald-50/40' : ($is_rejected ? 'bg-rose-50/50' : 'bg-white') ?>">
                <!-- Identity -->
                <td class="px-2 py-1.5 align-middle border-r border-slate-300 w-[20%] max-w-0">
                    <div class="w-full overflow-x-auto custom-scrollbar pb-1.5">
                        <div class="font-bold text-[#00205b] text-xs whitespace-nowrap searchable-name pr-2" title="<?= $student_name ?>"><?= $student_name ?></div>
                    </div>
                    <div class="pt-1.5 border-t border-slate-200">
                        <div class="text-[11px] text-slate-500 font-mono font-black tracking-widest truncate searchable-id flex items-center gap-1.5">
                            <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/></svg>
                            <?= htmlspecialchars($row['s_id'] ?? '') ?>
                        </div>
                    </div>
                </td>
                
                <!-- Bank -->
                <td class="px-2 py-1.5 align-middle border-r border-slate-300 w-[12%] truncate text-center">
                    <div class="text-[10px] font-bold text-[#00205b] truncate" title="<?= $bank_channel ?>"><?= $bank_channel ?></div>
                    <div class="text-[9px] uppercase tracking-widest text-slate-400 mt-0.5 font-bold truncate"><?= htmlspecialchars($row['year_level'] ?? '') ?></div>
                </td>
                
                <!-- Fee Assigned -->
                <td class="px-2 py-1.5 align-middle border-r border-slate-300 w-[20%]">
                    <div class="flex flex-col gap-1 overflow-y-auto custom-scrollbar max-h-[50px] pr-1 w-full">
                        <?php 
                        $fee_types_array = explode(',', $fee_type_raw);
                        foreach($fee_types_array as $ft):
                            $ft = trim(str_ireplace(['Payment for:', 'Payment For:', 'payment for:'], '', $ft));
                            if(!empty($ft)):
                        ?>
                            <span class="w-full text-left truncate px-2 py-1 bg-slate-50 border border-slate-200 text-slate-600 rounded text-[9px] font-bold uppercase tracking-widest shadow-sm" title="<?= htmlspecialchars($ft) ?>">
                                <?= htmlspecialchars($ft) ?>
                            </span>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </div>
                </td>

                <!-- Amount -->
                <td class="px-2 py-1.5 align-middle border-r border-slate-300 w-[10%] text-right truncate">
                    <div class="font-mono font-black text-[#00205b] text-[11px]">₱<?= $amount ?></div>
                </td>
                
                <!-- OR Number -->
                <td class="px-2 py-1.5 align-middle border-r border-slate-300 w-[12%] text-center truncate">
                    <div class="text-[10px] text-slate-600 font-mono font-bold uppercase tracking-widest truncate"><?= $or_num ?></div>
                </td>
                
                <!-- Date -->
                <td class="px-2 py-1.5 align-middle border-r border-slate-300 text-center w-[8%] truncate">
                    <span class="text-[9px] font-bold text-slate-500 tracking-widest"><?= $submit_date ?></span>
                </td>
                
                <!-- Receipt -->
                <td class="px-2 py-1.5 align-middle border-r border-slate-300 text-center w-[8%]">
                    <div class="flex items-center justify-center w-full">
                        <?php if (!empty($row['payment_proof'])): ?>
                            <button type="button" onclick="window.viewDocument('../<?= htmlspecialchars(trim($row['payment_proof'])) ?>')" class="bg-slate-100 hover:bg-blue-100 text-slate-500 hover:text-[#00205b] border border-slate-200 p-2 rounded transition-colors shadow-sm cursor-pointer" title="View Receipt">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            </button>
                        <?php elseif (!empty($row['uploaded_files'])): ?>
                            <button type="button" onclick="window.viewDocument('../<?= htmlspecialchars(trim(explode(',', $row['uploaded_files'])[0])) ?>')" class="bg-slate-100 hover:bg-blue-100 text-slate-500 hover:text-[#00205b] border border-slate-200 p-2 rounded transition-colors shadow-sm cursor-pointer" title="View Documents">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                            </button>
                        <?php else: ?>
                            <button type="button" disabled class="bg-slate-50 text-slate-300 border border-slate-100 p-2 rounded cursor-not-allowed" title="No Documents">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            </button>
                        <?php endif; ?>
                    </div>
                </td>

                <!-- Actions -->
                <td class="px-2 py-1.5 align-middle border-slate-300 text-center w-[10%]">
                    <div class="flex flex-col items-center justify-center gap-1.5 w-full">
                        <?php if ($can_edit): ?>
                            <?php if ($is_approved): ?>
                                <!-- 24-HOUR PRINT BUTTON FOR ACCOUNTING -->
                                <button type="button" onclick="window.open('print_receipt.php?or=<?= urlencode($or_num) ?>', '_blank')" class="bg-[#00205b] hover:bg-[#001233] text-white px-3 py-1.5 rounded text-[9px] uppercase tracking-widest font-black shadow-sm cursor-pointer transition-all w-full truncate flex justify-center items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2-2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                    Print
                                </button>
                                <div class="text-[7px] font-black uppercase tracking-widest text-emerald-600 mt-1 text-center w-full truncate">Verified</div>
                            <?php elseif ($is_rejected): ?>
                                <span class="bg-rose-50 text-rose-600 border border-rose-200 px-3 py-1.5 rounded text-[9px] font-black uppercase tracking-widest shadow-sm w-full truncate block text-center">Rejected</span>
                            <?php else: ?>
                                <form method="POST" class="m-0 p-0 flex flex-col gap-1 spa-form w-full" data-name="<?= addslashes($student_name) ?>" data-amount="₱<?= $amount ?>" data-or="<?= addslashes($or_num) ?>">
                                    <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                    <button type="button" onclick="window.confirmPaymentAction('approve', this.form)" class="bg-emerald-500 hover:bg-emerald-600 text-white px-2 py-1 rounded text-[9px] font-black uppercase tracking-widest shadow-sm cursor-pointer transition-colors w-full truncate">Approve</button>
                                    <button type="button" onclick="window.confirmPaymentAction('reject', this.form)" class="bg-white hover:bg-rose-50 border border-rose-200 text-rose-600 px-2 py-1 rounded text-[9px] font-black uppercase tracking-widest shadow-sm cursor-pointer transition-colors w-full truncate">Deny</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
    <?php 
        endwhile; 
    endif;
    return ob_get_clean();
}

function renderNewQueue($query_res, $can_edit) {
    ob_start();
    if ($query_res && $query_res->num_rows > 0): 
        while ($row = $query_res->fetch_assoc()): 
            $row['record_type'] = 'walkin'; 
            $first = !empty($row['first_name']) ? $row['first_name'] : '';
            $middle = !empty($row['middle_name']) ? ' ' . $row['middle_name'] : '';
            $last = !empty($row['last_name']) ? $row['last_name'] . ', ' : '';
            $applicant_name = htmlspecialchars(trim($last . $first . $middle));
            $row['applicant_name'] = $applicant_name;
            
            $ay = $row['academic_year'] ?? 'N/A';
            
            $display_id = !empty($row['actual_student_id']) ? $row['actual_student_id'] : $row['admission_number'];
            
            $is_approved = ($row['pushed_to_registrar'] == 1);
            $fee_ass_raw = $row['initial_fee_assigned'] ?? '';
            $pay_type = htmlspecialchars($row['initial_payment_type'] ?? '');
            $amt_paid = htmlspecialchars($row['initial_amount_paid'] ?? '0');
            $or_num = htmlspecialchars($row['initial_or_number'] ?? '');
            $submit_date = !empty($row['created_at']) ? date('Y-m-d', strtotime($row['created_at'])) : 'N/A';
            
            $bank_channel = '<span class="text-slate-300 font-black tracking-widest block text-center">---</span>';
            if ($is_approved) {
                if (preg_match('/\(via (.*?)\)/', $pay_type, $matches)) {
                    $bank_channel = $matches[1];
                } else {
                    $bank_channel = '---'; // Fallback
                }
            }
            
            $fee_display = $is_approved ? $fee_ass_raw : '<span class="text-slate-300 font-black tracking-widest block text-left">---</span>';
            $amt_display = $is_approved ? '₱' . number_format((float)$amt_paid, 2) : '<span class="text-slate-300 font-black tracking-widest block text-right">---</span>';
            $or_display = $is_approved ? $or_num : '<span class="text-slate-300 font-black tracking-widest block text-center">---</span>';
    ?>
            <tr class="hover:bg-blue-50/40 transition-colors <?= $is_approved ? 'bg-emerald-50/40' : 'bg-white' ?>">
                <!-- Identity -->
                <td class="px-2 py-1.5 align-middle border-r border-slate-300 w-[20%] max-w-0">
                    <div class="w-full overflow-x-auto custom-scrollbar pb-1.5">
                        <div class="font-bold text-[#00205b] text-xs whitespace-nowrap searchable-name pr-2" title="<?= $applicant_name ?>"><?= $applicant_name ?></div>
                    </div>
                    <div class="pt-1.5 border-t border-slate-200">
                        <div class="text-[11px] text-slate-500 font-mono font-black tracking-widest truncate searchable-id flex items-center gap-1.5">
                            <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/></svg>
                            <?= htmlspecialchars($display_id) ?>
                        </div>
                    </div>
                </td>
                
                <!-- Bank -->
                <td class="px-2 py-1.5 align-middle border-r border-slate-300 w-[12%] truncate text-center">
                    <?php if ($is_approved): ?>
                        <div class="text-[10px] font-bold text-[#00205b] truncate" title="<?= htmlspecialchars($bank_channel) ?>"><?= htmlspecialchars($bank_channel) ?></div>
                        <div class="text-[8px] uppercase tracking-widest font-black text-slate-400 mt-0.5 truncate text-center">A.Y. <?= htmlspecialchars($ay) ?></div>
                    <?php else: ?>
                        <?= $bank_channel ?>
                    <?php endif; ?>
                </td>
                
                <!-- Fee Assigned -->
                <td class="px-2 py-1.5 align-middle border-r border-slate-300 w-[20%]">
                    <?php if ($is_approved): ?>
                        <div class="flex flex-col gap-1 overflow-y-auto custom-scrollbar max-h-[50px] pr-1 w-full">
                            <?php 
                            $fee_ass_array = explode(',', $fee_display);
                            foreach($fee_ass_array as $fa):
                                $fa = trim(str_ireplace(['Payment for:', 'Payment For:', 'payment for:'], '', $fa));
                                if(!empty($fa)):
                            ?>
                                <span class="w-full text-left truncate px-2 py-1 bg-slate-50 border border-slate-200 text-slate-600 rounded text-[9px] font-bold uppercase tracking-widest shadow-sm" title="<?= htmlspecialchars($fa) ?> <?= !empty($pay_type) ? "($pay_type)" : "" ?>">
                                    <?= htmlspecialchars($fa) ?> <?= !empty($pay_type) ? "<span class='text-slate-400 ml-1'>($pay_type)</span>" : "" ?>
                                </span>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        </div>
                    <?php else: ?>
                        <?= $fee_display ?>
                    <?php endif; ?>
                </td>

                <!-- Amount -->
                <td class="px-2 py-1.5 align-middle border-r border-slate-300 w-[10%] text-right truncate">
                    <?php if ($is_pushed): ?>
                        <div class="font-mono font-black text-[#00205b] text-[11px]"><?= $amt_display ?></div>
                    <?php else: ?>
                        <?= $amt_display ?>
                    <?php endif; ?>
                </td>
                
                <!-- OR Number -->
                <td class="px-2 py-1.5 align-middle border-r border-slate-300 w-[12%] text-center truncate">
                    <?php if ($is_approved): ?>
                        <div class="text-[10px] text-slate-600 font-mono font-bold uppercase tracking-widest truncate"><?= $or_display ?></div>
                    <?php else: ?>
                        <?= $or_display ?>
                    <?php endif; ?>
                </td>
                
                <!-- Date -->
                <td class="px-2 py-1.5 align-middle border-r border-slate-300 text-center w-[8%] truncate">
                    <span class="text-[9px] font-bold text-slate-500 tracking-widest"><?= $submit_date ?></span>
                </td>
                
                <!-- Receipt -->
                <td class="px-2 py-1.5 align-middle border-r border-slate-300 text-center w-[8%]">
                    <div class="flex items-center justify-center w-full">
                        <button type="button" onclick="window.showToast('Accounting Office Applicant: Documents were submitted physically to the office and not online.', 'info')" title="Physical Documents Only" class="bg-slate-100 hover:bg-blue-100 text-slate-500 hover:text-[#00205b] border border-slate-200 p-2 rounded transition-colors shadow-sm cursor-pointer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                        </button>
                    </div>
                </td>

                <!-- Actions -->
                <td class="px-2 py-1.5 align-middle border-slate-300 text-center w-[10%]">
                    <div class="flex flex-col items-center justify-center gap-1.5 w-full">
                        <?php if ($can_edit): ?>
                            <?php if ($is_approved): ?>
                                <!-- 24-HOUR PRINT BUTTON FOR ACCOUNTING -->
                                <button type="button" onclick="window.open('print_receipt.php?or=<?= urlencode($or_num) ?>', '_blank')" class="bg-[#00205b] hover:bg-[#001233] text-white px-3 py-1.5 rounded text-[9px] uppercase tracking-widest font-black shadow-sm cursor-pointer transition-all w-full truncate flex justify-center items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2-2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                    Print
                                </button>
                                <div class="text-[7px] font-black uppercase tracking-widest text-emerald-600 mt-1 text-center w-full truncate">Verified</div>
                            <?php else: ?>
                                <button type="button" onclick="window.openProcessNewModal(<?= $row['id'] ?>, '<?= addslashes($applicant_name) ?>', '<?= addslashes($display_id) ?>')" class="bg-gradient-to-b from-emerald-500 to-emerald-600 hover:from-emerald-400 hover:to-emerald-500 text-white px-3 py-1.5 rounded text-[9px] uppercase tracking-widest font-black shadow-sm cursor-pointer transition-all border border-emerald-700 w-full truncate">
                                    Process
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
    <?php 
        endwhile; 
    endif;
    return ob_get_clean();
}

$online_query = fetchOnlineQueue($conn);
$new_query = fetchNewQueue($conn);

if (isset($_GET['api_refresh'])) {
    header('Content-Type: application/json');
    $combined_html = renderOnlineQueue($online_query, $can_edit) . renderNewQueue($new_query, $can_edit);
    if (empty(trim($combined_html))) {
        $combined_html = '<tr><td colspan="8" class="px-4 py-16 text-center text-slate-400 text-[10px] font-bold uppercase tracking-[0.2em] bg-white border border-slate-300">No pending verification requests.</td></tr>';
    }
    echo json_encode([
        'updates' => [
            'unified_tbody' => $combined_html
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
    <title>Payment Verification - LDSP Accounting</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css?v=<?= time(); ?>">
    <style>
        .modal-overlay { opacity: 0; transition: opacity 0.2s ease; pointer-events: none; }
        .modal-content { transform: scale(0.95); opacity: 0; transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); pointer-events: none; }
        .modal-active.modal-overlay { opacity: 1; pointer-events: auto; }
        .modal-active .modal-content { transform: scale(1); opacity: 1; pointer-events: auto; }
        
        /* SLEEK VERTICAL SCROLLBAR FOR FEES */
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

            <header class="mb-5 border-b border-slate-300/60 pb-3 drop-shadow-sm flex flex-col sm:flex-row justify-between sm:items-end gap-2">
                <div>
                    <h1 class="text-2xl md:text-3xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">Verification Management</h1>
                    <p class="text-slate-500 text-xs flex items-center gap-2 font-medium mt-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.8)]"></span>
                        Logged in as <?= htmlspecialchars($_SESSION['first_name'] ?? 'Accounting') ?>
                    </p>
                </div>
            </header>

            <div class="grid grid-cols-1 gap-6">
                <!-- UNIFIED VERIFICATION QUEUE -->
                <div class="bg-white border border-slate-300 rounded-lg overflow-hidden z-20 relative shadow-sm border-t-[4px] border-t-[#00205b]">
                    <div class="px-4 py-3 border-b border-slate-300 bg-slate-50 flex justify-between items-center relative z-20">
                        <h3 class="font-black text-[#00205b] text-xs uppercase tracking-widest flex items-center gap-2">
                            <div class="p-1 rounded-sm bg-[#00205b]/10"><svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg></div> 
                            Unified Verification Queue
                        </h3>
                    </div>
                    
                    <div class="mb-2 mt-3 px-4 relative w-full sm:w-[300px]">
                        <input type="text" id="liveSearch" onkeyup="window.filterTable()" class="w-full py-1.5 pl-8 pr-3 text-[10px] font-semibold text-[#00205b] bg-white border border-slate-300 rounded shadow-sm focus:outline-none focus:border-[#00205b]" placeholder="Search applicant name or ID...">
                        <svg class="w-3.5 h-3.5 text-slate-400 absolute left-6 top-[7px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>

                    <!-- Responsive Table Container -->
                    <div class="overflow-x-auto min-h-[450px] bg-white relative z-20 custom-scrollbar w-full">
                        <table class="w-full text-left border-collapse text-sm table-fixed border-b border-slate-300 min-w-[1000px]">
                            <thead class="bg-slate-100 text-slate-600 text-[9px] uppercase font-black tracking-widest sticky top-0 z-10 shadow-sm border-b-2 border-slate-300">
                                <tr>
                                    <th class="px-3 py-3 border-r border-slate-300 w-[20%]">Applicant Identity</th>
                                    <th class="px-2 py-3 border-r border-slate-300 w-[12%] text-center">Bank / Channel</th>
                                    <th class="px-2 py-3 border-r border-slate-300 w-[20%]">Fee Assigned</th>
                                    <th class="px-2 py-3 border-r border-slate-300 w-[10%] text-right">Amount (₱)</th>
                                    <th class="px-2 py-3 border-r border-slate-300 w-[12%] text-center">O.R. Number</th>
                                    <th class="px-2 py-3 border-r border-slate-300 w-[8%] text-center">Date</th>
                                    <th class="px-2 py-3 border-r border-slate-300 w-[8%] text-center">Receipt</th>
                                    <th class="px-2 py-3 border-slate-300 w-[10%] text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="unified_tbody" class="bg-white">
                                <?php 
                                    $html = renderOnlineQueue($online_query, $can_edit) . renderNewQueue($new_query, $can_edit);
                                    if (empty(trim($html))) {
                                        echo '<tr><td colspan="8" class="px-4 py-16 text-center text-slate-400 text-[10px] font-bold uppercase tracking-[0.2em] bg-white border border-slate-300">No pending verification requests.</td></tr>';
                                    } else {
                                        echo $html;
                                    }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- ACTION CONFIRM MODAL -->
    <div id="actionConfirmModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 modal-overlay bg-slate-900/70 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-xl shadow-2xl w-[95%] sm:w-full max-w-sm modal-content overflow-hidden border-t-4 border-t-blue-500">
            <div class="p-5 sm:p-6 text-center">
                <div id="confirmActionIcon"></div>
                <h3 id="confirmActionTitle" class="text-base font-black text-slate-800 uppercase tracking-wide mb-1">Confirm Action</h3>
                <p id="confirmActionDesc" class="text-[11px] text-slate-500 font-medium leading-relaxed mb-5"></p>
                <div id="confirmReasonDiv" class="hidden mb-5 text-left bg-slate-50 p-3 rounded-lg border border-slate-200 shadow-inner">
                    <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 ml-1">Reason for Denial / Hold</label>
                    <input type="text" id="confirmReasonInput" class="w-full bg-white border border-slate-300 rounded px-3 py-2 text-xs font-semibold focus:outline-none focus:border-rose-400" placeholder="e.g. Receipt unreadable">
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="window.closeConfirmModal()" class="flex-1 py-2.5 sm:py-2 px-4 rounded-xl text-[10px] font-bold uppercase tracking-widest text-slate-600 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-colors cursor-pointer">Cancel</button>
                    <button type="button" id="btnConfirmAction" onclick="window.executePaymentAction()" class="flex-1 py-2.5 sm:py-2 px-4 rounded-xl text-[10px] font-bold text-white uppercase tracking-widest shadow-md transition-colors cursor-pointer">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- NEW APPLICANT PROCESSING MODAL -->
    <div id="processNewModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 modal-overlay bg-slate-900/70 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-xl shadow-2xl border-t-4 border-t-[#00205b] w-[95%] sm:w-full max-w-md modal-content flex flex-col">
            <div class="px-4 sm:px-5 py-3.5 sm:py-4 border-b border-slate-200/80 bg-white/95 flex justify-between items-center relative z-20">
                <h3 class="text-xs sm:text-[13px] font-black text-[#00205b] uppercase tracking-[0.1em] flex items-center gap-2 drop-shadow-sm">
                    <div class="p-1 rounded-md bg-[#00205b]/10"><svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg></div>
                    Verify New Payment
                </h3>
                <button type="button" onclick="window.closeProcessNewModal()" class="text-slate-400 hover:text-rose-500 transition-colors bg-slate-50 hover:bg-rose-50 p-1.5 rounded-lg focus:outline-none cursor-pointer">
                    <svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
            
            <form class="p-4 sm:p-5 bg-slate-50/90 relative z-20 rounded-b-xl flex flex-col gap-3" onsubmit="window.handleFormSubmit(event, this)">
                <input type="hidden" name="process_new_payment" value="1">
                <input type="hidden" name="admission_id" id="proc_adm_id">
                
                <div class="text-center bg-white p-2 border border-slate-200 rounded-lg shadow-sm">
                    <h4 class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-0.5">Applicant</h4>
                    <p class="text-sm font-black text-[#00205b] uppercase drop-shadow-sm break-words" id="proc_name">NAME</p>
                    <p class="text-[9px] font-mono text-slate-500 font-bold" id="proc_id">ID</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 ml-1">Bank / Channel <span class="text-rose-500">*</span></label>
                        <select name="paid_through" required class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs font-black text-[#00205b] shadow-inner focus:bg-white focus:outline-none focus:border-[#00205b] focus:ring-1 focus:ring-[#00205b] cursor-pointer transition-colors">
                            <option value="" disabled selected>Select Channel...</option>
                            <?php foreach ($authorized_banks as $bank): ?>
                                <option value="<?= htmlspecialchars($bank['bank_name']) ?>"><?= htmlspecialchars($bank['bank_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 ml-1">O.R. Number <span class="text-rose-500">*</span></label>
                        <input type="text" name="or_number" id="modal_or_number" required pattern="\d{18}" minlength="18" maxlength="18" title="O.R. Number must be exactly 18 digits" 
                            oninput="
                                this.value = this.value.replace(/[^0-9]/g, '');
                                const warn = document.getElementById('or_warning');
                                const counter = document.getElementById('or_counter');
                                const btn = document.getElementById('process_submit_btn');
                                counter.innerText = this.value.length + '/18';
                                
                                if (this.value.length > 0 && this.value.length < 18) {
                                    warn.classList.remove('hidden');
                                    this.classList.add('border-rose-500', 'focus:border-rose-500', 'focus:ring-rose-500');
                                    this.classList.remove('border-slate-200', 'focus:border-[#00205b]', 'focus:ring-[#00205b]', 'border-[#00205b]');
                                    counter.classList.add('text-rose-500');
                                    counter.classList.remove('text-[#00205b]', 'text-slate-400');
                                    btn.disabled = true;
                                    btn.classList.add('opacity-50', 'cursor-not-allowed');
                                } else if (this.value.length === 18) {
                                    warn.classList.add('hidden');
                                    this.classList.remove('border-rose-500', 'focus:border-rose-500', 'focus:ring-rose-500', 'border-slate-200');
                                    this.classList.add('border-[#00205b]', 'focus:border-[#00205b]', 'focus:ring-[#00205b]');
                                    counter.classList.remove('text-rose-500', 'text-slate-400');
                                    counter.classList.add('text-[#00205b]');
                                    btn.disabled = false;
                                    btn.classList.remove('opacity-50', 'cursor-not-allowed');
                                } else {
                                    warn.classList.add('hidden');
                                    this.classList.remove('border-rose-500', 'focus:border-rose-500', 'focus:ring-rose-500', 'border-[#00205b]');
                                    this.classList.add('border-slate-200', 'focus:border-[#00205b]', 'focus:ring-[#00205b]');
                                    counter.classList.remove('text-rose-500', 'text-[#00205b]');
                                    counter.classList.add('text-slate-400');
                                    btn.disabled = true;
                                    btn.classList.add('opacity-50', 'cursor-not-allowed');
                                }
                            " 
                            class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 font-mono text-xs font-black text-[#00205b] uppercase tracking-[0.1em] shadow-inner focus:bg-white focus:outline-none focus:border-[#00205b] focus:ring-1 focus:ring-[#00205b] transition-colors" placeholder="e.g. 123456789012345678">
                        <div class="flex justify-between items-start mt-1 px-1 min-h-[16px]">
                            <p id="or_warning" class="text-[9px] font-bold text-rose-500 hidden flex items-center gap-1 transition-all">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                Incomplete!
                            </p>
                            <p id="or_counter" class="text-[9px] font-mono font-bold text-slate-400 ml-auto">0/18</p>
                        </div>
                    </div>
                </div>

                <div class="border-t border-slate-200/80 pt-3 mt-1">
                    <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 ml-1 drop-shadow-sm">Select Initial Fee <span class="text-xs text-rose-500">*</span></label>
                    <div class="grid grid-cols-2 gap-3">
                        <?php foreach($initial_fees as $fee): ?>
                            <label class="flex flex-col items-center justify-center p-2.5 bg-slate-50 rounded-xl border border-slate-200 hover:bg-white hover:border-[#00205b] hover:shadow-md transition-all cursor-pointer group">
                                <input type="radio" name="fee_assigned" value="<?= htmlspecialchars($fee['fee_name']) ?>" data-amount="<?= $fee['amount'] ?>" data-first="<?= $fee['first_payment'] ?>" required class="fee-radio w-4 h-4 text-[#00205b] border-slate-300 focus:ring-[#00205b] mb-1.5">
                                <span class="text-[10px] font-black text-[#00205b] uppercase tracking-widest text-center mb-1"><?= htmlspecialchars($fee['fee_name']) ?></span>
                                <span class="text-[9px] font-mono text-slate-600 font-bold text-center leading-tight">Full: ₱<?= number_format($fee['amount'], 2) ?><br>1st: ₱<?= number_format($fee['first_payment'], 2) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="border-t border-slate-200/80 pt-3 mt-1">
                    <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 ml-1 drop-shadow-sm">Select Payment Status <span class="text-xs text-rose-500">*</span></label>
                    <div class="grid grid-cols-2 gap-3" id="payment_status_container">
                        <label id="lbl_pay_full" class="flex flex-col items-center justify-center p-2.5 bg-slate-50 rounded-xl border border-slate-200 hover:bg-white hover:border-[#00205b] hover:shadow-md transition-all cursor-pointer group opacity-50 pointer-events-none">
                            <input type="radio" name="payment_type" value="Full" required class="status-radio w-4 h-4 text-[#00205b] border-slate-300 focus:ring-[#00205b] mb-1.5" disabled>
                            <span class="text-[10px] font-black text-[#00205b] uppercase tracking-widest">Full Payment</span>
                        </label>
                        <label id="lbl_pay_partial" class="flex flex-col items-center justify-center p-2.5 bg-slate-50 rounded-xl border border-slate-200 hover:bg-white hover:border-[#00205b] hover:shadow-md transition-all cursor-pointer group opacity-50 pointer-events-none">
                            <input type="radio" name="payment_type" value="Partial" required class="status-radio w-4 h-4 text-[#00205b] border-slate-300 focus:ring-[#00205b] mb-1.5" disabled>
                            <span class="text-[10px] font-black text-[#00205b] uppercase tracking-widest">Partial Payment</span>
                        </label>
                    </div>
                    <p id="payment_status_helper" class="text-[9px] font-bold text-rose-500 mt-1.5 ml-1 italic">Please select an Initial Fee first.</p>
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 ml-1">Amount Paid (₱) <span class="text-rose-500">*</span></label>
                    <div class="relative opacity-90">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <span class="text-[#00205b] font-black text-lg">₱</span>
                        </div>
                        <input type="text" name="amount_paid" id="new_amount_paid" required readonly tabindex="-1" class="w-full bg-slate-100 border border-slate-200 rounded-xl pl-10 pr-4 py-2 font-mono text-lg font-black text-[#00205b] tracking-wider shadow-inner cursor-not-allowed focus:outline-none pointer-events-none select-none transition-colors" placeholder="0.00">
                    </div>
                    <p class="text-[8px] font-bold text-slate-400 mt-1 ml-1 uppercase tracking-widest italic flex items-center gap-1">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2-2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        Strictly locked to database amount
                    </p>
                </div>

                <div class="flex gap-3 mt-1 border-t border-slate-200/80 pt-3">
                    <button type="button" onclick="window.closeProcessNewModal()" class="w-full px-4 py-2 sm:py-2 border border-slate-300 rounded-lg text-[10px] font-bold text-slate-600 uppercase tracking-widest hover:bg-slate-50 cursor-pointer transition-colors shadow-sm text-center">Cancel</button>
                    <button type="submit" id="process_submit_btn" disabled class="w-full bg-[#00205b] hover:bg-[#001233] text-white border border-[#001233] px-4 py-2.5 sm:py-2 rounded-lg text-[10px] font-bold uppercase tracking-widest flex items-center justify-center gap-1.5 cursor-not-allowed opacity-50 shadow-md hover:-translate-y-0.5 transition-transform">
                        Verify & Push
                        <svg class="w-4 h-4 opacity-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- DOCUMENT OVERLAY MODAL -->
    <div id="doc-modal" class="fixed inset-0 z-[9999] hidden items-center justify-center p-4 modal-overlay bg-slate-900/60 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-xl shadow-2xl w-[95%] sm:w-full max-w-3xl flex flex-col modal-content relative overflow-hidden border-t-4 border-t-[#00205b]">
            <div class="px-4 py-3 border-b border-slate-200 bg-slate-50 flex justify-between items-center z-20 shrink-0">
                <h3 class="text-[11px] sm:text-xs font-black text-[#00205b] uppercase tracking-[0.1em] flex items-center gap-1.5 sm:gap-2">
                    <div class="p-1 sm:p-1.5 rounded-md bg-[#00205b]/10"><svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg></div>
                    Document Viewer
                </h3>
                <div class="flex items-center gap-1.5 sm:gap-2">
                    <a href="#" id="docDownloadBtn" download class="bg-white border border-slate-300 hover:bg-slate-100 text-slate-600 px-2.5 sm:px-3 py-1.5 rounded text-[9px] font-bold uppercase tracking-widest transition-colors flex items-center gap-1 shadow-sm">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                        <span class="hidden sm:inline">Download</span>
                    </a>
                    <button type="button" onclick="window.closeDocModal()" class="text-slate-400 hover:text-rose-500 transition-colors bg-white border border-slate-300 hover:bg-rose-50 p-1.5 rounded focus:outline-none cursor-pointer shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </div>
            <div class="relative w-full h-[65vh] sm:h-[70vh] bg-slate-200/50 flex items-center justify-center overflow-hidden" id="doc-container">
                <div id="docLoader" class="absolute flex flex-col items-center justify-center inset-0 z-10 hidden bg-slate-100/80 backdrop-blur-sm">
                    <svg class="w-6 h-6 sm:w-8 sm:h-8 text-[#00205b] animate-spin mb-2 sm:mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span class="text-[9px] sm:text-[10px] font-bold text-[#00205b] uppercase tracking-widest">Loading Document...</span>
                </div>
                <div id="docError" class="hidden flex-col items-center justify-center text-center p-6 sm:p-8 z-10 w-full h-full bg-white">
                    <div class="w-12 h-12 sm:w-16 sm:h-16 bg-rose-50 text-rose-500 rounded-full flex items-center justify-center mb-3 sm:mb-4 border border-rose-100">
                        <svg class="w-6 h-6 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                    <p class="text-rose-600 font-black uppercase tracking-widest text-[10px] sm:text-xs mb-2">Unsupported File Format</p>
                    <p class="text-slate-500 text-[9px] sm:text-[10px] mb-4 max-w-xs leading-relaxed px-4">This file type cannot be previewed in the browser. Please download it to view.</p>
                    <a href="#" id="docFallbackLink" download class="px-3 sm:px-4 py-2 border border-slate-300 rounded text-[9px] sm:text-[10px] font-bold text-slate-700 uppercase tracking-widest hover:bg-slate-50 transition-colors shadow-sm bg-white">Download File Instead</a>
                </div>
                <img id="docImage" src="" class="w-full h-full object-contain hidden p-2 sm:p-4 drop-shadow-md" onload="document.getElementById('docLoader').classList.add('hidden');">
                <iframe id="docIframe" src="" class="w-full h-full bg-white hidden border-0" onload="document.getElementById('docLoader').classList.add('hidden');"></iframe>
            </div>
        </div>
    </div>

    <!-- TOAST NOTIFICATION CONTAINER -->
    <div id="toastContainer" class="fixed top-5 right-5 z-[100] flex flex-col gap-2 pointer-events-none"></div>

    <script src="registrar.js?v=<?= time() ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="payment_verification_management.js?v=<?= time() ?>"></script> 
    
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Live Search Engine
            window.filterTable = function() {
                let input = document.getElementById("liveSearch");
                if (!input) return;
                
                let filter = input.value.toUpperCase();
                let activeTable = document.getElementById('unified_tbody');
                
                if (activeTable) {
                    let tr = activeTable.getElementsByTagName("tr");
                    for (let i = 0; i < tr.length; i++) {
                        let tdName = tr[i].querySelector('.searchable-name');
                        let tdId = tr[i].querySelector('.searchable-id');
                        if (tdName || tdId) { 
                            let txtValue = (tdName ? tdName.textContent || tdName.innerText : "") + " " + (tdId ? tdId.textContent || tdId.innerText : "");
                            if (txtValue.toUpperCase().indexOf(filter) > -1) {
                                tr[i].style.display = "";
                            } else {
                                tr[i].style.display = "none";
                            }
                        }
                    }
                }
            };
            
            <?php if (!empty($success_msg)): ?> window.showToast("<?= addslashes($success_msg) ?>", 'success'); <?php endif; ?>
            <?php if (!empty($error_msg)): ?> window.showToast("<?= addslashes($error_msg) ?>", 'error'); <?php endif; ?>
            
            // --- AUTO-CALCULATE AMOUNT PAID LOGIC ---
            const feeRadios = document.querySelectorAll('.fee-radio');
            const statusRadios = document.querySelectorAll('.status-radio');
            const amountInput = document.getElementById('new_amount_paid');
            const lblPayFull = document.getElementById('lbl_pay_full');
            const lblPayPartial = document.getElementById('lbl_pay_partial');
            const statusHelper = document.getElementById('payment_status_helper');

            let selectedFeeFull = 0;
            let selectedFeeFirst = 0;

            feeRadios.forEach(radio => {
                radio.addEventListener('change', (e) => {
                    selectedFeeFull = parseFloat(e.target.getAttribute('data-amount')) || 0;
                    selectedFeeFirst = parseFloat(e.target.getAttribute('data-first')) || 0;
                    
                    statusRadios.forEach(sr => {
                        sr.disabled = false;
                        sr.checked = false;
                    });
                    
                    if(lblPayFull) lblPayFull.classList.remove('opacity-50', 'pointer-events-none');
                    if(lblPayPartial) lblPayPartial.classList.remove('opacity-50', 'pointer-events-none');
                    if(statusHelper) statusHelper.classList.add('hidden');
                    
                    if(amountInput) amountInput.value = ''; // Reset amount until status is picked
                });
            });

            statusRadios.forEach(radio => {
                radio.addEventListener('change', (e) => {
                    if(amountInput) {
                        if (e.target.value === 'Full') {
                            amountInput.value = selectedFeeFull.toFixed(2);
                        } else if (e.target.value === 'Partial') {
                            amountInput.value = selectedFeeFirst.toFixed(2);
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>