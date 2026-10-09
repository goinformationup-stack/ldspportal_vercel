<?php
// 1. FORCE ERROR REPORTING & VISUAL FAILSAFE
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "<div style='position:fixed; top:0; left:0; width:100%; z-index:99999; background:#ef4444; color:white; padding:20px; font-family:monospace; font-weight:bold; box-shadow:0 10px 25px rgba(0,0,0,0.5);'>";
        echo "<h2 style='margin-top:0;'>CRITICAL PHP ERROR DETECTED</h2>";
        echo "<strong>Message:</strong> " . htmlspecialchars($error['message']) . "<br><br>";
        echo "<strong>File:</strong> " . $error['file'] . "<br>";
        echo "<strong>Line:</strong> " . $error['line'];
        echo "</div>";
    }
});

// 2. ISOLATED SESSION HANDLER FOR STAFF
$session_lifetime = 60 * 60 * 24 * 30; // 30 days
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params($session_lifetime, '/');
session_name('LDSP_STAFF_SESSION'); 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. CACHE BUSTING
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

include "../dbconn.php";

// =========================================================
// STRICT ROLE ENFORCEMENT: ACCOUNTING & ADMIN
// =========================================================
$allowed_roles = ['admin', 'accounting'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../index.php");
    exit();
}
$can_edit = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'accounting');
$success_msg = "";
$error_msg = "";

// =========================================================
// 🔥 THE ULTIMATE DATABASE CLONE & RECOVERY SCRIPT 🔥
// =========================================================
try { 
    // Step 1: If accounting_fees exists but has 0 rows (the bug), DROP IT so we can cleanly clone.
    $check_new = $conn->query("SHOW TABLES LIKE 'accounting_fees'");
    if ($check_new && $check_new->num_rows > 0) {
        $check_empty = $conn->query("SELECT COUNT(*) as count FROM accounting_fees");
        if ($check_empty && $check_empty->fetch_assoc()['count'] == 0) {
            $conn->query("DROP TABLE accounting_fees");
        }
    }

    // Step 2: Perfectly clone the structure and data from registrar_fees
    $check_old = $conn->query("SHOW TABLES LIKE 'registrar_fees'");
    $check_new_again = $conn->query("SHOW TABLES LIKE 'accounting_fees'");
    
    if ($check_old && $check_old->num_rows > 0 && (!$check_new_again || $check_new_again->num_rows === 0)) {
        $conn->query("CREATE TABLE accounting_fees LIKE registrar_fees");
        $conn->query("INSERT INTO accounting_fees SELECT * FROM registrar_fees");
    }

    // Step 3: Ensure prospectus fee columns exist
    $check_col = $conn->query("SHOW COLUMNS FROM prospectus LIKE 'unit_cost'");
    if ($check_col && $check_col->num_rows === 0) {
        $conn->query("ALTER TABLE prospectus ADD COLUMN unit_cost DECIMAL(10,2) DEFAULT 0.00");
    }
    $check_col2 = $conn->query("SHOW COLUMNS FROM prospectus LIKE 'subject_fee'");
    if ($check_col2 && $check_col2->num_rows === 0) {
        $conn->query("ALTER TABLE prospectus ADD COLUMN subject_fee DECIMAL(10,2) DEFAULT 0.00");
    }

    // Step 4: Add target_semester to allow precise semester filtering
    $check_col3 = $conn->query("SHOW COLUMNS FROM accounting_fees LIKE 'target_semester'");
    if ($check_col3 && $check_col3->num_rows === 0) {
        $conn->query("ALTER TABLE accounting_fees ADD COLUMN target_semester VARCHAR(50) DEFAULT 'All'");
    }
    $check_col4 = $conn->query("SHOW COLUMNS FROM registrar_fees LIKE 'target_semester'");
    if ($check_col4 && $check_col4->num_rows === 0) {
        $conn->query("ALTER TABLE registrar_fees ADD COLUMN target_semester VARCHAR(50) DEFAULT 'All'");
    }

} catch (Exception $e) {}


// =========================================================
// POST ACTION PROCESSOR
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // ADD GENERAL FEE
    if (isset($_POST['add_fee'])) {
        $fee_name = $conn->real_escape_string($_POST['fee_name']);
        $amount = (float)$_POST['amount'];
        $payment_rule = $conn->real_escape_string($_POST['payment_rule']);
        $linked_subject = $conn->real_escape_string($_POST['linked_subject'] ?? 'None');
        $target_program = $conn->real_escape_string($_POST['target_program']);
        $target_year = $conn->real_escape_string($_POST['target_year']);
        $target_semester = $conn->real_escape_string($_POST['target_semester'] ?? 'All');
        $author = $conn->real_escape_string($_SESSION['firstname'] ?? 'Accounting');
        
        $first_payment = $payment_rule === 'Installments Allowed' ? (float)($_POST['first_payment'] ?? 0) : 0;
        $second_payment = $payment_rule === 'Installments Allowed' ? (float)($_POST['second_payment'] ?? 0) : 0;

        $conn->query("INSERT INTO accounting_fees (fee_name, amount, payment_rule, linked_subject, target_program, target_year, target_semester, created_by, created_at, first_payment, second_payment) VALUES ('$fee_name', $amount, '$payment_rule', '$linked_subject', '$target_program', '$target_year', '$target_semester', '$author', NOW(), $first_payment, $second_payment)");
        $success_msg = "New fee structure added successfully.";
    }

    // EDIT GENERAL FEE
    if (isset($_POST['edit_fee'])) {
        $fee_id = (int)$_POST['fee_id'];
        $fee_name = $conn->real_escape_string($_POST['fee_name']);
        $amount = (float)$_POST['amount'];
        $payment_rule = $conn->real_escape_string($_POST['payment_rule']);
        $linked_subject = $conn->real_escape_string($_POST['linked_subject'] ?? 'None');
        $target_program = $conn->real_escape_string($_POST['target_program']);
        $target_year = $conn->real_escape_string($_POST['target_year']);
        $target_semester = $conn->real_escape_string($_POST['target_semester'] ?? 'All');
        
        $first_payment = $payment_rule === 'Installments Allowed' ? (float)($_POST['first_payment'] ?? 0) : 0;
        $second_payment = $payment_rule === 'Installments Allowed' ? (float)($_POST['second_payment'] ?? 0) : 0;

        $conn->query("UPDATE accounting_fees SET fee_name = '$fee_name', amount = $amount, payment_rule = '$payment_rule', linked_subject = '$linked_subject', target_program = '$target_program', target_year = '$target_year', target_semester = '$target_semester', first_payment = $first_payment, second_payment = $second_payment WHERE id = $fee_id");
        $success_msg = "Fee structure updated successfully.";
    }

    // UPDATE SPECIFIC SUBJECT FEE
    if (isset($_POST['update_subject_fee'])) {
        $course_code = $conn->real_escape_string($_POST['course_code']);
        $subject_fee = (float)$_POST['subject_fee'];
        $conn->query("UPDATE prospectus SET subject_fee = $subject_fee WHERE course_code = '$course_code'");
        $success_msg = "Subject fee for $course_code updated.";
    }

    // UPDATE GLOBAL UNIT COST
    if (isset($_POST['update_global_unit_cost'])) {
        $unit_cost = (float)$_POST['global_unit_cost'];
        $conn->query("UPDATE prospectus SET unit_cost = $unit_cost");
        $success_msg = "Global Unit Cost applied to all subjects.";
    }
}

// =========================================================
// DATA FETCHING
// =========================================================
$prog_list = [];
$prog_query = $conn->query("SELECT program_name FROM programs WHERE is_archived = 0 OR is_archived IS NULL ORDER BY program_name ASC");
if ($prog_query) { 
    while($r = $prog_query->fetch_assoc()) { 
        $prog_list[] = $r['program_name']; 
    } 
}
$year_list = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
$semester_list = ['1st Semester', '2nd Semester', 'Summer'];

$subjects_list = [];
$sub_query = $conn->query("SELECT DISTINCT course_code, descriptive_title FROM prospectus WHERE IFNULL(is_archived, 0) = 0 ORDER BY course_code ASC");
if($sub_query) {
    while($r = $sub_query->fetch_assoc()) {
        $subjects_list[] = $r;
    }
}

$all_fees = [];
$fees_res = $conn->query("SELECT * FROM accounting_fees ORDER BY target_program ASC, fee_name ASC");
if ($fees_res) { 
    while($f = $fees_res->fetch_assoc()) { 
        $all_fees[] = $f; 
    } 
}

$prospectus_subjects = []; 
$sub_q = $conn->query("SELECT course_code, MIN(descriptive_title) as title, MAX(IFNULL(units, 3)) as units, MAX(IFNULL(unit_cost, 0)) as unit_cost, MAX(IFNULL(subject_fee, 0)) as current_fee FROM prospectus WHERE IFNULL(is_archived, 0) = 0 GROUP BY course_code ORDER BY course_code ASC");
if($sub_q) { 
    while($s = $sub_q->fetch_assoc()){ 
        $prospectus_subjects[] = $s; 
    } 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Management - LDSP Accounting</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css?v=<?= time(); ?>">
    <style>
        .modal-overlay { opacity: 0; transition: opacity 0.2s ease; pointer-events: none; }
        .modal-content { transform: scale(0.95); opacity: 0; transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); pointer-events: none; }
        .modal-active.modal-overlay { opacity: 1; pointer-events: auto; }
        .modal-active .modal-content { transform: scale(1); opacity: 1; pointer-events: auto; }
        
        /* SLEEK VERTICAL SCROLLBAR FOR COMPACT ROWS */
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

    <main id="mainScrollArea" class="flex-1 min-w-0 overflow-y-auto h-full w-full pt-20 md:pt-0 relative custom-scrollbar z-10">
        <div class="p-4 md:p-6 max-w-[1500px] mx-auto relative z-20">

            <header class="mb-5 border-b border-slate-300 pb-3 flex flex-col md:flex-row justify-between md:items-end gap-3 drop-shadow-sm fade-in-up">
                <div>
                    <div class="flex items-center gap-3 mb-1">
                        <h1 class="text-2xl md:text-3xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm" id="page-title">Fee Management</h1>
                    </div>
                    <p class="text-slate-500 text-xs flex items-center gap-1.5 font-medium mt-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.8)]"></span>
                        Logged in as <?= htmlspecialchars($_SESSION['first_name'] ?? 'Accounting Staff') ?>
                    </p>
                </div>
            </header>

            <div class="fade-in-up delay-1 space-y-6">
                <!-- GENERAL FEES CARD -->
                <div class="bg-white/95 backdrop-blur-md border border-slate-200 rounded-xl flex flex-col z-20 shadow-sm border-t-[4px] border-t-[#00205b] overflow-hidden">
                    <div class="px-4 py-3 border-b border-slate-200 bg-slate-50 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 relative z-20">
                        <div>
                            <h3 class="font-black text-[#00205b] text-xs uppercase tracking-widest drop-shadow-sm flex items-center gap-2">
                                <div class="p-1 rounded-sm bg-[#00205b]/10"><svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg></div> General Fee Masterlist
                            </h3>
                        </div>
                        <div class="flex items-center gap-2 w-full sm:w-auto">
                            <div class="relative w-full sm:w-64">
                                <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none"><svg class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg></div>
                                <input type="text" id="generalFeeSearch" onkeyup="window.filterGeneralFees()" placeholder="Search fees..." class="w-full bg-white border border-slate-300 rounded px-3 py-1.5 pl-8 text-[11px] font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b]">
                            </div>
                            <?php if($can_edit): ?>
                            <button onclick="window.openModal('addFeeModal')" class="bg-[#00205b] hover:bg-[#001233] text-white border border-[#001233] px-3 py-1.5 rounded text-[9px] font-bold uppercase tracking-widest shadow-sm transition-colors whitespace-nowrap cursor-pointer">+ Add Fee</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="overflow-x-auto min-h-[250px] max-h-[350px] custom-scrollbar bg-white">
                        <table id="generalFeesTable" class="w-full text-left border-collapse text-sm table-fixed whitespace-nowrap">
                            <thead class="bg-slate-100/95 backdrop-blur-sm text-[#00205b] uppercase text-[9px] font-black tracking-widest border-b-2 border-slate-300 sticky top-0 z-30 shadow-sm">
                                <tr>
                                    <th class="px-3 py-2 border-r border-slate-200 w-[10%] text-center">Fee ID</th>
                                    <th class="px-3 py-2 border-r border-slate-200 w-[20%]">Fee Name</th>
                                    <th class="px-3 py-2 border-r border-slate-200 text-right w-[15%]">Amount (₱)</th>
                                    <th class="px-3 py-2 border-r border-slate-200 w-[15%]">Payment Rule</th>
                                    <th class="px-3 py-2 border-r border-slate-200 w-[12%]">Linked Subj</th>
                                    <th class="px-3 py-2 border-r border-slate-200 w-[15%] truncate">Target Program</th>
                                    <th class="px-3 py-2 border-r border-slate-200 w-[9%] text-center truncate">Year</th>
                                    <?php if($can_edit): ?><th class="px-3 py-2 text-center w-[4%]"></th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="general_fees_tbody" class="bg-transparent">
                                <?php if (!empty($all_fees)): foreach($all_fees as $fee): ?>
                                    <tr class="hover:bg-blue-50/40 transition-colors border-b border-slate-200">
                                        <td class="px-3 py-1.5 border-r border-slate-200 text-center font-mono text-[9px] font-bold text-slate-400">FEE-<?= str_pad($fee['id'], 4, '0', STR_PAD_LEFT) ?></td>
                                        <td class="px-3 py-1.5 border-r border-slate-200 font-bold text-[#00205b] text-[11px] truncate" title="<?= htmlspecialchars($fee['fee_name']) ?>"><?= htmlspecialchars($fee['fee_name']) ?></td>
                                        <td class="px-3 py-1.5 border-r border-slate-200 text-right font-mono text-[11px] font-black text-[#00205b]">₱<?= number_format($fee['amount'], 2) ?></td>
                                        <td class="px-3 py-1.5 border-r border-slate-200">
                                            <span class="text-[8px] font-black uppercase tracking-widest text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded border border-slate-200 shadow-sm block w-max truncate" title="<?= htmlspecialchars($fee['payment_rule']) ?>"><?= htmlspecialchars($fee['payment_rule']) ?></span>
                                            <?php if($fee['payment_rule'] === 'Installments Allowed'): ?>
                                                <div class="text-[8px] text-slate-400 font-bold tracking-wider mt-0.5 truncate">1st: ₱<?= number_format($fee['first_payment'] ?? 0, 2) ?> | 2nd: ₱<?= number_format($fee['second_payment'] ?? 0, 2) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-3 py-1.5 border-r border-slate-200 text-[9px] font-bold text-slate-600 truncate" title="<?= htmlspecialchars($fee['linked_subject'] ?? 'None') ?>"><?= htmlspecialchars($fee['linked_subject'] ?? 'None') ?></td>
                                        <td class="px-3 py-1.5 border-r border-slate-200 text-[9px] text-[#00205b] font-bold uppercase tracking-widest truncate" title="<?= htmlspecialchars($fee['target_program']) ?>"><?= htmlspecialchars($fee['target_program']) ?></td>
                                        <td class="px-3 py-1.5 border-r border-slate-200 text-[9px] text-slate-500 font-bold uppercase tracking-widest text-center truncate"><?= htmlspecialchars(str_replace(' Year', '', $fee['target_year'])) ?></td>
                                        <?php if($can_edit): ?>
                                        <td class="px-3 py-1.5 text-center">
                                            <button type="button" data-id="<?= $fee['id'] ?>" data-name="<?= htmlspecialchars($fee['fee_name'], ENT_QUOTES) ?>" data-amount="<?= $fee['amount'] ?>" data-rule="<?= htmlspecialchars($fee['payment_rule'], ENT_QUOTES) ?>" data-linked="<?= htmlspecialchars($fee['linked_subject'] ?? 'None', ENT_QUOTES) ?>" data-program="<?= htmlspecialchars($fee['target_program'], ENT_QUOTES) ?>" data-year="<?= htmlspecialchars($fee['target_year'], ENT_QUOTES) ?>" data-semester="<?= htmlspecialchars($fee['target_semester'] ?? 'All', ENT_QUOTES) ?>" data-first="<?= $fee['first_payment'] ?? 0 ?>" data-second="<?= $fee['second_payment'] ?? 0 ?>" onclick="window.openEditFeeModal(this)" class="text-[#00205b] hover:text-blue-700 bg-slate-100 hover:bg-blue-100 p-1 rounded transition-colors cursor-pointer shadow-sm border border-slate-200" title="Edit Fee"><svg class="w-3.5 h-3.5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg></button>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="<?= $can_edit ? '8' : '7' ?>" class="px-4 py-8 text-center text-slate-400 text-[10px] font-bold uppercase tracking-widest bg-slate-50/50">No general fees configured.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- SUBJECT FEES CARD -->
                <div class="bg-white/95 backdrop-blur-md border border-slate-200 rounded-xl flex flex-col z-20 shadow-sm border-t-[4px] border-t-[#00205b] overflow-hidden">
                    <div class="px-4 py-3 border-b border-slate-200 bg-slate-50 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 relative z-20">
                        <div>
                            <h3 class="font-black text-[#00205b] text-xs uppercase tracking-widest drop-shadow-sm flex items-center gap-2">
                                <div class="p-1 rounded-sm bg-[#00205b]/10"><svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg></div> Curriculum Subject Fees
                            </h3>
                        </div>
                        <div class="flex items-center gap-2 w-full sm:w-auto">
                            <div class="relative w-full sm:w-64">
                                <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none"><svg class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg></div>
                                <input type="text" id="subjectSearch" onkeyup="window.filterSubjects()" placeholder="Search Subject or Code..." class="w-full bg-white border border-slate-300 rounded px-3 py-1.5 pl-8 text-[11px] font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b]">
                            </div>
                            <?php if($can_edit): ?>
                            <button onclick="window.openModal('globalUnitCostModal')" class="bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-300 px-3 py-1.5 rounded text-[9px] font-bold uppercase tracking-widest shadow-sm transition-colors whitespace-nowrap cursor-pointer">
                                Set Global Price
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="overflow-x-auto min-h-[300px] max-h-[450px] custom-scrollbar bg-white">
                        <table id="prospectusTable" class="w-full text-left border-collapse text-sm table-fixed whitespace-nowrap">
                            <thead class="bg-slate-100/95 backdrop-blur-sm text-[#00205b] uppercase text-[9px] font-black tracking-widest border-b-2 border-slate-300 sticky top-0 z-30 shadow-sm">
                                <tr>
                                    <th class="px-3 py-2 border-r border-slate-300 w-[15%]">Course Code</th>
                                    <th class="px-3 py-2 border-r border-slate-300 w-[45%]">Descriptive Title</th>
                                    <th class="px-2 py-2 border-r border-slate-300 text-center w-[8%]">Units</th>
                                    <th class="px-3 py-2 border-r border-slate-300 text-right w-[14%]">Unit Cost</th>
                                    <th class="px-3 py-2 border-r border-slate-300 text-right w-[14%]">Subject Fee</th>
                                    <?php if($can_edit): ?><th class="px-2 py-2 text-center w-[4%]"></th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="subject_fees_tbody" class="bg-transparent border-b border-slate-200">
                                <?php if (!empty($prospectus_subjects)): foreach($prospectus_subjects as $ps): ?>
                                    <tr class="hover:bg-blue-50/40 transition-colors border-b border-slate-200 subject-row">
                                        <td class="px-3 py-1.5 border-r border-slate-300 font-mono text-[10px] font-black text-[#00205b] truncate" title="<?= htmlspecialchars($ps['course_code']) ?>"><?= htmlspecialchars($ps['course_code']) ?></td>
                                        <td class="px-3 py-1.5 border-r border-slate-300 font-semibold text-slate-700 text-[11px] truncate" title="<?= htmlspecialchars($ps['title']) ?>"><?= htmlspecialchars($ps['title']) ?></td>
                                        <td class="px-2 py-1.5 border-r border-slate-300 text-center font-bold text-slate-500 text-[11px]"><?= $ps['units'] ?></td>
                                        <td class="px-3 py-1.5 border-r border-slate-300 text-right font-mono text-[11px] <?= $ps['unit_cost'] > 0 ? 'text-[#00205b] font-black' : 'text-slate-400 font-bold' ?>">
                                            ₱<?= number_format($ps['unit_cost'], 2) ?>
                                            <div class="text-[8px] text-slate-400 tracking-wider mt-0.5">Total: ₱<?= number_format($ps['unit_cost'] * $ps['units'], 2) ?></div>
                                        </td>
                                        <td class="px-3 py-1.5 border-r border-slate-300 text-right font-mono text-[11px] <?= $ps['current_fee'] > 0 ? 'text-emerald-600 font-black' : 'text-slate-400 font-bold' ?>">₱<?= number_format($ps['current_fee'], 2) ?></td>
                                        <?php if($can_edit): ?>
                                        <td class="px-2 py-1.5 text-center">
                                            <button type="button" data-code="<?= htmlspecialchars($ps['course_code'], ENT_QUOTES) ?>" data-title="<?= htmlspecialchars($ps['title'], ENT_QUOTES) ?>" data-fee="<?= $ps['current_fee'] ?>" onclick="window.openSubjectFeeModal(this)" class="text-[#00205b] bg-slate-100 hover:bg-blue-100 border border-slate-200 p-1 rounded transition-colors cursor-pointer" title="Edit Subject Fee"><svg class="w-3.5 h-3.5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg></button>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="<?= $can_edit ? '6' : '5' ?>" class="px-4 py-8 text-center text-slate-400 text-[10px] font-bold uppercase tracking-widest bg-slate-50/50">No subjects found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <button id="floatingBackToTop" onclick="window.scrollToTop()" class="fixed bottom-6 right-6 md:bottom-10 md:right-10 bg-gradient-to-br from-[#003882] to-[#00205b] hover:from-[#004ba8] hover:to-[#00205b] border border-[#001233] text-white w-12 h-12 rounded-full shadow-lg z-[90] flex items-center justify-center transition-all duration-300 opacity-0 pointer-events-none translate-y-4 focus:outline-none group">
            <svg class="w-5 h-5 drop-shadow-sm group-hover:-translate-y-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 15l7-7 7 7" /></svg>
        </button>
    </main>

    <!-- GLOBAL UNIT COST MODAL -->
    <div id="globalUnitCostModal" class="fixed inset-0 hidden items-center justify-center p-4 modal-overlay bg-slate-900/60 backdrop-blur-sm z-[100]">
        <div class="bg-white/95 backdrop-blur-md border-t-[4px] border-t-[#00205b] rounded-xl shadow-2xl w-full max-w-sm modal-content relative">
            <div class="px-4 py-3 border-b border-slate-200/60 flex justify-between items-center relative z-20 bg-white rounded-t-xl shadow-sm">
                <h3 class="font-black text-[#00205b] text-xs uppercase tracking-widest drop-shadow-sm flex items-center gap-2">
                    <div class="p-1 rounded-sm bg-[#00205b]/10"><svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg></div> Global Unit Price
                </h3>
                <button type="button" onclick="window.closeModal('globalUnitCostModal')" class="text-slate-400 hover:text-rose-500 transition-colors bg-slate-50 hover:bg-rose-50 p-1.5 rounded focus:outline-none cursor-pointer"><svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
            </div>
            <form method="POST" class="p-5 space-y-4 bg-slate-50/90 relative z-20 rounded-b-xl">
                <div class="bg-white p-3 shadow-sm text-center border border-slate-200 rounded-lg">
                    <div class="text-[9px] font-black text-slate-500 uppercase tracking-widest mb-1">Apply to all subjects</div>
                    <div class="text-[10px] font-bold text-rose-500 leading-tight">This will permanently override the unit cost of every subject.</div>
                </div>
                <div>
                    <label class="block text-[9px] font-black text-[#00205b] uppercase tracking-widest mb-1.5 text-center">Price Per Unit (₱)</label>
                    <input type="number" step="0.01" name="global_unit_cost" required min="0" class="w-full bg-white border border-slate-300 rounded px-3 py-2 font-mono text-[#00205b] font-black text-lg text-center shadow-inner focus:outline-none focus:border-[#00205b]">
                </div>
                <div class="flex gap-2 mt-4 pt-3 border-t border-slate-200/80">
                    <button type="button" onclick="window.closeModal('globalUnitCostModal')" class="flex-1 bg-white hover:bg-slate-50 text-slate-600 border border-slate-300 px-3 py-2 rounded text-[10px] font-bold uppercase tracking-widest shadow-sm transition-colors cursor-pointer">Cancel</button>
                    <button type="submit" name="update_global_unit_cost" value="1" class="flex-1 bg-[#00205b] hover:bg-[#001233] text-white border border-[#001233] px-3 py-2 rounded text-[10px] font-bold uppercase tracking-widest shadow-sm cursor-pointer">Save Global</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ADD FEE MODAL -->
    <div id="addFeeModal" class="fixed inset-0 hidden items-center justify-center p-4 modal-overlay bg-slate-900/60 backdrop-blur-sm z-[100]">
        <div class="bg-white/95 backdrop-blur-md border-t-[4px] border-t-[#00205b] rounded-xl shadow-2xl w-full max-w-xl modal-content relative">
            <div class="px-4 py-3 border-b border-slate-200/60 flex justify-between items-center relative z-20 bg-white rounded-t-xl shadow-sm">
                <h3 class="font-black text-[#00205b] text-xs uppercase tracking-widest drop-shadow-sm flex items-center gap-2"><div class="p-1 rounded-sm bg-[#00205b]/10"><svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg></div> Add General Fee</h3>
                <button type="button" onclick="window.closeModal('addFeeModal')" class="text-slate-400 hover:text-rose-500 transition-colors bg-slate-50 hover:bg-rose-50 p-1.5 rounded focus:outline-none cursor-pointer"><svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
            </div>
            <form method="POST" class="p-5 space-y-3 bg-slate-50/90 relative z-20 rounded-b-xl">
                <div>
                    <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">Fee Name</label>
                    <input type="text" name="fee_name" required class="w-full bg-white border border-slate-300 rounded px-2.5 py-1.5 text-[11px] font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b]">
                </div>
                <div>
                    <label class="block text-[9px] font-bold text-[#00205b] uppercase tracking-widest mb-1 drop-shadow-sm text-center">Total Amount (₱)</label>
                    <input type="number" step="0.01" id="add_fee_amount" name="amount" required oninput="window.calcAddSecondPayment()" class="w-full bg-white border border-slate-300 rounded px-3 py-2 font-mono font-black text-lg text-center text-[#00205b] shadow-inner focus:outline-none focus:border-[#00205b]">
                </div>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 border-t border-slate-200/60 pt-3 mt-1">
                    <div>
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">Payment Rule</label>
                        <select name="payment_rule" id="add_payment_rule" onchange="window.toggleAddInstallments()" class="w-full bg-white border border-slate-300 rounded px-2.5 py-1.5 text-[11px] font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b] cursor-pointer">
                            <option value="Full Payment Required">Full Payment Required</option>
                            <option value="Installments Allowed">Installments Allowed</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">Linked Subject (Optional)</label>
                        <select name="linked_subject" class="w-full bg-white border border-slate-300 rounded px-2.5 py-1.5 text-[11px] font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b] cursor-pointer">
                            <option value="None">None (General Fee)</option>
                            <?php foreach($subjects_list as $s): ?>
                                <option value="<?= htmlspecialchars($s['course_code']) ?>"><?= htmlspecialchars($s['course_code']) ?> - <?= htmlspecialchars($s['descriptive_title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Installments Container -->
                <div id="add_fee_installments" class="hidden grid-cols-2 gap-3 border-t border-slate-200/60 pt-3 mt-1 bg-slate-50 p-2.5 rounded border border-slate-200">
                    <div>
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">1st Payment (₱)</label>
                        <input type="number" step="0.01" id="add_first_payment" name="first_payment" oninput="window.calcAddSecondPayment()" class="w-full bg-white border border-slate-300 rounded px-2.5 py-1.5 text-[11px] font-mono font-bold text-center text-[#00205b] shadow-inner focus:outline-none focus:border-[#00205b]">
                    </div>
                    <div>
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">2nd Payment (₱)</label>
                        <input type="number" step="0.01" id="add_second_payment" name="second_payment" readonly class="w-full bg-slate-100 border border-slate-300 border-dashed rounded px-2.5 py-1.5 text-[11px] font-mono font-bold text-center text-slate-500 shadow-inner outline-none pointer-events-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 border-t border-slate-200/60 pt-3">
                    <div>
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">Target Program</label>
                        <select name="target_program" class="w-full bg-white border border-slate-300 rounded px-2.5 py-1.5 text-[11px] font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b] cursor-pointer">
                            <option value="All">All</option>
                            <?php foreach ($prog_list as $prog): ?><option value="<?= htmlspecialchars($prog) ?>"><?= htmlspecialchars($prog) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">Target Year</label>
                        <select name="target_year" class="w-full bg-white border border-slate-300 rounded px-2.5 py-1.5 text-[11px] font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b] cursor-pointer">
                            <option value="All">All</option>
                            <?php foreach ($year_list as $yl): ?><option value="<?= htmlspecialchars($yl) ?>"><?= htmlspecialchars($yl) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">Target Semester</label>
                        <select name="target_semester" class="w-full bg-white border border-slate-300 rounded px-2.5 py-1.5 text-[11px] font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b] cursor-pointer">
                            <option value="All">All</option>
                            <?php foreach ($semester_list as $sem): ?><option value="<?= htmlspecialchars($sem) ?>"><?= htmlspecialchars($sem) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="flex gap-2 mt-4 pt-3 border-t border-slate-200/80">
                    <button type="button" onclick="window.closeModal('addFeeModal')" class="flex-1 bg-white hover:bg-slate-50 text-slate-600 border border-slate-300 px-3 py-2 rounded text-[10px] font-bold uppercase tracking-widest shadow-sm transition-colors cursor-pointer">Cancel</button>
                    <button type="submit" name="add_fee" value="1" class="flex-1 bg-[#00205b] hover:bg-[#001233] text-white border border-[#001233] shadow-sm px-3 py-2 rounded text-[10px] font-bold uppercase tracking-widest cursor-pointer">Save Fee</button>
                </div>
            </form>
        </div>
    </div>

    <!-- EDIT FEE MODAL -->
    <div id="editFeeModal" class="fixed inset-0 hidden items-center justify-center p-4 modal-overlay bg-slate-900/60 backdrop-blur-sm z-[100]">
        <div class="bg-white/95 backdrop-blur-md border-t-[4px] border-t-[#00205b] rounded-xl shadow-2xl w-full max-w-xl modal-content relative">
            <div class="px-4 py-3 border-b border-slate-200/60 flex justify-between items-center relative z-20 bg-white rounded-t-xl shadow-sm">
                <h3 class="font-black text-[#00205b] text-xs uppercase tracking-widest drop-shadow-sm flex items-center gap-2"><div class="p-1 rounded-sm bg-[#00205b]/10"><svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg></div> Edit General Fee</h3>
                <button type="button" onclick="window.closeModal('editFeeModal')" class="text-slate-400 hover:text-rose-500 transition-colors bg-slate-50 hover:bg-rose-50 p-1.5 rounded focus:outline-none cursor-pointer"><svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
            </div>
            <form method="POST" class="p-5 space-y-3 bg-slate-50/90 relative z-20 rounded-b-xl">
                <input type="hidden" name="fee_id" id="edit_fee_id">
                <div>
                    <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">Fee Name</label>
                    <input type="text" name="fee_name" id="edit_fee_name" required class="w-full bg-white border border-slate-300 rounded px-2.5 py-1.5 text-[11px] font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b]">
                </div>
                <div>
                    <label class="block text-[9px] font-bold text-[#00205b] uppercase tracking-widest mb-1 drop-shadow-sm text-center">Total Amount (₱)</label>
                    <input type="number" step="0.01" name="amount" id="edit_fee_amount" required oninput="window.calcEditSecondPayment()" class="w-full bg-white border border-slate-300 rounded px-3 py-2 font-mono font-black text-lg text-center text-[#00205b] shadow-inner focus:outline-none focus:border-[#00205b]">
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 border-t border-slate-200/60 pt-3 mt-1">
                    <div>
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">Payment Rule</label>
                        <select name="payment_rule" id="edit_fee_rule" onchange="window.toggleEditInstallments()" class="w-full bg-white border border-slate-300 rounded px-2.5 py-1.5 text-[11px] font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b] cursor-pointer">
                            <option value="Full Payment Required">Full Payment Required</option>
                            <option value="Installments Allowed">Installments Allowed</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">Linked Subject (Optional)</label>
                        <select name="linked_subject" id="edit_linked_subject" class="w-full bg-white border border-slate-300 rounded px-2.5 py-1.5 text-[11px] font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b] cursor-pointer">
                            <option value="None">None (General Fee)</option>
                            <?php foreach($subjects_list as $s): ?>
                                <option value="<?= htmlspecialchars($s['course_code']) ?>"><?= htmlspecialchars($s['course_code']) ?> - <?= htmlspecialchars($s['descriptive_title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Installments Container -->
                <div id="edit_fee_installments" class="hidden grid-cols-2 gap-3 border-t border-slate-200/60 pt-3 mt-1 bg-slate-50 p-2.5 rounded border border-slate-200">
                    <div>
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">1st Payment (₱)</label>
                        <input type="number" step="0.01" id="edit_first_payment" name="first_payment" oninput="window.calcEditSecondPayment()" class="w-full bg-white border border-slate-300 rounded px-2.5 py-1.5 text-[11px] font-mono font-bold text-center text-[#00205b] shadow-inner focus:outline-none focus:border-[#00205b]">
                    </div>
                    <div>
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">2nd Payment (₱)</label>
                        <input type="number" step="0.01" id="edit_second_payment" name="second_payment" readonly class="w-full bg-slate-100 border border-slate-300 border-dashed rounded px-2.5 py-1.5 text-[11px] font-mono font-bold text-center text-slate-500 shadow-inner outline-none pointer-events-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 border-t border-slate-200/60 pt-3">
                    <div>
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">Target Program</label>
                        <select name="target_program" id="edit_fee_program" class="w-full bg-white border border-slate-300 rounded px-2.5 py-1.5 text-[11px] font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b] cursor-pointer">
                            <option value="All">All</option>
                            <?php foreach ($prog_list as $prog): ?><option value="<?= htmlspecialchars($prog) ?>"><?= htmlspecialchars($prog) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">Target Year</label>
                        <select name="target_year" id="edit_fee_year" class="w-full bg-white border border-slate-300 rounded px-2.5 py-1.5 text-[11px] font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b] cursor-pointer">
                            <option value="All">All</option>
                            <?php foreach ($year_list as $yl): ?><option value="<?= htmlspecialchars($yl) ?>"><?= htmlspecialchars($yl) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">Target Semester</label>
                        <select name="target_semester" id="edit_fee_semester" class="w-full bg-white border border-slate-300 rounded px-2.5 py-1.5 text-[11px] font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b] cursor-pointer">
                            <option value="All">All</option>
                            <?php foreach ($semester_list as $sem): ?><option value="<?= htmlspecialchars($sem) ?>"><?= htmlspecialchars($sem) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="flex gap-2 mt-4 pt-3 border-t border-slate-200/80">
                    <button type="button" onclick="window.closeModal('editFeeModal')" class="flex-1 bg-white hover:bg-slate-50 text-slate-600 border border-slate-300 px-3 py-2 rounded text-[10px] font-bold uppercase tracking-widest shadow-sm transition-colors cursor-pointer">Cancel</button>
                    <button type="submit" name="edit_fee" value="1" class="flex-1 bg-[#00205b] hover:bg-[#001233] text-white border border-[#001233] shadow-sm px-3 py-2 rounded text-[10px] font-bold uppercase tracking-widest cursor-pointer">Update Fee</button>
                </div>
            </form>
        </div>
    </div>

    <!-- EDIT SUBJECT FEE MODAL -->
    <div id="subjectFeeModal" class="fixed inset-0 hidden items-center justify-center p-4 modal-overlay bg-slate-900/60 backdrop-blur-sm z-[100]">
        <div class="bg-white/95 backdrop-blur-md border-t-[4px] border-t-[#00205b] rounded-xl shadow-2xl w-full max-w-sm modal-content relative">
            <div class="px-4 py-3 border-b border-slate-200/60 flex justify-between items-center relative z-20 bg-white rounded-t-xl shadow-sm">
                <h3 class="font-black text-[#00205b] text-xs uppercase tracking-widest drop-shadow-sm flex items-center gap-2"><div class="p-1 rounded-sm bg-[#00205b]/10"><svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg></div> Edit Subject Fee</h3>
                <button type="button" onclick="window.closeModal('subjectFeeModal')" class="text-slate-400 hover:text-rose-500 transition-colors bg-slate-50 hover:bg-rose-50 p-1.5 rounded focus:outline-none cursor-pointer"><svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
            </div>
            <form method="POST" class="p-5 space-y-4 bg-slate-50/90 relative z-20 rounded-b-xl">
                <input type="hidden" name="course_code" id="sf_code_input">
                <div class="bg-white p-3 shadow-sm text-center border border-slate-200 rounded-lg">
                    <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5" id="sf_code_display">Course Code</div>
                    <div id="sf_title_display" class="font-bold text-[#00205b] text-xs leading-tight">Descriptive Title</div>
                </div>
                <div>
                    <label class="block text-[9px] font-black text-[#00205b] uppercase tracking-widest mb-1.5 text-center">Assigned Fee (₱)</label>
                    <input type="number" step="0.01" name="subject_fee" id="sf_fee_input" required min="0" class="w-full bg-white border border-slate-300 rounded px-3 py-2 font-mono text-[#00205b] font-black text-lg text-center shadow-inner focus:outline-none focus:border-[#00205b]">
                </div>
                <div class="flex gap-2 mt-4 pt-3 border-t border-slate-200/80">
                    <button type="button" onclick="window.closeModal('subjectFeeModal')" class="flex-1 bg-white hover:bg-slate-50 text-slate-600 border border-slate-300 px-3 py-2 rounded text-[10px] font-bold uppercase tracking-widest shadow-sm transition-colors cursor-pointer">Cancel</button>
                    <button type="submit" name="update_subject_fee" value="1" class="flex-1 bg-[#00205b] hover:bg-[#001233] text-white border border-[#001233] shadow-sm px-3 py-2 rounded text-[10px] font-bold uppercase tracking-widest cursor-pointer">Save Update</button>
                </div>
            </form>
        </div>
    </div>

    <!-- TOAST NOTIFICATION CONTAINER -->
    <div id="toastContainer" class="fixed top-5 right-5 z-[100] flex flex-col gap-2 pointer-events-none"></div>

    <script src="accounting.js?v=<?= time() ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="fee_management.js?v=<?= time() ?>"></script>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            <?php if (!empty($success_msg)): ?> window.showToast("<?= addslashes($success_msg) ?>", 'success'); <?php endif; ?>
            <?php if (!empty($error_msg)): ?> window.showToast("<?= addslashes($error_msg) ?>", 'error'); <?php endif; ?>
        });
    </script>
</body>
</html>