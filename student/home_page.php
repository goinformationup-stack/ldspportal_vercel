<?php
// 1. ISOLATED SESSION HANDLER FOR STUDENT (BULLETPROOF)
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

$student_email = $conn->real_escape_string($_SESSION['email'] ?? '');
$success_msg = "";
$error_msg = "";

$settings = [];
$settings_q = $conn->query("SELECT * FROM portal_settings");
if ($settings_q) { while ($row = $settings_q->fetch_assoc()) { $settings[$row['setting_key']] = $row['setting_value']; } }
$is_gateway_open = (($settings['enrollment_status'] ?? 'Closed') === 'Open');

// =========================================================
// FINALIZED DATA FETCH: UNIFIED USER & PROFILE (FAST JOIN)
// =========================================================
$user_q = $conn->query("
    SELECT u.id AS user_internal_id, u.email, u.student_id, u.role, p.* 
    FROM users u 
    LEFT JOIN user_profiles p ON u.id = p.user_id 
    WHERE u.email = '$student_email'
");
$user_data = ($user_q && $user_q->num_rows > 0) ? $user_q->fetch_assoc() : [];

$student_id = $user_data['student_id'] ?? '';
$user_internal_id = (int)($user_data['user_internal_id'] ?? 0);

// =========================================================
// AUTO-HEAL SCRIPT: RESTORE MISSING PROFILE DATA FROM ADMISSIONS
// =========================================================
if (empty($user_data['school_last_attended']) && $user_internal_id > 0) {
    $heal_q = $conn->query("SELECT * FROM admissions WHERE provisioned_user_id = $user_internal_id LIMIT 1");
    if ($heal_q && $heal_q->num_rows > 0) {
        $heal = $heal_q->fetch_assoc();
        
        $school = $conn->real_escape_string($heal['school_last_attended'] ?? '');
        $school_yr = $conn->real_escape_string($heal['school_year_attended'] ?? '');
        $f_name = $conn->real_escape_string($heal['father_name'] ?? '');
        $f_occ = $conn->real_escape_string($heal['father_occupation'] ?? '');
        $f_con = $conn->real_escape_string($heal['father_contact'] ?? '');
        $m_name = $conn->real_escape_string($heal['mother_name'] ?? '');
        $m_occ = $conn->real_escape_string($heal['mother_occupation'] ?? '');
        $m_con = $conn->real_escape_string($heal['mother_contact'] ?? '');
        $em_name = $conn->real_escape_string($heal['emergency_contact_name'] ?? '');
        $em_con = $conn->real_escape_string($heal['emergency_contact_number'] ?? '');
        $src = $conn->real_escape_string($heal['influence_source'] ?? '');

        $conn->query("UPDATE user_profiles SET 
            school_last_attended = '$school',
            school_year_attended = '$school_yr',
            father_name = '$f_name',
            father_occupation = '$f_occ',
            father_contact = '$f_con',
            mother_name = '$m_name',
            mother_occupation = '$m_occ',
            mother_contact = '$m_con',
            emergency_contact_name = '$em_name',
            emergency_contact_number = '$em_con',
            source_of_info = '$src'
            WHERE user_id = $user_internal_id");
            
        $user_data = $conn->query("SELECT u.id AS user_internal_id, u.email, u.student_id, u.role, p.* FROM users u LEFT JOIN user_profiles p ON u.id = p.user_id WHERE u.email = '$student_email'")->fetch_assoc();
    }
}

// =========================================================
// AUTO-MIGRATE PERSONAL EMAIL TRANSACTIONS TO INSTITUTIONAL
// =========================================================
$conn->query("
    UPDATE transaction_history th
    JOIN admissions a ON th.student_email = a.email
    SET th.student_email = '$student_email'
    WHERE a.provisioned_user_id = $user_internal_id 
      AND th.student_email != '$student_email'
");

// =========================================================
// HANDLE ACCOUNT-ON-HOLD PROGRAM SELECTION SUBMISSION
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_profile'])) {
    if (!$is_gateway_open) {
        $error_msg = "The enrollment portal is closed. Modifications are currently locked by the Registrar.";
    } else {
        $prog = isset($_POST['program']) ? $conn->real_escape_string($_POST['program']) : '';
        $update_profile_sql = "UPDATE user_profiles SET program = '$prog' WHERE user_id = $user_internal_id";
        if ($conn->query($update_profile_sql)) { 
            $success_msg = "Program saved successfully! The hold on your account has been lifted."; 
            $user_data['program'] = $prog;
        } else { 
            $error_msg = "Database Error: Could not save program."; 
        }
    }
}

// =========================================================
// PREPARE VIEW VARIABLES
// =========================================================
$cor_last = $user_data['last_name'] ?? ''; 
$cor_first = $user_data['first_name'] ?? ''; 
$cor_middle = $user_data['middle_name'] ?? '';

$student_admission_type = trim($user_data['admission_type'] ?? 'Freshman (New)');

$active_school_year = $settings['active_school_year'] ?? '2024-2025';
$active_semester = $settings['active_semester'] ?? '1st Semester';

// =========================================================
// THE TIME TRAVELER: FETCH TARGET ENROLLMENT RECORD
// =========================================================
$enroll_q = $conn->query("SELECT * FROM enrollment_requests WHERE user_id = $user_internal_id AND school_year = '$active_school_year' AND semester = '$active_semester' ORDER BY id DESC LIMIT 1");
$enroll_data = ($enroll_q && $enroll_q->num_rows > 0) ? $enroll_q->fetch_assoc() : [];

$is_viewing_past_debt = false;

if (empty($enroll_data)) {
    $past_debt_q = $conn->query("SELECT * FROM enrollment_requests WHERE user_id = $user_internal_id AND balance > 0 ORDER BY id DESC LIMIT 1");
    if ($past_debt_q && $past_debt_q->num_rows > 0) {
        $enroll_data = $past_debt_q->fetch_assoc();
        $is_viewing_past_debt = true;
    }
}

$target_school_year = $enroll_data['school_year'] ?? $active_school_year;
$target_semester = $enroll_data['semester'] ?? $active_semester;
$status = $enroll_data['final_status'] ?? 'No Record';

if (!empty($enroll_data['id']) && $enroll_data['final_status'] === 'Pending Payment') {
    $current_term_string = ($enroll_data['year_level'] ?? '') . ' - ' . ($enroll_data['semester'] ?? '');
    $adm_check_q = $conn->query("SELECT initial_amount_paid, enrollment_term FROM admissions WHERE provisioned_user_id = $user_internal_id LIMIT 1");
    if ($adm_check_q && $adm_check_q->num_rows > 0) {
        $adm_res = $adm_check_q->fetch_assoc();
        if ($adm_res['enrollment_term'] !== $current_term_string && (float)$enroll_data['total_paid_accumulated'] == (float)$adm_res['initial_amount_paid']) {
            $e_id = (int)$enroll_data['id'];
            $conn->query("UPDATE enrollment_requests SET total_paid_accumulated = 0, payment_amount = 0, payment_or_number = NULL, payment_type = NULL WHERE id = $e_id");
            $enroll_data['total_paid_accumulated'] = 0;
        }
    }
}

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

$has_active_request = !empty($enroll_data) && in_array($status, ['Pending', 'Pending Admission Verification', 'Pending Payment', 'Pending Registrar', 'Program Head Approved', 'Registrar Verified', 'Payment Rejected', 'Accounting Verification', 'Enrolled']);

$account_on_hold = false; $hold_reason = "";
if (empty($student_program_safe) || $student_program_id === 0) {
    $account_on_hold = true;
    $hold_reason = empty($student_program_safe) ? "No academic program has been formally assigned to your account yet. Please select your Program below." : "The assigned academic program is no longer recognized in the database. Please select your Program below.";
} 

// =========================================================
// CALCULATE CUMULATIVE GRADES & UNITS
// =========================================================
$student_grades = [];
if ($user_internal_id > 0) {
    $grades_q = $conn->query("SELECT e.grade, p.course_code AS subject_code, p.units FROM enrollments e JOIN prospectus p ON e.subject_id = p.id WHERE e.student_id = $user_internal_id");
    if ($grades_q && $grades_q->num_rows > 0) { while ($g = $grades_q->fetch_assoc()) { $student_grades[] = $g; } }
}

$all_enrolled_records = [];
$all_enroll_q = $conn->query("SELECT * FROM enrollment_requests WHERE user_id = $user_internal_id AND (final_status = 'Enrolled' OR enrolled_at IS NOT NULL) ORDER BY school_year DESC, semester DESC, created_at DESC");
if ($all_enroll_q && $all_enroll_q->num_rows > 0) { while ($r = $all_enroll_q->fetch_assoc()) { $all_enrolled_records[] = $r; } }

$total_enrolled_units = 0; $total_graded_units = 0; $total_grade_points = 0; $gwa = 0;

if (!empty($all_enrolled_records)) {
    foreach ($all_enrolled_records as $rec) {
        $r_sy = $rec['school_year']; $r_sem = $rec['semester']; $r_yl = $rec['year_level']; $r_prog = $rec['program'];
        $r_subjects = []; $r_safe_prog = $conn->real_escape_string($r_prog);
        $r_pid_q = $conn->query("SELECT program_id FROM programs WHERE program_name = '$r_safe_prog' LIMIT 1");
        
        if ($r_pid_q && $r_pid_q->num_rows > 0) {
            $r_pid = $r_pid_q->fetch_assoc()['program_id'];
            $r_cur_year = '';
            $r_def_q = $conn->query("SELECT active_year FROM program_defaults WHERE program = '$r_safe_prog'");
            if ($r_def_q && $r_def_q->num_rows > 0) { $r_cur_year = $r_def_q->fetch_assoc()['active_year']; } 
            else {
                $r_y_q = $conn->query("SELECT curriculum_year FROM prospectus WHERE program_id = $r_pid ORDER BY curriculum_year DESC LIMIT 1");
                if ($r_y_q && $r_y_q->num_rows > 0) { $r_cur_year = $r_y_q->fetch_assoc()['curriculum_year']; }
            }
            
            $r_cur_q = $conn->query("SELECT * FROM prospectus WHERE program_id = $r_pid AND curriculum_year = '$r_cur_year' AND year_level = '$r_yl' AND semester = '$r_sem' AND IFNULL(is_archived, 0) = 0");
            if (!$r_cur_q || $r_cur_q->num_rows == 0) { $r_cur_q = $conn->query("SELECT * FROM prospectus WHERE program_id = $r_pid AND year_level = '$r_yl' AND semester = '$r_sem' AND IFNULL(is_archived, 0) = 0"); }

            if ($r_cur_q) { while ($rs = $r_cur_q->fetch_assoc()) { $r_subjects[] = $rs; } }
        }
        
        foreach ($r_subjects as $subj) {
            $code = strtoupper(trim($subj['course_code']));
            $unit_val = (float)$subj['units'];
            $total_enrolled_units += $unit_val; 

            foreach ($student_grades as $sg) {
                if (strtoupper(trim($sg['subject_code'])) === $code && is_numeric($sg['grade'])) {
                    $total_graded_units += $unit_val; 
                    $total_grade_points += ((float)$sg['grade'] * $unit_val);
                    break;
                }
            }
        }
    }
}
if ($total_graded_units > 0) { $gwa = $total_grade_points / $total_graded_units; }

// =========================================================
// FETCH ASSIGNED INITIAL FEE EARLY FOR MUTUAL EXCLUSIVITY
// =========================================================
$adm_initial_fee = 'Miscellaneous Fee'; // Default fallback
if ($user_internal_id > 0) {
    $adm_fee_q = $conn->query("SELECT assigned_initial_fee FROM admissions WHERE provisioned_user_id = $user_internal_id LIMIT 1");
    if ($adm_fee_q && $adm_fee_q->num_rows > 0) {
        $fetched_fee = trim($adm_fee_q->fetch_assoc()['assigned_initial_fee'] ?? '');
        if (!empty($fetched_fee)) {
            $adm_initial_fee = $fetched_fee;
        }
    }
}

// Generate the short label for the red locked block (e.g. "PAY MISC FIRST", "PAY TUITION FIRST")
$short_initial_fee = 'MISC';
if (stripos($adm_initial_fee, 'Misc') !== false) {
    $short_initial_fee = 'MISC';
} elseif (stripos($adm_initial_fee, 'Tuition') !== false) {
    $short_initial_fee = 'TUITION';
} else {
    $short_initial_fee = strtoupper(explode(' ', $adm_initial_fee)[0]);
}

// =========================================================
// UNIFIED FINANCIAL OVERVIEW ENGINE (STRICT ISOLATION)
// =========================================================
$fee_breakdown = []; $total_assessed = 0;
$is_enrolled = ($status === 'Enrolled' || !empty($enroll_data['enrolled_at']));
$needs_initial_payment = in_array($status, ['Pending Payment', 'Payment Rejected']);

$is_transaction_pending = ($status === 'Accounting Verification');
$is_registrar_evaluating = ($status === 'Pending Registrar'); // REGISTRAR LOCK FLAG

if ($has_active_request && !empty($enroll_data['year_level']) && !empty($enroll_data['semester'])) {
    $safe_year_lvl = $conn->real_escape_string($enroll_data['year_level']);
    $safe_sem = $conn->real_escape_string($enroll_data['semester']);

    // 1. GENERAL FEES - STRICT SEMESTER ISOLATION & MUTUAL EXCLUSIVITY
    $gen_fees = $conn->query("SELECT fee_name, amount, payment_rule, first_payment, second_payment FROM accounting_fees WHERE (target_program = 'All' OR target_program = '$student_program_safe' OR target_program LIKE '%$student_program_safe%') AND (target_year = 'All' OR target_year = '$safe_year_lvl') AND (target_semester = 'All' OR target_semester = '$safe_sem' OR target_semester IS NULL)");
    
    if ($gen_fees && $gen_fees->num_rows > 0) {
        while ($f = $gen_fees->fetch_assoc()) {
            $is_tuition = (stripos($f['fee_name'], 'Tuition') !== false);
            $is_misc = (stripos($f['fee_name'], 'Misc') !== false);
            
            if ($current_student_status === 'Regular' && $is_tuition) continue; 

            // STRICT MUTUAL EXCLUSIVITY: Water and Salt. 
            // If they were assigned Misc, completely destroy Tuition so it doesn't inflate Total Assessed.
            // If they were assigned Tuition, completely destroy Misc.
            if ($is_tuition && stripos($adm_initial_fee, 'Misc') !== false) continue;
            if ($is_misc && stripos($adm_initial_fee, 'Tuition') !== false) continue;

            $has_installments = ($f['payment_rule'] === 'Installments Allowed' && (float)$f['first_payment'] > 0);
            $fee_amount = (float)$f['amount'];

            if ($has_installments) {
                $fee_breakdown[] = [
                    'type' => 'General Fee', 
                    'name' => $f['fee_name'], 
                    'amount' => $fee_amount,
                    'is_misc_installment' => true,
                    'first_amount' => (float)$f['first_payment'],
                    'second_amount' => (float)$f['second_payment']
                ];
            } else {
                $fee_breakdown[] = ['type' => 'General Fee', 'name' => $f['fee_name'], 'amount' => $fee_amount]; 
            }
            $total_assessed += $fee_amount;
        }
    }

    // 2. SUBJECT-SPECIFIC FEES - STRICT MATCHING
    $enrolled_subj_ids = [];
    if (!empty($enroll_data['id'])) {
        $e_q = $conn->query("SELECT subject_id FROM enrollments WHERE enrollment_request_id = " . (int)$enroll_data['id']);
        if ($e_q && $e_q->num_rows > 0) {
            while($r = $e_q->fetch_assoc()) { $enrolled_subj_ids[] = $r['subject_id']; }
        } else {
            $pulled_from_admissions = false;
            $a_q = $conn->query("SELECT evaluated_subjects, enrollment_term FROM admissions WHERE provisioned_user_id = $user_internal_id LIMIT 1");
            if ($a_q && $a_q->num_rows > 0) {
                $adm_row = $a_q->fetch_assoc();
                $current_term_string = $safe_year_lvl . ' - ' . $safe_sem;
                
                if ($adm_row['enrollment_term'] === $current_term_string && !empty($adm_row['evaluated_subjects'])) {
                    $enrolled_subj_ids = array_map('intval', explode(',', $adm_row['evaluated_subjects']));
                    $pulled_from_admissions = true;
                }
            }
            
            if (!$pulled_from_admissions && $student_program_id > 0) {
                $p_q = $conn->query("SELECT id FROM prospectus WHERE program_id = $student_program_id AND year_level = '$safe_year_lvl' AND semester = '$safe_sem' AND IFNULL(is_archived, 0) = 0");
                if ($p_q && $p_q->num_rows > 0) {
                    while($r = $p_q->fetch_assoc()) { $enrolled_subj_ids[] = $r['id']; }
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
                    $fee_breakdown[] = ['type' => 'Subject Fee', 'name' => $s['course_code'] . ' - ' . $s['descriptive_title'], 'amount' => (float)$s['subject_fee']];
                    $total_assessed += (float)$s['subject_fee']; 
                }
            }
        }
    }

    // 3. RETAKE FEES
    if (!empty($enroll_data['retake_fee']) && $enroll_data['retake_fee'] > 0) {
        $fee_breakdown[] = ['type' => 'Retake Fee', 'name' => 'Failed/Retake Subject Fees', 'amount' => (float)$enroll_data['retake_fee']];
        $total_assessed += (float)$enroll_data['retake_fee'];
    }
}

// =========================================================
// TOTAL PAID / BALANCE CALCULATIONS & AUTO-HEAL
// =========================================================
$total_paid = (float)($enroll_data['total_paid_accumulated'] ?? 0);

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
            
            $conn->query("UPDATE enrollment_requests SET 
                total_paid_accumulated = $walk_in_paid, 
                payment_amount = $walk_in_paid,
                payment_or_number = '$or_num',
                payment_type = 'Initial Fee - $p_type'
                WHERE id = $e_id");
                
            $total_paid = $walk_in_paid;
        }
    }
}

// INSTALLMENT-AWARE BALANCE DUE CALCULATION
$remaining_to_allocate = $total_paid;
foreach ($fee_breakdown as &$fee) {
    $fee['amount_covered'] = 0;
    if ($remaining_to_allocate > 0) {
        $apply = min($fee['amount'], $remaining_to_allocate);
        $fee['amount_covered'] = $apply;
        $remaining_to_allocate -= $apply;
    }
    if ($fee['amount_covered'] >= $fee['amount']) {
        $fee['payment_status'] = 'Paid';
    } elseif ($fee['amount_covered'] > 0) {
        $fee['payment_status'] = 'Partial';
    } else {
        $fee['payment_status'] = 'Unpaid';
    }
}
unset($fee);

// FORMAT UI LABELS (DYNAMIC SPLITTING IF PARTIALLY PAID OR UNPAID AFTER INITIAL)
$ui_fee_breakdown = [];
foreach ($fee_breakdown as $fee) {
    
    $is_assigned_initial = (stripos($fee['name'] ?? '', $adm_initial_fee) !== false);
    $fee['is_assigned_initial'] = $is_assigned_initial;

    // ONLY split into 1st/2nd installments AFTER the initial payment phase (i.e. when paying balances).
    // During the initial phase, keep it as ONE row.
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
            'is_misc_installment' => true,
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

// Sort to ensure the assigned initial fee is strictly at the top
usort($ui_fee_breakdown, function($a, $b) {
    $a_is_init = !empty($a['is_assigned_initial']) ? 0 : 1;
    $b_is_init = !empty($b['is_assigned_initial']) ? 0 : 1;
    return $a_is_init <=> $b_is_init;
});

// Calculate total balance correctly (just sum of all unpaid amounts)
$total_due_now = 0;
foreach ($ui_fee_breakdown as $fee) {
    $total_due_now += ($fee['amount'] - $fee['amount_covered']);
}

$remaining_balance = max(0, $total_due_now);
$display_balance = $remaining_balance;

if (!empty($enroll_data['id'])) {
    $sync_id = (int)$enroll_data['id'];
    $conn->query("UPDATE enrollment_requests SET assessed_fee = '$total_assessed', total_paid_accumulated = '$total_paid', balance = '$remaining_balance' WHERE id = $sync_id");
}

$prog_list = [];
try {
    $prog_query = $conn->query("SELECT program_name FROM programs WHERE is_archived = 0 OR is_archived IS NULL ORDER BY program_name ASC");
    if (!$prog_query) $prog_query = $conn->query("SELECT program_name FROM programs ORDER BY program_name ASC");
    if ($prog_query && $prog_query->num_rows > 0) { while($row = $prog_query->fetch_assoc()) $prog_list[] = $row['program_name']; }
} catch (Exception $e) {}

// =========================================================
// DYNAMIC PROGRESS TRACKER STATE CALCULATION
// =========================================================
$has_inst_email = !empty($user_data['institutional_email']) && strpos($user_data['institutional_email'], '@mstip.edu.ph') !== false;
$is_returning = in_array($current_student_status, ['Regular', 'Irregular', 'Returnee']);

$tracker_state = 1; // Default 4 steps (New student)
if ($is_enrolled) {
    $tracker_state = 3; // Balance only (1 step)
} elseif ($has_inst_email || $is_returning) {
    $tracker_state = 2; // Old Student enrolling (3 steps)
}

$s1_state = 'pending'; $s2_state = 'pending'; $s3_state = 'pending'; $s4_state = 'pending';
$line_width = '0%';

if ($tracker_state === 3) {
    $s2_state = 'active'; // Accounting Payment is active
} elseif ($tracker_state === 2) {
    if ($status === 'Pending Admission Verification' || $status === 'Pending') {
        $s1_state = 'active';
    } elseif ($status === 'Pending Payment' || $status === 'Accounting Verification') {
        $s1_state = 'completed'; $s2_state = 'active'; $line_width = '50%';
    } elseif ($status === 'Pending Registrar') {
        $s1_state = 'completed'; $s2_state = 'completed'; $s3_state = 'active'; $line_width = '100%';
    } else {
        $s1_state = 'active';
    }
} else {
    if ($status === 'Pending Admission Verification' || $status === 'Pending') {
        $s1_state = 'active';
    } elseif ($status === 'Pending Payment' || $status === 'Accounting Verification') {
        $s1_state = 'completed'; $s2_state = 'active'; $line_width = '33%';
    } elseif ($status === 'Pending Registrar') {
        $s1_state = 'completed'; $s2_state = 'completed'; $s3_state = 'active'; $line_width = '66%';
    } elseif ($status === 'Account Creation' || $status === 'Registrar Verified') {
        $s1_state = 'completed'; $s2_state = 'completed'; $s3_state = 'completed'; $s4_state = 'active'; $line_width = '100%';
    } else {
        $s1_state = 'active';
    }
}

function renderStep($title, $state, $id_prefix, $actual_num) {
    $icon_class = "w-7 h-7 rounded-full flex items-center justify-center font-bold text-[10px] border-[2px] transition-all duration-500 z-10 ";
    $text_class = "absolute top-8 text-[8px] font-black uppercase tracking-wider text-center w-20 ";
    $content = $actual_num;

    if ($state === 'completed') {
        $icon_class .= "border-emerald-500 bg-emerald-500 text-white";
        $text_class .= "text-emerald-700";
        $content = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>';
    } elseif ($state === 'active') {
        $icon_class .= "border-blue-500 bg-blue-50 text-blue-600 shadow-[0_0_0_4px_rgba(59,130,246,0.2)] animate-pulse";
        $text_class .= "text-blue-700";
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home - Student Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css">
</head>
<body class="flex h-screen overflow-hidden antialiased relative">

    <div class="ambient-orb-1 no-print"></div>
    <div class="ambient-orb-2 no-print"></div>

    <?php include 'sidebar.php'; ?>

    <main id="mainScrollArea" class="flex-1 overflow-y-auto h-full w-full pt-20 md:pt-0 relative custom-scrollbar z-10">
        <div id="spa-content-root" class="p-4 md:p-8 lg:p-10 max-w-[1200px] mx-auto relative z-20">
            
            <header class="mb-8 flex flex-col md:flex-row md:justify-between items-start md:items-end gap-4 border-b border-slate-300/60 pb-6 no-print animate-up">
                <div>
                    <h1 class="text-3xl md:text-4xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">Welcome, <?= htmlspecialchars($cor_first ?? '') ?>!</h1>
                    <p class="text-slate-500 text-sm flex items-center gap-2 font-medium mt-2">
                        <span class="w-2 h-2 rounded-full <?= $account_on_hold ? 'bg-rose-500' : 'bg-emerald-500' ?> shadow-sm"></span>
                        Student ID: <strong class="text-[#00205b] font-mono tracking-wider drop-shadow-sm"><?= htmlspecialchars($student_id ?: 'Pending Assignment') ?></strong>
                        
                        <?php if ($is_enrolled): ?>
                            <span class="ml-4 px-2.5 py-0.5 rounded-lg bg-[#00205b] text-white text-[10px] font-bold uppercase tracking-widest shadow-sm">
                                Enrolled: <?= htmlspecialchars($enroll_data['year_level'] ?? '') ?> | <?= htmlspecialchars($student_program_safe ?? '') ?>
                            </span>
                        <?php else: ?>
                            <span class="ml-4 px-2.5 py-0.5 rounded-lg bg-white border border-slate-300 text-[10px] font-bold uppercase tracking-widest shadow-sm text-slate-600">Status: <?= htmlspecialchars($current_student_status ?? '') ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="text-left md:text-right">
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1 drop-shadow-sm">
                        <?= $is_viewing_past_debt ? '<span class="text-rose-500">PAST TERM DEBT</span>' : 'Active Enrollment Term' ?>
                    </p>
                    <p class="text-sm font-black text-[#00205b] drop-shadow-sm"><?= htmlspecialchars($target_semester ?? '') ?> (<?= htmlspecialchars($target_school_year ?? '') ?>)</p>
                </div>
            </header>

            <?php if ($is_viewing_past_debt): ?>
                <div class="bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded-xl text-sm font-bold shadow-sm relative z-20 flex items-start gap-3 mb-6">
                    <svg class="w-5 h-5 text-rose-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <div>
                        <span class="block uppercase tracking-widest text-[10px] font-black text-rose-600 mb-1">Past Term Balance Detected</span>
                        You are viewing an unpaid balance from <strong><?= htmlspecialchars($target_semester) ?> (<?= htmlspecialchars($target_school_year) ?>)</strong>. You must settle this balance to unlock the enrollment form for the current active term.
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($account_on_hold): ?>
                <div class="glossy-panel p-8 md:p-12 text-center border-t-[5px] !border-t-rose-500 max-w-2xl mx-auto shadow-[0_10px_30px_rgba(244,63,94,0.1)] mt-10 animate-up delay-1">
                    <div class="glossy-panel-header bg-gradient-to-r from-rose-400 to-rose-600"></div>
                    <h2 class="text-2xl font-black text-[#00205b] uppercase tracking-widest mb-4 drop-shadow-sm">Action Needed</h2>
                    <div class="bg-rose-50/80 backdrop-blur-sm border border-rose-200 text-rose-700 p-5 rounded-xl text-sm font-bold mb-6 text-left shadow-sm">
                        <strong class="uppercase text-[10px] tracking-widest block mb-1">Hold Reason:</strong> <?= htmlspecialchars($hold_reason ?? '') ?>
                    </div>
                    <p class="text-slate-500 text-sm mb-6 font-medium">Please select your correct Program below to generate your curriculum and unlock the portal.</p>
                    
                    <form method="POST" class="relative z-20">
                        <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-widest mb-2 text-left drop-shadow-sm">Select Your Program:</label>
                        <div class="flex flex-col md:flex-row gap-3">
                            <select name="program" class="input-glossy-smooth !py-3 flex-1 font-bold text-[#00205b] cursor-pointer" required>
                                <option value="" disabled selected>Select Program...</option>
                                <?php foreach ($prog_list as $p): ?>
                                    <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="save_profile" class="bg-[#00205b] text-white hover:bg-[#001233] px-6 py-3 rounded-lg text-xs font-bold uppercase tracking-widest transition-colors shadow-md shrink-0" <?= (!$is_gateway_open) ? 'disabled' : '' ?>>Save Program</button>
                        </div>
                    </form>
                </div>
            <?php else: ?>

                <!-- SECTION: HOME PAGE OVERVIEW -->
                <div class="block no-print animate-up delay-1">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                        
                        <div class="lg:col-span-1 glossy-panel p-6 border-t-[5px] !border-t-[#003882] shadow-[0_10px_30px_rgba(0,32,91,0.05)] relative z-20 flex flex-col">
                            <div class="glossy-panel-header bg-gradient-to-r from-[#003882] to-[#00205b]"></div>
                            <h3 class="font-black text-[#00205b] text-base uppercase tracking-widest mb-4 drop-shadow-sm border-b border-slate-200/80 pb-3 flex items-center gap-2">
                                <div class="p-1 rounded bg-blue-50"><svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></div>
                                Student Identity
                            </h3>
                            
                            <div class="space-y-4 flex-1">
                                <div>
                                    <p class="text-[9px] text-slate-500 uppercase tracking-widest font-black mb-1">Full Name</p>
                                    <p class="text-sm font-bold text-slate-800 uppercase"><?= htmlspecialchars(trim(($cor_last ? $cor_last . ', ' : '') . ($cor_first ?? '') . ' ' . ($cor_middle ?? ''))) ?></p>
                                </div>
                                <div>
                                    <p class="text-[9px] text-slate-500 uppercase tracking-widest font-black mb-1">Academic Program</p>
                                    <p class="text-xs font-bold text-[#00205b] uppercase tracking-wider"><?= htmlspecialchars($student_program_safe ?: 'Not Assigned') ?></p>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-[9px] text-slate-500 uppercase tracking-widest font-black mb-1">Year Level</p>
                                        <p class="text-xs font-bold text-slate-800"><?= htmlspecialchars($enroll_data['year_level'] ?? $user_data['year_level'] ?? 'N/A') ?></p>
                                    </div>
                                    <div>
                                        <p class="text-[9px] text-slate-500 uppercase tracking-widest font-black mb-1">Status</p>
                                        <p class="text-xs font-bold text-slate-800"><?= htmlspecialchars($current_student_status ?? 'Regular') ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-200 p-5 rounded-xl shadow-[inset_0_2px_10px_rgba(255,255,255,1)] mt-6 relative overflow-hidden group">
                                <div class="absolute right-0 top-0 w-24 h-24 bg-blue-400 rounded-full blur-3xl opacity-20 group-hover:opacity-30 transition-opacity"></div>
                                <p class="text-[9px] text-blue-700 uppercase tracking-[0.15em] font-black mb-2 flex items-center gap-1.5 relative z-10">
                                    <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 00-2-2V7a2 2 0 00-2-2H5a2 2 0 00-2-2v10a2 2 0 002 2z"/></svg>
                                    Official School Email
                                </p>
                                <?php if(!empty($student_email)): ?>
                                    <div class="relative z-10">
                                        <p class="text-[13px] sm:text-sm font-mono font-black text-[#00205b] break-all drop-shadow-sm select-all mb-2"><?= htmlspecialchars($student_email) ?></p>
                                        <div class="bg-white/60 text-[9px] text-slate-600 font-bold p-2.5 rounded-lg border border-white shadow-sm leading-relaxed">
                                            <span class="text-emerald-600 font-black">ACTIVE:</span> Use this email address alongside your 6-Digit PIN to log into your Google Workspace and the Student Portal.
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="relative z-10">
                                        <span class="inline-block bg-white text-slate-400 border border-slate-200 px-3 py-1.5 rounded-md text-[10px] font-black uppercase tracking-widest shadow-sm">Pending Assignment</span>
                                        <p class="text-[9px] text-slate-500 font-bold mt-2 leading-relaxed">Your school email is currently being set up by the Administrator.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="lg:col-span-2 flex flex-col gap-6">
                            <?php if (!$has_active_request): ?>
                                <div class="glossy-panel p-6 md:p-8 border-t-[5px] !border-t-[#003882] shadow-[0_10px_30px_rgba(0,32,91,0.05)] w-full">
                                    <div class="glossy-panel-header bg-gradient-to-r from-[#004ba8] to-[#00205b]"></div>
                                    <h3 class="font-black text-[#00205b] text-lg uppercase tracking-tight font-academic mb-4 drop-shadow-sm">Fees to Pay (<?= htmlspecialchars($target_semester ?? '') ?>)</h3>
                                    <div class="bg-indigo-50/80 backdrop-blur-sm border border-indigo-200 text-indigo-800 p-5 rounded-xl text-sm font-bold shadow-sm relative z-20 flex items-center gap-3">
                                        <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                        You need to submit your Enrollment Form for <?= htmlspecialchars($target_semester ?? '') ?> before you can see your fees.
                                    </div>
                                </div>

                            <?php elseif ($status === 'Pending Admission Verification'): ?>
                                <div class="glossy-panel p-6 md:p-8 border-t-[5px] !border-t-[#003882] shadow-[0_10px_30px_rgba(0,32,91,0.05)] w-full">
                                    <div class="glossy-panel-header bg-gradient-to-r from-[#004ba8] to-[#00205b]"></div>
                                    <h3 class="font-black text-[#00205b] text-lg uppercase tracking-tight font-academic mb-4 drop-shadow-sm">Clearance Submitted</h3>
                                    <div class="bg-indigo-50/80 backdrop-blur-sm border border-indigo-200 text-indigo-800 p-5 rounded-xl text-sm font-bold flex items-center gap-3 shadow-sm relative z-20">
                                        <svg class="w-5 h-5 animate-spin shrink-0 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                        Please wait for Admissions to check your uploaded documents.
                                    </div>
                                    <div class="mt-8 border-t border-slate-200/60 pt-6">
                                        <?php echo $tracker_html; ?>
                                        <div class="h-8"></div>
                                    </div>
                                </div>

                            <?php elseif ($status === 'Pending Registrar'): ?>
                                <div class="glossy-panel p-6 md:p-8 border-t-[5px] !border-t-blue-500 shadow-[0_10px_30px_rgba(59,130,246,0.05)] w-full">
                                    <div class="glossy-panel-header bg-gradient-to-r from-blue-400 to-blue-600"></div>
                                    <h3 class="font-black text-[#00205b] text-lg uppercase tracking-tight font-academic mb-4 drop-shadow-sm">Enrollment Details (<?= htmlspecialchars($target_semester ?? '') ?>)</h3>
                                    <div class="bg-blue-50/80 backdrop-blur-sm border border-blue-200 text-blue-800 p-5 rounded-xl text-sm font-bold flex items-center gap-3 shadow-sm relative z-20">
                                        <svg class="w-5 h-5 animate-spin shrink-0 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                        Accounting has checked your payment. Please wait for the Registrar to officially enroll you.
                                    </div>
                                    <div class="mt-8 border-t border-slate-200/60 pt-6">
                                        <?php echo $tracker_html; ?>
                                        <div class="h-8"></div>
                                    </div>
                                </div>
                            <?php else: ?>
                                
                                <?php if ($status === 'Pending Payment'): ?>
                                    <div class="glossy-panel p-6 md:p-8 border-t-[5px] !border-t-emerald-500 shadow-[0_10px_30px_rgba(16,185,129,0.05)] w-full mb-2">
                                        <div class="glossy-panel-header bg-gradient-to-r from-emerald-400 to-emerald-600"></div>
                                        <h3 class="font-black text-[#00205b] text-lg uppercase tracking-tight font-academic mb-4 drop-shadow-sm">Clearance Verified</h3>
                                        <div class="bg-emerald-50/80 backdrop-blur-sm border border-emerald-200 text-emerald-800 p-5 rounded-xl text-sm font-bold flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 shadow-sm relative z-20">
                                            <div class="flex items-center gap-3">
                                                <svg class="w-5 h-5 shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                <span>Your clearance is verified! Please pay your fees below.</span>
                                            </div>
                                        </div>
                                        <div class="mt-8 border-t border-slate-200/60 pt-6">
                                            <?php echo $tracker_html; ?>
                                            <div class="h-8"></div>
                                        </div>
                                    </div>
                                <?php elseif ($status === 'Accounting Verification'): ?>
                                    <div class="glossy-panel p-6 md:p-8 border-t-[5px] !border-t-[#003882] shadow-[0_10px_30px_rgba(0,32,91,0.05)] w-full mb-2">
                                        <div class="glossy-panel-header bg-gradient-to-r from-[#004ba8] to-[#00205b]"></div>
                                        <h3 class="font-black text-[#00205b] text-lg uppercase tracking-tight font-academic mb-4 drop-shadow-sm">Checking Payment</h3>
                                        <div class="bg-indigo-50/80 backdrop-blur-sm border border-indigo-200 text-indigo-800 p-5 rounded-xl text-sm font-bold flex items-center gap-3 shadow-sm relative z-20">
                                            <svg class="w-5 h-5 animate-spin shrink-0 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                            Your payment receipt has been sent to Accounting for checking.
                                        </div>
                                        <div class="mt-8 border-t border-slate-200/60 pt-6">
                                            <?php echo $tracker_html; ?>
                                            <div class="h-8"></div>
                                        </div>
                                    </div>
                                <?php elseif ($tracker_state === 3 && $remaining_balance > 0): ?>
                                    <div class="glossy-panel p-6 md:p-8 border-t-[5px] !border-t-blue-500 shadow-[0_10px_30px_rgba(59,130,246,0.05)] w-full mb-2">
                                        <div class="glossy-panel-header bg-gradient-to-r from-blue-400 to-blue-600"></div>
                                        <h3 class="font-black text-[#00205b] text-lg uppercase tracking-tight font-academic mb-4 drop-shadow-sm">Remaining Balance</h3>
                                        <div class="bg-blue-50/80 backdrop-blur-sm border border-blue-200 text-blue-800 p-5 rounded-xl text-sm font-bold flex items-center gap-3 shadow-sm relative z-20">
                                            <svg class="w-5 h-5 shrink-0 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            You still have a balance for this semester. Please pay it.
                                        </div>
                                        <div class="mt-8 border-t border-slate-200/60 pt-6">
                                            <?php echo $tracker_html; ?>
                                            <div class="h-8"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="rounded-3xl overflow-hidden shadow-2xl relative border <?= $display_balance > 0 ? 'border-red-900/50' : 'border-[#002875]/50' ?> z-20 bg-[#001233] flex flex-col w-full">
                                    <div class="absolute top-0 right-0 w-96 h-96 <?= $display_balance > 0 ? 'bg-red-600/15 animate-pulse' : 'bg-[#00205b]/20' ?> rounded-full blur-3xl -translate-y-1/2 translate-x-1/3 pointer-events-none"></div>

                                    <div class="p-8 md:p-10 pb-8 relative z-10">
                                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-8 mb-8">
                                            <div>
                                                <div class="flex items-center gap-3 mb-3">
                                                    <?php if ($display_balance > 0): ?>
                                                        <div class="p-1.5 bg-red-500/10 rounded-md border border-red-500/20 text-red-400 shadow-[0_0_10px_rgba(239,68,68,0.2)]">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                        </div>
                                                        <p class="text-xs font-bold text-red-300 uppercase tracking-[0.15em] drop-shadow-sm">Outstanding Balance Due</p>
                                                    <?php else: ?>
                                                        <div class="p-1.5 bg-white/5 rounded-md border border-white/10 text-blue-300">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                                                        </div>
                                                        <p class="text-xs font-bold text-blue-200 uppercase tracking-[0.15em] drop-shadow-sm">Total Balance Due</p>
                                                    <?php endif; ?>
                                                </div>
                                                <h2 class="text-5xl md:text-6xl font-extrabold text-white tracking-tight drop-shadow-md font-academic">₱<?= number_format($display_balance ?? 0, 2) ?></h2>
                                                <?php if ($display_balance > 0): ?>
                                                    <p class="text-red-200/70 text-[10px] uppercase font-bold mt-3 tracking-widest">Please settle this remaining amount</p>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="w-full md:w-auto flex-shrink-0">
                                                <?php if ($status === 'Accounting Verification'): ?>
                                                    <a href="payment_fees.php" class="bg-amber-500/20 text-amber-300 border border-amber-500/50 hover:bg-amber-500/30 px-8 py-4 rounded-xl text-xs font-bold uppercase tracking-widest transition-all w-full md:w-auto flex items-center justify-center gap-3 backdrop-blur-sm">
                                                        <svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                                        Verifying Payment
                                                    </a>
                                                <?php elseif ($remaining_balance > 0): ?>
                                                    <a href="payment_fees.php" class="bg-gradient-to-r from-emerald-500 to-emerald-400 text-white border border-emerald-600 hover:shadow-[0_0_20px_rgba(16,185,129,0.4)] px-8 py-4 rounded-xl text-xs font-black uppercase tracking-widest transition-all w-full md:w-auto flex items-center justify-center gap-3 shadow-lg transform hover:-translate-y-0.5">
                                                        Proceed to Payment &rarr;
                                                    </a>
                                                <?php else: ?>
                                                    <span class="bg-emerald-500/20 text-emerald-300 border border-emerald-500/50 px-8 py-4 rounded-xl text-xs font-bold uppercase tracking-widest flex items-center justify-center gap-3 w-full md:w-auto backdrop-blur-sm">
                                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                        Account Cleared
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-8">
                                            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 backdrop-blur-md flex items-center justify-between transition-colors hover:bg-white/10">
                                                <div>
                                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Total Assessed Fees</p>
                                                    <p class="text-xl font-bold text-slate-100 font-academic tracking-wide">₱<?= number_format($total_assessed ?? 0, 2) ?></p>
                                                </div>
                                                <div class="w-10 h-10 rounded-full bg-blue-500/20 flex items-center justify-center text-blue-300 border border-blue-500/30">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                                </div>
                                            </div>
                                            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 backdrop-blur-md flex items-center justify-between transition-colors hover:bg-white/10">
                                                <div>
                                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Total Payments Applied</p>
                                                    <p class="text-xl font-bold text-emerald-400 font-academic tracking-wide">₱<?= number_format($total_paid ?? 0, 2) ?></p>
                                                </div>
                                                <div class="w-10 h-10 rounded-full bg-emerald-500/20 flex items-center justify-center text-emerald-400 border border-emerald-500/30">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Statement of Account List -->
                                        <div class="mt-8 pt-8 border-t border-white/10">
                                            <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-4">Official Fee Breakdown</h4>
                                            <div class="bg-white/5 border border-white/10 rounded-xl overflow-hidden backdrop-blur-sm">
                                                <table class="w-full text-left text-xs">
                                                    <thead class="bg-white/10 text-slate-300 text-[8px] uppercase tracking-widest font-black border-b border-white/10">
                                                        <tr>
                                                            <th class="px-4 py-3">Fee Description</th>
                                                            <th class="px-4 py-3 text-right">Amount</th>
                                                            <th class="px-4 py-3 text-center w-[120px]">Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-white/5">
                                                        <?php if(empty($ui_fee_breakdown)): ?>
                                                            <tr><td colspan="3" class="px-4 py-8 text-center text-slate-400 italic text-[10px] uppercase tracking-widest">No active fee assessment found.</td></tr>
                                                        <?php else: ?>
                                                            <?php foreach($ui_fee_breakdown as $fee): ?>
                                                                <tr class="hover:bg-white/5 transition-colors">
                                                                    <td class="px-4 py-3">
                                                                        <span class="block font-bold text-white text-[11px] mb-0.5"><?= htmlspecialchars($fee['name']) ?></span>
                                                                        <span class="block text-[8px] uppercase tracking-widest text-blue-300/70 font-black"><?= htmlspecialchars($fee['type']) ?></span>
                                                                        
                                                                        <?php if (!empty($fee['is_misc_installment']) && $fee['payment_status'] === 'Unpaid' && $needs_initial_payment): ?>
                                                                            <div class="mt-2 text-[8px] font-bold text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-2 py-1 rounded w-max">
                                                                                Installment Plan Available in Portal
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td class="px-4 py-3 text-right">
                                                                        <span class="font-mono font-black text-white text-[10px]">₱<?= number_format($fee['amount'], 2) ?></span>
                                                                    </td>
                                                                    <td class="px-4 py-3 text-center">
                                                                        <?php if($fee['payment_status'] === 'Paid'): ?>
                                                                            <span class="bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 px-2 py-1.5 rounded text-[8px] font-black uppercase tracking-widest w-full block">Paid</span>
                                                                        <?php elseif($fee['payment_status'] === 'Partial'): ?>
                                                                            <span class="bg-amber-500/20 text-amber-300 border border-amber-500/30 px-2 py-1 rounded text-[8px] font-black uppercase tracking-widest w-full block mb-1">Partial</span>
                                                                            <span class="block text-[8px] text-amber-400 font-mono font-bold">Bal: ₱<?= number_format($fee['amount'] - $fee['amount_covered'], 2) ?></span>
                                                                        <?php else: ?>
                                                                            <?php 
                                                                            $is_foundational_fee = !empty($fee['is_assigned_initial']);
                                                                            $is_locked_by_gatekeeper = ($needs_initial_payment && !$is_foundational_fee);
                                                                            ?>
                                                                            <?php if($is_transaction_pending): ?>
                                                                                <span class="text-[8px] font-black text-amber-400 uppercase tracking-widest">Pending Verif.</span>
                                                                            <?php elseif($is_registrar_evaluating): ?>
                                                                                <span class="text-[8px] font-black text-blue-400 uppercase tracking-widest">Locked</span>
                                                                            <?php elseif($is_locked_by_gatekeeper): ?>
                                                                                <span class="text-[8px] font-black text-red-400 uppercase tracking-widest border border-red-500/30 bg-red-500/10 px-2 py-1 rounded block">Pay <?= htmlspecialchars($short_initial_fee) ?> 1st</span>
                                                                            <?php else: ?>
                                                                                <span class="bg-white/10 text-slate-300 border border-white/20 px-2 py-1.5 rounded text-[8px] font-black uppercase tracking-widest w-full block">Unpaid</span>
                                                                            <?php endif; ?>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="bg-[#001233] rounded-xl p-6 shadow-2xl border border-[#00205b] flex flex-col sm:flex-row justify-between items-center gap-4 relative overflow-hidden mt-6 no-print animate-up delay-2">
                <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI4IiBoZWlnaHQ9IjgiPgo8cmVjdCB3aWR0aD0iOCIgaGVpZ2h0PSI4IiBmaWxsPSIjMDAxMjMzIj48L3JlY3Q+CjxwYXRoIGQ9Ik0wIDBMOCA4Wk04IDBMMCA4WiIgc3Ryb2tlPSIjMDAyMDViIiBzdHJva2Utd2lkdGg9IjEiPjwvcGF0aD4KPC9zdmc+')] opacity-20"></div>
                <div class="absolute right-0 top-0 w-48 h-48 bg-blue-500 rounded-full blur-3xl opacity-10 pointer-events-none translate-x-1/2 -translate-y-1/2"></div>
                
                <div class="relative z-10 text-center sm:text-left">
                    <h4 class="text-blue-300 font-black uppercase text-[10px] tracking-[0.2em] mb-1">Overall Grades</h4>
                    <p class="text-white/80 text-xs font-medium">A summary of your total units and general average.</p>
                </div>
                <div class="flex gap-4 relative z-10">
                    <div class="bg-white/10 backdrop-blur-sm border border-white/20 rounded-lg p-3 text-center min-w-[100px]">
                        <span class="block text-[9px] text-blue-200 uppercase tracking-widest font-bold mb-1">Total Units</span>
                        <span class="block text-xl font-black text-white font-mono"><?= number_format($total_enrolled_units ?? 0, 2) ?></span>
                    </div>
                    <div class="bg-[#003882] border border-[#004ba8] rounded-lg p-3 text-center min-w-[100px] shadow-sm">
                        <span class="block text-[9px] text-blue-200 uppercase tracking-widest font-black mb-1">Gen. Average</span>
                        <span class="block text-xl font-black text-white font-academic"><?= ($total_graded_units > 0) ? number_format($gwa ?? 0, 2) : 'N/A' ?></span>
                    </div>
                </div>
            </div>

        </div>
        
        <button id="floatingBackToTop" onclick="scrollToTop()" class="fixed bottom-6 right-6 md:bottom-10 md:right-10 bg-gradient-to-br from-[#003882] to-[#00205b] hover:from-[#004ba8] hover:to-[#00205b] border border-[#001233] text-white w-14 h-14 rounded-full shadow-[0_10px_25px_rgba(0,32,91,0.5),inset_0_2px_0_rgba(255,255,255,0.3)] z-[90] flex items-center justify-center transition-all duration-300 opacity-0 pointer-events-none translate-y-4 focus:outline-none group">
            <svg class="w-6 h-6 drop-shadow-sm group-hover:-translate-y-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 15l7-7 7 7" /></svg>
        </button>

        <!-- Toast Notification Container -->
        <div id="toast-container" class="fixed top-6 right-6 z-[100] flex flex-col gap-3 pointer-events-none"></div>
    </main>

    <script src="student.js?v=<?= time() ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="home_page.js?v=<?= time() ?>"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            <?php if($success_msg): ?> window.showToast("<?= addslashes($success_msg) ?>", "success"); <?php endif; ?>
            <?php if($error_msg): ?> window.showToast("<?= addslashes($error_msg) ?>", "error"); <?php endif; ?>
        });
    </script>
</body>
</html>