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
if (!isset($_SESSION['role'])) {
    header("Location: index.php"); 
    exit();
}
if ($_SESSION['role'] !== 'student') {
    header("Location: index.php"); 
    exit();
}

$student_email = $conn->real_escape_string(isset($_SESSION['email']) ? $_SESSION['email'] : '');

$settings = [];
$settings_q = $conn->query("SELECT * FROM portal_settings");
if ($settings_q) { 
    while ($row = $settings_q->fetch_assoc()) { 
        $settings[$row['setting_key']] = $row['setting_value']; 
    } 
}

// =========================================================
// FINALIZED DATA FETCH: UNIFIED USER & PROFILE
// =========================================================
$user_q = $conn->query("
    SELECT u.id AS user_internal_id, u.student_id, p.*, a.uploaded_files, a.influence_source 
    FROM users u 
    LEFT JOIN user_profiles p ON u.id = p.user_id 
    LEFT JOIN admissions a ON u.id = a.provisioned_user_id
    WHERE u.email = '$student_email'
");

$user_data = [];
if ($user_q) {
    if ($user_q->num_rows > 0) {
        $user_data = $user_q->fetch_assoc();
    }
}

$student_id = isset($user_data['student_id']) ? $user_data['student_id'] : '';
$user_internal_id = isset($user_data['user_internal_id']) ? (int)$user_data['user_internal_id'] : 0;

$cor_last = isset($user_data['last_name']) ? $user_data['last_name'] : ''; 
$cor_first = isset($user_data['first_name']) ? $user_data['first_name'] : ''; 
$cor_middle = isset($user_data['middle_name']) ? $user_data['middle_name'] : '';
$student_program_raw = isset($user_data['program']) ? $user_data['program'] : '';

$student_age = '';
if (!empty($user_data['dob'])) {
    if ($user_data['dob'] !== '0000-00-00') {
        $dob = new DateTime($user_data['dob']);
        $now = new DateTime();
        $student_age = $now->diff($dob)->y;
    }
}

$account_on_hold = empty($student_program_raw);

// =========================================================
// FETCH ENROLLED RECORDS & STRICT CURRICULUM FROM THE VAULT
// =========================================================
$all_enrolled_records = [];

$all_enroll_q = $conn->query("
    SELECT 
        c.enrollment_request_id as id, c.school_year, c.semester, c.program, c.year_level, c.section as assigned_section, c.published_at as enrolled_at,
        er.payment_amount, er.payment_type, er.total_paid_accumulated, er.balance, er.learning_mode, er.assigned_fees, er.retake_fee, er.student_status,
        p.admission_type, a.evaluated_admission_type, a.student_type as a_student_type 
    FROM official_student_cors c
    JOIN enrollment_requests er ON c.enrollment_request_id = er.id
    LEFT JOIN user_profiles p ON c.user_id = p.user_id
    LEFT JOIN admissions a ON c.user_id = a.provisioned_user_id
    WHERE c.user_id = $user_internal_id
    ORDER BY c.school_year DESC, c.semester DESC, c.published_at DESC
");

if ($all_enroll_q) {
    if ($all_enroll_q->num_rows > 0) { 
        while ($r = $all_enroll_q->fetch_assoc()) { 
            $all_enrolled_records[] = $r; 
        } 
    }
}

$student_curriculum_history = [];
foreach ($all_enrolled_records as $rec) {
    $rec_id = (int)$rec['id'];
    $r_sy = $rec['school_year']; 
    $r_sem = $rec['semester']; 
    $r_yl = $rec['year_level']; 
    $r_prog = $rec['program'];
    $r_status = isset($rec['student_status']) ? $rec['student_status'] : 'Regular';
    
    $r_subjects = []; 
    $r_safe_prog = $conn->real_escape_string($r_prog);
    
    $enrolled_q = $conn->query("
        SELECT p.* 
        FROM enrollments e 
        JOIN prospectus p ON e.subject_id = p.id 
        WHERE e.enrollment_request_id = $rec_id 
        ORDER BY p.year_level ASC, p.semester ASC
    ");
    
    if ($enrolled_q) {
        if ($enrolled_q->num_rows > 0) {
            while($s = $enrolled_q->fetch_assoc()) { 
                $r_subjects[] = $s; 
            }
        } else {
            $r_pid_q = $conn->query("SELECT program_id FROM programs WHERE program_name = '$r_safe_prog' LIMIT 1");
            if ($r_pid_q) {
                if ($r_pid_q->num_rows > 0) {
                    $r_pid = $r_pid_q->fetch_assoc()['program_id'];
                    $r_cur_year = '';
                    $r_def_q = $conn->query("SELECT active_year FROM program_defaults WHERE program = '$r_safe_prog'");
                    if ($r_def_q) {
                        if ($r_def_q->num_rows > 0) { 
                            $r_cur_year = $r_def_q->fetch_assoc()['active_year']; 
                        } else {
                            $r_y_q = $conn->query("SELECT curriculum_year FROM prospectus WHERE program_id = $r_pid ORDER BY curriculum_year DESC LIMIT 1");
                            if ($r_y_q) {
                                if ($r_y_q->num_rows > 0) { 
                                    $r_cur_year = $r_y_q->fetch_assoc()['curriculum_year']; 
                                }
                            }
                        }
                    }
                    
                    $r_cur_q = $conn->query("SELECT * FROM prospectus WHERE program_id = $r_pid AND curriculum_year = '$r_cur_year' AND year_level = '$r_yl' AND semester = '$r_sem' AND IFNULL(is_archived, 0) = 0");
                    if (!$r_cur_q) {
                        $r_cur_q = $conn->query("SELECT * FROM prospectus WHERE program_id = $r_pid AND year_level = '$r_yl' AND semester = '$r_sem' AND IFNULL(is_archived, 0) = 0");
                    } else if ($r_cur_q->num_rows == 0) {
                        $r_cur_q = $conn->query("SELECT * FROM prospectus WHERE program_id = $r_pid AND year_level = '$r_yl' AND semester = '$r_sem' AND IFNULL(is_archived, 0) = 0");
                    }

                    if ($r_cur_q) { 
                        while ($rs = $r_cur_q->fetch_assoc()) { 
                            $r_subjects[] = $rs; 
                        } 
                    }
                }
            }
        }
    }
    $student_curriculum_history[$rec['id']] = $r_subjects;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digital COR - Student Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css">

    <style>
        /* Enforce box-sizing inside the document so padding doesn't blow out dimensions */
        .word-doc * {
            box-sizing: border-box !important;
        }
    </style>
</head>
<body class="flex h-screen overflow-hidden antialiased relative bg-[#f8fafc]">

    <div class="ambient-orb-1 no-print"></div>
    <div class="ambient-orb-2 no-print"></div>

    <?php include 'sidebar.php'; ?>

    <main id="mainScrollArea" class="flex-1 overflow-y-auto h-full w-full pt-16 md:pt-0 relative custom-scrollbar z-10">
        <div id="spa-content-root" class="p-4 md:p-8 lg:p-10 max-w-[1200px] mx-auto relative z-20">
            
            <div class="fade-in relative z-20">
                <div class="bg-white border border-slate-200 shadow-sm border-t-[4px] border-t-[#00205b] overflow-hidden rounded-xl">
                    
                    <div class="no-print bg-slate-50 border-b border-slate-200 p-4 md:p-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4 relative z-20 rounded-t-xl">
                        <div>
                            <h3 class="font-black text-[#00205b] text-base uppercase tracking-widest flex items-center gap-2 drop-shadow-sm">
                                <div class="p-1.5 rounded-md bg-[#00205b]/10"><svg class="w-4 h-4 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg></div>
                                Digital COR Archive
                            </h3>
                            <p class="text-[10px] text-slate-500 mt-1 font-bold uppercase tracking-widest">Your official registration records across all terms.</p>
                        </div>
                    </div>

                    <?php if (empty($all_enrolled_records)): ?>
                        <div class="bg-white p-12 text-center relative z-20 rounded-b-xl">
                            <svg class="w-12 h-12 text-slate-300 mx-auto mb-4 drop-shadow-sm" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <p class="text-[#00205b] text-xs font-black uppercase tracking-widest drop-shadow-sm">No official Digital COR records found yet.</p>
                            <p class="text-[10px] text-slate-500 mt-2 font-bold uppercase tracking-widest">You must be officially enrolled by the Registrar to generate a COR.</p>
                        </div>
                    <?php else: ?>
                        
                        <!-- CARD COLLECTION VIEW -->
                        <div class="p-4 md:p-6 bg-white border-b border-slate-200 no-print">
                            <h4 class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-3">Select a term to view your COR</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <?php foreach ($all_enrolled_records as $index => $rec): 
                                    $rec_id = $rec['id'];
                                    $is_latest = ($index === 0);
                                    $prog_display = !empty($rec['program']) ? $rec['program'] : 'General / Core';
                                ?>
                                    <div onclick="window.viewCOR(<?= $rec_id ?>)" class="cor-card bg-slate-50 border <?= $is_latest ? 'border-[#00205b] ring-1 ring-[#00205b] shadow-md' : 'border-slate-300 shadow-sm' ?> rounded-xl p-4 cursor-pointer hover:-translate-y-1 hover:shadow-lg transition-all group relative overflow-hidden flex flex-col min-h-[140px]">
                                        <?php if($is_latest): ?>
                                            <div class="absolute top-0 right-0 bg-[#00205b] text-white text-[7px] font-black uppercase tracking-widest px-2.5 py-1 rounded-bl-lg shadow-sm z-10">Current Term</div>
                                        <?php endif; ?>
                                        
                                        <div class="flex items-start gap-3 mb-3">
                                            <div class="w-10 h-10 rounded-full <?= $is_latest ? 'bg-[#00205b]/10 text-[#00205b]' : 'bg-slate-200 text-slate-500' ?> flex items-center justify-center shrink-0 transition-colors">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 00-2-2V7a2 2 0 00-2-2H5a2 2 0 00-2-2v12a2 2 0 002 2z"/></svg>
                                            </div>
                                            <div class="min-w-0 flex-1 pr-12">
                                                <h5 class="text-xs font-black text-[#00205b] uppercase tracking-wider drop-shadow-sm group-hover:text-[#c5a02c] transition-colors truncate"><?= htmlspecialchars($rec['semester']) ?></h5>
                                                <p class="text-[10px] font-bold text-slate-500 font-mono mt-0.5">S.Y. <?= htmlspecialchars($rec['school_year']) ?></p>
                                            </div>
                                        </div>
                                        
                                        <!-- NEW PROGRAM INDICATOR (Inner Box) -->
                                        <div class="mb-4 bg-white border border-slate-200 rounded-lg px-2.5 py-2 shadow-sm group-hover:border-[#00205b]/30 transition-colors">
                                            <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Program Enrolled</p>
                                            <p class="text-[10px] font-black text-[#00205b] truncate" title="<?= htmlspecialchars($prog_display) ?>">
                                                <?= htmlspecialchars($prog_display) ?>
                                            </p>
                                        </div>
                                        
                                        <div class="mt-auto text-[9px] text-slate-500 font-black uppercase tracking-widest border-t border-slate-200 pt-2.5 flex justify-between items-center">
                                            <span><?= htmlspecialchars($rec['year_level']) ?></span>
                                            <span class="text-[#00205b] flex items-center gap-1 group-hover:text-[#c5a02c] transition-colors">View File <svg class="w-3.5 h-3.5 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- ACTUAL COR VIEWS -->
                        <div class="bg-slate-200/50 p-4 flex flex-col items-center relative z-20 rounded-b-xl" id="cor_display_area">
                            <?php foreach ($all_enrolled_records as $index => $rec): 
                                $rec_id = $rec['id'];
                                $r_sy = $rec['school_year'];
                                $r_sem = $rec['semester'];
                                $r_yl = $rec['year_level'];
                                $r_sec = isset($rec['assigned_section']) ? $rec['assigned_section'] : '';
                                $r_status = isset($rec['student_status']) ? $rec['student_status'] : 'Regular';
                                
                                $r_subjects = isset($student_curriculum_history[$rec['id']]) ? $student_curriculum_history[$rec['id']] : [];
                                
                                $r_total_units = 0;
                                foreach ($r_subjects as $sub_item) {
                                    if (isset($sub_item['units'])) {
                                        $r_total_units += (float)$sub_item['units'];
                                    }
                                }

                                $rec_fee_breakdown = [];
                                $rec_total_assessed = 0;
                                $rec_safe_year_lvl = $conn->real_escape_string($r_yl);
                                $rec_safe_sem = $conn->real_escape_string($r_sem);
                                
                                $r_prog = !empty($rec['program']) ? $rec['program'] : $student_program_raw; 
                                $r_safe_prog = $conn->real_escape_string($r_prog);

                                $rec_total_paid = isset($rec['total_paid_accumulated']) ? (float)$rec['total_paid_accumulated'] : 0;
                                
                                $adm_initial_fee = 'Miscellaneous Fee';
                                $adm_data_q = $conn->query("SELECT assigned_initial_fee, evaluated_modality FROM admissions WHERE provisioned_user_id = $user_internal_id OR email = '$student_email' LIMIT 1");
                                if ($adm_data_q && $adm_data_q->num_rows > 0) {
                                    $adm_row = $adm_data_q->fetch_assoc();
                                    if (!empty($adm_row['assigned_initial_fee'])) {
                                        $adm_initial_fee = trim($adm_row['assigned_initial_fee']);
                                    }
                                    if (empty($rec['learning_mode']) && !empty($adm_row['evaluated_modality'])) {
                                        $rec['learning_mode'] = $adm_row['evaluated_modality'];
                                    }
                                }

                                $fee_registry = [];
                                
                                // 1. Calculate General Fees from accounting_fees
                                $gen_fees = $conn->query("SELECT fee_name, amount FROM accounting_fees WHERE (target_program = 'All' OR target_program = '$r_safe_prog' OR target_program LIKE '%$r_safe_prog%') AND (target_year = 'All' OR target_year = '$rec_safe_year_lvl') AND (target_semester = 'All' OR target_semester = '$rec_safe_sem' OR target_semester IS NULL)");

                                if ($gen_fees && $gen_fees->num_rows > 0) {
                                    while ($f = $gen_fees->fetch_assoc()) {
                                        $clean_name = trim($f['fee_name']);
                                        if (in_array($clean_name, $fee_registry)) continue; // BLOCK DUPLICATES
                                        
                                        $is_tuition = (stripos($clean_name, 'Tuition') !== false);
                                        $is_misc = (stripos($clean_name, 'Misc') !== false);
                                        
                                        if ($r_status === 'Regular' && $is_tuition) continue; 
                                        if ($is_tuition && stripos($adm_initial_fee, 'Misc') !== false) continue;
                                        if ($is_misc && stripos($adm_initial_fee, 'Tuition') !== false) continue;

                                        $fee_registry[] = $clean_name;
                                        $rec_fee_breakdown[] = ['name' => $clean_name, 'amount' => (float)$f['amount']];
                                        $rec_total_assessed += (float)$f['amount'];
                                    }
                                }

                                // 2. Add Subject Fees
                                foreach ($r_subjects as $subj) {
                                    if (isset($subj['subject_fee']) && $subj['subject_fee'] > 0) {
                                        $clean_name = trim($subj['course_code'] . ' - ' . $subj['descriptive_title']);
                                        if (!in_array($clean_name, $fee_registry)) {
                                            $fee_registry[] = $clean_name;
                                            $rec_fee_breakdown[] = ['name' => $clean_name, 'amount' => (float)$subj['subject_fee']];
                                            $rec_total_assessed += (float)$subj['subject_fee'];
                                        }
                                    }
                                }

                                if (!empty($rec['retake_fee']) && $rec['retake_fee'] > 0) {
                                    $rec_fee_breakdown[] = ['name' => 'Failed/Retake Subject Fees', 'amount' => (float)$rec['retake_fee']];
                                    $rec_total_assessed += (float)$rec['retake_fee'];
                                }

                                $t_fee = 0;
                                $m_reg = 0; $m_energy = 0; $m_dev = 0; $m_online = 0; $m_inst = 0; $m_test = 0; $m_other = 0;
                                foreach($rec_fee_breakdown as $fee) {
                                    if (stripos($fee['name'], 'Tuition') !== false) {
                                        $t_fee += $fee['amount'];
                                    } else {
                                        if(stripos($fee['name'], 'Registration') !== false) { $m_reg += $fee['amount']; }
                                        elseif(stripos($fee['name'], 'Energy') !== false) { $m_energy += $fee['amount']; }
                                        elseif(stripos($fee['name'], 'Development') !== false) { $m_dev += $fee['amount']; }
                                        elseif(stripos($fee['name'], 'Online') !== false || stripos($fee['name'], 'Platform') !== false) { $m_online += $fee['amount']; }
                                        elseif(stripos($fee['name'], 'Instructional') !== false || stripos($fee['name'], 'Media') !== false) { $m_inst += $fee['amount']; }
                                        elseif(stripos($fee['name'], 'Test') !== false || stripos($fee['name'], 'Exam') !== false) { $m_test += $fee['amount']; }
                                        else { $m_other += $fee['amount']; }
                                    }
                                }
                            
                                $rec_display_balance = 0;
                                if (isset($rec['balance']) && $rec['balance'] > 0) {
                                    $rec_display_balance = (float)$rec['balance'];
                                } else {
                                    $rec_display_balance = $rec_total_assessed - $rec_total_paid;
                                    if ($rec_display_balance < 0) $rec_display_balance = 0;
                                }

                                // 3. STRICT MATCHING FOR "NEW" vs "OLD" CHECKBOXES
                                $type1 = isset($rec['admission_type']) ? $rec['admission_type'] : '';
                                $type2 = isset($rec['evaluated_admission_type']) ? $rec['evaluated_admission_type'] : '';
                                $type3 = isset($rec['a_student_type']) ? $rec['a_student_type'] : '';
                                
                                $student_admission_type = !empty($type1) ? $type1 : (!empty($type2) ? $type2 : $type3);
                                
                                // Force fallback to 'NEW' if blank OR if it contains 'Freshman'
                                if (empty(trim($student_admission_type)) || stripos($student_admission_type, 'Freshman') !== false) {
                                    $student_admission_type = 'NEW';
                                }
                                
                                $adm_type_check = strtoupper($student_admission_type);
                                $is_new = strpos($adm_type_check, 'NEW') !== false;
                                
                                $is_old = false;
                                if (strpos($adm_type_check, 'OLD') !== false) {
                                    if (strpos($adm_type_check, 'NEW') === false) {
                                        $is_old = true;
                                    }
                                }
                                 
                                $is_cc = strpos($adm_type_check, 'CHANGE COURSE') !== false;
                                $is_cross = strpos($adm_type_check, 'CROSS-ENROLLEE') !== false;
                                $is_ret = strpos($adm_type_check, 'RETURNEE') !== false;
                                $is_trans = strpos($adm_type_check, 'TRANSFEREE') !== false;
                                $is_spec = strpos($adm_type_check, 'SPECIAL') !== false;
                                $is_short = strpos($adm_type_check, 'SHORT COURSE') !== false;
                                
                                $is_mod = false;
                                if (!empty($rec['learning_mode'])) {
                                    if (stripos($rec['learning_mode'], 'Modular') !== false) {
                                        $is_mod = true;
                                    }
                                }
                                
                                $is_onl = false;
                                if (!empty($rec['learning_mode'])) {
                                    if (stripos($rec['learning_mode'], 'Online') !== false) {
                                        $is_onl = true;
                                    } else if (stripos($rec['learning_mode'], 'On-Line') !== false) {
                                        $is_onl = true;
                                    }
                                }
                                
                                $is_hyb = false;
                                if (!empty($rec['learning_mode'])) {
                                    if (stripos($rec['learning_mode'], 'Hybrid') !== false) {
                                        $is_hyb = true;
                                    }
                                }
                                
                                $is_1st = stripos($r_yl, '1st') !== false;
                                $is_2nd = stripos($r_yl, '2nd') !== false;
                                $is_3rd = stripos($r_yl, '3rd') !== false;
                                $is_4th = stripos($r_yl, '4th') !== false;
                                
                                // Graduating condition: Student must be 4th Year AND 2nd Semester
                                $is_graduating = ($is_4th && stripos($r_sem, '2nd') !== false);
                                
                                // Hide all except the first one by default
                                $is_visible = ($index === 0) ? "block" : "hidden";
                            ?>
                                <div id="cor_wrapper_<?= $rec_id ?>" class="cor-record-wrapper <?= $is_visible ?> w-full max-w-[850px] mb-8 relative print-wrapper">
                                    <div class="bg-white p-3 md:p-4 border border-slate-200 flex flex-col sm:flex-row justify-between items-center no-print gap-3 rounded-t-xl shadow-sm">
                                        
                                        <!-- Responsive Print Actions -->
                                        <div class="flex items-center gap-2 w-full sm:w-auto">
                                            <!-- ZOOM CONTROLS -->
                                            <div class="flex items-center bg-slate-50 border border-slate-200 rounded shadow-sm overflow-hidden h-[32px] shrink-0">
                                                <button onclick="window.zoomCOR(<?= $rec_id ?>, -0.1)" class="px-2 h-full flex items-center justify-center text-slate-500 hover:bg-slate-200 transition-colors border-r border-slate-200">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M20 12H4" /></svg>
                                                </button>
                                                <span id="zoomLevel_<?= $rec_id ?>" class="text-[9px] font-black text-[#00205b] uppercase tracking-widest px-2 min-w-[3rem] h-full flex items-center justify-center select-none">
                                                    100%
                                                </span>
                                                <button onclick="window.zoomCOR(<?= $rec_id ?>, 0.1)" class="px-2 h-full flex items-center justify-center text-slate-500 hover:bg-slate-200 transition-colors border-l border-slate-200">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4" /></svg>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- DIRECTLY OPENS THE DIGITAL_COR.PHP PRINT PAGE -->
                                        <button type="button" onclick="window.open('digital_cor.php?id=<?= $rec_id ?>', '_blank')" class="w-full sm:w-auto bg-[#00205b] hover:bg-[#001233] text-white border border-[#001233] px-4 py-1.5 rounded text-[9px] font-bold uppercase tracking-widest flex items-center justify-center gap-1.5 shadow-sm transition-colors cursor-pointer h-[32px]">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" /></svg>
                                            Print / PDF
                                        </button>
                                    </div>

                                    <!-- IMPORTANT: OVERFLOW HIDDEN + TOUCH ACTION NONE -->
                                    <div class="doc-container bg-slate-200/50 overflow-hidden p-2 md:p-6 flex justify-center items-center border border-t-0 border-slate-200 rounded-b-xl shadow-inner relative select-none" style="touch-action: none; cursor: grab;">
                                        <!-- ACTUAL COR PAPER (STRICT REPLICA WITH PADDING FIXES) -->
                                        <div id="cor_document_<?= $rec_id ?>" class="word-doc bg-white relative flex flex-row p-4 shadow-2xl" style="width: 210mm; height: 148.5mm; box-sizing: border-box; transform-origin: center center; position: relative;">
                                            
                                            <!-- Watermark Background -->
                                            <div class="absolute inset-0 z-0 flex items-center justify-center pointer-events-none">
                                                <img src="../logo.jpg" class="w-[50%] object-contain opacity-10" alt="Watermark">
                                            </div>

                                            <div class="flex-1 flex flex-col min-w-0 pr-3 relative z-10">
                                                
                                                <!-- Header -->
                                                <div class="flex items-start justify-center mb-2 relative">
                                                    <img src="../logo.jpg" class="w-12 h-12 object-contain absolute left-4 top-0" />
                                                    <div class="text-center pt-1">
                                                        <h1 class="text-[16px] font-black uppercase tracking-widest font-serif leading-none text-black">LYCEUM DE SAN PABLO</h1>
                                                        <p class="text-[7px] font-serif leading-tight mt-1 text-black">Richfield Educational Compound, Mahabang Parang St.,<br>Barangay San Francisco, D.I. Calihan, San Pablo City</p>
                                                        <h2 class="text-[10px] font-bold mt-1.5 uppercase font-serif tracking-widest text-black">CERTIFICATE OF REGISTRATION</h2>
                                                    </div>
                                                </div>

                                                <!-- Student Info Top row -->
                                                <table class="cor-exact border-2 border-black w-full text-[6px] leading-tight mt-1 text-black bg-transparent">
                                                    <tr>
                                                        <td class="w-[30%] border-black px-2 relative h-[26px] align-bottom pb-1">
                                                            <span class="absolute top-1 left-2 text-[5px] font-bold text-slate-600 uppercase tracking-wider">Surname</span>
                                                            <span class="font-black text-[8.5px] uppercase text-black block mt-2"><?= htmlspecialchars($cor_last) ?></span>
                                                        </td>
                                                        <td class="w-[30%] border-black px-2 relative h-[26px] align-bottom pb-1">
                                                            <span class="absolute top-1 left-2 text-[5px] font-bold text-slate-600 uppercase tracking-wider">First Name</span>
                                                            <span class="font-black text-[8.5px] uppercase text-black block mt-2"><?= htmlspecialchars($cor_first) ?></span>
                                                        </td>
                                                        <td class="w-[20%] border-black px-2 relative h-[26px] align-bottom pb-1">
                                                            <span class="absolute top-1 left-2 text-[5px] font-bold text-slate-600 uppercase tracking-wider">Middle Name</span>
                                                            <span class="font-black text-[8.5px] uppercase text-black block mt-2"><?= htmlspecialchars($cor_middle) ?></span>
                                                        </td>
                                                        <td class="w-[10%] border-black px-2 relative h-[26px] align-bottom pb-1">
                                                            <span class="absolute top-1 left-2 text-[5px] font-bold text-slate-600 uppercase tracking-wider">Gender</span>
                                                            <span class="font-black text-[8.5px] uppercase text-black block mt-2"><?= htmlspecialchars(isset($user_data['gender']) ? $user_data['gender'] : '') ?></span>
                                                        </td>
                                                        <td class="w-[10%] border-black px-2 relative h-[26px] align-bottom pb-1">
                                                            <span class="absolute top-1 left-2 text-[5px] font-bold text-slate-600 uppercase tracking-wider">Age</span>
                                                            <span class="font-black text-[8.5px] uppercase text-black block mt-2"><?= $student_age ?></span>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td colspan="3" class="border-black px-2 relative h-[24px] align-bottom pb-1">
                                                            <span class="absolute top-1 left-2 text-[5px] font-bold text-slate-600 uppercase tracking-wider">Address</span>
                                                            <span class="font-black text-[7px] uppercase text-black block mt-2 truncate max-w-[95%]"><?= htmlspecialchars(isset($user_data['address']) ? $user_data['address'] : '') ?></span>
                                                        </td>
                                                        <td class="border-black px-2 relative h-[24px] align-bottom pb-1">
                                                            <span class="absolute top-1 left-2 text-[5px] font-bold text-slate-600 uppercase tracking-wider">Date of Birth</span>
                                                            <span class="font-black text-[8px] uppercase text-black block mt-2"><?= htmlspecialchars(isset($user_data['dob']) ? $user_data['dob'] : '') ?></span>
                                                        </td>
                                                        <td class="border-black px-2 relative h-[24px] align-bottom pb-1">
                                                            <span class="absolute top-1 left-2 text-[5px] font-bold text-slate-600 uppercase tracking-wider">Contact Number</span>
                                                            <span class="font-black text-[8px] uppercase text-black block mt-2"><?= htmlspecialchars(isset($user_data['phone']) ? $user_data['phone'] : '') ?></span>
                                                        </td>
                                                    </tr>
                                                </table>

                                                <!-- Middle 3 columns -->
                                                <table class="cor-exact border-x-2 border-b-2 border-black w-full text-[6px] leading-tight text-black bg-transparent" style="border-top: none;">
                                                    <tr>
                                                        <td class="w-[33%] p-2 align-top border-r border-black">
                                                            <div class="font-black mb-1.5 border-b border-gray-400 pb-1 text-center text-[7px] uppercase tracking-widest text-black">STUDENT TYPE</div>
                                                            <div class="grid grid-cols-2 gap-x-2 gap-y-1 pl-2 uppercase text-[6px] text-black pb-1">
                                                                <div>[ <?= $is_new ? '✓' : '&nbsp;&nbsp;' ?> ] NEW</div>
                                                                <div>[ <?= $is_cc ? '✓' : '&nbsp;&nbsp;' ?> ] CHANGE COURSE</div>
                                                                
                                                                <div>[ <?= $is_old ? '✓' : '&nbsp;&nbsp;' ?> ] OLD</div>
                                                                <div>[ <?= $is_ret ? '✓' : '&nbsp;&nbsp;' ?> ] RETURNEE</div>
                                                                
                                                                <div>[ <?= $is_cross ? '✓' : '&nbsp;&nbsp;' ?> ] CROSS-ENROLLEE</div>
                                                                <div>[ <?= $is_spec ? '✓' : '&nbsp;&nbsp;' ?> ] SPECIAL</div>
                                                                
                                                                <div>[ <?= $is_mod ? '✓' : '&nbsp;&nbsp;' ?> ] MODULAR</div>
                                                                <div>[ <?= $is_trans ? '✓' : '&nbsp;&nbsp;' ?> ] TRANSFEREE</div>
                                                                
                                                                <div>[ <?= $is_onl ? '✓' : '&nbsp;&nbsp;' ?> ] ON-LINE</div>
                                                                <div>[ <?= $is_short ? '✓' : '&nbsp;&nbsp;' ?> ] SHORT COURSE</div>
                                                                
                                                                <div>[ <?= $is_hyb ? '✓' : '&nbsp;&nbsp;' ?> ] HYBRID</div>
                                                                <div></div>
                                                            </div>
                                                        </td>
                                                        <td class="w-[27%] p-2 align-top border-r border-black">
                                                            <div class="font-black mb-1.5 border-b border-gray-400 pb-1 text-center text-[7px] uppercase tracking-widest text-black">YEAR LEVEL</div>
                                                            <div class="flex flex-wrap gap-x-2 gap-y-1 justify-center mb-1 text-black text-[6px]">
                                                                <span>[ <?= $is_1st ? '✓' : '&nbsp;&nbsp;' ?> ] 1ST</span>
                                                                <span>[ <?= $is_2nd ? '✓' : '&nbsp;&nbsp;' ?> ] 2ND</span>
                                                                <span>[ <?= $is_3rd ? '✓' : '&nbsp;&nbsp;' ?> ] 3RD</span>
                                                                <span>[ <?= $is_4th ? '✓' : '&nbsp;&nbsp;' ?> ] 4TH</span>
                                                            </div>
                                                            <div class="border-t border-gray-400 pt-1 pb-1 text-black mt-1">
                                                                <span class="font-bold block mb-[2px]">GRADUATING?</span>
                                                                <span class="pl-2 text-[6px]">[ <?= $is_graduating ? '✓' : '&nbsp;&nbsp;' ?> ] YES &nbsp;&nbsp; [ <?= !$is_graduating ? '✓' : '&nbsp;&nbsp;' ?> ] NO</span>
                                                            </div>
                                                            <div class="border-t border-gray-400 pt-1 text-black">
                                                                <span class="font-bold block mb-[2px]">STATUS:</span>
                                                                <span class="pl-2 text-[6px]">[ <?= $r_status == 'Regular' ? '✓' : '&nbsp;&nbsp;' ?> ] REGULAR &nbsp;&nbsp; [ <?= $r_status == 'Irregular' ? '✓' : '&nbsp;&nbsp;' ?> ] IRREGULAR</span>
                                                            </div>
                                                        </td>
                                                        <td class="w-[40%] p-1.5 align-top text-[6px] text-black">
                                                            <div class="mb-1 flex items-end text-black px-1">
                                                                <span class="font-bold whitespace-nowrap text-[6px]">Student No.:</span> 
                                                                <span class="border-b border-black flex-1 ml-1 px-1 font-mono text-[7px] font-bold text-center"><?= htmlspecialchars($student_id) ?></span>
                                                            </div>
                                                            <div class="mb-1 flex items-end text-black px-1">
                                                                <span class="font-bold whitespace-nowrap text-[6px]">Course:</span> 
                                                                <span class="border-b border-black flex-1 ml-1 px-1 font-bold text-[6.5px] text-center truncate"><?= htmlspecialchars($rec['program']) ?></span>
                                                            </div>
                                                            <div class="mb-1 flex items-end text-black px-1">
                                                                <span class="font-bold whitespace-nowrap text-[6px]">SCHOOL YEAR:</span> 
                                                                <span class="border-b border-black flex-1 ml-1 px-1 font-bold text-[7px] text-center"><?= htmlspecialchars($r_sy) ?></span>
                                                            </div>
                                                            <div class="mb-1 mt-1 font-bold pl-2 text-black text-[6px]">
                                                                [ <?= stripos($r_sem, '1st') !== false ? '✓' : '&nbsp;&nbsp;' ?> ] 1st sem &nbsp; [ <?= stripos($r_sem, '2nd') !== false ? '✓' : '&nbsp;&nbsp;' ?> ] 2nd sem &nbsp; [ <?= stripos($r_sem, 'Summer') !== false ? '✓' : '&nbsp;&nbsp;' ?> ] Summer
                                                            </div>
                                                            <div class="mb-1 border-t border-gray-400 pt-1 flex flex-wrap gap-1 text-black px-1 text-[6px]">
                                                                <span class="font-bold">Assessment Status:</span>
                                                                <span>[ &nbsp;&nbsp; ] PRELIM</span><span>[ &nbsp;&nbsp; ] MIDTERM</span><span>[ &nbsp;&nbsp; ] FINALS</span>
                                                            </div>
                                                            <div class="flex border-t border-gray-400 pt-1 text-black px-1 text-[6px]">
                                                                <span class="font-bold mr-1 whitespace-nowrap">Scholarship:</span>
                                                                <div class="flex gap-1 flex-wrap flex-1 leading-none text-black">
                                                                    <span>[ &nbsp; ] Academic</span><span>[ &nbsp; ] Student Assist</span><span>[ &nbsp; ] Rotary</span><span>[ &nbsp; ] Others</span>
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </table>

                                                <!-- Subjects Table -->
                                                <table class="cor-exact border-x-2 border-b-2 border-black w-full text-[6px] text-black bg-transparent" style="border-top: none;">
                                                    <tr class="bg-gray-200 text-center font-bold text-black" style="background-color: #e5e7eb !important; -webkit-print-color-adjust: exact;">
                                                        <th class="w-[12%] py-1.5 px-1 border-black leading-tight text-[6.5px] text-black uppercase">SUBJECT<br>CODE</th>
                                                        <th class="w-[35%] py-1.5 px-2 border-black text-[6.5px] text-black uppercase">SUBJECT DESCRIPTION</th>
                                                        <th class="w-[10%] py-1.5 border-black text-[6.5px] text-black uppercase">DAYS</th>
                                                        <th class="w-[15%] py-1.5 border-black text-[6.5px] text-black uppercase">TIME</th>
                                                        <th class="w-[10%] py-1.5 border-black leading-tight text-[6.5px] text-black uppercase">CLASS<br>CODE</th>
                                                        <th class="w-[10%] py-1.5 border-black text-[6.5px] text-black uppercase">SECTION</th>
                                                        <th class="w-[8%] py-1.5 border-black text-[6.5px] text-black uppercase">UNITS</th>
                                                    </tr>
                                                    <?php
                                                    $row_count = 0;
                                                    if (!empty($r_subjects)) {
                                                        foreach ($r_subjects as $subj) {
                                                            echo "<tr>";
                                                            echo "<td class='text-center border-black font-bold uppercase text-[6.5px] text-black py-1 px-1'>" . htmlspecialchars(isset($subj['course_code']) ? $subj['course_code'] : '') . "</td>";
                                                            echo "<td class='border-black uppercase text-black px-2 py-1 text-[6.5px] truncate max-w-[200px]'>" . htmlspecialchars(isset($subj['descriptive_title']) ? $subj['descriptive_title'] : '') . "</td>";
                                                            echo "<td class='border-black text-black'></td><td class='border-black text-black'></td><td class='border-black text-black'></td>"; 
                                                            
                                                            $subj_sec = ($r_sec !== 'UNASSIGNED' && $r_sec !== '') ? $r_sec : '';
                                                            echo "<td class='border-black text-center font-bold uppercase text-black text-[6.5px]'>" . htmlspecialchars($subj_sec) . "</td>"; 
                                                            
                                                            echo "<td class='text-center border-black font-bold text-black text-[6.5px] py-1'>" . htmlspecialchars(isset($subj['units']) ? $subj['units'] : '') . "</td>";
                                                            echo "</tr>";
                                                            $row_count++;
                                                        }
                                                    }
                                                    while ($row_count < 7) { 
                                                        echo "<tr><td class='border-black py-[6px] text-black'>&nbsp;</td><td class='border-black text-black'></td><td class='border-black text-black'></td><td class='border-black text-black'></td><td class='border-black text-black'></td><td class='border-black text-black'></td><td class='border-black text-black'></td></tr>";
                                                        $row_count++;
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td colspan="6" class="text-right font-black pr-3 border-black tracking-[0.2em] text-[8px] py-1 text-black">TOTAL</td>
                                                        <td class="text-center font-black bg-gray-200 border-black text-[8px] text-black" style="background-color: #e5e7eb !important; -webkit-print-color-adjust: exact;"><?= $r_total_units ?></td>
                                                    </tr>
                                                </table>

                                                <!-- Footer: Assessment and Signatures -->
                                                <table class="cor-exact border-x-2 border-b-2 border-black w-full text-black bg-transparent" style="border-top: none;">
                                                    <tr>
                                                        <td class="w-[35%] p-0 align-top border-r border-black text-black">
                                                            <!-- Assessment Block -->
                                                            <table class="w-full border-collapse text-black bg-transparent">
                                                                <tr>
                                                                    <td colspan="2" class="text-center font-black border-b border-black py-1.5 text-[7px] tracking-[0.2em] text-black">ASSESSMENT</td>
                                                                </tr>
                                                                <tr>
                                                                    <td class="border-r border-b border-black w-[65%] px-2 py-1 font-bold text-[6px] text-black uppercase">Tuition Fee</td>
                                                                    <td class="border-b border-black w-[35%] text-right px-2 py-1 font-mono text-[7px] font-bold text-black"><?= $t_fee > 0 ? number_format($t_fee, 2) : '' ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <td class="border-r border-b border-black px-2 py-1 font-bold text-[6px] text-black uppercase">Miscellaneous</td>
                                                                    <td class="border-b border-black text-right px-2 py-1 font-mono text-[7px] text-black"><?= $m_other > 0 ? number_format($m_other, 2) : '' ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <td class="border-r border-b border-black px-2 py-1 font-bold text-[6px] text-black uppercase">Registration Fee</td>
                                                                    <td class="border-b border-black text-right px-2 py-1 font-mono text-[7px] text-black"><?= $m_reg>0 ? number_format($m_reg,2) : '' ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <td class="border-r border-b border-black px-2 py-1 font-bold text-[6px] text-black uppercase">Energy Fee</td>
                                                                    <td class="border-b border-black text-right px-2 py-1 font-mono text-[7px] text-black"><?= $m_energy>0 ? number_format($m_energy,2) : '' ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <td class="border-r border-b border-black px-2 py-1 font-bold text-[6px] text-black uppercase">Developmental Fee</td>
                                                                    <td class="border-b border-black text-right px-2 py-1 font-mono text-[7px] text-black"><?= $m_dev>0 ? number_format($m_dev,2) : '' ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <td class="border-r border-b border-black px-2 py-1 font-bold text-[6px] text-black uppercase">Online platform & infra</td>
                                                                    <td class="border-b border-black text-right px-2 py-1 font-mono text-[7px] text-black"><?= $m_online>0 ? number_format($m_online,2) : '' ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <td class="border-r border-b border-black px-2 py-1 font-bold text-[6px] text-black uppercase">Instructional / Media Fee</td>
                                                                    <td class="border-b border-black text-right px-2 py-1 font-mono text-[7px] text-black"><?= $m_inst>0 ? number_format($m_inst,2) : '' ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <td class="border-r border-b border-black px-2 py-1 font-bold text-[6px] text-black uppercase">Test & Examination</td>
                                                                    <td class="border-b border-black text-right px-2 py-1 font-mono text-[7px] text-black"><?= $m_test>0 ? number_format($m_test,2) : '' ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <td class="border-r border-b border-black px-2 py-1.5 font-black text-[6.5px] uppercase tracking-wider bg-gray-200 text-black" style="background-color: #e5e7eb !important; -webkit-print-color-adjust: exact;">Total Cash Basis</td>
                                                                    <td class="border-b border-black px-2 py-1.5 text-right font-black font-mono text-[7px] bg-gray-200 text-black" style="background-color: #e5e7eb !important; -webkit-print-color-adjust: exact;"><?= number_format($rec_total_assessed, 2) ?></td>
                                                                </tr>
                                                                <tr><td colspan="2" class="font-black border-b border-black px-2 py-1.5 text-[6.5px] uppercase tracking-widest text-center text-black">INSTALLMENT BASIS</td></tr>
                                                                <tr>
                                                                    <td class="border-r border-b border-black px-2 py-1 font-bold text-[6px] text-black uppercase">Down payment</td>
                                                                    <td class="border-b border-black text-right px-2 py-1 font-mono text-[7px] font-bold text-black"><?= number_format($rec_total_paid, 2) ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <td class="border-r border-b border-black px-2 py-1 font-bold text-[6px] text-black uppercase">Installment charge</td>
                                                                    <td class="border-b border-black text-right px-2 py-1 font-mono text-[7px] text-black"></td>
                                                                </tr>
                                                                <tr>
                                                                    <td class="border-r border-black font-black px-2 py-1.5 text-[7px] uppercase tracking-widest bg-gray-200 text-black" style="background-color: #e5e7eb !important; -webkit-print-color-adjust: exact;">BALANCE</td>
                                                                    <td class="font-black text-right px-2 py-1.5 font-mono text-[7.5px] bg-gray-200 text-black" style="background-color: #e5e7eb !important; -webkit-print-color-adjust: exact;"><?= number_format($rec_display_balance, 2) ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <td colspan="2" class="text-center pt-6 pb-2 border-t border-black text-black">
                                                                        <div class="border-t border-black w-[80%] mx-auto mt-1"></div>
                                                                        <div class="text-[6px] font-bold mt-1 text-black">(Signature Over Printed Name)</div>
                                                                    </td>
                                                                </tr>
                                                            </table>
                                                        </td>
                                                        <td class="w-[65%] p-3 align-top relative text-black">
                                                             <!-- Signatures and Stamp -->
                                                             <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                                                                 <div class="text-[#00205b]/10 font-serif font-black text-6xl tracking-[0.3em] uppercase border-[5px] border-[#00205b]/10 p-2 px-6 rounded transform rotate-[-15deg]">
                                                                     ENROLLED
                                                                 </div>
                                                             </div>

                                                             <div class="flex justify-between items-end h-full w-full relative z-10 pt-20 px-6 pb-3 text-black">
                                                                 <div class="text-center w-[40%] text-black">
                                                                     <div class="border-t border-black pt-1 text-black">
                                                                         <div class="text-[8px] font-black uppercase mb-0.5 text-black">Corina Joyce C. Obiena</div>
                                                                         <div class="text-[6px] uppercase tracking-widest text-black font-bold">SCHOOL REGISTRAR</div>
                                                                     </div>
                                                                     <div class="text-[7px] font-black uppercase mt-3 text-left tracking-wider text-black">PREPARED BY :</div>
                                                                 </div>
                                                                 <div class="text-center w-[40%] text-black">
                                                                     <div class="border-t border-black pt-1 text-black">
                                                                         <div class="text-[8px] font-black uppercase mb-0.5 text-black">Eric Dolloso</div>
                                                                         <div class="text-[6px] uppercase tracking-widest text-black font-bold">SCHOOL HEAD</div>
                                                                     </div>
                                                                     <div class="text-[7px] font-black uppercase mt-3 text-left tracking-wider text-black">APPROVED BY:</div>
                                                                 </div>
                                                             </div>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </div>
                                            
                                            <div class="w-6 flex items-start justify-center pt-8 shrink-0">
                                                <div class="text-[8px] font-black tracking-[0.1em] text-black whitespace-nowrap" style="writing-mode: vertical-rl; transform: rotate(180deg);">
                                                    Students' Original Copy
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
        </div>
        
        <button id="floatingBackToTop" onclick="scrollToTop()" class="fixed bottom-6 right-6 md:bottom-10 md:right-10 bg-gradient-to-br from-[#003882] to-[#00205b] hover:from-[#004ba8] hover:to-[#00205b] border border-[#001233] text-white w-14 h-14 rounded-full shadow-[0_10px_25px_rgba(0,32,91,0.5),inset_0_2px_0_rgba(255,255,255,0.3)] z-[90] flex items-center justify-center transition-all duration-300 opacity-0 pointer-events-none translate-y-4 focus:outline-none group">
            <svg class="w-6 h-6 drop-shadow-sm group-hover:-translate-y-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 15l7-7 7 7" /></svg>
        </button>

    </main>

    <script src="student.js?v=<?= time() ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="digital_cor_record.js?v=<?= time() ?>"></script>

</body>
</html>