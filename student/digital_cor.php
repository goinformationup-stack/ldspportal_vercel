<?php
// student/digital_cor.php

if (session_status() === PHP_SESSION_NONE) {
    $session_lifetime = 60 * 60 * 24 * 30; // 30 days
    ini_set('session.gc_maxlifetime', $session_lifetime);
    session_set_cookie_params($session_lifetime, '/'); 
    session_start();
}

include "../dbconn.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    die("<div style='text-align:center; padding: 50px; font-family: sans-serif; color: #e11d48;'><h3>Unauthorized Access.</h3></div>");
}

$req_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($req_id === 0) {
    die("<div style='text-align:center; padding: 50px; font-family: sans-serif; color: #e11d48;'><h3>Error: Missing Document ID.</h3></div>");
}

$student_email = $conn->real_escape_string($_SESSION['email']);

// =========================================================
// FETCH RECORD DATA
// =========================================================
$q = $conn->query("
    SELECT 
        c.enrollment_request_id as id, c.school_year, c.semester, c.program, c.year_level, c.section as assigned_section, c.published_at as enrolled_at,
        er.payment_amount, er.payment_type, er.total_paid_accumulated, er.balance, er.learning_mode, er.assigned_fees, er.retake_fee, er.student_status,
        u.id AS user_internal_id, u.student_id, 
        p.first_name, p.last_name, p.middle_name, p.gender, p.dob, p.address, p.phone, p.admission_type,
        a.evaluated_admission_type, a.student_type as a_student_type, a.assigned_initial_fee, a.evaluated_modality
    FROM official_student_cors c
    JOIN enrollment_requests er ON c.enrollment_request_id = er.id
    JOIN users u ON c.user_id = u.id
    LEFT JOIN user_profiles p ON u.id = p.user_id
    LEFT JOIN admissions a ON u.id = a.provisioned_user_id
    WHERE c.enrollment_request_id = $req_id AND u.email = '$student_email'
    LIMIT 1
");

if (!$q || $q->num_rows === 0) {
    die("<div style='text-align:center; padding: 50px; font-family: sans-serif; color: #e11d48;'><h3>Document not found or unauthorized.</h3></div>");
}

$rec = $q->fetch_assoc();

// Personal Details
$cor_last = $rec['last_name'] ?? ''; 
$cor_first = $rec['first_name'] ?? ''; 
$cor_middle = $rec['middle_name'] ?? '';
$student_id = $rec['student_id'] ?? 'Pending';
$r_prog = $rec['program'] ?? '';
$r_sy = $rec['school_year'] ?? '';
$r_sem = $rec['semester'] ?? '';
$r_yl = $rec['year_level'] ?? '';
$r_sec = $rec['assigned_section'] ?? '';
$r_status = $rec['student_status'] ?? 'Regular';

// Age Calculation
$student_age = '';
if (!empty($rec['dob']) && $rec['dob'] !== '0000-00-00') {
    $dob = new DateTime($rec['dob']);
    $now = new DateTime();
    $student_age = $now->diff($dob)->y;
}

// Checkboxes Logic
$type1 = $rec['admission_type'] ?? '';
$type2 = $rec['evaluated_admission_type'] ?? '';
$type3 = $rec['a_student_type'] ?? '';
$student_admission_type = !empty($type1) ? $type1 : (!empty($type2) ? $type2 : $type3);
if (empty(trim($student_admission_type)) || stripos($student_admission_type, 'Freshman') !== false) {
    $student_admission_type = 'NEW';
}
$adm_type_check = strtoupper($student_admission_type);
$is_new = strpos($adm_type_check, 'NEW') !== false;
$is_old = (strpos($adm_type_check, 'OLD') !== false && !$is_new);
$is_cc = strpos($adm_type_check, 'CHANGE COURSE') !== false;
$is_cross = strpos($adm_type_check, 'CROSS-ENROLLEE') !== false;
$is_ret = strpos($adm_type_check, 'RETURNEE') !== false;
$is_trans = strpos($adm_type_check, 'TRANSFEREE') !== false;
$is_spec = strpos($adm_type_check, 'SPECIAL') !== false;
$is_short = strpos($adm_type_check, 'SHORT COURSE') !== false;

$mode = !empty($rec['learning_mode']) ? $rec['learning_mode'] : ($rec['evaluated_modality'] ?? '');
$is_mod = stripos($mode, 'Modular') !== false;
$is_onl = (stripos($mode, 'Online') !== false || stripos($mode, 'On-Line') !== false);
$is_hyb = stripos($mode, 'Hybrid') !== false;

$is_1st = stripos($r_yl, '1st') !== false;
$is_2nd = stripos($r_yl, '2nd') !== false;
$is_3rd = stripos($r_yl, '3rd') !== false;
$is_4th = stripos($r_yl, '4th') !== false;
$is_graduating = ($is_4th && stripos($r_sem, '2nd') !== false);

$is_sem1 = stripos($r_sem, '1st') !== false;
$is_sem2 = stripos($r_sem, '2nd') !== false;
$is_summer = stripos($r_sem, 'Summer') !== false;

// Fetch Subjects
$r_subjects = [];
$r_total_units = 0;
$enrolled_q = $conn->query("
    SELECT p.* 
    FROM enrollments e 
    JOIN prospectus p ON e.subject_id = p.id 
    WHERE e.enrollment_request_id = $req_id 
    ORDER BY p.year_level ASC, p.semester ASC
");
if ($enrolled_q && $enrolled_q->num_rows > 0) {
    while($s = $enrolled_q->fetch_assoc()) { 
        $r_subjects[] = $s; 
        $r_total_units += (float)($s['units'] ?? 0);
    }
}

// Fetch Fees
$fee_registry = [];
$rec_fee_breakdown = [];
$rec_total_assessed = 0;
$rec_safe_year_lvl = $conn->real_escape_string($r_yl);
$rec_safe_sem = $conn->real_escape_string($r_sem);
$r_safe_prog = $conn->real_escape_string($r_prog);
$adm_initial_fee = !empty($rec['assigned_initial_fee']) ? trim($rec['assigned_initial_fee']) : 'Miscellaneous Fee';

// General Fees
$gen_fees = $conn->query("SELECT fee_name, amount FROM accounting_fees WHERE (target_program = 'All' OR target_program = '$r_safe_prog' OR target_program LIKE '%$r_safe_prog%') AND (target_year = 'All' OR target_year = '$rec_safe_year_lvl') AND (target_semester = 'All' OR target_semester = '$rec_safe_sem' OR target_semester IS NULL)");
if ($gen_fees && $gen_fees->num_rows > 0) {
    while ($f = $gen_fees->fetch_assoc()) {
        $clean_name = trim($f['fee_name']);
        if (in_array($clean_name, $fee_registry)) continue; 
        
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

// Subject Fees
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

// Retake Fee
if (!empty($rec['retake_fee']) && $rec['retake_fee'] > 0) {
    $rec_fee_breakdown[] = ['name' => 'Failed/Retake Subject Fees', 'amount' => (float)$rec['retake_fee']];
    $rec_total_assessed += (float)$rec['retake_fee'];
}

// Distribute breakdown
$t_fee = 0; $m_reg = 0; $m_energy = 0; $m_dev = 0; $m_online = 0; $m_inst = 0; $m_test = 0; $m_other = 0;
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

$rec_total_paid = isset($rec['total_paid_accumulated']) ? (float)$rec['total_paid_accumulated'] : 0;
$rec_display_balance = 0;
if (isset($rec['balance']) && $rec['balance'] > 0) {
    $rec_display_balance = (float)$rec['balance'];
} else {
    $rec_display_balance = $rec_total_assessed - $rec_total_paid;
    if ($rec_display_balance < 0) $rec_display_balance = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COR_<?= htmlspecialchars($student_id) ?>_<?= htmlspecialchars($r_sem) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Times+New+Roman:wght@400;700&family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <style>
        /* BASE SETUP */
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f1f5f9; 
            margin: 0; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            -webkit-print-color-adjust: exact !important; 
            print-color-adjust: exact !important; 
        }
        
        /* THE PAPER - PREVIEW MODE */
        .cor-paper {
            background-color: white;
            width: 11in;       
            height: 8.5in;     
            margin: 20px auto;
            padding: 0.3in 0.4in;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
            box-sizing: border-box;
            display: flex;
        }

        /* STRICT 1px BORDER COLLAPSE TABLES */
        table.strict-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: -1px; /* Prevents double lines between stacked tables */
        }
        table.strict-table th, table.strict-table td {
            border: 1px solid #000;
            padding: 3px 4px; 
            color: #000;
        }

        /* CHECKBOX FONT */
        .chk { font-family: monospace; font-weight: bold; }

        @media print {
            .no-print { display: none !important; }
            
            @page {
                size: landscape; /* Auto-adapts to landscape paper */
                margin: 0; /* Erases browser URL headers */
            }

            /* --- ABSOLUTE 1-PAGE LOCK --- */
            html, body { 
                display: block !important;
                background: white !important; 
                margin: 0 !important; 
                padding: 0 !important;
                width: 100vw !important;
                height: 100vh !important; 
                max-height: 100vh !important;
                overflow: hidden !important; /* CRITICAL: Kills page 2 completely */
                box-sizing: border-box !important;
            }

            .cor-paper {
                box-shadow: none !important;
                margin: 0 !important;
                width: 100vw !important;
                height: 100vh !important;
                max-width: 100vw !important;
                max-height: 100vh !important;
                /* Dynamic padding hugging the exact physical paper edges */
                padding: 4vh 4vw !important; 
                border: none !important;
                page-break-inside: avoid !important;
                overflow: hidden !important;
                box-sizing: border-box !important;
                display: flex !important;
            }
        }
    </style>
</head>
<body>

    <!-- Print Toolbar -->
    <div class="no-print w-[11in] mt-6 mb-2 flex justify-between items-center bg-white p-4 rounded-lg shadow-sm border border-slate-200">
        <div class="text-sm text-slate-500 font-semibold">
            Paper Format: <span class="text-[#00205b] font-bold">Auto-Fit Landscape (A4, Letter, Legal)</span>
        </div>
        <div class="flex gap-3">
            <button onclick="window.print()" class="bg-[#00205b] hover:bg-blue-900 text-white text-xs font-bold uppercase tracking-wider px-6 py-2.5 rounded-md shadow flex items-center gap-2 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                Print Document
            </button>
            <button onclick="window.close()" class="bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold uppercase tracking-wider px-4 py-2.5 rounded-md transition">
                Close
            </button>
        </div>
    </div>

    <!-- THE COR PAPER -->
    <div class="cor-paper">
        
        <!-- Faint Large Background Logo -->
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none z-0 overflow-hidden">
            <img src="../logo.jpg" class="w-[5.5in] object-contain opacity-10" alt="Watermark">
        </div>

        <!-- THE REALISTIC FRONT OVERLAY STAMP (ENROLLED) -->
        <div class="absolute inset-0 flex items-end justify-end pointer-events-none z-[50] pb-[8%] pr-[10%]">
            <div class="font-serif font-black tracking-[0.2em] uppercase border-[5px] py-1 px-5 rounded-2xl transform rotate-[-15deg]" style="font-size: 5rem; color: #cbd5e1; border-color: #cbd5e1; opacity: 0.8; mix-blend-mode: multiply; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
                ENROLLED
            </div>
        </div>
        
        <div class="flex w-full h-full relative z-10 bg-transparent">
            <!-- MAIN LEFT CONTENT -->
            <div class="w-full pr-2 flex flex-col h-full min-h-0">
                
                <!-- HEADER -->
                <div class="relative w-full mb-3 shrink-0 pt-2">
                    <img src="../logo.jpg" class="absolute left-2 top-0 w-16 h-16 object-contain z-10" alt="LDSP Logo" />
                    <div class="text-center w-full">
                        <h1 class="text-[20px] font-black uppercase tracking-widest leading-none text-black" style="font-family: 'Times New Roman', serif;">LYCEUM DE SAN PABLO</h1>
                        <p class="text-[9px] leading-tight mt-1 text-black" style="font-family: 'Times New Roman', serif;">
                            Richfield Educational Compound, Mahabang Parang St.,<br>
                            Barangay San Francisco, D.I. Calihan, San Pablo City
                        </p>
                        <h2 class="text-[13px] font-black mt-2 uppercase tracking-widest text-black" style="font-family: 'Times New Roman', serif;">CERTIFICATE OF REGISTRATION</h2>
                    </div>
                </div>

                <!-- TABLE 1: Student Identity -->
                <table class="strict-table text-[10px] shrink-0 mt-1">
                    <tr>
                        <td class="w-[30%] border-black px-2 py-1.5 align-top">
                            <div class="text-[6px] font-bold text-gray-600 uppercase tracking-wider mb-0.5">Surname</div>
                            <div class="font-black text-[12px] uppercase text-black truncate"><?= htmlspecialchars($cor_last) ?></div>
                        </td>
                        <td class="w-[30%] border-black px-2 py-1.5 align-top">
                            <div class="text-[6px] font-bold text-gray-600 uppercase tracking-wider mb-0.5">First Name</div>
                            <div class="font-black text-[12px] uppercase text-black truncate"><?= htmlspecialchars($cor_first) ?></div>
                        </td>
                        <td class="w-[20%] border-black px-2 py-1.5 align-top">
                            <div class="text-[6px] font-bold text-gray-600 uppercase tracking-wider mb-0.5">Middle Name</div>
                            <div class="font-black text-[12px] uppercase text-black truncate"><?= htmlspecialchars($cor_middle) ?></div>
                        </td>
                        <td class="w-[10%] border-black px-2 py-1.5 align-top">
                            <div class="text-[6px] font-bold text-gray-600 uppercase tracking-wider mb-0.5">Gender</div>
                            <div class="font-black text-[10px] uppercase text-black truncate"><?= htmlspecialchars($rec['gender'] ?? '') ?></div>
                        </td>
                        <td class="w-[10%] border-black px-2 py-1.5 align-top">
                            <div class="text-[6px] font-bold text-gray-600 uppercase tracking-wider mb-0.5">Age</div>
                            <div class="font-black text-[10px] uppercase text-black"><?= $student_age ?></div>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="3" class="border-black px-2 py-1.5 align-top">
                            <div class="text-[6px] font-bold text-gray-600 uppercase tracking-wider mb-0.5">Address</div>
                            <div class="font-black text-[10px] uppercase text-black truncate w-[98%]"><?= htmlspecialchars($rec['address'] ?? '') ?></div>
                        </td>
                        <td class="border-black px-2 py-1.5 align-top">
                            <div class="text-[6px] font-bold text-gray-600 uppercase tracking-wider mb-0.5">Date of Birth</div>
                            <div class="font-black text-[10px] uppercase text-black"><?= htmlspecialchars($rec['dob'] ?? '') ?></div>
                        </td>
                        <td class="border-black px-2 py-1.5 align-top">
                            <div class="text-[6px] font-bold text-gray-600 uppercase tracking-wider mb-0.5">Contact Number</div>
                            <div class="font-black text-[10px] uppercase text-black"><?= htmlspecialchars($rec['phone'] ?? '') ?></div>
                        </td>
                    </tr>
                </table>

                <!-- TABLE 2: Metadata / Status -->
                <table class="strict-table text-[10px] shrink-0" style="border-top: none;">
                    <tr>
                        <td class="w-[33%] align-top p-1.5">
                            <div class="font-black mb-1.5 text-center text-[8px] uppercase tracking-widest text-black border-b border-gray-300 pb-0.5">STUDENT TYPE</div>
                            <div class="grid grid-cols-2 gap-x-2 gap-y-1 pl-2 text-[7px] text-black">
                                <div><span class="chk">[ <?= $is_new ? '✓' : '&nbsp;&nbsp;' ?> ]</span> NEW</div>
                                <div><span class="chk">[ <?= $is_cc ? '✓' : '&nbsp;&nbsp;' ?> ]</span> CHANGE COURSE</div>
                                <div><span class="chk">[ <?= $is_old ? '✓' : '&nbsp;&nbsp;' ?> ]</span> OLD</div>
                                <div><span class="chk">[ <?= $is_ret ? '✓' : '&nbsp;&nbsp;' ?> ]</span> RETURNEE</div>
                                <div><span class="chk">[ <?= $is_cross ? '✓' : '&nbsp;&nbsp;' ?> ]</span> CROSS-ENROLLEE</div>
                                <div><span class="chk">[ <?= $is_spec ? '✓' : '&nbsp;&nbsp;' ?> ]</span> SPECIAL</div>
                                <div><span class="chk">[ <?= $is_mod ? '✓' : '&nbsp;&nbsp;' ?> ]</span> MODULAR</div>
                                <div><span class="chk">[ <?= $is_trans ? '✓' : '&nbsp;&nbsp;' ?> ]</span> TRANSFEREE</div>
                                <div><span class="chk">[ <?= $is_onl ? '✓' : '&nbsp;&nbsp;' ?> ]</span> ON-LINE</div>
                                <div><span class="chk">[ <?= $is_short ? '✓' : '&nbsp;&nbsp;' ?> ]</span> SHORT COURSE</div>
                                <div><span class="chk">[ <?= $is_hyb ? '✓' : '&nbsp;&nbsp;' ?> ]</span> HYBRID</div>
                                <div></div>
                            </div>
                        </td>
                        <td class="w-[27%] align-top p-1.5">
                            <div class="font-black mb-1.5 text-center text-[8px] uppercase tracking-widest text-black border-b border-gray-300 pb-0.5">YEAR LEVEL</div>
                            <div class="flex flex-wrap justify-center gap-x-3 gap-y-1 text-black text-[7px] mb-1.5">
                                <span><span class="chk">[ <?= $is_1st ? '✓' : '&nbsp;&nbsp;' ?> ]</span> 1ST</span>
                                <span><span class="chk">[ <?= $is_2nd ? '✓' : '&nbsp;&nbsp;' ?> ]</span> 2ND</span>
                                <span><span class="chk">[ <?= $is_3rd ? '✓' : '&nbsp;&nbsp;' ?> ]</span> 3RD</span>
                                <span><span class="chk">[ <?= $is_4th ? '✓' : '&nbsp;&nbsp;' ?> ]</span> 4TH</span>
                            </div>
                            <div class="border-t border-gray-300 pt-1 text-black text-[7px] mt-1">
                                <span class="font-bold block mb-0.5">GRADUATING?</span>
                                <span class="pl-2"><span class="chk">[ <?= $is_graduating ? '✓' : '&nbsp;&nbsp;' ?> ]</span> YES &nbsp;&nbsp;&nbsp; <span class="chk">[ <?= !$is_graduating ? '✓' : '&nbsp;&nbsp;' ?> ]</span> NO</span>
                            </div>
                            <div class="border-t border-gray-300 pt-1 text-black text-[7px] mt-1">
                                <span class="font-bold block mb-0.5">STATUS:</span>
                                <span class="pl-2"><span class="chk">[ <?= $r_status == 'Regular' ? '✓' : '&nbsp;&nbsp;' ?> ]</span> REGULAR &nbsp;&nbsp; <span class="chk">[ <?= $r_status == 'Irregular' ? '✓' : '&nbsp;&nbsp;' ?> ]</span> IRREGULAR</span>
                            </div>
                        </td>
                        <td class="w-[40%] align-top p-1.5 text-[7px] text-black space-y-1">
                            <div class="flex items-end">
                                <span class="font-bold whitespace-nowrap">STUDENT NO.:</span> 
                                <span class="border-b border-black flex-1 ml-1 px-1 font-mono font-bold text-center text-[9px] pb-0.5"><?= htmlspecialchars($student_id) ?></span>
                            </div>
                            <div class="flex items-end mt-1">
                                <span class="font-bold whitespace-nowrap">COURSE:</span> 
                                <span class="border-b border-black flex-1 ml-1 px-1 font-bold text-center text-[8px] truncate pb-0.5"><?= htmlspecialchars($r_prog) ?></span>
                            </div>
                            <div class="flex items-end mt-1">
                                <span class="font-bold whitespace-nowrap">SCHOOL YEAR:</span> 
                                <span class="border-b border-black flex-1 ml-1 px-1 font-bold text-center text-[9px] pb-0.5"><?= htmlspecialchars($r_sy) ?></span>
                            </div>
                            <div class="font-bold pl-2 pt-0.5 mt-0.5">
                                <span class="chk">[ <?= $is_sem1 ? '✓' : '&nbsp;&nbsp;' ?> ]</span> 1ST SEM &nbsp;&nbsp; <span class="chk">[ <?= $is_sem2 ? '✓' : '&nbsp;&nbsp;' ?> ]</span> 2ND SEM &nbsp;&nbsp; <span class="chk">[ <?= $is_summer ? '✓' : '&nbsp;&nbsp;' ?> ]</span> SUMMER
                            </div>
                            <div class="border-t border-gray-300 pt-1 mt-1 flex gap-2">
                                <span class="font-bold">ASSESSMENT STATUS:</span>
                                <span><span class="chk">[ &nbsp; ]</span> PRELIM</span>
                                <span><span class="chk">[ &nbsp; ]</span> MIDTERM</span>
                                <span><span class="chk">[ &nbsp; ]</span> FINALS</span>
                            </div>
                            <div class="flex border-t border-gray-300 pt-1 mt-1">
                                <span class="font-bold mr-2 whitespace-nowrap">SCHOLARSHIP:</span>
                                <div class="flex gap-2 flex-wrap">
                                    <span><span class="chk">[ &nbsp; ]</span> ACADEMIC</span>
                                    <span><span class="chk">[ &nbsp; ]</span> STUDENT ASSIST</span>
                                    <span><span class="chk">[ &nbsp; ]</span> ROTARY</span>
                                    <span><span class="chk">[ &nbsp; ]</span> OTHERS</span>
                                </div>
                            </div>
                        </td>
                    </tr>
                </table>

                <!-- TABLE 3: Subjects List -->
                <!-- flex-1 allows this table to stretch/shrink to absorb whatever space is left over perfectly -->
                <div class="flex-1 min-h-0">
                    <table class="strict-table text-[8px] h-full" style="border-top: none;">
                        <thead>
                            <tr class="bg-gray-100 font-bold text-center uppercase">
                                <th class="w-[15%] py-1.5">SUBJECT CODE</th>
                                <th class="w-[45%] py-1.5 text-left px-2">SUBJECT DESCRIPTION</th>
                                <th class="w-[8%] py-1.5">DAYS</th>
                                <th class="w-[12%] py-1.5">TIME</th>
                                <th class="w-[8%] py-1.5">CLASS CODE</th>
                                <th class="w-[7%] py-1.5">SECTION</th>
                                <th class="w-[5%] py-1.5">UNITS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $row_count = 0;
                            if (!empty($r_subjects)) {
                                foreach ($r_subjects as $subj) {
                                    echo "<tr>";
                                    echo "<td class='text-center font-bold uppercase py-1'>" . htmlspecialchars($subj['course_code'] ?? '') . "</td>";
                                    echo "<td class='uppercase pl-2 py-1 truncate max-w-[250px]'>" . htmlspecialchars($subj['descriptive_title'] ?? '') . "</td>";
                                    echo "<td></td><td></td><td></td>";
                                    $subj_sec = ($r_sec !== 'UNASSIGNED' && $r_sec !== '') ? $r_sec : '';
                                    echo "<td class='text-center font-bold uppercase py-1'>" . htmlspecialchars($subj_sec) . "</td>"; 
                                    echo "<td class='text-center font-bold py-1'>" . htmlspecialchars($subj['units'] ?? '') . "</td>";
                                    echo "</tr>";
                                    $row_count++;
                                }
                            }
                            // Reduced empty rows to 5 so it naturally fits on a single page without overflowing
                            while ($row_count < 5) { 
                                echo "<tr><td class='py-1.5'>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td></tr>";
                                $row_count++;
                            }
                            ?>
                            <tr class="bg-gray-100 font-black uppercase h-[1px]">
                                <td colspan="6" class="text-right pr-4 py-1 tracking-[0.2em] text-[9px]">TOTAL</td>
                                <td class="text-center py-1 text-[10px]"><?= $r_total_units ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- TABLE 4: Footer Assessment & Signatures -->
                <table class="strict-table text-[8px] shrink-0" style="border-top: none; height: auto;">
                    <tr>
                        <td class="w-[40%] align-top p-0">
                            <!-- Assessment Inner Table -->
                            <table class="w-full border-collapse">
                                <tr>
                                    <td colspan="2" class="text-center font-black border-b border-black py-1 text-[8px] tracking-[0.2em] uppercase">ASSESSMENT</td>
                                </tr>
                                <tr>
                                    <td class="border-r border-b border-black w-[65%] px-2 py-1.5 font-bold uppercase text-[7px]">Tuition Fee</td>
                                    <td class="border-b border-black w-[35%] text-right px-2 py-1.5 font-mono font-bold"><?= $t_fee > 0 ? number_format($t_fee, 2) : '' ?></td>
                                </tr>
                                <tr>
                                    <td class="border-r border-b border-black px-2 py-1.5 font-bold uppercase text-[7px]">Miscellaneous</td>
                                    <td class="border-b border-black text-right px-2 py-1.5 font-mono text-[8px]"><?= $m_other > 0 ? number_format($m_other, 2) : '' ?></td>
                                </tr>
                                <tr>
                                    <td class="border-r border-b border-black px-2 py-1.5 font-bold uppercase text-[7px]">Registration Fee</td>
                                    <td class="border-b border-black text-right px-2 py-1.5 font-mono text-[8px]"><?= $m_reg > 0 ? number_format($m_reg, 2) : '' ?></td>
                                </tr>
                                <tr>
                                    <td class="border-r border-b border-black px-2 py-1.5 font-bold uppercase text-[7px]">Energy Fee</td>
                                    <td class="border-b border-black text-right px-2 py-1.5 font-mono text-[8px]"><?= $m_energy > 0 ? number_format($m_energy, 2) : '' ?></td>
                                </tr>
                                <tr>
                                    <td class="border-r border-b border-black px-2 py-1.5 font-bold uppercase text-[7px]">Developmental Fee</td>
                                    <td class="border-b border-black text-right px-2 py-1.5 font-mono text-[8px]"><?= $m_dev > 0 ? number_format($m_dev, 2) : '' ?></td>
                                </tr>
                                <tr>
                                    <td class="border-r border-b border-black px-2 py-1.5 font-bold uppercase text-[7px]">Online platform & infra</td>
                                    <td class="border-b border-black text-right px-2 py-1.5 font-mono text-[8px]"><?= $m_online > 0 ? number_format($m_online, 2) : '' ?></td>
                                </tr>
                                <tr>
                                    <td class="border-r border-b border-black px-2 py-1.5 font-bold uppercase text-[7px]">Instructional / Media Fee</td>
                                    <td class="border-b border-black text-right px-2 py-1.5 font-mono text-[8px]"><?= $m_inst > 0 ? number_format($m_inst, 2) : '' ?></td>
                                </tr>
                                <tr>
                                    <td class="border-r border-b border-black px-2 py-1.5 font-bold uppercase text-[7px]">Test & Examination</td>
                                    <td class="border-b border-black text-right px-2 py-1.5 font-mono text-[8px]"><?= $m_test > 0 ? number_format($m_test, 2) : '' ?></td>
                                </tr>
                                <tr class="bg-gray-100">
                                    <td class="border-r border-b border-black px-2 py-1.5 font-black uppercase text-[7px]">TOTAL CASH BASIS</td>
                                    <td class="border-b border-black text-right px-2 py-1.5 font-mono font-black text-[9px]"><?= number_format($rec_total_assessed, 2) ?></td>
                                </tr>
                                <tr>
                                    <td colspan="2" class="text-center font-black border-b border-black py-1 text-[7px] tracking-[0.1em] uppercase">INSTALLMENT BASIS</td>
                                </tr>
                                <tr>
                                    <td class="border-r border-b border-black px-2 py-1.5 font-bold uppercase text-[7px]">Down payment</td>
                                    <td class="border-b border-black text-right px-2 py-1.5 font-mono font-bold text-[8px]"><?= number_format($rec_total_paid, 2) ?></td>
                                </tr>
                                <tr>
                                    <td class="border-r border-b border-black px-2 py-1.5 font-bold uppercase text-[7px]">Installment charge</td>
                                    <td class="border-b border-black text-right px-2 py-1.5 font-mono"></td>
                                </tr>
                                <tr class="bg-gray-100">
                                    <td class="border-r border-black px-2 py-1.5 font-black uppercase text-[8px] tracking-widest">BALANCE</td>
                                    <td class="text-right px-2 py-1.5 font-mono font-black text-[9px]"><?= number_format($rec_display_balance, 2) ?></td>
                                </tr>
                                <tr>
                                    <td colspan="2" class="text-center pt-5 pb-2 border-t border-black">
                                        <div class="border-t border-black w-[80%] mx-auto"></div>
                                        <div class="text-[6px] font-bold mt-1 text-slate-700">(SIGNATURE OVER PRINTED NAME)</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                        <td class="w-[60%] align-bottom relative p-0 bg-transparent border-black">
                            <!-- Adjusted Signature Block -->
                            <div class="flex justify-between items-end w-full relative z-10 px-6 pb-4 pt-16">
                                <div class="w-[45%]">
                                    <div class="text-[7px] font-black uppercase mb-6 text-left tracking-wider">PREPARED BY :</div>
                                    <div class="border-t border-black pt-1 text-center">
                                        <div class="text-[10px] font-black uppercase mb-0.5">Corina Joyce C. Obiena</div>
                                        <div class="text-[7px] uppercase tracking-widest font-bold text-slate-700">SCHOOL REGISTRAR</div>
                                    </div>
                                </div>
                                <div class="w-[45%]">
                                    <div class="text-[7px] font-black uppercase mb-6 text-left tracking-wider">APPROVED BY:</div>
                                    <div class="border-t border-black pt-1 text-center">
                                        <div class="text-[10px] font-black uppercase mb-0.5">Eric Dolloso</div>
                                        <div class="text-[7px] uppercase tracking-widest font-bold text-slate-700">SCHOOL HEAD</div>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                </table>

            </div>

            <!-- RIGHT SIDEBAR: VERTICAL TEXT -->
            <div class="w-8 shrink-0 flex items-start justify-center pt-12 h-full bg-transparent relative z-10">
                <div class="text-[12px] font-black tracking-[0.15em] uppercase whitespace-nowrap text-black" style="writing-mode: vertical-rl; transform: rotate(180deg);">
                    Students' Original Copy
                </div>
            </div>

        </div>
    </div>

</body>
</html>