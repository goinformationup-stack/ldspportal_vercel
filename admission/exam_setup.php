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
date_default_timezone_set('Asia/Manila');

$allowed_roles = ['admin', 'admission', 'registrar', 'accounting'];

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../index.php");
    exit();
}

$can_edit = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'admission');
$success_msg = isset($_GET['success']) ? htmlspecialchars($_GET['success']) : "";
$error_msg = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : "";

function formatSeconds($seconds) {
    $seconds = (int)$seconds;
    if ($seconds <= 0) return "0 secs";
    $hours = floor($seconds / 3600);
    $mins = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    
    $parts = [];
    if ($hours > 0) $parts[] = $hours . ($hours == 1 ? " hr" : " hrs");
    if ($mins > 0) $parts[] = $mins . ($mins == 1 ? " min" : " mins");
    if ($secs > 0) $parts[] = $secs . ($secs == 1 ? " sec" : " secs");
    
    return implode(" ", $parts);
}

// Auto-migration: ensure duration columns support exact timing
try {
    $conn->query("ALTER TABLE entrance_exams MODIFY COLUMN duration_minutes DECIMAL(10,2) DEFAULT 0.00");
} catch(Exception $e) {}

// ==========================================
// 1. DELETE EXAM LOGIC
// ==========================================
if ($can_edit && isset($_POST['delete_exam'])) {
    $exam_id = (int)$_POST['delete_exam_id'];
    if ($conn->query("DELETE FROM entrance_exams WHERE id = $exam_id")) {
        $success_msg = "Examination Module successfully deleted.";
    } else {
        $error_msg = "Failed to delete examination module.";
    }
}

// ==========================================
// 2. UPDATE META LOGIC
// ==========================================
if ($can_edit && isset($_POST['update_exam_meta'])) {
    $edit_id = (int)$_POST['edit_exam_id'];
    $title = mysqli_real_escape_string($conn, $_POST['exam_title']);
    $activation = date('Y-m-d H:i:s', strtotime($_POST['activation_time']));
    $deadline = date('Y-m-d H:i:s', strtotime($_POST['deadline_time']));
    $passing = intval($_POST['passing_score']);

    if (strtotime($deadline) <= strtotime($activation)) {
        if (isset($_POST['ajax_request'])) { header('Content-Type: application/json'); echo json_encode(['status' => 'error', 'message' => 'Close Time must be AFTER Open Time.']); exit; }
        $error_msg = "Close Time must be AFTER Open Time.";
    } else {
        $stmt = $conn->prepare("UPDATE entrance_exams SET exam_title=?, activation_time=?, deadline_time=?, passing_score=? WHERE id=?");
        $stmt->bind_param("sssii", $title, $activation, $deadline, $passing, $edit_id);
        
        if ($stmt->execute()) {
            $conn->query("UPDATE exam_results SET status = IF(score >= $passing, 'Passed', 'Failed') WHERE exam_id = $edit_id");
            if (isset($_POST['ajax_request'])) { header('Content-Type: application/json'); echo json_encode(['status' => 'success', 'message' => 'Exam updated and past results recalculated.']); exit; }
            $success_msg = "Exam updated successfully.";
        } else {
            if (isset($_POST['ajax_request'])) { header('Content-Type: application/json'); echo json_encode(['status' => 'error', 'message' => 'Database error.']); exit; }
            $error_msg = "Failed to update exam.";
        }
    }
}

// ==========================================
// 3. CREATE NEW EXAM LOGIC (Moved from Builder)
// ==========================================
if ($can_edit && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_exam'])) {
    
    $title = mysqli_real_escape_string($conn, $_POST['new_exam_title']);
    $activation = date('Y-m-d H:i:s', strtotime($_POST['new_activation_time']));
    $deadline = date('Y-m-d H:i:s', strtotime($_POST['new_deadline_time']));
    $passing = intval($_POST['new_passing_score']);

    // Exact Seconds Accumulator
    $total_seconds = 0;
    if (isset($_POST['q']) && is_array($_POST['q'])) {
        foreach ($_POST['q'] as $q) {
            if (!empty(trim($q['text'] ?? ''))) {
                // Convert MM:SS to Seconds
                $m = isset($q['time_m']) ? (int)$q['time_m'] : 0;
                $s = isset($q['time_s']) ? (int)$q['time_s'] : 0;
                $total_seconds += ($m * 60) + $s; 
            }
        }
    }
    
    $exact_minutes = round($total_seconds / 60, 2);

    if (strtotime($deadline) <= strtotime($activation)) {
        $error_msg = "Timeline Error: Close Time must be AFTER Open Time.";
    } elseif ($total_seconds <= 0) {
        $error_msg = "Validation Error: You must create at least one question with a timer greater than 0.";
    } else {
        $stmt = $conn->prepare("INSERT INTO entrance_exams (exam_title, activation_time, deadline_time, duration_minutes, passing_score) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssdi", $title, $activation, $deadline, $exact_minutes, $passing);
        
        if ($stmt->execute()) {
            $new_exam_id = $conn->insert_id; 
            
            if (isset($_POST['q']) && is_array($_POST['q'])) {
                $questions = $_POST['q'];
                $upload_dir = "../uploads/exams/";
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

                $q_stmt = $conn->prepare("INSERT INTO exam_questions (exam_id, question_text, question_image, option_a, image_a, option_b, image_b, option_c, image_c, option_d, image_d, correct_option, sort_order, time_limit_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $sort_order = 1;
                foreach ($questions as $index => $q) {
                    if (empty(trim($q['text'] ?? ''))) continue;

                    $paths = ['q_img' => '', 'img_a' => '', 'img_b' => '', 'img_c' => '', 'img_d' => ''];
                    
                    foreach ($paths as $key => $val) {
                        if (isset($_FILES['q_files']['name'][$index][$key]) && $_FILES['q_files']['error'][$index][$key] == 0) {
                            $ext = pathinfo($_FILES['q_files']['name'][$index][$key], PATHINFO_EXTENSION);
                            $new_name = "exam_" . $new_exam_id . "_q" . $index . "_" . $key . "_" . time() . "." . $ext;
                            move_uploaded_file($_FILES['q_files']['tmp_name'][$index][$key], $upload_dir . $new_name);
                            $paths[$key] = "uploads/exams/" . $new_name;
                        }
                    }

                    $m = isset($q['time_m']) ? (int)$q['time_m'] : 0;
                    $s = isset($q['time_s']) ? (int)$q['time_s'] : 0;
                    $time_limit = ($m * 60) + $s;
                    if ($time_limit == 0) $time_limit = 60; // Failsafe

                    $q_stmt->bind_param("isssssssssssii", 
                        $new_exam_id, $q['text'], $paths['q_img'], 
                        $q['a'], $paths['img_a'], 
                        $q['b'], $paths['img_b'], 
                        $q['c'], $paths['img_c'], 
                        $q['d'], $paths['img_d'], 
                        $q['correct'], $sort_order, $time_limit
                    );
                    $q_stmt->execute();
                    $sort_order++;
                }
            }
            $success_msg = "Exam successfully deployed! Exact duration locked at " . $total_seconds . " seconds.";
        } else {
            $error_msg = "Database Error: Could not compile the exam module.";
        }
    }
}

// Fetch Active Exams with precise seconds sum
$exams = [];
$exam_q = $conn->query("
    SELECT e.*, 
           COALESCE(SUM(q.time_limit_seconds), 0) as total_seconds,
           COUNT(q.id) as question_count
    FROM entrance_exams e
    LEFT JOIN exam_questions q ON e.id = q.exam_id
    GROUP BY e.id
    ORDER BY e.id DESC
");
if ($exam_q) {
    while ($row = $exam_q->fetch_assoc()) {
        $exams[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Setup - LDSP Command Center</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css?v=<?= time(); ?>">
    <style>
        .modal-overlay { opacity: 0; transition: opacity 0.2s ease; pointer-events: none; }
        .modal-content { transform: scale(0.95) translateY(10px); opacity: 0; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); pointer-events: none; }
        .modal-active.modal-overlay { opacity: 1; pointer-events: auto; }
        .modal-active .modal-content { transform: scale(1) translateY(0); opacity: 1; pointer-events: auto; }
        
        /* Custom Radio Buttons for Builder */
        .custom-radio input[type="radio"]:checked + div {
            background-color: #00205b;
            color: white;
            border-color: #00205b;
            box-shadow: 0 4px 10px rgba(0, 32, 91, 0.3);
            transform: translateY(-2px);
        }
        
        /* Hide number arrows except on hover */
        input[type="number"]::-webkit-outer-spin-button, input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        input[type="number"]:hover::-webkit-inner-spin-button { -webkit-appearance: auto; }
        input[type="number"] { -moz-appearance: textfield; }
    </style>
</head>
<body class="flex h-screen overflow-hidden antialiased bg-[#f4f6f9]">

    <div class="ambient-orb-1"></div>
    <div class="ambient-orb-2"></div>

    <div class="md:hidden fixed w-full top-0 left-0 bg-[#001233] z-30 px-6 py-4 flex justify-between items-center shadow-lg border-b border-white/10">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-white flex items-center justify-center p-0.5">
                <img src="../logo.jpg" onerror="this.src='logo.jpg'" alt="LDSP Logo" class="w-full h-full object-contain rounded-full">
            </div>
            <h2 class="text-lg font-bold text-white font-academic uppercase tracking-widest">LDSP</h2>
        </div>
        <button id="mobileMenuBtn" class="text-white focus:outline-none"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg></button>
    </div>

    <div id="sidebarOverlay" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-40 hidden md:hidden transition-opacity"></div>

    <?php include 'sidebar.php'; ?>

    <main class="flex-1 min-w-0 overflow-y-auto h-full w-full pt-20 md:pt-0 relative z-10 custom-scrollbar">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1400px] mx-auto relative z-20 fade-in-up">
            
            <header class="mb-6 border-b border-slate-300/60 pb-4 drop-shadow-sm">
                <div class="flex items-center gap-4 mb-1">
                    <h1 class="text-2xl md:text-3xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">Exam Management</h1>
                    <?php if (!$can_edit): ?>
                        <span class="px-2.5 py-1 bg-white/80 backdrop-blur-sm text-slate-500 border border-slate-300 text-[10px] font-bold rounded-lg uppercase tracking-widest shadow-sm">READ ONLY</span>
                    <?php endif; ?>
                </div>
                <p class="text-slate-500 text-xs flex items-center gap-2 font-medium">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.8)]"></span>
                    Logged in as <?= htmlspecialchars($_SESSION['first_name'] ?? 'User') ?>
                </p>
            </header>

            <!-- ==========================================
                 DASHBOARD VIEW (List of Exams)
                 ========================================== -->
            <div id="dashboard_view" class="block">
                <div class="mb-8">
                    <div class="bg-gradient-to-br from-white to-slate-50 border border-slate-200 rounded-xl shadow-[0_10px_30px_rgba(0,32,91,0.05)] p-6 md:p-8 w-full z-20 relative overflow-hidden border-t-4 border-t-[#c5a02c]">
                        <div class="absolute right-0 top-0 w-64 h-64 bg-[#c5a02c] opacity-[0.03] rounded-bl-full -z-10 pointer-events-none"></div>
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                            <div>
                                <h3 class="text-xl font-black text-[#00205b] mb-2 font-academic uppercase tracking-tight drop-shadow-sm flex items-center gap-2">
                                    <div class="p-1.5 rounded-md bg-amber-50 shadow-sm border border-amber-100">
                                        <svg class="w-5 h-5 text-[#c5a02c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
                                    </div>
                                    Entrance Exam Builder Engine
                                </h3>
                                <p class="text-slate-500 text-sm leading-relaxed font-medium max-w-2xl">Construct universal examination modules with exact per-question timers. Passing thresholds and schedules are automatically managed.</p>
                            </div>
                            <div class="w-full md:w-auto shrink-0">
                                <?php if ($can_edit): ?>
                                    <button type="button" onclick="toggleView('builder')" class="w-full md:w-auto bg-gradient-to-r from-[#dfb944] to-[#c5a02c] text-white px-8 py-3.5 rounded-xl font-black uppercase tracking-widest shadow-[0_8px_20px_-6px_rgba(197,160,44,0.6)] hover:shadow-[0_12px_25px_-8px_rgba(197,160,44,0.8)] hover:-translate-y-0.5 transition-all border border-[#997a1d] text-xs flex items-center justify-center gap-2 cursor-pointer">
                                        Open Builder Studio 
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                                    </button>
                                <?php else: ?>
                                    <span class="bg-slate-100 text-slate-400 border border-slate-300 px-8 py-3.5 rounded-xl font-black uppercase tracking-widest shadow-sm inline-block text-xs cursor-not-allowed w-full text-center border-dashed">Access Restricted</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Table Board: Deployed Examinations -->
                <div class="bg-white rounded-xl shadow-xl overflow-hidden my-8 border-t-4 border-t-[#00205b] z-20">
                    <div class="p-5 bg-white/80 border-b border-slate-200/60 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 relative z-20">
                        <div>
                            <h3 class="font-black text-[#00205b] text-sm uppercase tracking-[0.1em] drop-shadow-sm flex items-center gap-2">
                                <svg class="w-4 h-4 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                                Deployed Examination Modules
                            </h3>
                            <p class="text-[10px] text-slate-500 font-bold tracking-widest uppercase mt-0.5">Manage active and closed test configurations</p>
                        </div>
                    </div>
                    <div class="overflow-x-auto overflow-y-auto min-h-[400px] max-h-[620px] bg-slate-50/50 relative z-20 custom-scrollbar p-2">
                        <table class="w-full text-left border-collapse text-sm bg-white rounded-lg shadow-sm border border-slate-200">
                            <thead class="bg-slate-100/90 backdrop-blur-md text-slate-600 uppercase text-[9px] font-black tracking-widest border-b border-slate-300 sticky top-0 z-30 shadow-sm">
                                <tr>
                                    <th class="px-5 py-4 rounded-tl-lg">Exam Identity</th>
                                    <th class="px-5 py-4">Configuration Schedule</th>
                                    <th class="px-5 py-4 text-center">Module Status</th>
                                    <th class="px-5 py-4 text-right rounded-tr-lg">Administrative Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (empty($exams)): ?>
                                    <tr><td colspan="4" class="px-6 py-16 text-center text-slate-400 text-xs font-bold uppercase tracking-[0.2em] bg-white/50">No examinations configured yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($exams as $exam): 
                                        $current_time = date('Y-m-d H:i:s');
                                        if ($current_time < $exam['activation_time']) {
                                            $status = 'Upcoming';
                                            $badge_class = 'bg-amber-50 text-amber-700 border-amber-200 shadow-sm';
                                        } elseif ($current_time >= $exam['activation_time'] && $current_time <= $exam['deadline_time']) {
                                            $status = 'Live Now';
                                            $badge_class = 'bg-emerald-50 text-emerald-700 border-emerald-200 shadow-sm';
                                        } else {
                                            $status = 'Closed';
                                            $badge_class = 'bg-slate-100 text-slate-500 border-slate-200 shadow-inner';
                                        }
                                        $exact_duration_str = formatSeconds($exam['total_seconds']);
                                    ?>
                                        <tr class="hover:bg-slate-50 transition-colors duration-150 group">
                                            <td class="px-5 py-5 align-top">
                                                <div class="text-[13px] font-black text-[#00205b] drop-shadow-sm mb-1.5 uppercase tracking-wide"><?= htmlspecialchars($exam['exam_title']) ?></div>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="text-[9px] bg-white border border-slate-200 px-2 py-0.5 rounded shadow-sm text-slate-600 font-black uppercase tracking-widest flex items-center gap-1"><svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg> <?= $exam['question_count'] ?> Qs</span>
                                                    <span class="text-[9px] bg-white border border-slate-200 px-2 py-0.5 rounded shadow-sm text-[#00205b] font-black uppercase tracking-widest flex items-center gap-1 font-mono"><svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> <?= $exact_duration_str ?></span>
                                                    <span class="text-[9px] bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded shadow-sm text-emerald-700 font-black uppercase tracking-widest">Pass: <?= $exam['passing_score'] ?></span>
                                                </div>
                                            </td>
                                            <td class="px-5 py-5 align-top">
                                                <div class="text-[10px] text-slate-700 font-semibold mb-2 bg-slate-50 border border-slate-100 p-1.5 rounded inline-block w-full max-w-[200px]">
                                                    <span class="font-black text-slate-400 uppercase tracking-widest text-[8px] flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Opens</span>
                                                    <?= date('M j, Y g:i A', strtotime($exam['activation_time'])) ?>
                                                </div>
                                                <div class="text-[10px] text-slate-700 font-semibold bg-slate-50 border border-slate-100 p-1.5 rounded inline-block w-full max-w-[200px]">
                                                    <span class="font-black text-slate-400 uppercase tracking-widest text-[8px] flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> Closes</span>
                                                    <?= date('M j, Y g:i A', strtotime($exam['deadline_time'])) ?>
                                                </div>
                                            </td>
                                            <td class="px-5 py-5 align-middle text-center">
                                                <span class="px-3 py-1.5 rounded-lg text-[9px] uppercase tracking-[0.15em] font-black border whitespace-nowrap <?= $badge_class ?>"><?= $status ?></span>
                                            </td>
                                            <td class="px-5 py-5 align-middle text-right relative z-30">
                                                <div class="flex flex-col gap-2 w-full max-w-[160px] ml-auto opacity-90 group-hover:opacity-100 transition-opacity">
                                                    <a href="manage_questions.php?exam_id=<?= $exam['id'] ?>" class="bg-white hover:bg-slate-50 text-[#00205b] border border-slate-300 text-[9px] font-bold uppercase tracking-widest py-1.5 px-3 h-[32px] w-full rounded-lg shadow-sm transition-colors cursor-pointer flex items-center justify-center gap-1.5">
                                                        Manage Questions
                                                        <svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                                                    </a>
                                                    <?php if ($can_edit): ?>
                                                    <div class="grid grid-cols-2 gap-2">
                                                        <button type="button" onclick="window.openEditModal(<?= $exam['id'] ?>, '<?= htmlspecialchars($exam['exam_title'], ENT_QUOTES) ?>', '<?= date('Y-m-d\TH:i', strtotime($exam['activation_time'])) ?>', '<?= date('Y-m-d\TH:i', strtotime($exam['deadline_time'])) ?>', <?= $exam['passing_score'] ?>)" class="bg-amber-50 text-amber-700 border border-amber-200 hover:bg-amber-100 hover:text-amber-800 h-[32px] text-[9px] font-bold uppercase tracking-widest flex items-center justify-center rounded-lg transition-colors cursor-pointer shadow-sm w-full">Edit</button>
                                                        <form method="POST" class="m-0 p-0" onsubmit="return confirm('WARNING: Are you sure you want to delete this Exam Module? This will delete all associated questions.');">
                                                            <input type="hidden" name="delete_exam_id" value="<?= $exam['id'] ?>">
                                                            <button type="submit" name="delete_exam" class="bg-white text-rose-600 border border-rose-200 hover:bg-rose-500 hover:text-white h-[32px] text-[9px] font-bold uppercase tracking-widest flex items-center justify-center rounded-lg transition-colors cursor-pointer shadow-sm w-full">Drop</button>
                                                        </form>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ==========================================
                 BUILDER VIEW (Form to create new exam)
                 ========================================== -->
            <div id="builder_view" class="hidden">
                <div class="flex justify-between items-center mb-6">
                    <button type="button" onclick="toggleView('dashboard')" class="text-slate-500 hover:text-[#00205b] text-[9px] font-black uppercase tracking-widest flex items-center gap-1.5 transition-colors bg-white/60 backdrop-blur-sm px-3 py-2 rounded-lg border border-slate-200 shadow-sm hover:shadow-md cursor-pointer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M15 19l-7-7 7-7" /></svg> Back to Dashboard
                    </button>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <div class="bg-white/90 backdrop-blur-md border border-slate-200 rounded-xl shadow-lg p-5 md:p-6 mb-8 relative overflow-hidden border-t-4 border-t-[#00205b]">
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-5 border-b border-slate-200/60 pb-4">
                            <h3 class="text-[11px] font-black text-[#00205b] uppercase tracking-widest flex items-center gap-2">
                                <div class="p-1.5 rounded bg-[#00205b]/10"><svg class="w-4 h-4 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" /></svg></div>
                                Phase 1: Security & Timeline
                            </h3>
                            <span class="bg-blue-50 border border-blue-200 text-blue-700 px-3 py-1.5 rounded text-[8px] font-black uppercase tracking-widest flex items-center gap-1.5 shadow-sm w-max">
                                <svg class="w-3.5 h-3.5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2-2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                Anti-Cheat Active
                            </span>
                        </div>
                        
                        <div class="mb-5">
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5 drop-shadow-sm">Exam Title</label>
                            <input type="text" name="new_exam_title" required placeholder="e.g. 2026 Universal Freshman Entry Test" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-4 py-2.5 text-sm font-bold text-[#00205b] shadow-inner focus:outline-none focus:border-[#00205b] focus:bg-white transition-colors">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                            <div class="bg-emerald-50/50 p-3 rounded-xl border border-emerald-100">
                                <label class="block text-[9px] font-black text-emerald-700 uppercase tracking-widest mb-1.5 flex items-center gap-1.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" /></svg> Open Exam At</label>
                                <input type="datetime-local" name="new_activation_time" required class="w-full bg-white border border-emerald-300 rounded-lg px-3 py-2 text-xs font-bold text-emerald-900 shadow-sm focus:outline-none focus:border-emerald-600 cursor-pointer">
                            </div>
                            <div class="bg-rose-50/50 p-3 rounded-xl border border-rose-100">
                                <label class="block text-[9px] font-black text-rose-700 uppercase tracking-widest mb-1.5 flex items-center gap-1.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2-2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg> Close Exam At</label>
                                <input type="datetime-local" name="new_deadline_time" required class="w-full bg-white border border-rose-300 rounded-lg px-3 py-2 text-xs font-bold text-rose-900 shadow-sm focus:outline-none focus:border-rose-600 cursor-pointer">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 pt-5 border-t border-slate-200/60">
                            <div class="bg-slate-50 p-3 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between gap-3">
                                <div>
                                    <label class="block text-[10px] font-black text-slate-600 uppercase tracking-widest mb-0.5">Calculated Duration</label>
                                    <p class="text-[8px] text-slate-400 font-bold uppercase tracking-widest">Exact sum of all question timers</p>
                                </div>
                                <span id="live_total_duration" class="bg-gradient-to-r from-[#00205b] to-[#003882] text-white font-mono font-black text-xs px-3.5 py-1.5 rounded-lg shadow-sm border border-[#001233]">
                                    0 secs
                                </span>
                            </div>
                            <div class="bg-emerald-50/50 p-3 rounded-xl border border-emerald-200 shadow-sm flex items-center justify-between gap-3">
                                <div>
                                    <label class="block text-[10px] font-black text-emerald-800 uppercase tracking-widest mb-0.5">Passing Score</label>
                                    <p class="text-[8px] text-emerald-600/70 font-bold uppercase tracking-widest">Min. points to pass</p>
                                </div>
                                <input type="number" name="new_passing_score" required min="1" value="50" class="w-24 bg-white border border-emerald-300 rounded-lg px-2 py-2 text-center font-mono font-black text-sm text-emerald-700 shadow-inner focus:outline-none focus:border-emerald-600">
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 mb-4">
                        <div class="h-px bg-slate-300 flex-1"></div>
                        <h2 class="text-[#c5a02c] font-black uppercase tracking-[0.2em] text-[11px] drop-shadow-sm flex items-center gap-1.5"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg> Phase 2: Question Canvas</h2>
                        <div class="h-px bg-slate-300 flex-1"></div>
                    </div>

                    <div id="questions-container" class="space-y-6"></div>

                    <div class="mt-8 flex flex-col sm:flex-row gap-4 mb-12">
                        <button type="button" onclick="window.addNewQuestion()" class="bg-white hover:bg-slate-50 text-[#00205b] border-2 border-dashed border-[#00205b]/30 hover:border-[#00205b] px-6 py-4 rounded-xl font-black uppercase tracking-widest shadow-sm transition-colors flex-1 flex items-center justify-center gap-2 text-[11px]">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4"/></svg>
                            Add New Question
                        </button>
                        <button type="submit" name="save_exam" class="bg-gradient-to-r from-[#dfb944] to-[#c5a02c] text-white px-6 py-4 rounded-xl font-black uppercase tracking-widest shadow-[0_8px_20px_-6px_rgba(197,160,44,0.6)] hover:shadow-[0_12px_25px_-8px_rgba(197,160,44,0.8)] hover:-translate-y-0.5 transition-all border border-[#997a1d] flex-1 flex items-center justify-center gap-2 text-[11px] cursor-pointer">
                            Compile & Deploy Exam
                            <svg class="w-4 h-4 opacity-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" /></svg>
                        </button>
                    </div>
                </form>
            </div>
            
        </div>
    </main>

    <!-- Edit Exam Meta Settings Modal (Dashboard View) -->
    <div id="editExamModal" class="fixed inset-0 z-[100] hidden flex-col items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-opacity modal-overlay">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md border-t-4 border-t-[#c5a02c] modal-content flex flex-col max-h-[90vh]">
            <div class="p-4 border-b border-slate-200 flex justify-between items-center bg-slate-50/50 rounded-t-xl shrink-0">
                <h3 class="font-black text-[#00205b] uppercase tracking-widest text-[11px] flex items-center gap-2 drop-shadow-sm">
                    <div class="p-1 rounded bg-[#00205b]/10"><svg class="w-4 h-4 text-[#c5a02c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg></div>
                    Edit Meta Configuration
                </h3>
                <button type="button" onclick="window.closeModal('editExamModal')" class="text-slate-400 hover:text-rose-500 p-1.5 bg-white hover:bg-rose-50 border border-slate-200 rounded-lg transition-colors cursor-pointer shadow-sm"><svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
            </div>
            <form class="ajax-form p-5 bg-white space-y-4 rounded-b-xl overflow-y-auto custom-scrollbar flex-1">
                <input type="hidden" name="edit_exam_id" id="edit_exam_id">
                <input type="hidden" name="update_exam_meta" value="1">
                
                <div>
                    <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1.5">Exam Title</label>
                    <input type="text" name="exam_title" id="edit_exam_title" required class="w-full bg-slate-50 border border-slate-300 rounded-lg px-4 py-2.5 text-xs font-bold text-[#00205b] focus:outline-none focus:border-[#c5a02c] focus:bg-white shadow-inner transition-colors">
                </div>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[9px] font-bold text-emerald-600 uppercase tracking-widest mb-1.5">Open Exam At</label>
                        <input type="datetime-local" name="activation_time" id="edit_activation_time" required class="w-full bg-emerald-50 border border-emerald-200 rounded-lg px-3 py-2.5 text-[10px] font-bold text-emerald-800 focus:outline-none focus:border-emerald-500 shadow-inner cursor-pointer">
                    </div>
                    <div>
                        <label class="block text-[9px] font-bold text-rose-600 uppercase tracking-widest mb-1.5">Close Exam At</label>
                        <input type="datetime-local" name="deadline_time" id="edit_deadline_time" required class="w-full bg-rose-50 border border-rose-200 rounded-lg px-3 py-2.5 text-[10px] font-bold text-rose-800 focus:outline-none focus:border-rose-500 shadow-inner cursor-pointer">
                    </div>
                </div>

                <div class="pt-2">
                    <div class="bg-emerald-50 p-3 border border-emerald-200 rounded-lg shadow-sm">
                        <label class="block text-[9px] font-bold text-emerald-700 uppercase tracking-widest mb-1.5 text-center">Passing Score</label>
                        <input type="number" name="passing_score" id="edit_passing_score" required min="1" class="w-full bg-white border border-emerald-300 rounded-lg px-2 py-2 text-sm font-mono font-black text-center text-emerald-700 focus:outline-none focus:border-emerald-600 shadow-inner transition-colors">
                    </div>
                </div>

                <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-4 border-t border-slate-100 mt-4">
                    <button type="button" onclick="window.closeModal('editExamModal')" class="bg-white hover:bg-slate-50 text-slate-600 border border-slate-300 px-6 py-2.5 rounded-lg font-bold uppercase tracking-widest shadow-sm transition-colors text-[9px] cursor-pointer w-full sm:w-auto text-center">Cancel</button>
                    <button type="submit" class="bg-gradient-to-r from-[#dfb944] to-[#c5a02c] text-white px-6 py-2.5 rounded-lg font-bold uppercase tracking-widest shadow-[0_4px_10px_rgba(197,160,44,0.4)] hover:shadow-[0_8px_15px_rgba(197,160,44,0.5)] hover:-translate-y-0.5 transition-all border border-[#997a1d] text-[9px] cursor-pointer w-full sm:w-auto text-center">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- TOAST NOTIFICATION CONTAINER -->
    <div id="toastContainer" class="fixed top-5 right-5 z-[100] flex flex-col gap-2 pointer-events-none"></div>

    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="exam_setup.js?v=<?= time() ?>"></script>
    
    <script>
        document.getElementById('mobileMenuBtn')?.addEventListener('click', () => {
            document.getElementById('sidebar')?.classList.toggle('-translate-x-full');
            document.getElementById('sidebarOverlay')?.classList.toggle('hidden');
        });
        document.getElementById('sidebarOverlay')?.addEventListener('click', () => {
            document.getElementById('sidebar')?.classList.add('-translate-x-full');
            document.getElementById('sidebarOverlay')?.classList.add('hidden');
        });

        // Initialize empty questions when loading builder
        document.addEventListener("DOMContentLoaded", () => {
            const container = document.getElementById('questions-container');
            if (container && container.children.length === 0) {
                window.addNewQuestion();
            }
        });

        document.addEventListener('DOMContentLoaded', () => {
            const successMsg = "<?= addslashes($success_msg) ?>";
            const errorMsg = "<?= addslashes($error_msg) ?>";
            if (successMsg) window.showToast(successMsg, 'success');
            if (errorMsg) window.showToast(errorMsg, 'error');
            
            // Intercept modal form submissions
            document.querySelectorAll('.ajax-form').forEach(form => {
                if(!form.hasAttribute('onsubmit') && !form.querySelector('button[name="save_exam"]')) {
                    form.addEventListener('submit', async function(e) {
                        e.preventDefault();
                        const submitBtn = this.querySelector('button[type="submit"]');
                        const origBtnText = submitBtn.innerHTML;
                        submitBtn.innerHTML = 'Saving...';
                        submitBtn.disabled = true;

                        const formData = new FormData(this);
                        formData.append('ajax_request', '1');
                        try {
                            const res = await fetch(window.location.href, { method: 'POST', body: formData });
                            const data = await res.json();
                            if (data.status === 'success') {
                                window.showToast(data.message, 'success');
                                setTimeout(() => window.location.reload(), 1500);
                            } else {
                                window.showToast(data.message, 'error');
                                submitBtn.innerHTML = origBtnText;
                                submitBtn.disabled = false;
                            }
                        } catch (err) {
                            window.showToast('Failed to connect to server.', 'error');
                            submitBtn.innerHTML = origBtnText;
                            submitBtn.disabled = false;
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>