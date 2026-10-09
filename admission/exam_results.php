<?php
// 1. FORCE ERROR REPORTING & CATCH FATAL ERRORS VISUALLY
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

include "../dbconn.php";
date_default_timezone_set('Asia/Manila');

// Allow view access to all administrative roles
$allowed_roles = ['admin', 'admission', 'registrar', 'accounting'];

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../index.php");
    exit();
}

$can_edit = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'admission');

// =========================================================
// FETCH GLOBAL SETTINGS
// =========================================================
$settings = [];
$settings_q = $conn->query("SELECT * FROM portal_settings");
if ($settings_q) {
    while ($row = $settings_q->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}
$active_school_year = $settings['active_school_year'] ?? '2024-2025';
$safe_sy = $conn->real_escape_string($active_school_year);

// =========================================================
// STRICT ACADEMIC YEAR GENERATOR (Connected to Portal Settings)
// =========================================================
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
krsort($years_list); // Sort newest to oldest

// =========================================================
// DATA FETCHING & FILTERING
// =========================================================
// DEFAULT TO ACTIVE SCHOOL YEAR FROM PORTAL SETTINGS TO PREVENT SERVER OVERLOAD
$filter_year = $_GET['year'] ?? $active_school_year; 

$where_clauses = [];
if ($filter_year !== 'All') {
    $where_clauses[] = "r.academic_year = '" . $conn->real_escape_string($filter_year) . "'";
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

$results_query = $conn->query("
    SELECT r.*, 
           a.id as admission_id, a.last_name, a.first_name, a.middle_name, a.admission_number, a.email as personal_email, a.account_request_pushed, 
           a.gender, a.pob, a.dob, a.phone, a.address, a.program, a.student_type, a.school_last_attended, a.school_year_attended, 
           a.father_name, a.father_occupation, a.father_contact, a.mother_name, a.mother_occupation, a.mother_contact, 
           a.emergency_contact_name, a.emergency_contact_number, a.influence_source, a.pushed_to_accounting, a.pushed_to_registrar, a.registrar_evaluated,
           a.evaluated_program, a.evaluated_year, a.evaluated_section, a.final_status,
           e.exam_title, 
           u.id as provisioned_user_id, u.student_id as assigned_id, u.email as institutional_email,
           a.uploaded_files
    FROM exam_results r
    JOIN admissions a ON r.student_id = a.admission_number
    LEFT JOIN entrance_exams e ON r.exam_id = e.id
    LEFT JOIN users u ON a.provisioned_user_id = u.id
    $where_sql
    ORDER BY r.time_finished DESC
");

$total_results = $results_query ? $results_query->num_rows : 0;

function renderResultRows($query_res, $can_edit) {
    ob_start();
    if ($query_res && $query_res->num_rows > 0): 
        while ($row = $query_res->fetch_assoc()): 
            
            // Combine the names securely BEFORE json_encoding
            $first = !empty($row['first_name']) ? $row['first_name'] : '';
            $middle = !empty($row['middle_name']) ? ' ' . $row['middle_name'] : '';
            $last = !empty($row['last_name']) ? $row['last_name'] . ', ' : '';
            $raw_name = trim($last . $first . $middle);
            $applicant_name = htmlspecialchars($raw_name);
            
            $row['applicant_name'] = $raw_name; // Inject raw string directly into payload
            $row['email'] = $row['personal_email']; 
            
            $profile_data = htmlspecialchars(json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

            $status = $row['status'] ?? 'Unknown';
            $score = $row['score'] ?? 0;
            $total_questions = $row['total_questions'] ?? 0;
            $academic_year = $row['academic_year'] ?? 'N/A';
            $time_finished = !empty($row['time_finished']) ? strtotime($row['time_finished']) : time();
            $adm_number = $row['admission_number'] ?? 'N/A';

            $row_class = 'hover:bg-blue-50/40 transition-colors';
    ?>
            <tr class="<?= $row_class ?> border-b border-slate-200">
                <td class="px-3 py-2 align-middle border-r border-slate-300 w-[28%] max-w-0">
                    <div class="w-full overflow-x-auto custom-scrollbar pb-1.5">
                        <div class="font-bold text-[#00205b] text-xs whitespace-nowrap searchable-name pr-2" title="<?= $applicant_name ?>"><?= $applicant_name ?></div>
                    </div>
                    <div class="pt-1.5 border-t border-slate-200">
                        <div class="text-[11px] text-slate-500 font-mono font-black tracking-widest truncate searchable-id flex items-center gap-1.5">
                            <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/></svg>
                            <?= htmlspecialchars($adm_number) ?>
                        </div>
                    </div>
                </td>
                <td class="px-2 py-2 align-middle border-r border-slate-300 w-[15%] text-center truncate">
                    <div class="text-[10px] uppercase tracking-widest font-black text-slate-600">A.Y. <?= htmlspecialchars($academic_year) ?></div>
                </td>
                <td class="px-2 py-2 align-middle border-r border-slate-300 w-[15%] text-center truncate">
                    <div class="font-mono font-black text-[#00205b] text-[11px]"><?= htmlspecialchars((string)$score) ?> <span class="text-[9px] text-slate-400 font-bold uppercase tracking-widest">/ <?= htmlspecialchars((string)$total_questions) ?></span></div>
                </td>
                <td class="px-2 py-2 align-middle border-r border-slate-300 w-[15%] text-center truncate">
                    <span class="px-2 py-1 rounded text-[9px] uppercase tracking-widest font-black shadow-sm <?= ($status === 'Passed') ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200' ?> block w-max mx-auto"><?= htmlspecialchars($status) ?></span>
                </td>
                <td class="px-2 py-2 align-middle border-r border-slate-300 text-center w-[15%] truncate">
                    <div class="text-[9px] font-bold text-slate-600 tracking-widest"><?= date('M j, Y', $time_finished) ?></div>
                    <div class="text-[8px] font-bold text-slate-400 uppercase tracking-widest mt-0.5"><?= date('h:i A', $time_finished) ?></div>
                </td>
                <td class="px-2 py-2 align-middle text-center w-[12%]">
                    <button type="button" onclick="window.openProfileModal(this)" data-profile="<?= $profile_data ?>" class="bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-300 text-[9px] font-bold uppercase tracking-widest py-1.5 px-3 rounded shadow-sm transition-colors cursor-pointer w-full flex items-center justify-center gap-1.5">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> Profile
                    </button>
                </td>
            </tr>
    <?php 
        endwhile; 
    else: ?>
        <tr><td colspan="6" class="px-4 py-16 text-center text-slate-400 text-[10px] font-bold uppercase tracking-[0.2em] bg-white border border-slate-300">No examination records found for the selected filters.</td></tr>
    <?php 
    endif;
    return ob_get_clean();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Results Archive - LDSP</title>
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

    <main class="flex-1 min-w-0 overflow-y-auto h-full w-full pt-16 md:pt-0 relative z-10 custom-scrollbar">
        <div class="p-4 md:p-6 max-w-[1500px] mx-auto relative z-20 fade-in-up">

            <!-- Page Header -->
            <header class="mb-5 border-b border-slate-300 pb-3 drop-shadow-sm flex flex-col md:flex-row justify-between md:items-end gap-3">
                <div>
                    <div class="flex items-center gap-3 mb-1">
                        <h1 class="text-2xl md:text-3xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">Exam Results Archive</h1>
                        <?php if (!$can_edit): ?>
                            <span class="px-2 py-0.5 bg-white text-slate-500 border border-slate-300 text-[9px] font-bold rounded uppercase tracking-widest shadow-sm">READ ONLY</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-slate-500 text-xs flex items-center gap-1.5 font-medium mt-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.8)]"></span>
                        Logged in as <?= htmlspecialchars($_SESSION['first_name'] ?? 'Staff') ?>
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
                                Historical Registry
                            </h3>
                            <p class="text-[9px] text-slate-500 font-bold mt-1 uppercase tracking-widest drop-shadow-sm ml-7"><?= $total_results ?> RECORDS FOUND</p>
                        </div>

                        <!-- FILTER CONTROLS -->
                        <div class="flex flex-col sm:flex-row items-center gap-2 w-full md:w-auto">

                            <!-- Search -->
                            <div class="relative w-full sm:w-[220px]">
                                <input type="text" id="liveSearch" onkeyup="window.filterTable()" class="w-full py-1.5 pl-7 pr-3 text-[10px] font-semibold text-[#00205b] bg-white border border-slate-300 rounded-lg shadow-sm focus:outline-none focus:border-[#00205b]" placeholder="Search applicant name or ID...">
                                <svg class="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-[7px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            </div>

                            <!-- Academic Year Filter -->
                            <form method="GET" class="w-full sm:w-auto m-0 p-0">
                                <select name="year" id="filter_year" onchange="this.form.submit();" class="cursor-pointer py-1.5 px-3 text-[11px] font-bold text-[#00205b] bg-white border border-slate-300 rounded-lg shadow-sm focus:outline-none focus:border-[#00205b] w-full sm:w-[150px]">
                                    <option value="All" <?= $filter_year === 'All' ? 'selected' : '' ?>>All Years</option>
                                    <?php foreach ($years_list as $yr): ?>
                                        <option value="<?= htmlspecialchars($yr) ?>" <?= $filter_year === $yr ? 'selected' : '' ?>><?= htmlspecialchars($yr) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                    </div>

                    <div class="overflow-x-auto min-h-[450px] bg-white relative z-20 custom-scrollbar">
                        <table class="w-full text-left border-collapse text-sm table-fixed border-b border-slate-300">
                            <thead class="bg-slate-100/95 backdrop-blur-sm text-[#00205b] text-[9px] uppercase font-black tracking-widest sticky top-0 z-30 shadow-sm border-b-2 border-slate-300">
                                <tr>
                                    <th class="px-3 py-3 border-r border-slate-300 w-[28%]">Student Profile</th>
                                    <th class="px-2 py-3 border-r border-slate-300 w-[15%] text-center">Academic Year</th>
                                    <th class="px-2 py-3 border-r border-slate-300 w-[15%] text-center">Test Score</th>
                                    <th class="px-2 py-3 border-r border-slate-300 w-[15%] text-center">Final Outcome</th>
                                    <th class="px-2 py-3 border-r border-slate-300 text-right w-[15%]">Completion Time</th>
                                    <th class="px-2 py-3 text-center w-[12%]">Action</th>
                                </tr>
                            </thead>
                            <tbody id="results_tbody" class="bg-transparent border-b border-slate-200">
                                <?= renderResultRows($results_query, $can_edit) ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- PROFILE MODAL (ARCHIVE VIEW) -->
    <div id="profileModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 modal-overlay bg-slate-900/70 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-xl shadow-2xl border-t-[4px] border-t-[#00205b] w-full max-w-4xl max-h-[95vh] flex flex-col modal-content relative" id="profileModalInner">
            <div class="bg-slate-50 border-b border-slate-200 p-4 flex justify-between items-center relative z-20 sticky top-0 rounded-t-xl shadow-sm">
                <h3 class="font-black text-[#00205b] text-xs uppercase tracking-[0.1em] drop-shadow-sm flex items-center gap-2"><div class="p-1.5 rounded-sm bg-[#00205b]/10"><svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477-4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg></div> Student Profile Records</h3>
                <button type="button" onclick="window.closeProfileModal()" class="text-slate-400 hover:text-rose-500 transition-colors bg-white hover:bg-rose-50 p-1.5 rounded border border-slate-200 focus:outline-none cursor-pointer"><svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
            </div>

            <div class="p-4 md:p-5 flex-1 overflow-y-auto custom-scrollbar bg-slate-50/90 space-y-4">
                <!-- TOP IDENTITY CARD -->
                <div class="flex flex-col md:flex-row items-start gap-4 border-b border-slate-200 pb-4">
                    <div class="w-20 h-20 bg-[#00205b] text-white rounded-full flex items-center justify-center text-3xl font-black shadow-md border-[3px] border-white overflow-hidden relative shrink-0">
                        <span id="m_initial">?</span>
                        <img id="m_profile_pic" src="" class="hidden absolute inset-0 w-full h-full object-cover z-10" alt="Applicant Photo">
                    </div>
                    <div class="flex-1 w-full">
                        <div class="flex flex-col md:flex-row md:justify-between md:items-start gap-2">
                            <div>
                                <h4 class="text-xl font-black text-[#00205b] tracking-tight uppercase" id="m_applicant_name">Student Name</h4>
                                <div class="flex flex-wrap items-center gap-2 mt-1">
                                    <span class="text-xs font-bold text-[#c5a02c] font-mono tracking-wider" id="m_studentid">ID: Pending</span>
                                    <span class="bg-indigo-100 text-indigo-700 border border-indigo-200 px-1.5 py-0.5 rounded text-[8px] uppercase font-black" id="m_student_type">Status</span>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-2">
                                <div class="bg-white p-2.5 rounded border border-slate-200 text-left md:text-right w-full md:w-auto shadow-sm">
                                    <p class="text-[8px] font-black uppercase tracking-widest text-slate-400">Current Placement</p>
                                    <p class="text-[10px] font-bold text-[#00205b]" id="m_detail_placement">Program / Year / Sec</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm space-y-2">
                        <h5 class="text-[9px] font-black uppercase tracking-widest text-slate-400 border-b border-slate-100 pb-1 mb-2">Personal Details</h5>
                        <div class="grid grid-cols-3 gap-1"><span class="text-[10px] font-bold text-slate-500">Email:</span><span class="col-span-2 text-[10px] font-semibold text-slate-800 break-all" id="m_email"></span></div>
                        <div class="grid grid-cols-3 gap-1"><span class="text-[10px] font-bold text-slate-500">Inst Email:</span><span class="col-span-2 text-[10px] font-semibold text-slate-800 break-all" id="m_inst_email"></span></div>
                        <div class="grid grid-cols-3 gap-1"><span class="text-[10px] font-bold text-slate-500">Phone:</span><span class="col-span-2 text-[10px] font-semibold text-slate-800 font-mono" id="m_phone"></span></div>
                        <div class="grid grid-cols-3 gap-1"><span class="text-[10px] font-bold text-slate-500">Gender:</span><span class="col-span-2 text-[10px] font-semibold text-slate-800" id="m_gender"></span></div>
                        <div class="grid grid-cols-3 gap-1"><span class="text-[10px] font-bold text-slate-500">DOB:</span><span class="col-span-2 text-[10px] font-semibold text-slate-800" id="m_dob"></span></div>
                        <div class="grid grid-cols-3 gap-1"><span class="text-[10px] font-bold text-slate-500 col-span-3">Place of Birth:</span>
                        <span class="font-bold text-slate-800 text-[10px] col-span-3 pb-1 border-b border-slate-50" id="m_pob"></span>
                        <span class="text-slate-500 font-semibold text-[10px] col-span-3 mt-1">Address:</span>
                        <span class="font-bold text-slate-800 text-[10px] col-span-3 leading-relaxed" id="m_address"></span></div>
                    </div>

                    <div class="space-y-4">
                        <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm border-l-[3px] border-l-[#00205b] h-full">
                            <h4 class="text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-1 mb-2">Academic Intent</h4>
                            <div class="grid grid-cols-1 gap-y-2 text-[10px]">
                                <div><span class="text-slate-500 font-semibold block text-[8px] uppercase tracking-wider mb-0.5">Program</span> <span class="font-bold text-slate-800" id="m_program"></span></div>
                                <div class="grid grid-cols-2 gap-2 mt-1">
                                    <div><span class="text-slate-500 font-semibold block text-[8px] uppercase tracking-wider mb-0.5">Type</span> <span class="font-bold text-slate-800" id="m_type"></span></div>
                                    <div><span class="text-slate-500 font-semibold block text-[8px] uppercase tracking-wider mb-0.5">Year Level</span> <span class="font-bold text-slate-800" id="m_year_level"></span></div>
                                </div>
                                <div class="mt-1 border-t border-slate-50 pt-2"><span class="text-slate-500 font-semibold block text-[8px] uppercase tracking-wider mb-0.5">Last School</span> <span class="font-bold text-slate-800" id="m_school"></span></div>
                                <div><span class="text-slate-500 font-semibold block text-[8px] uppercase tracking-wider mb-0.5">S.Y. Attended</span> <span class="font-bold text-slate-800 font-mono" id="m_school_year"></span></div>
                                <div class="mt-1 border-t border-slate-50 pt-2"><span class="text-slate-500 font-semibold block text-[8px] uppercase tracking-wider mb-0.5">Source of Info</span> <span class="font-bold text-slate-800" id="m_influence"></span></div>
                            </div>
                        </div>
                    </div>

                    <div class="md:col-span-2 bg-white p-3 rounded-lg border border-slate-200 shadow-sm">
                        <h4 class="text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-1 mb-2">Family & Emergency Contacts</h4>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <p class="text-[8px] uppercase tracking-widest font-black text-[#00205b] mb-1">Father's Info</p>
                                <div class="text-[10px] font-semibold text-slate-800" id="m_father_name"></div>
                                <div class="text-[9px] text-slate-500" id="m_father_occ"></div>
                                <div class="text-[9px] text-slate-500 font-mono" id="m_father_con"></div>
                            </div>
                            <div>
                                <p class="text-[8px] uppercase tracking-widest font-black text-[#00205b] mb-1">Mother's Info</p>
                                <div class="text-[10px] font-semibold text-slate-800" id="m_mother_name"></div>
                                <div class="text-[9px] text-slate-500" id="m_mother_occ"></div>
                                <div class="text-[9px] text-slate-500 font-mono" id="m_mother_con"></div>
                            </div>
                            <div class="bg-rose-50 p-2 rounded border border-rose-100">
                                <p class="text-[8px] uppercase tracking-widest font-black text-rose-600 mb-1">Emergency Contact</p>
                                <div class="text-[10px] font-bold text-slate-800" id="m_em_name"></div>
                                <div class="text-[10px] font-mono text-rose-700 font-bold mt-0.5" id="m_em_con"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SUBMITTED DOCUMENTS SECTION -->
                <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm mt-2">
                    <h4 class="text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 pb-1 mb-2">Submitted Documents</h4>
                    <div id="m_documents_list" class="flex flex-wrap gap-2">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>

            </div>

            <div class="p-3 border-t border-slate-200 bg-white sticky bottom-0 text-right rounded-b-xl z-20 shadow-[0_-4px_10px_rgba(0,0,0,0.02)]">
                <button type="button" onclick="window.closeProfileModal()" class="bg-white border border-slate-300 hover:bg-slate-50 text-slate-600 font-bold uppercase tracking-widest text-[9px] px-5 py-2 rounded transition-colors cursor-pointer shadow-sm">Close Profile</button>
            </div>
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

    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="exam_results.js?v=<?= time() ?>"></script>

</body>
</html>