<?php
// 1. ISOLATED SESSION HANDLER FOR STUDENT
if (session_status() === PHP_SESSION_NONE) {
    $session_lifetime = 60 * 60 * 24 * 30; // 30 days
    ini_set('session.gc_maxlifetime', $session_lifetime);
    session_set_cookie_params($session_lifetime, '/'); 
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

include "../dbconn.php";

// =========================================================
// STRICT STUDENT-ONLY ACCESS
// =========================================================
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../index.php"); 
    exit();
}

$student_email = $conn->real_escape_string($_SESSION['email']);
$success_msg = "";
$error_msg = "";

$settings = [];
$settings_q = $conn->query("SELECT * FROM portal_settings");
if ($settings_q) { while ($row = $settings_q->fetch_assoc()) { $settings[$row['setting_key']] = $row['setting_value']; } }
$is_gateway_open = (($settings['enrollment_status'] ?? 'Closed') === 'Open');
$active_school_year = $settings['active_school_year'] ?? '2024-2025';
$active_semester = $settings['active_semester'] ?? '1st Semester';

// Program Shifting Policy: Determine if shifting is enabled for the active open term
$program_shift_period = $settings['program_shift_period'] ?? 'Every New Semester or Year';
$is_shifting_allowed = ($program_shift_period === 'Every New Semester or Year' || stripos($program_shift_period, 'Semester') !== false || $active_semester === '1st Semester');

// Dynamically fetch Accepted and Available types
$accepted_types = ($settings['accepted_types'] ?? '') ? array_map('trim', explode(',', $settings['accepted_types'])) : [];
$available_types_setting = $settings['available_types'] ?? 'Freshman (New),Transferee (New),Returnee (Old),Irregular (Old),Regular (Old)';
$admission_types_list = array_filter(array_map('trim', explode(',', $available_types_setting)));

$prog_list = [];
try {
    $prog_query = $conn->query("SELECT program_name FROM programs WHERE is_archived = 0 OR is_archived IS NULL ORDER BY program_name ASC");
    if (!$prog_query) $prog_query = $conn->query("SELECT program_name FROM programs ORDER BY program_name ASC");
    if ($prog_query && $prog_query->num_rows > 0) { while($row = $prog_query->fetch_assoc()) $prog_list[] = $row['program_name']; }
} catch (Exception $e) {}

// =========================================================
// DATA FETCH: UNIFIED USER & PROFILE
// =========================================================
$user_q = $conn->query("
    SELECT u.id AS user_internal_id, p.* 
    FROM users u 
    LEFT JOIN user_profiles p ON u.id = p.user_id 
    WHERE u.email = '$student_email'
");
$user_data = ($user_q && $user_q->num_rows > 0) ? $user_q->fetch_assoc() : [];
$user_internal_id = (int)($user_data['user_internal_id'] ?? 0);

// =========================================================
// AUTO-MIGRATE SHIFTING COLUMN & PERSONAL EMAIL TRANSACTIONS
// =========================================================
try {
    $check = $conn->query("SHOW COLUMNS FROM enrollment_requests LIKE 'shifting_to'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE enrollment_requests ADD COLUMN shifting_to VARCHAR(150) NULL");
    }
} catch (Exception $e) {}

$conn->query("
    UPDATE transaction_history th
    JOIN admissions a ON th.student_email = a.email
    SET th.student_email = '$student_email'
    WHERE a.provisioned_user_id = $user_internal_id 
      AND th.student_email != '$student_email'
");

// =========================================================
// SMART AUTO-HEAL LOGIC 
// =========================================================
$calc_year_level = '1st Year';
$calc_student_status = 'Regular'; 
$actual_current_program = $user_data['program'] ?? '';

$latest_enr_q = $conn->query("
    SELECT program, year_level, semester, school_year, student_status 
    FROM enrollment_requests 
    WHERE user_id = $user_internal_id 
    AND final_status IN ('Enrolled', 'Registrar Verified', 'Program Head Approved') 
    ORDER BY id DESC LIMIT 1
");

if ($latest_enr_q && $latest_enr_q->num_rows > 0) {
    $last_enr = $latest_enr_q->fetch_assoc();
    $past_yr = $last_enr['year_level'];
    $calc_student_status = $last_enr['student_status'] ?? 'Regular';
    
    $actual_current_program = $last_enr['program'] ?? $actual_current_program;
    
    if (!empty($actual_current_program) && $actual_current_program !== $user_data['program']) {
        $safe_heal_prog = $conn->real_escape_string($actual_current_program);
        $conn->query("UPDATE user_profiles SET program = '$safe_heal_prog' WHERE user_id = $user_internal_id");
        $user_data['program'] = $actual_current_program;
    }

    if (!empty($actual_current_program)) {
        $safe_base_prog = $conn->real_escape_string($actual_current_program);
        $conn->query("
            UPDATE enrollment_requests 
            SET shifting_to = program, program = '$safe_base_prog' 
            WHERE user_id = $user_internal_id 
            AND final_status IN ('Pending', 'Pending Payment', 'Pending Registrar', 'Accounting Verification')
            AND program != '$safe_base_prog' 
            AND (shifting_to IS NULL OR shifting_to = '')
        ");
    }
    
    $calc_year_level = $past_yr;
    
} else {
    $adm_q = $conn->query("SELECT evaluated_year, year_level, evaluated_admission_type FROM admissions WHERE provisioned_user_id = $user_internal_id LIMIT 1");
    if ($adm_q && $adm_q->num_rows > 0) {
        $adm_data = $adm_q->fetch_assoc();
        $calc_year_level = !empty($adm_data['evaluated_year']) ? $adm_data['evaluated_year'] : (!empty($adm_data['year_level']) ? $adm_data['year_level'] : '1st Year');
        $calc_student_status = !empty($adm_data['evaluated_admission_type']) ? $adm_data['evaluated_admission_type'] : 'Regular';
    }
}

// =========================================================
// THE HARD GATEKEEPER (PAST BALANCE CHECKER)
// =========================================================
$debt_q = $conn->query("
    SELECT SUM(balance) as total_debt 
    FROM enrollment_requests 
    WHERE user_id = $user_internal_id 
    AND (school_year != '$active_school_year' OR semester != '$active_semester')
");
$total_debt = ($debt_q && $debt_q->num_rows > 0) ? (float)$debt_q->fetch_assoc()['total_debt'] : 0;
$has_outstanding_balance = ($total_debt > 0);

// =========================================================
// HANDLE FORM SUBMISSIONS (APPLICATION POST)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_enrollment'])) {
    $semester = $conn->real_escape_string($_POST['semester']);
    $year_level = $conn->real_escape_string($_POST['year_level']);
    $learning_mode = $conn->real_escape_string($_POST['learning_mode']);
    $student_type = $conn->real_escape_string($_POST['student_type']); 
    
    $target_program = $conn->real_escape_string($_POST['target_program']);
    $current_prog = $conn->real_escape_string($actual_current_program);
    
    $shifting_to = NULL;
    if ($is_shifting_allowed && !empty($current_prog) && $target_program !== $current_prog) {
        $shifting_to = $target_program;
        $final_program_to_insert = $current_prog; 
    } else {
        $final_program_to_insert = !empty($current_prog) ? $current_prog : $target_program;
    }

    if ($has_outstanding_balance) {
        $error_msg = "Enrollment Blocked: You must clear your previous balance of ₱" . number_format($total_debt, 2) . " before enrolling.";
    } elseif (!$is_gateway_open) {
        $error_msg = "Enrollment is currently closed.";
    } else {
        $dup_check = $conn->query("SELECT id FROM enrollment_requests WHERE user_id = $user_internal_id AND school_year = '$active_school_year' AND semester = '$semester'");
        
        if ($dup_check && $dup_check->num_rows > 0) {
            $error_msg = "You already have an active enrollment application for this term.";
        } else {
            $target_status = 'Pending Payment';
            $insert_sql = "INSERT INTO enrollment_requests (user_id, email, program, shifting_to, year_level, semester, school_year, learning_mode, student_status, final_status, created_at, clearance_file, classcard_file) 
                    VALUES ($user_internal_id, '$student_email', '$final_program_to_insert', " . ($shifting_to ? "'$shifting_to'" : "NULL") . ", '$year_level', '$semester', '$active_school_year', '$learning_mode', '$student_type', '$target_status', NOW(), '', '')";
            
            if ($conn->query($insert_sql)) { 
                $conn->query("UPDATE admissions SET status = 'Accepted' WHERE provisioned_user_id = $user_internal_id");
                $success_msg = "Application submitted! You can now proceed to payment."; 
            } else { $error_msg = "Database Error: Could not submit enrollment request."; }
        }
    }
}

// =========================================================
// DETERMINE ELIGIBILITY
// =========================================================
$student_admission_type = trim($user_data['admission_type'] ?? 'Freshman (New)');
$is_student_type_accepted = false;
$student_type_parts = array_map('trim', explode(',', $student_admission_type));

foreach ($accepted_types as $allowed_type) {
    $allowed_type = trim($allowed_type);
    if(empty($allowed_type)) continue;

    $base_allowed = trim(explode('(', $allowed_type)[0]);

    foreach ($student_type_parts as $part) {
        $base_part = trim(explode('(', $part)[0]);
        if (strcasecmp($part, $allowed_type) === 0 || 
            (!empty($base_part) && !empty($base_allowed) && strcasecmp($base_part, $base_allowed) === 0) || 
            stripos($allowed_type, $base_part) !== false) {
            $is_student_type_accepted = true;
            break 2; 
        }
    }
}

// =========================================================
// THE TIME TRAVELER: FETCH TARGET ENROLLMENT RECORD
// =========================================================
$enroll_q = $conn->query("SELECT * FROM enrollment_requests WHERE user_id = $user_internal_id AND school_year = '$active_school_year' AND semester = '$active_semester' ORDER BY id DESC LIMIT 1");
$enroll_data = ($enroll_q && $enroll_q->num_rows > 0) ? $enroll_q->fetch_assoc() : [];

$is_viewing_past_debt = false;

if (empty($enroll_data)) {
    // Only search for true past debts
    $past_debt_q = $conn->query("SELECT * FROM enrollment_requests WHERE user_id = $user_internal_id AND balance > 0 AND (school_year != '$active_school_year' OR semester != '$active_semester') ORDER BY id DESC LIMIT 1");
    if ($past_debt_q && $past_debt_q->num_rows > 0) {
        $enroll_data = $past_debt_q->fetch_assoc();
        $is_viewing_past_debt = true;
    }
}

$target_school_year = $enroll_data['school_year'] ?? $active_school_year;
$target_semester = $enroll_data['semester'] ?? $active_semester;
$status = $enroll_data['final_status'] ?? 'No Record';
$has_active_request = !empty($enroll_data) && in_array($status, ['Pending', 'Pending Admission Verification', 'Pending Payment', 'Pending Registrar', 'Program Head Approved', 'Registrar Verified', 'Payment Rejected', 'Accounting Verification', 'Enrolled']);

$display_target_program = $user_data['program'] ?? '';
$is_actively_shifting = false;
if ($has_active_request) {
    if (!empty($enroll_data['shifting_to'])) {
        $display_target_program = $enroll_data['shifting_to'];
        $is_actively_shifting = true;
    } else {
        $display_target_program = $enroll_data['program'];
    }
}

// =========================================================
// POST ACTION: PAYMENT UPLOAD (SPA COMPATIBLE)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['upload_payment']) || (isset($_POST['ajax_post']) && isset($_POST['or_number'])))) {
    $or_number = $conn->real_escape_string(trim($_POST['or_number'] ?? ''));
    $transaction_date = date('Y-m-d'); 
    $payment_intent = isset($_POST['payment_intent']) ? $conn->real_escape_string($_POST['payment_intent']) : '';
    $payment_type = $payment_intent ? "Payment for: $payment_intent" : "Initial/Balance Payment";
    $amount_paid_now = (float)($_POST['amount'] ?? 0);
    
    // STRICT 18-DIGIT VALIDATION
    if (strlen($or_number) !== 18 || !ctype_digit($or_number)) {
        $error_msg = "Security Error: Reference number must be exactly 18 numeric digits.";
    } elseif ($amount_paid_now <= 0) {
        $error_msg = "Security Error: Amount must be greater than 0.";
    } else {
        $current_enroll_id = (isset($enroll_data['id'])) ? (int)$enroll_data['id'] : 0;
        
        $dup_query = "
            SELECT 1 FROM transaction_history WHERE or_number = '$or_number'
            UNION
            SELECT 1 FROM enrollment_requests WHERE payment_or_number = '$or_number' AND id != $current_enroll_id
            UNION
            SELECT 1 FROM admissions WHERE initial_or_number = '$or_number' AND provisioned_user_id != $user_internal_id
        ";
        
        $dup_check = $conn->query($dup_query);
        
        if ($dup_check && $dup_check->num_rows > 0) {
            $error_msg = "Transaction Error: This Reference No. is already recorded in the financial ledger. Please verify your receipt to avoid duplication.";
        } else {
            $paid_through = $conn->real_escape_string($_POST['paid_through'] ?? 'Not Specified');
            $target_dir = "../uploads/payments/";
            if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);

            if (isset($_FILES["payment_receipt"]) && $_FILES["payment_receipt"]["error"] == 0) {
                $file_ext = strtolower(pathinfo($_FILES["payment_receipt"]["name"], PATHINFO_EXTENSION));
                $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf'];

                $max_size = 5 * 1024 * 1024; 
                if ($_FILES["payment_receipt"]["size"] > $max_size) {
                    $error_msg = "Your file is too large. Please upload an image under 5MB.";
                } elseif (in_array($file_ext, $allowed_exts)) {
                    $new_filename = "receipt_" . preg_replace('/[^A-Za-z0-9]/', '', $student_email) . "_" . time() . "." . $file_ext;
                    $target_file = $target_dir . $new_filename;

                    if (move_uploaded_file($_FILES["payment_receipt"]["tmp_name"], $target_file)) {
                        $db_filepath = "uploads/payments/" . $new_filename;
                        
                        $update_sql = "UPDATE enrollment_requests SET 
                            payment_proof = '$db_filepath', payment_or_number = '$or_number', transaction_date = '$transaction_date',
                            payment_type = '$payment_type', payment_amount = '$amount_paid_now', paid_through = '$paid_through', final_status = 'Accounting Verification'
                            WHERE user_id = $user_internal_id AND school_year = '$target_school_year' AND semester = '$target_semester' ORDER BY id DESC LIMIT 1";
                        
                        if ($conn->query($update_sql)) { 
                            $conn->query("UPDATE admissions SET pushed_to_accounting = 1 WHERE provisioned_user_id = $user_internal_id");
                            $success_msg = "Payment successfully submitted! Accounting will verify."; 
                            
                            $status = 'Accounting Verification';
                            $enroll_data['final_status'] = 'Accounting Verification';
                            $enroll_data['payment_amount'] = $amount_paid_now;
                            
                        } else { 
                            $error_msg = "Database Error: Could not link the file."; 
                        }
                    } else { $error_msg = "Error uploading file to the server."; }
                } else { $error_msg = "Invalid file type. Only JPG, PNG, and PDF allowed."; }
            } else {
                $error_msg = "Please attach a valid payment receipt.";
            }
        }
    }
}

$authorized_banks = [];
$banks_q = $conn->query("SELECT * FROM bank_accounts ORDER BY id ASC");
if ($banks_q && $banks_q->num_rows > 0) { while ($b = $banks_q->fetch_assoc()) { $authorized_banks[] = $b; } }

if ($status === 'Pending Registrar' && !empty($enroll_data['enrolled_at']) && $enroll_data['enrolled_at'] !== '0000-00-00 00:00:00') {
    $conn->query("UPDATE enrollment_requests SET final_status = 'Enrolled' WHERE id = " . $enroll_data['id']);
    $status = 'Enrolled';
}

$current_student_status = $enroll_data['student_status'] ?? 'Regular'; 
$student_program_raw = !empty($enroll_data['program']) ? $enroll_data['program'] : ($user_data['program'] ?? '');
$student_program_safe = $conn->real_escape_string(trim($student_program_raw));

$student_program_id = 0;
if (!empty($student_program_safe)) {
    $p_id_q = $conn->query("SELECT program_id FROM programs WHERE program_name = '$student_program_safe' LIMIT 1");
    if ($p_id_q && $p_id_q->num_rows > 0) { $student_program_id = (int)$p_id_q->fetch_assoc()['program_id']; }
}

// =========================================================
// FETCH ASSIGNED INITIAL FEE
// =========================================================
$adm_initial_fee = 'Miscellaneous Fee'; 

if ($user_internal_id > 0) {
    $adm_fee_q = $conn->query("SELECT assigned_initial_fee FROM admissions WHERE provisioned_user_id = $user_internal_id LIMIT 1");
    if ($adm_fee_q && $adm_fee_q->num_rows > 0) {
        $adm_row = $adm_fee_q->fetch_assoc();
        $fetched_fee = trim($adm_row['assigned_initial_fee'] ?? '');
        if (!empty($fetched_fee)) {
            $adm_initial_fee = $fetched_fee;
        }
    }
}

$short_initial_fee = 'MISC';
if (stripos($adm_initial_fee, 'Misc') !== false) {
    $short_initial_fee = 'MISC';
} elseif (stripos($adm_initial_fee, 'Tuition') !== false) {
    $short_initial_fee = 'TUITION';
} else {
    $short_initial_fee = strtoupper(explode(' ', $adm_initial_fee)[0]);
}

// =========================================================
// TOTAL PAID / ACCOUNTING MISMATCH AUTO-HEAL 
// =========================================================
$total_paid = (float)($enroll_data['total_paid_accumulated'] ?? 0);

// Fix 1: Pull Walk-In Admission Payments
if ($total_paid == 0 && !empty($enroll_data['id'])) {
    $adm_paid_q = $conn->query("SELECT initial_amount_paid, initial_or_number, initial_fee_assigned, email, enrollment_term FROM admissions WHERE provisioned_user_id = $user_internal_id LIMIT 1");
    if ($adm_paid_q && $adm_paid_q->num_rows > 0) {
        $adm_res = $adm_paid_q->fetch_assoc();
        $walk_in_paid = (float)$adm_res['initial_amount_paid'];
        $current_term_string = ($enroll_data['year_level'] ?? '') . ' - ' . ($enroll_data['semester'] ?? '');
        
        if ($walk_in_paid > 0 && $adm_res['enrollment_term'] === $current_term_string) {
            $or_num = $conn->real_escape_string($adm_res['initial_or_number'] ?? '');
            $p_type = $conn->real_escape_string($adm_res['initial_fee_assigned'] ?? 'Walk-In Payment');
            $e_id = (int)$enroll_data['id'];
            
            $conn->query("UPDATE enrollment_requests SET total_paid_accumulated = $walk_in_paid, payment_amount = $walk_in_paid, payment_or_number = '$or_num', payment_type = 'Initial Fee - $p_type' WHERE id = $e_id");
            $total_paid = $walk_in_paid;
        }
    }
}

// Fix 2: THE "HARD CLEAN" SYNC - STRICTLY BOUNDED BY ACADEMIC YEAR
if (!empty($enroll_data['id'])) {
    $recent_date = date('Y-m-d', strtotime('-6 months'));
    $safe_sy = $conn->real_escape_string($target_school_year);
    $safe_sem = $conn->real_escape_string($target_semester);
    
    // Filter strictly by both semester AND academic year
    $tx_sql = "SELECT SUM(amount) as recent_paid FROM transaction_history 
               WHERE student_email = '$student_email' 
                 AND semester = '$safe_sem'
                 AND academic_year = '$safe_sy'
                 AND transaction_date >= '$recent_date'"; 
                
    $tx_res = $conn->query($tx_sql);
    
    if ($tx_res && $tx_res->num_rows > 0) {
        $sum_tx = (float)$tx_res->fetch_assoc()['recent_paid'];
        
        if ($sum_tx > 0 && $sum_tx != $total_paid) {
            $total_paid = $sum_tx; // Force override UI variable
            $sync_id = (int)$enroll_data['id'];
            $conn->query("UPDATE enrollment_requests SET total_paid_accumulated = '$total_paid' WHERE id = $sync_id");
            $enroll_data['total_paid_accumulated'] = $total_paid;
        }
    }
}

// =========================================================
// UNIFIED FINANCIAL OVERVIEW ENGINE
// =========================================================
$fee_breakdown = []; 
$total_assessed = 0;
$fee_registry = []; // STRICT DEDUPLICATION REGISTRY

$is_enrolled = ($status === 'Enrolled' || !empty($enroll_data['enrolled_at']));
$is_transaction_pending = ($status === 'Accounting Verification');
$is_registrar_evaluating = ($status === 'Pending Registrar'); 

$can_pay_balance = (!$is_transaction_pending && !$is_registrar_evaluating && ($is_enrolled || in_array($status, ['Payment Rejected', 'Pending Payment'])));

if ($has_active_request && !empty($enroll_data['year_level']) && !empty($enroll_data['semester'])) {
    $safe_year_lvl = $conn->real_escape_string($enroll_data['year_level']);
    $safe_sem = $conn->real_escape_string($enroll_data['semester']);

    // 1. GENERAL FEES
    $gen_fees = $conn->query("SELECT fee_name, amount, payment_rule, first_payment, second_payment FROM accounting_fees WHERE (target_program = 'All' OR target_program = '$student_program_safe' OR target_program LIKE '%$student_program_safe%') AND (target_year = 'All' OR target_year = '$safe_year_lvl') AND (target_semester = 'All' OR target_semester = '$safe_sem' OR target_semester IS NULL)");
    
    if ($gen_fees && $gen_fees->num_rows > 0) {
        while ($f = $gen_fees->fetch_assoc()) {
            $clean_name = trim($f['fee_name']);
            if (in_array($clean_name, $fee_registry)) continue; // BLOCK DUPLICATES
            
            $is_tuition = (stripos($clean_name, 'Tuition') !== false);
            $is_misc = (stripos($clean_name, 'Misc') !== false);
            
            if ($current_student_status === 'Regular' && $is_tuition) continue; 
            if ($is_tuition && stripos($adm_initial_fee, 'Misc') !== false) continue;
            if ($is_misc && stripos($adm_initial_fee, 'Tuition') !== false) continue;

            $has_installments = ($f['payment_rule'] === 'Installments Allowed' && (float)$f['first_payment'] > 0);
            $fee_amount = (float)$f['amount'];

            $fee_registry[] = $clean_name;
            if ($has_installments) {
                $fee_breakdown[] = ['type' => 'General Fee', 'name' => $clean_name, 'amount' => $fee_amount, 'is_misc_installment' => true, 'first_amount' => (float)$f['first_payment'], 'second_amount' => (float)$f['second_payment']];
            } else {
                $fee_breakdown[] = ['type' => 'General Fee', 'name' => $clean_name, 'amount' => $fee_amount]; 
            }
            $total_assessed += $fee_amount;
        }
    }

    // 2. SUBJECT-SPECIFIC FEES
    $enrolled_subj_ids = [];
    if (!empty($enroll_data['id'])) {
        $e_q = $conn->query("SELECT subject_id FROM enrollments WHERE enrollment_request_id = " . (int)$enroll_data['id']);
        if ($e_q && $e_q->num_rows > 0) {
            while($r = $e_q->fetch_assoc()) { $enrolled_subj_ids[] = $r['subject_id']; }
        } else {
            $a_q = $conn->query("SELECT evaluated_subjects, enrollment_term FROM admissions WHERE provisioned_user_id = $user_internal_id LIMIT 1");
            if ($a_q && $a_q->num_rows > 0) {
                $adm_row = $a_q->fetch_assoc();
                if ($adm_row['enrollment_term'] === ($safe_year_lvl . ' - ' . $safe_sem) && !empty($adm_row['evaluated_subjects'])) {
                    $enrolled_subj_ids = array_map('intval', explode(',', $adm_row['evaluated_subjects']));
                }
            }
        }
    }

    if (!empty($enrolled_subj_ids)) {
        $id_str = implode(',', array_filter($enrolled_subj_ids));
        if (!empty($id_str)) {
            $subj_fees = $conn->query("SELECT course_code, descriptive_title, subject_fee FROM prospectus WHERE id IN ($id_str) AND subject_fee > 0");
            if ($subj_fees && $subj_fees->num_rows > 0) {
                while ($s = $subj_fees->fetch_assoc()) { 
                    $clean_name = trim($s['course_code'] . ' - ' . $s['descriptive_title']);
                    if (in_array($clean_name, $fee_registry)) continue; // BLOCK DUPLICATES
                    
                    $fee_registry[] = $clean_name;
                    $fee_breakdown[] = ['type' => 'Subject Fee', 'name' => $clean_name, 'amount' => (float)$s['subject_fee']];
                    $total_assessed += (float)$s['subject_fee']; 
                }
            }
        }
    }

    // 3. RETAKE FEES
    if (!empty($enroll_data['retake_fee']) && $enroll_data['retake_fee'] > 0) {
        if (!in_array('Failed/Retake Subject Fees', $fee_registry)) {
            $fee_breakdown[] = ['type' => 'Retake Fee', 'name' => 'Failed/Retake Subject Fees', 'amount' => (float)$enroll_data['retake_fee']];
            $total_assessed += (float)$enroll_data['retake_fee'];
        }
    }
}

// =========================================================
// WATERFALL ALLOCATION & SMART GATEKEEPER CHECK
// =========================================================
usort($fee_breakdown, function($a, $b) use ($adm_initial_fee) {
    $a_is_init = (stripos($a['name'] ?? '', $adm_initial_fee) !== false) ? 0 : 1;
    $b_is_init = (stripos($b['name'] ?? '', $adm_initial_fee) !== false) ? 0 : 1;
    return $a_is_init <=> $b_is_init;
});

$remaining_to_allocate = $total_paid;
foreach ($fee_breakdown as &$fee) {
    $fee['amount_covered'] = 0;
    if ($remaining_to_allocate > 0) {
        $apply = min($fee['amount'], $remaining_to_allocate);
        $fee['amount_covered'] = $apply;
        $remaining_to_allocate -= $apply;
    }
    
    if ($fee['amount_covered'] >= $fee['amount'] - 0.01) {
        $fee['payment_status'] = 'Paid';
    } elseif ($fee['amount_covered'] > 0) {
        $fee['payment_status'] = 'Partial';
    } else {
        $fee['payment_status'] = 'Unpaid';
    }
}
unset($fee);

// MATH-BASED GATEKEEPER
$needs_initial_payment = true;

if ($is_enrolled) {
    $needs_initial_payment = false;
} else {
    foreach ($fee_breakdown as $fee) {
        if (stripos($fee['name'] ?? '', $adm_initial_fee) !== false) {
            if (!empty($fee['is_misc_installment'])) {
                if ($fee['amount_covered'] >= $fee['first_amount'] - 0.01) { $needs_initial_payment = false; }
            } else {
                if ($fee['amount_covered'] >= $fee['amount'] - 0.01) { $needs_initial_payment = false; }
            }
            break; 
        }
    }
}

// SPLIT INSTALLMENTS (Only if Gateway unlocked)
$ui_fee_breakdown = [];
foreach ($fee_breakdown as $fee) {
    $is_assigned_initial = (stripos($fee['name'] ?? '', $adm_initial_fee) !== false);
    $fee['is_assigned_initial'] = $is_assigned_initial;

    if (!empty($fee['is_misc_installment']) && $fee['payment_status'] !== 'Paid' && !$needs_initial_payment) {
        $first_amt = $fee['first_amount'];
        $second_amt = $fee['second_amount'];
        $covered = $fee['amount_covered'];
        
        $first_covered = min($first_amt, $covered);
        $ui_fee_breakdown[] = [
            'type' => 'General Fee',
            'name' => $fee['name'] . ' (1st Installment)',
            'amount' => $first_amt,
            'amount_covered' => $first_covered,
            'payment_status' => ($first_covered >= $first_amt - 0.01) ? 'Paid' : (($first_covered > 0) ? 'Partial' : 'Unpaid'),
            'is_assigned_initial' => $is_assigned_initial,
            'is_misc_installment' => false, 
            'first_amount' => $first_amt,
            'second_amount' => $second_amt,
            'original_name' => $fee['name']
        ];

        $covered_remainder = $covered - $first_covered;
        $second_covered = min($second_amt, $covered_remainder);
        $ui_fee_breakdown[] = [
            'type' => 'General Fee',
            'name' => $fee['name'] . ' (2nd Installment)',
            'amount' => $second_amt,
            'amount_covered' => $second_covered,
            'payment_status' => ($second_covered >= $second_amt - 0.01) ? 'Paid' : (($second_covered > 0) ? 'Partial' : 'Unpaid'),
            'is_assigned_initial' => false, 
            'is_misc_installment' => false 
        ];
    } else {
        $ui_fee_breakdown[] = $fee;
    }
}

// Calculate true display balances
$total_due_now = 0;
foreach ($ui_fee_breakdown as $fee) {
    $total_due_now += ($fee['amount'] - $fee['amount_covered']);
}

$remaining_balance = max(0, $total_due_now);
$pending_payment_amount = ($status === 'Accounting Verification') ? (float)($enroll_data['payment_amount'] ?? 0) : 0;
$display_balance = max(0, $remaining_balance - $pending_payment_amount);

if (!empty($enroll_data['id'])) {
    $sync_id = (int)$enroll_data['id'];
    $conn->query("UPDATE enrollment_requests SET assessed_fee = '$total_assessed', balance = '$remaining_balance' WHERE id = $sync_id");
}

// =========================================================
// PROGRESS TRACKER ENGINE 
// =========================================================
$has_inst_email = !empty($user_data['institutional_email']) && strpos($user_data['institutional_email'], '@mstip.edu.ph') !== false;
$is_returning = in_array($current_student_status, ['Regular', 'Irregular', 'Returnee']);

$tracker_state = 1; 
if ($is_enrolled) { $tracker_state = 3; } 
elseif ($has_inst_email || $is_returning) { $tracker_state = 2; }

$s1_state = 'pending'; $s2_state = 'pending'; $s3_state = 'pending'; $s4_state = 'pending';
$line_width = '0%';

if ($tracker_state === 3) {
    if ($remaining_balance <= 0 && !in_array($status, ['Payment Rejected', 'Pending Registrar', 'Accounting Verification'])) { $s2_state = 'completed'; } else { $s2_state = 'active'; }
} elseif ($tracker_state === 2) {
    if ($status === 'Pending Admission Verification' || $status === 'Pending') { $s1_state = 'active'; } 
    elseif (in_array($status, ['Pending Payment', 'Accounting Verification', 'Payment Rejected'])) { $s1_state = 'completed'; $s2_state = 'active'; $line_width = '50%'; } 
    elseif ($status === 'Pending Registrar') { $s1_state = 'completed'; $s2_state = 'completed'; $s3_state = 'active'; $line_width = '100%'; } 
    else { $s1_state = 'active'; }
} else {
    if ($status === 'Pending Admission Verification' || $status === 'Pending') { $s1_state = 'active'; } 
    elseif (in_array($status, ['Pending Payment', 'Accounting Verification', 'Payment Rejected'])) { $s1_state = 'completed'; $s2_state = 'active'; $line_width = '33%'; } 
    elseif ($status === 'Pending Registrar') { $s1_state = 'completed'; $s2_state = 'completed'; $s3_state = 'active'; $line_width = '66%'; } 
    elseif ($status === 'Account Creation' || $status === 'Registrar Verified') { $s1_state = 'completed'; $s2_state = 'completed'; $s3_state = 'completed'; $s4_state = 'active'; $line_width = '100%'; } 
    else { $s1_state = 'active'; }
}

if (!function_exists('renderStep')) {
    function renderStep($title, $state, $id_prefix, $actual_num) {
        $icon_class = "w-7 h-7 rounded-full flex items-center justify-center font-bold text-[10px] border-[2px] transition-all duration-500 z-10 ";
        $text_class = "absolute top-8 text-[8px] font-black uppercase tracking-wider text-center w-20 ";
        $content = $actual_num;

        if ($state === 'completed') {
            $icon_class .= "border-emerald-500 bg-emerald-500 text-white";
            $text_class .= "text-emerald-700";
            $content = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>';
        } elseif ($state === 'active') {
            $icon_class .= "border-[#00205b] bg-indigo-50 text-[#00205b] shadow-[0_0_0_4px_rgba(0,32,91,0.1)] animate-pulse";
            $text_class .= "text-[#00205b]";
        } else {
            $icon_class .= "border-slate-300 bg-white text-slate-400";
            $text_class .= "text-slate-400";
        }

        return '
        <div id="'.$id_prefix.'-wrapper" class="relative z-10 flex flex-col items-center group">
            <div id="'.$id_prefix.'-icon" class="'.$icon_class.'">'.$content.'</div>
            <div id="'.$id_prefix.'-text" class="'.$text_class.'">'.$title.'</div>
        </div>';
    }
}

$tracker_html = '<div class="relative flex items-center ' . ($tracker_state === 3 ? 'justify-center' : 'justify-between') . ' w-[90%] mx-auto max-w-[500px]">';
if ($tracker_state !== 3) {
    $tracker_html .= '<div class="absolute left-0 top-[14px] w-full h-[3px] bg-slate-100 rounded z-0"></div>';
    $tracker_html .= '<div class="absolute left-0 top-[14px] h-[3px] bg-emerald-500 rounded transition-all duration-700 ease-out z-0" style="width: '.$line_width.';"></div>';
}
if ($tracker_state !== 3) { $tracker_html .= renderStep("Admissions<br>Review", $s1_state, "step1", '1'); }

$s2_num = ($tracker_state === 3) ? '1' : '2';
$tracker_html .= renderStep("Accounting<br>Payment", $s2_state, "step2", $s2_num);

if ($tracker_state !== 3) { $tracker_html .= renderStep("Registrar<br>Evaluation", $s3_state, "step3", '3'); }
if ($tracker_state === 1) { $tracker_html .= renderStep("Account<br>Creation", $s4_state, "step4", '4'); }
$tracker_html .= '</div>';

ob_start();
?>
    <input type="hidden" id="current_enrollment_status" value="<?= htmlspecialchars($status) ?>">
    <input type="hidden" id="current_balance" value="<?= htmlspecialchars($display_balance) ?>">

    <header class="mb-8 flex flex-col md:flex-row md:justify-between items-start md:items-end gap-4 border-b border-slate-300/60 pb-6 no-print">
        <div>
            <h1 class="text-3xl md:text-4xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">
                <?= $has_active_request ? 'Payment & Fees' : 'Enrollment Application' ?>
            </h1>
            <p class="text-slate-500 text-sm flex items-center gap-2 font-medium mt-2">
                <?= $has_active_request ? 'Manage your financial obligations and upload proof of payment.' : 'Submit your academic application for the current term.' ?>
            </p>
        </div>
        <div class="text-left md:text-right">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1 drop-shadow-sm">
                <?= $is_viewing_past_debt ? '<span class="text-rose-500">PAST TERM DEBT</span>' : 'Active Enrollment Term' ?>
            </p>
            <p class="text-sm font-black text-[#00205b] drop-shadow-sm"><?= htmlspecialchars($target_semester ?? '') ?> (<?= htmlspecialchars($target_school_year ?? '') ?>)</p>
        </div>
    </header>

    <div>
        <?php if ($is_viewing_past_debt): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-800 p-5 rounded-xl text-sm font-bold shadow-sm relative z-20 flex items-start gap-3 mb-6">
                <svg class="w-6 h-6 text-rose-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <div>
                    <span class="block uppercase tracking-widest text-[11px] font-black text-rose-600 mb-1">Past Term Balance Detected</span>
                    You are viewing an unpaid balance from <strong><?= htmlspecialchars($target_semester) ?> (<?= htmlspecialchars($target_school_year) ?>)</strong>. You must settle this balance before you can enroll in the current active term.
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$has_active_request): ?>
            
            <div class="bg-white p-6 md:p-8 rounded-2xl mb-8 border border-slate-200 border-t-[5px] !border-t-[#00205b] relative overflow-hidden shadow-sm">
                <?php if (!$is_gateway_open || !$is_student_type_accepted): ?>
                    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-[2px] z-30 flex flex-col items-center justify-center p-6 text-center">
                        <div class="bg-white p-8 rounded-2xl shadow-2xl max-w-lg w-full border-t-4 border-rose-500">
                            <div class="w-16 h-16 mx-auto bg-rose-50 text-rose-500 rounded-full flex items-center justify-center mb-4 border border-rose-100 shadow-inner">
                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2-2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            </div>
                            <h3 class="text-xl font-black text-slate-800 uppercase tracking-widest mb-2">Enrollment Closed</h3>
                            <?php if(!$is_gateway_open): ?>
                                <p class="text-sm text-slate-500 font-medium">The Registrar has temporarily closed the enrollment portal. Please wait for the next term to open.</p>
                            <?php else: ?>
                                <p class="text-sm text-slate-500 font-medium">The portal is currently open, but it is not accepting applications for your admission type (<strong class="text-rose-600"><?= htmlspecialchars($student_admission_type) ?></strong>) right now.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <h3 class="font-black text-[#00205b] text-lg uppercase tracking-tight font-academic mb-6 drop-shadow-sm flex items-center gap-2 relative z-20">
                    <div class="p-1.5 rounded-md bg-[#00205b]/10"><svg class="w-5 h-5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg></div>
                    Enrollment Application (<?= htmlspecialchars($active_semester) ?>)
                </h3>
                
                <form id="enrollmentForm" onsubmit="return window.submitPaymentForm(event, this);" method="POST" enctype="multipart/form-data" class="space-y-6 relative z-20">
                    <div class="grid grid-cols-1 md:grid-cols-5 gap-5">
                        
                        <div class="md:col-span-2 relative">
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2 ml-1 tracking-widest drop-shadow-sm">Target Program</label>
                            
                            <div class="flex items-stretch rounded-md shadow-sm border border-slate-300 overflow-hidden group">
                                <input type="hidden" name="target_program" id="target_program" value="<?= htmlspecialchars($display_target_program) ?>">
                                <input type="hidden" id="current_program_hidden" value="<?= htmlspecialchars($actual_current_program) ?>">
                                
                                <div class="flex-1 bg-slate-50 px-3 py-2 flex items-center overflow-hidden">
                                    <span id="display_target_program_text" class="font-bold text-xs truncate <?= $is_actively_shifting ? 'text-amber-600' : 'text-[#00205b]' ?>">
                                        <?= htmlspecialchars($display_target_program) ?>
                                    </span>
                                </div>
                                
                                <?php if ($is_shifting_allowed): ?>
                                    <button type="button" onclick="window.openShiftModal()" class="shrink-0 bg-white hover:bg-[#00205b] text-[#00205b] hover:text-white border-l border-slate-300 px-4 py-2 text-[10px] font-black uppercase tracking-widest transition-colors focus:outline-none flex items-center justify-center gap-1.5 cursor-pointer" title="Request a Program Shift for this Term">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                                        Shift
                                    </button>
                                <?php else: ?>
                                    <span class="shrink-0 bg-slate-100 text-slate-400 border-l border-slate-300 px-3 py-2 text-[9px] font-bold uppercase tracking-wider flex items-center cursor-not-allowed" title="Program shifting is restricted to 1st Semester">
                                        Locked
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <p id="shift_warning" class="<?= $is_actively_shifting ? 'flex' : 'hidden' ?> text-[9px] font-bold text-amber-600 mt-2 bg-amber-50 border border-amber-200 px-2 py-1.5 rounded items-center gap-1.5 shadow-sm leading-tight transition-all">
                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg> 
                                You requested a program shift for <?= htmlspecialchars($active_semester) ?>. The Registrar will manually evaluate this request.
                            </p>
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2 ml-1 tracking-widest drop-shadow-sm">Student Type</label>
                            <input type="text" value="<?= htmlspecialchars($calc_student_status) ?>" readonly class="w-full bg-slate-100 border border-slate-200 text-slate-600 font-bold text-xs rounded-md px-3 py-2 cursor-not-allowed shadow-inner focus:outline-none">
                            <input type="hidden" name="student_type" value="<?= htmlspecialchars($calc_student_status) ?>">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2 ml-1 tracking-widest drop-shadow-sm">Applying For Year</label>
                            <input type="text" name="year_level" value="<?= htmlspecialchars($calc_year_level) ?>" readonly class="w-full bg-slate-100 border border-slate-200 text-slate-600 font-bold text-xs rounded-md px-3 py-2 cursor-not-allowed shadow-inner focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2 ml-1 tracking-widest drop-shadow-sm">Modality</label>
                            <select name="learning_mode" required class="w-full bg-white border border-slate-300 rounded-md px-3 py-2 cursor-pointer text-xs font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b]">
                                <option value="Hybrid">Hybrid</option>
                                <option value="Online">Online</option>
                                <option value="Modular">Modular</option>
                            </select>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-slate-100">
                        <button type="button" onclick="window.openAgreementModal()" class="w-full sm:w-auto sm:float-right bg-[#00205b] hover:bg-[#001233] text-white border border-[#001233] px-8 py-3 rounded-lg text-xs font-black uppercase tracking-widest shadow-sm transition-all flex items-center justify-center gap-2 focus:outline-none" <?= (!$is_gateway_open || !$is_student_type_accepted || $has_outstanding_balance) ? 'disabled' : '' ?>>
                            <?php if ($has_outstanding_balance): ?>
                                <svg class="w-4 h-4 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2-2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg> Locked: Outstanding Balance
                            <?php else: ?>
                                Proceed & Review Agreement <svg class="w-4 h-4 opacity-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                            <?php endif; ?>
                        </button>
                        <div class="clear-both"></div>
                        <input type="hidden" name="semester" value="<?= htmlspecialchars($active_semester) ?>">
                    </div>
                </form>
            </div>

        <?php else: ?>
            
            <!-- PROGRESS TRACKER -->
            <div class="bg-white p-6 border border-slate-200 border-t-[4px] !border-t-[#00205b] rounded-lg shadow-sm w-full mb-6 relative z-20">
                <h3 class="font-black text-slate-400 text-[10px] uppercase tracking-widest drop-shadow-sm text-center mb-6">Enrollment Progress</h3>
                <?php echo $tracker_html; ?>
                <div class="h-6"></div>
            </div>

            <?php if ($status === 'Pending Registrar'): ?>
                <div class="bg-blue-50/90 backdrop-blur-sm border border-blue-200 text-blue-800 p-5 rounded-xl text-sm font-bold flex items-center gap-3 shadow-sm relative z-20 mb-6 transition-all">
                    <svg class="w-5 h-5 animate-spin shrink-0 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Payment verified by Accounting. Waiting for Registrar to officially enroll you.
                </div>
            <?php endif; ?>
            
            <div class="bg-white rounded-lg border border-slate-200 border-t-[4px] !border-t-[#00205b] shadow-sm flex flex-col lg:flex-row divide-y lg:divide-y-0 lg:divide-x divide-slate-200 relative z-20 transition-all overflow-hidden">
                
                <div class="lg:w-2/3 flex flex-col bg-white">
                    <div class="px-4 sm:px-6 py-5 border-b border-slate-200 bg-slate-50 flex justify-between items-center shadow-sm relative z-20">
                        <div>
                            <h3 class="font-black text-[#00205b] text-sm uppercase tracking-widest drop-shadow-sm flex items-center gap-2">
                                <div class="p-1.5 rounded-md bg-[#00205b]/10"><svg class="w-4 h-4 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg></div>
                                <?= !$is_enrolled ? 'Initial Enrollment Assessment' : 'Statement of Account' ?>
                            </h3>
                            <p class="text-[9px] text-slate-500 font-bold uppercase tracking-widest mt-1 ml-9">Official Fee Breakdown</p>
                        </div>
                        <?php if ($can_pay_balance && $remaining_balance > 0): ?>
                            <div class="text-[9px] text-emerald-700 font-black uppercase tracking-widest bg-emerald-50 px-3 py-1.5 rounded-lg border border-emerald-200 shadow-sm flex items-center gap-1 hidden sm:flex">
                                Select Unpaid Fees to Pay <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="relative z-30 w-full flex-1 pb-40 lg:pb-10">
                        <table class="w-full text-left text-xs">
                            <thead class="text-[8px] sm:text-[9px] uppercase tracking-widest text-slate-500 bg-slate-50 border-b border-slate-200 font-black sticky top-0 z-10">
                                <tr>
                                    <th class="px-3 sm:px-6 py-3 sm:py-4">Fee Description</th>
                                    <th class="px-2 sm:px-6 py-3 sm:py-4 text-right">Amount</th>
                                    <th class="px-2 sm:px-6 py-3 sm:py-4 text-center w-[80px] sm:w-[140px] md:w-[180px]">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-transparent font-medium">
                                <?php if(empty($ui_fee_breakdown)): ?>
                                    <tr><td colspan="3" class="py-12 text-center text-slate-400 italic text-[11px] font-bold uppercase tracking-widest bg-slate-50 border-b border-slate-100">No active fee assessment found.</td></tr>
                                <?php else: ?>
                                    <?php 
                                    foreach($ui_fee_breakdown as $fee): 
                                        $unpaid_amount = $fee['amount'] - $fee['amount_covered'];
                                        $hash_id = md5($fee['name']);
                                    ?>
                                    <tr class="<?= $fee['payment_status'] === 'Paid' ? 'bg-emerald-50/30' : 'hover:bg-slate-50 transition-colors' ?> group relative">
                                        <td class="px-3 sm:px-6 py-4 align-top">
                                            <span id="basename_<?= $hash_id ?>" class="block text-[#00205b] font-bold text-[11px] sm:text-xs base-fee-name leading-tight sm:leading-normal mb-0.5 sm:mb-0"><?= htmlspecialchars($fee['name']) ?></span>
                                            <span class="block text-[8px] uppercase tracking-widest text-slate-400 font-black mt-1"><?= htmlspecialchars($fee['type']) ?></span>

                                            <?php if (!empty($fee['is_misc_installment']) && in_array($status, ['Pending Payment', 'Payment Rejected']) && $fee['payment_status'] === 'Unpaid'): ?>
                                                <div class="mt-3 flex items-center gap-4 bg-white px-4 py-3 rounded-lg border border-slate-200 w-max relative z-20 shadow-sm">
                                                    <label class="flex items-center gap-2 cursor-pointer group/btn">
                                                        <input type="radio" name="plan_<?= $hash_id ?>" value="full" class="misc-plan-toggle w-3.5 h-3.5 text-blue-600 bg-gray-100 border-gray-300 focus:ring-blue-500 cursor-pointer transition-all" data-target="<?= $hash_id ?>" data-full="<?= $fee['amount'] ?>" data-first="<?= $fee['first_amount'] ?>" data-second="<?= $fee['second_amount'] ?>" checked>
                                                        <span class="text-[10px] font-black text-slate-600 group-hover/btn:text-[#00205b] uppercase tracking-widest transition-colors">Full Payment</span>
                                                    </label>
                                                    <label class="flex items-center gap-2 cursor-pointer group/btn">
                                                        <input type="radio" name="plan_<?= $hash_id ?>" value="partial" class="misc-plan-toggle w-3.5 h-3.5 text-blue-600 bg-gray-100 border-gray-300 focus:ring-blue-500 cursor-pointer transition-all" data-target="<?= $hash_id ?>" data-full="<?= $fee['amount'] ?>" data-first="<?= $fee['first_amount'] ?>" data-second="<?= $fee['second_amount'] ?>">
                                                        <span class="text-[10px] font-black text-slate-600 group-hover/btn:text-[#00205b] uppercase tracking-widest transition-colors">Partial Payment</span>
                                                    </label>
                                                </div>

                                                <div id="float_<?= $hash_id ?>" class="hidden absolute right-0 sm:right-auto sm:left-10 top-[85%] mt-2 z-[60] bg-white border border-blue-200 shadow-[0_20px_50px_rgba(0,32,91,0.15)] rounded-xl p-4 sm:p-5 w-[200px] sm:w-72 transition-all">
                                                    <div class="absolute -top-2 left-6 w-4 h-4 bg-white border-t border-l border-blue-200 transform rotate-45 hidden sm:block"></div>
                                                    <h4 class="text-[9px] sm:text-[11px] font-black text-[#00205b] mb-3 uppercase border-b border-slate-100 pb-2 relative z-10 tracking-widest">PARTIAL PAYMENT BREAKDOWN</h4>
                                                    <div class="flex justify-between items-center text-[9px] sm:text-[11px] mb-2 relative z-10">
                                                        <span class="text-slate-500 font-bold leading-tight">1st Installment <span class="text-[8px] sm:text-[9px] font-normal block mt-0.5">(Due Now)</span></span>
                                                        <span class="font-mono font-black text-[#00205b] text-xs sm:text-sm">₱<?= number_format($fee['first_amount'], 2) ?></span>
                                                    </div>
                                                    <div class="flex justify-between items-center text-[9px] sm:text-[11px] mb-4 relative z-10">
                                                        <span class="text-slate-500 font-bold leading-tight">2nd Installment <span class="text-[8px] sm:text-[9px] font-normal block mt-0.5">(Billed Later)</span></span>
                                                        <span class="font-mono font-black text-[#00205b] text-xs sm:text-sm">₱<?= number_format($fee['second_amount'], 2) ?></span>
                                                    </div>
                                                    <button type="button" class="w-full bg-[#00205b] hover:bg-[#001233] text-white border border-[#001233] font-bold text-[9px] sm:text-[10px] py-2 sm:py-2.5 rounded-lg transition-colors confirm-partial-btn uppercase tracking-widest relative z-10 focus:outline-none" data-target="<?= $hash_id ?>">Confirm</button>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-2 sm:px-6 py-4 text-right align-top">
                                            <span class="font-mono font-black text-[#00205b] text-[10px] sm:text-sm opacity-80" id="amt_<?= $hash_id ?>">₱<?= number_format($fee['amount'], 2) ?></span>
                                        </td>
                                        <td class="px-2 sm:px-6 py-4 text-center align-top w-[100px] sm:w-[150px]">
                                            <?php if($fee['payment_status'] === 'Paid'): ?>
                                                <span class="bg-emerald-100/80 text-emerald-800 border border-emerald-300/80 px-2 py-1.5 rounded-md text-[9px] font-black uppercase tracking-widest w-full block shadow-sm">Paid</span>
                                            <?php elseif($fee['payment_status'] === 'Partial'): ?>
                                                <div class="flex flex-col gap-1.5">
                                                    <div class="bg-amber-100/80 text-amber-800 border border-amber-300/80 px-2 py-1.5 rounded-md text-[9px] font-black uppercase tracking-widest w-full text-center shadow-sm">Partial</div>
                                                    
                                                    <?php if($can_pay_balance): ?>
                                                        <label class="flex flex-col items-center justify-center p-2 bg-white rounded-lg border border-emerald-200 shadow-sm cursor-pointer hover:bg-emerald-50 transition-colors w-full">
                                                            <div class="flex items-center gap-2">
                                                                <input type="checkbox" class="fee-selector w-3 h-3 text-emerald-600 rounded border-slate-300 focus:ring-emerald-500 shadow-sm cursor-pointer" id="cb_<?= $hash_id ?>" value="<?= htmlspecialchars($fee['name']) ?> (Balance)" data-amount="<?= $unpaid_amount ?>" onchange="window.updateSelectedFees()">
                                                                <span class="text-[9px] font-black text-emerald-700 uppercase tracking-widest leading-none mt-0.5">Pay Bal</span>
                                                            </div>
                                                            <span class="text-[9px] sm:text-[10px] font-mono font-black text-[#00205b] mt-1.5" id="cbamt_<?= $hash_id ?>">₱<?= number_format($unpaid_amount, 2) ?></span>
                                                        </label>
                                                    <?php else: ?>
                                                        <span class="block text-[9px] sm:text-[10px] text-[#00205b] font-mono font-black bg-slate-50 py-1 rounded border border-slate-200 shadow-sm w-full text-center">Bal: ₱<?= number_format($unpaid_amount, 2) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <?php 
                                                $is_foundational_fee = !empty($fee['is_assigned_initial']);
                                                $is_locked_by_gatekeeper = ($needs_initial_payment && !$is_foundational_fee);
                                                $row_clickable = ($can_pay_balance && !$is_locked_by_gatekeeper && !$is_transaction_pending && !$is_registrar_evaluating);
                                                ?>
                                                
                                                <?php if($is_transaction_pending): ?>
                                                    <span class="text-[8px] font-black text-amber-500 uppercase tracking-widest">Pending Verif.</span>
                                                <?php elseif($is_registrar_evaluating): ?>
                                                    <span class="text-[8px] font-black text-[#00205b] uppercase tracking-widest">Locked</span>
                                                <?php elseif($is_locked_by_gatekeeper): ?>
                                                    <span class="text-[8px] font-black text-rose-500 uppercase tracking-widest border border-rose-500/30 bg-rose-500/10 px-2 py-1 rounded block">Pay <?= htmlspecialchars($short_initial_fee) ?> 1st</span>
                                                <?php else: ?>
                                                    <label class="flex flex-col items-center justify-center p-2 bg-white rounded-lg border border-slate-200 shadow-sm transition-all <?= $row_clickable ? 'cursor-pointer hover:bg-emerald-50 hover:border-emerald-300 hover:shadow-md' : 'cursor-not-allowed opacity-60 bg-slate-50' ?> w-full">
                                                        <div class="flex items-center gap-2">
                                                            <?php if($row_clickable): ?>
                                                                <input type="checkbox" class="fee-selector w-3.5 h-3.5 text-[#00205b] rounded border-slate-300 focus:ring-[#00205b] shadow-sm cursor-pointer" id="cb_<?= $hash_id ?>" value="<?= trim(htmlspecialchars($fee['name']) . (!empty($fee['is_misc_installment']) ? ' (Full Payment)' : '')) ?>" data-amount="<?= $unpaid_amount ?>" onchange="window.updateSelectedFees()" <?= (!empty($fee['is_misc_installment']) || $is_foundational_fee) ? 'checked' : '' ?>>
                                                            <?php else: ?>
                                                                <input type="checkbox" class="fee-selector w-3.5 h-3.5 text-[#00205b] rounded border-slate-300 bg-slate-100 cursor-not-allowed shadow-inner" id="cb_<?= $hash_id ?>" value="<?= htmlspecialchars($fee['name']) ?>" data-amount="<?= $unpaid_amount ?>" disabled>
                                                            <?php endif; ?>
                                                            <span class="text-[9px] font-black text-[#00205b] uppercase tracking-widest leading-none mt-0.5" id="lbl_<?= $hash_id ?>">
                                                                <?= (!empty($fee['is_misc_installment']) && !empty($fee['is_assigned_initial'])) ? "FULL" : "PAY" ?>
                                                            </span>
                                                        </div>
                                                        <span class="text-[9px] sm:text-[10px] font-mono font-black text-[#00205b] mt-1.5" id="cbamt_<?= $hash_id ?>">₱<?= number_format($unpaid_amount, 2) ?></span>
                                                    </label>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="mt-auto border-t border-slate-200 bg-slate-50 p-4 sm:p-6 relative z-20 rounded-bl-lg">
                        <div class="space-y-3">
                            <div class="flex justify-between items-center text-[11px] font-bold text-slate-500">
                                <span class="uppercase text-[9px] tracking-widest">Total Assessed</span>
                                <span class="font-mono text-sm text-slate-700">₱<?= number_format($total_assessed, 2) ?></span>
                            </div>
                            <div class="flex justify-between items-center text-[11px] font-bold text-[#00205b] border-b border-slate-200/80 pb-3">
                                <span class="uppercase text-[9px] tracking-widest">Total Paid History</span>
                                <span class="font-mono text-sm">- ₱<?= number_format($total_paid, 2) ?></span>
                            </div>
                            <?php if ($pending_payment_amount > 0): ?>
                            <div class="flex justify-between items-center text-[11px] font-bold text-amber-600 border-b border-slate-200/80 pb-3">
                                <span class="uppercase text-[9px] tracking-widest flex items-center gap-1.5"><svg class="w-3.5 h-3.5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Pending Verif.</span>
                                <span class="font-mono text-sm">- ₱<?= number_format($pending_payment_amount, 2) ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="flex justify-between items-center text-base font-black text-[#00205b] pt-1 drop-shadow-sm">
                                <span class="uppercase text-[11px] tracking-[0.2em] text-[#00205b]">Total Balance Due</span>
                                <span class="font-mono text-xl">₱<?= number_format($display_balance, 2) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- RIGHT COLUMN: PAYMENT PORTAL OR CLEARED STATE -->
                <div id="payment-right-panel" class="lg:w-1/3 flex flex-col bg-white relative z-20 border-t lg:border-t-0 lg:border-l border-slate-200 rounded-br-lg">
                    <?php if ($status === 'Accounting Verification' || $status === 'Pending Registrar' || ($remaining_balance <= 0 && $status !== 'Payment Rejected')): ?>
                        <div class="flex-1 flex flex-col items-center justify-center text-center p-10 min-h-[300px]">
                            <?php if($remaining_balance <= 0 && $status !== 'Payment Rejected' && $status !== 'Pending Registrar'): ?>
                                <div class="w-16 h-16 bg-[#dcfce7] text-[#166534] rounded-full flex items-center justify-center mb-4 shadow-sm border border-[#bbf7d0]">
                                    <svg class="w-8 h-8 drop-shadow-sm" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                </div>
                                <h4 class="font-black text-[#166534] text-sm uppercase tracking-[0.15em] mb-2 drop-shadow-sm">Account Cleared</h4>
                                <p class="text-[11px] font-semibold text-slate-500">You have fully paid your assessed fees.</p>
                            <?php elseif($status === 'Pending Registrar'): ?>
                                <div class="w-16 h-16 bg-blue-100 text-[#00205b] rounded-full flex items-center justify-center mb-4 shadow-sm border border-blue-200">
                                    <svg class="w-8 h-8 drop-shadow-sm" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2-2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                </div>
                                <h4 class="font-black text-[#00205b] text-sm uppercase tracking-[0.15em] mb-2 drop-shadow-sm">Account Locked</h4>
                                <p class="text-[11px] font-semibold text-slate-500 max-w-[250px]">Your payment was verified. The Registrar is currently mapping your official curriculum. Payments are locked to prevent duplicate charges.</p>
                            <?php else: ?>
                                <div class="w-16 h-16 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mb-4 shadow-sm border border-amber-200">
                                    <svg class="w-8 h-8 animate-spin drop-shadow-sm" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                </div>
                                <h4 class="font-black text-amber-700 text-sm uppercase tracking-[0.15em] mb-2 drop-shadow-sm">Verifying Payment</h4>
                                <p class="text-[11px] font-semibold text-slate-500 max-w-[250px]">Accounting is reviewing your latest transaction. Additional payments are temporarily locked to prevent duplicate charges.</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="px-6 py-5 border-b border-slate-200 bg-slate-50 flex justify-between items-center shadow-sm relative z-20">
                            <div>
                                <h3 class="font-black text-[#00205b] text-sm uppercase tracking-widest drop-shadow-sm flex items-center gap-2">
                                    <div class="p-1.5 rounded-md bg-[#00205b]/10"><svg class="w-4 h-4 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg></div>
                                    Submit Payment
                                </h3>
                                <p class="text-[9px] text-slate-500 font-bold uppercase tracking-widest mt-1 ml-9">Enter details to clear your balance.</p>
                            </div>
                        </div>

                        <form id="paymentUploadForm" onsubmit="return window.submitPaymentForm(event, this);" method="POST" enctype="multipart/form-data" class="flex-1 flex flex-col p-6 md:p-8 relative z-30">
                            <fieldset class="contents">
                                <input type="hidden" name="payment_intent" id="payment_intent" value="">
                                <input type="hidden" name="amount" id="amount_paid" value="0.00">

                                <?php if($status === 'Payment Rejected'): ?>
                                    <div class="bg-rose-50/80 backdrop-blur-sm border border-rose-200 text-rose-800 p-3 rounded-lg text-[10px] font-black mb-5 uppercase tracking-widest flex items-center gap-2 shadow-sm drop-shadow-sm">
                                        <svg class="w-4 h-4 shrink-0 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Accounting rejected your last submission. Please upload a valid receipt or re-submit.
                                    </div>
                                <?php endif; ?>

                                <div class="space-y-5 flex-1 flex flex-col">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                                        <div>
                                            <div class="flex justify-between items-center mb-1.5 px-1 min-h-[14px]">
                                                <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest drop-shadow-sm leading-none m-0">Reference No.</label>
                                                <span id="ref_count_display" class="text-[9px] font-black text-rose-500 tracking-widest transition-colors leading-none m-0">0/18</span>
                                            </div>
                                            <input type="text" name="or_number" id="or_number_input" required minlength="18" maxlength="18" pattern="[0-9]{18}" title="Reference number must be exactly 18 digits" oninput="this.value = this.value.replace(/[^0-9]/g, ''); let c = document.getElementById('ref_count_display'); let l = this.value.length; c.innerText = l + '/18'; if(l === 18){ c.classList.remove('text-rose-500'); c.classList.add('text-[#00205b]'); } else { c.classList.remove('text-[#00205b]'); c.classList.add('text-rose-500'); }" class="w-full bg-white border border-slate-300 rounded-md px-3 py-2.5 font-mono text-xs font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b] m-0" placeholder="123456789012345678">
                                        </div>
                                        <div>
                                            <div class="flex justify-between items-center mb-1.5 px-1 min-h-[14px]">
                                                <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest drop-shadow-sm leading-none m-0">Bank / Channel</label>
                                            </div>
                                            <select name="paid_through" id="paid_through_select" required class="w-full bg-white border border-slate-300 rounded-md px-3 py-2.5 cursor-pointer text-xs font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b] m-0">
                                                <option value="" disabled selected>Select...</option>
                                                <?php foreach ($authorized_banks as $bank): ?>
                                                    <option value="<?= htmlspecialchars($bank['bank_name']) ?>" 
                                                            data-accname="<?= htmlspecialchars($bank['account_name']) ?>"
                                                            data-accnum="<?= htmlspecialchars($bank['account_number']) ?>"
                                                            data-link="<?= htmlspecialchars($bank['payment_link'] ?? '') ?>">
                                                        <?= htmlspecialchars($bank['bank_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div id="selected_bank_card" class="hidden mb-4 p-4 rounded-lg border border-[#00205b]/20 bg-indigo-50/50 shadow-inner"></div>

                                    <div id="upload_container_receipt" class="mt-4 border-2 border-dashed border-slate-300 bg-white rounded-xl p-5 text-center hover:bg-slate-50 hover:border-[#00205b] transition-all cursor-pointer shadow-sm group flex-1 flex flex-col justify-center overflow-hidden" onclick="document.getElementById('file-upload').click()">
                                        <p class="text-xs font-black text-[#00205b] uppercase tracking-widest transition-colors drop-shadow-sm flex items-center justify-center gap-2">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                                            Click to Attach Receipt
                                        </p>
                                        <p class="text-[9px] text-slate-400 mt-1.5 font-bold uppercase tracking-widest">JPG, PNG, PDF (Max 5MB)</p>
                                    </div>
                                    <input type="file" id="file-upload" name="payment_receipt" accept=".jpg,.jpeg,.png,.pdf" class="hidden" required onchange="window.handleReceiptUpload(this, 'card_receipt')">

                                    <div id="card_receipt" class="hidden mt-4 border border-[#00205b]/30 bg-indigo-50/50 rounded-xl p-4 shadow-sm relative overflow-hidden transition-all">
                                        <div class="flex justify-between items-center relative z-10">
                                            <div class="flex items-center gap-3 overflow-hidden w-full pr-3">
                                                <div class="p-2 bg-[#00205b]/10 rounded-lg text-[#00205b] shrink-0">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                </div>
                                                <div class="truncate">
                                                    <p class="text-[11px] font-bold text-[#00205b] truncate filename-text"></p>
                                                    <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest mt-0.5 filesize-text"></p>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-2 shrink-0">
                                                <button type="button" class="preview-btn p-1.5 bg-white text-[#00205b] hover:bg-[#00205b] hover:text-white border border-[#00205b]/30 rounded transition-colors shadow-sm focus:outline-none" title="View File">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                                </button>
                                                <button type="button" onclick="window.removeReceiptFile('file-upload', 'card_receipt')" class="p-1.5 bg-white text-rose-500 hover:bg-rose-500 hover:text-white border border-rose-200 rounded transition-colors shadow-sm focus:outline-none" title="Remove File">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <button type="submit" id="btn_submit_payment" name="upload_payment" class="bg-[#00205b] hover:bg-[#001233] border border-[#001233] text-white shadow-md transition-all w-full text-[11px] uppercase tracking-widest py-3 mt-auto flex items-center justify-center gap-2 rounded-lg font-bold focus:outline-none" <?= $can_pay_balance ? '' : 'disabled' ?>>
                                        Submit Payment <svg class="w-4 h-4 opacity-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                                    </button>
                                </div>
                            </fieldset>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- PROGRAM SHIFT MODAL -->
    <div id="shiftModal" class="fixed inset-0 z-[9999] hidden flex-col items-center justify-center p-4 modal-overlay bg-slate-900/80 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl border-t-[5px] border-t-[#00205b] w-full max-w-md flex flex-col modal-content relative overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-200 bg-slate-50 flex justify-between items-center relative z-20 shrink-0">
                <h3 class="text-sm font-black text-[#00205b] uppercase tracking-widest flex items-center gap-3 drop-shadow-sm">
                    Shift Program
                </h3>
                <button type="button" onclick="window.closeShiftModal()" class="text-slate-400 hover:text-rose-600 transition-colors bg-white hover:bg-rose-50 p-2 rounded-md shadow-sm border border-slate-200 focus:outline-none cursor-pointer">
                    <svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
            
            <div class="p-6 bg-white space-y-4">
                <div class="bg-slate-50 border border-slate-200 p-4 rounded-lg">
                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1">Current Master Program</label>
                    <div class="font-black text-[#00205b] text-sm"><?= htmlspecialchars($actual_current_program) ?></div>
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-2">Select New Program</label>
                    <select id="modal_program_select" class="w-full bg-white border border-slate-300 text-[#00205b] text-sm font-bold rounded-lg px-3 py-3 shadow-sm focus:outline-none focus:border-[#00205b] focus:ring-1 focus:ring-[#00205b] transition-all cursor-pointer">
                        <?php foreach ($prog_list as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>" <?= ($p === $actual_current_program) ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 flex flex-col sm:flex-row justify-end gap-3 shrink-0">
                <button type="button" onclick="window.closeShiftModal()" class="px-6 py-2.5 border border-slate-300 rounded-md text-xs font-bold text-slate-600 uppercase tracking-widest hover:bg-white shadow-sm transition-colors focus:outline-none w-full sm:w-auto">Cancel</button>
                <button type="button" onclick="window.applyShiftSelection()" class="bg-[#00205b] hover:bg-[#001233] text-white border border-[#001233] shadow-sm transition-all px-6 py-2.5 rounded-md text-xs font-black uppercase tracking-widest focus:outline-none w-full sm:w-auto">
                    Confirm Selection
                </button>
            </div>
        </div>
    </div>

    <!-- FORMAL AGREEMENT MODAL -->
    <div id="agreementModal" class="fixed inset-0 z-[9999] hidden flex-col items-center justify-center p-4 modal-overlay bg-slate-900/80 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl border-t-[5px] border-t-[#00205b] w-full max-w-3xl max-h-[90vh] flex flex-col modal-content relative overflow-hidden">
            
            <div class="px-6 py-5 border-b border-slate-200 bg-slate-50 flex justify-between items-center relative z-20 shrink-0">
                <h3 class="text-sm font-black text-[#00205b] uppercase tracking-widest flex items-center gap-3 drop-shadow-sm">
                    <div class="p-1.5 rounded-md bg-[#00205b]/10 text-[#00205b] shadow-inner border border-[#00205b]/20">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                    Terms of Enrollment & Official Agreement
                </h3>
                <button type="button" onclick="window.closeAgreementModal()" class="text-slate-400 hover:text-[#00205b] transition-colors bg-white hover:bg-slate-100 p-1.5 rounded-md shadow-sm border border-slate-200 focus:outline-none cursor-pointer">
                    <svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
            
            <div class="overflow-y-auto p-6 md:p-8 bg-white relative z-20 custom-scrollbar flex-1 space-y-6">
                
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-5 md:p-6 text-center shadow-inner">
                    <h4 class="text-lg font-black text-[#00205b] uppercase tracking-widest mb-2 drop-shadow-sm">Strict No Refund Policy</h4>
                    <p class="text-xs text-slate-600 font-bold leading-relaxed max-w-xl mx-auto">By proceeding with this enrollment application, you formally acknowledge and agree to Lyceum de San Pablo's financial policies. <span class="bg-[#00205b]/10 text-[#00205b] px-1 rounded">All payments made to the institution are strictly non-refundable and non-transferable</span> once verified by the Accounting Office.</p>
                </div>

                <div class="space-y-4 text-xs font-medium text-slate-600 leading-relaxed px-2">
                    <p>I hereby certify that all information provided in my Master Profile and this application form is true, correct, and complete to the best of my knowledge.</p>
                    <p>I understand that any false information or misrepresentation may result in the rejection of my application or immediate dismissal from the institution.</p>
                    <p>I agree to abide by all the rules, regulations, and academic policies established by Lyceum de San Pablo.</p>
                </div>

            </div>
            
            <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 flex flex-col sm:flex-row justify-end gap-3 shrink-0 relative z-20 rounded-b-2xl">
                <button type="button" onclick="window.closeAgreementModal()" class="px-6 py-2.5 border border-slate-300 rounded-md text-xs font-bold text-slate-600 uppercase tracking-widest hover:bg-white shadow-sm transition-colors focus:outline-none w-full sm:w-auto">Cancel</button>
                <button type="submit" name="submit_enrollment" form="enrollmentForm" class="bg-[#00205b] hover:bg-[#001233] text-white border border-[#001233] shadow-sm transition-all px-8 py-2.5 rounded-md text-xs font-black uppercase tracking-widest flex items-center justify-center gap-2 focus:outline-none w-full sm:w-auto">
                    I Agree, Submit Enrollment <svg class="w-4 h-4 opacity-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                </button>
            </div>
        </div>
    </div>

<?php 
$buffered_html = ob_get_clean();

if (isset($_POST['ajax_post'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $error_msg ? 'error' : 'success',
        'message' => $error_msg ?: $success_msg,
        'final_status' => $status,
        'balance' => $display_balance,
        'total_paid' => $total_paid,
        'new_html' => $buffered_html
    ]);
    exit();
}

if (isset($_GET['api_refresh'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'final_status' => $status,
        'balance' => $display_balance,
        'total_paid' => $total_paid,
        'new_html' => $buffered_html
    ]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment & Fees - Student Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css">
</head>
<body class="flex h-screen overflow-hidden antialiased relative bg-[#f8fafc]">

    <div class="ambient-orb-1 no-print"></div>
    <div class="ambient-orb-2 no-print"></div>

    <?php include 'sidebar.php'; ?>

    <main id="mainScrollArea" class="flex-1 overflow-y-auto h-full w-full pt-20 md:pt-0 relative custom-scrollbar z-10">
        
        <div id="spa-content-root" class="p-4 md:p-8 lg:p-10 max-w-[1200px] mx-auto relative z-20">
            <?= $buffered_html ?>
        </div>
        
        <button id="floatingBackToTop" onclick="scrollToTop()" class="fixed bottom-6 right-6 md:bottom-10 md:right-10 bg-gradient-to-br from-[#003882] to-[#00205b] hover:from-[#004ba8] hover:to-[#00205b] border border-[#001233] text-white w-14 h-14 rounded-full shadow-[0_10px_25px_rgba(0,32,91,0.5),inset_0_2px_0_rgba(255,255,255,0.3)] z-[90] flex items-center justify-center transition-all duration-300 opacity-0 pointer-events-none translate-y-4 focus:outline-none group">
            <svg class="w-6 h-6 drop-shadow-sm group-hover:-translate-y-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 15l7-7 7 7" /></svg>
        </button>

        <div id="toast-container" class="fixed top-6 right-6 z-[100] flex flex-col gap-3 pointer-events-none"></div>
    </main>

    <!-- DOCUMENT OVERLAY MODAL -->
    <div id="doc-modal" class="fixed inset-0 z-[9999] hidden flex-col items-center justify-center bg-black/95 backdrop-blur-md transition-opacity duration-300 opacity-0">
        <div class="relative w-full h-full flex items-center justify-center overflow-hidden" id="doc-container">
            <img id="doc-image" src="" alt="Document Preview" class="max-w-[90vw] max-h-[90vh] object-contain transition-transform duration-100 hidden" onmousedown="startDrag(event)">
            <iframe id="doc-iframe" src="" class="w-[90vw] h-[90vh] bg-white rounded-xl shadow-2xl hidden border-0"></iframe>
        </div>
        <button type="button" onclick="document.getElementById('doc-modal').classList.add('hidden', 'opacity-0'); document.getElementById('doc-modal').classList.remove('flex');" class="absolute top-5 right-5 p-2 bg-white/20 text-white rounded-full hover:bg-white/30 hover:text-rose-400 transition-colors z-[10000] cursor-pointer">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
    </div>

    <!-- FILE SIZE DENIAL TOAST -->
    <div id="file-size-toast" class="fixed top-6 left-1/2 -translate-x-1/2 z-[10000] hidden bg-white/95 backdrop-blur-md border border-rose-200 shadow-2xl p-4 rounded-2xl transform transition-all duration-300 translate-y-[-20px] opacity-0 w-[90vw] sm:w-[400px]">
        <div class="flex items-start gap-4">
            <div class="bg-rose-50 border border-rose-100 p-2.5 rounded-xl shrink-0 shadow-sm mt-0.5">
                <svg class="w-6 h-6 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
            </div>
            <div class="flex-1 pt-1">
                <p class="text-[14px] font-black text-[#00205b] tracking-tight">File Exceeds Limit</p>
                <p class="text-[12px] text-gray-500 font-medium mt-1 leading-relaxed">The selected image is larger than 5MB. Please choose a smaller file.</p>
            </div>
            <button type="button" onclick="window.hideFileSizeToast()" class="text-gray-400 hover:text-rose-600 hover:bg-rose-50 transition-colors p-1.5 rounded-lg shrink-0 mt-0.5 focus:outline-none cursor-pointer">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
    </div>

    <script src="student.js?v=<?= time() ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="payment_fees.js?v=<?= time() ?>"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof window.rebindAll === 'function') {
                window.rebindAll();
            }
            <?php if($success_msg): ?> window.showToast("<?= addslashes($success_msg) ?>", "success"); <?php endif; ?>
            <?php if($error_msg): ?> window.showToast("<?= addslashes($error_msg) ?>", "error"); <?php endif; ?>
        });
    </script>
</body>
</html>