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

// 2. FIX: PROPER SESSION HANDLER TO RESTORE SIDEBAR ROLE CONTEXT
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

if (!isset($_GET['exam_id'])) {
    die("No Exam ID provided in the URL.");
}

$exam_id = (int)$_GET['exam_id'];
date_default_timezone_set('Asia/Manila');

function ajaxResponse($status, $msg) {
    if (isset($_POST['ajax_request'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => $status, 'message' => $msg]);
        exit;
    }
}

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

// Synchronize exact duration
function syncExamDuration($conn, $exam_id) {
    $res = $conn->query("SELECT SUM(time_limit_seconds) as total_sec FROM exam_questions WHERE exam_id = $exam_id");
    $sec = (int)($res->fetch_assoc()['total_sec'] ?? 0);
    $min = round($sec / 60, 2);
    $conn->query("UPDATE entrance_exams SET duration_minutes = $min WHERE id = $exam_id");
}

// UPDATE INDIVIDUAL QUESTION TEXT & OPTIONS
if (isset($_POST['edit_question'])) {
    $q_id = (int)$_POST['question_id'];
    $q_text = $_POST['q_text'];
    $opt_a = $_POST['opt_A'];
    $opt_b = $_POST['opt_B'];
    $opt_c = $_POST['opt_C'];
    $opt_d = $_POST['opt_D'];
    $correct = $_POST['correct_option'];
    
    // Convert MM:SS to Seconds for the DB
    $m = isset($_POST['time_m']) ? (int)$_POST['time_m'] : 0;
    $s = isset($_POST['time_s']) ? (int)$_POST['time_s'] : 0;
    $time_limit = ($m * 60) + $s;
    if ($time_limit <= 0) $time_limit = 60; // Failsafe

    $upload_dir = "../uploads/exams/";
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    
    $update_sql = "UPDATE exam_questions SET question_text=?, option_a=?, option_b=?, option_c=?, option_d=?, correct_option=?, time_limit_seconds=?";
    $params = [$q_text, $opt_a, $opt_b, $opt_c, $opt_d, $correct, $time_limit];
    $types = "ssssssi";

    $file_inputs = ['q_img' => 'question_image', 'img_A' => 'image_a', 'img_B' => 'image_b', 'img_C' => 'image_c', 'img_D' => 'image_d'];
    foreach ($file_inputs as $input_name => $db_key) {
        if (isset($_FILES[$input_name]) && $_FILES[$input_name]['error'] == 0) {
            $ext = pathinfo($_FILES[$input_name]['name'], PATHINFO_EXTENSION);
            $new_name = "exam_" . $exam_id . "_q" . $q_id . "_" . $db_key . "_" . time() . "." . $ext;
            move_uploaded_file($_FILES[$input_name]['tmp_name'], $upload_dir . $new_name);
            $update_sql .= ", $db_key=?";
            $params[] = "uploads/exams/" . $new_name;
            $types .= "s";
        }
    }

    $update_sql .= " WHERE id=?";
    $params[] = $q_id;
    $types .= "i";

    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param($types, ...$params);
    if($stmt->execute()) {
        syncExamDuration($conn, $exam_id);
        ajaxResponse('success', 'Question updated and duration recalculated.');
    } else {
        ajaxResponse('error', 'Failed to update question.');
    }
}

// UPDATE EXAM METADATA
if (isset($_POST['update_exam_meta'])) {
    $title = mysqli_real_escape_string($conn, $_POST['exam_title']);
    $activation = date('Y-m-d H:i:s', strtotime($_POST['activation_time']));
    $deadline = date('Y-m-d H:i:s', strtotime($_POST['deadline_time']));
    $passing = intval($_POST['passing_score']);

    if (strtotime($deadline) <= strtotime($activation)) {
        ajaxResponse('error', 'Error: Close Time must be AFTER Open Time.');
    } else {
        $stmt = $conn->prepare("UPDATE entrance_exams SET exam_title=?, activation_time=?, deadline_time=?, passing_score=? WHERE id=?");
        $stmt->bind_param("sssii", $title, $activation, $deadline, $passing, $exam_id);
        
        if ($stmt->execute()) {
            $conn->query("UPDATE exam_results SET status = IF(score >= $passing, 'Passed', 'Failed') WHERE exam_id = $exam_id");
            ajaxResponse('success', 'Exam parameters updated successfully.');
        } else {
            ajaxResponse('error', 'Failed to update exam parameters.');
        }
    }
}

// DELETE A QUESTION
if (isset($_POST['delete_question'])) {
    $q_id = (int)$_POST['question_id'];
    $conn->query("DELETE FROM exam_questions WHERE id = $q_id");
    syncExamDuration($conn, $exam_id);
    ajaxResponse('success', 'Question removed and duration recalculated.');
}

// ADD A SINGLE NEW QUESTION
if (isset($_POST['add_single_question'])) {
    $q_text = $_POST['new_q_text'];
    $opt_a = $_POST['new_opt_a'];
    $opt_b = $_POST['new_opt_b'];
    $opt_c = $_POST['new_opt_c'];
    $opt_d = $_POST['new_opt_d'];
    $correct = $_POST['new_correct'];
    
    // Convert MM:SS to Seconds
    $m = isset($_POST['new_time_m']) ? (int)$_POST['new_time_m'] : 0;
    $s = isset($_POST['new_time_s']) ? (int)$_POST['new_time_s'] : 0;
    $time_limit = ($m * 60) + $s;
    if ($time_limit <= 0) $time_limit = 60; // Failsafe

    $max_q = $conn->query("SELECT MAX(sort_order) as m FROM exam_questions WHERE exam_id = $exam_id")->fetch_assoc();
    $sort_order = ($max_q['m'] ?? 0) + 1;

    $upload_dir = "../uploads/exams/";
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    
    $paths = ['q_img' => '', 'img_a' => '', 'img_b' => '', 'img_c' => '', 'img_d' => ''];
    $file_inputs = ['new_q_img' => 'q_img', 'new_img_a' => 'img_a', 'new_img_b' => 'img_b', 'new_img_c' => 'img_c', 'new_img_d' => 'img_d'];

    foreach ($file_inputs as $input_name => $db_key) {
        if (isset($_FILES[$input_name]) && $_FILES[$input_name]['error'] == 0) {
            $ext = pathinfo($_FILES[$input_name]['name'], PATHINFO_EXTENSION);
            $new_name = "exam_" . $exam_id . "_add_" . $db_key . "_" . time() . "." . $ext;
            move_uploaded_file($_FILES[$input_name]['tmp_name'], $upload_dir . $new_name);
            $paths[$db_key] = "uploads/exams/" . $new_name;
        }
    }

    $q_stmt = $conn->prepare("INSERT INTO exam_questions (exam_id, question_text, question_image, option_a, image_a, option_b, image_b, option_c, image_c, option_d, image_d, correct_option, sort_order, time_limit_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $q_stmt->bind_param("isssssssssssii", $exam_id, $q_text, $paths['q_img'], $opt_a, $paths['img_a'], $opt_b, $paths['img_b'], $opt_c, $paths['img_c'], $opt_d, $paths['img_d'], $correct, $sort_order, $time_limit);
    
    if($q_stmt->execute()) {
        syncExamDuration($conn, $exam_id);
        ajaxResponse('success', 'New question appended and duration recalculated.');
    } else {
        ajaxResponse('error', 'Failed to append the new question.');
    }
}

// Fetch Exam & Questions Data with precise seconds sum
$exam_q = $conn->query("
    SELECT e.*, COALESCE(SUM(q.time_limit_seconds), 0) as total_seconds 
    FROM entrance_exams e
    LEFT JOIN exam_questions q ON e.id = q.exam_id
    WHERE e.id = $exam_id
    GROUP BY e.id
");
if ($exam_q->num_rows == 0) { die("Exam not found."); }
$exam_data = $exam_q->fetch_assoc();

$questions_q = $conn->query("SELECT * FROM exam_questions WHERE exam_id = $exam_id ORDER BY sort_order ASC, id ASC");
$all_questions = [];
if ($questions_q) {
    while($q = $questions_q->fetch_assoc()) {
        $all_questions[] = $q;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Exam - LDSP Command Center</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css?v=<?= time(); ?>">
    <style>
        .modal-overlay { opacity: 0; transition: opacity 0.2s ease; pointer-events: none; }
        .modal-content { transform: scale(0.95) translateY(10px); opacity: 0; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); pointer-events: none; }
        .modal-active.modal-overlay { opacity: 1; pointer-events: auto; }
        .modal-active .modal-content { transform: scale(1) translateY(0); opacity: 1; pointer-events: auto; }
        #toastContainer { position: fixed; top: 1.5rem; right: 1.5rem; z-index: 9999; display: flex; flex-direction: column; gap: 0.5rem; }

        /* Glossy Panel Styling */
        .glossy-panel { 
            background: linear-gradient(145deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.85) 100%);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 1);
            border-radius: 1.5rem; 
            box-shadow: 0 20px 40px -15px rgba(0, 32, 91, 0.15);
            position: relative;
            overflow: hidden;
        }
        .glossy-panel-header {
            height: 5px; width: 100%; position: absolute; top: 0; left: 0;
            background: linear-gradient(90deg, #00205b 0%, #c5a02c 100%);
        }
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

    <div id="toastContainer" class="fixed top-5 right-5 z-[9999] flex flex-col gap-2 pointer-events-none"></div>

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
            <header class="mb-6 flex flex-col md:flex-row justify-between items-start md:items-end gap-3 border-b border-slate-300/60 pb-4 drop-shadow-sm">
                <div>
                    <a href="exam_setup.php" class="text-slate-500 hover:text-[#00205b] text-[9px] font-black uppercase tracking-widest flex items-center gap-1.5 mb-2 transition-colors bg-white/60 backdrop-blur-sm px-2.5 py-1.5 rounded border border-slate-200 inline-flex shadow-sm hover:shadow-md w-max">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M15 19l-7-7 7-7" /></svg> Back
                    </a>
                    <h1 class="text-2xl md:text-3xl font-black tracking-tight font-academic uppercase text-[#00205b] drop-shadow-sm leading-none flex items-center gap-2">
                        Exam <span class="text-transparent bg-clip-text bg-gradient-to-r from-[#c5a02c] to-[#dfba45]">Editor</span>
                    </h1>
                    <p class="text-slate-500 mt-1 text-[10px] font-medium uppercase tracking-widest">Editing: <strong class="text-[#00205b] font-bold"><?= htmlspecialchars($exam_data['exam_title']) ?></strong></p>
                </div>
                <div class="flex flex-col items-end gap-2 w-full md:w-auto mt-4 md:mt-0">
                    <div class="bg-white/70 backdrop-blur-md border border-slate-200 px-3 py-1.5 rounded-lg font-mono text-[9px] font-bold text-[#00205b] shadow-sm flex items-center gap-1.5 w-max">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse shadow-[0_0_8px_rgba(16,185,129,0.8)]"></span>
                        SERVER: <?= date('M j, Y h:i A') ?>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
                        <button type="button" onclick="window.openPreviewModal()" class="bg-gradient-to-r from-blue-500 to-blue-600 text-white px-4 py-2 rounded-lg font-bold uppercase tracking-widest shadow-md hover:shadow-lg hover:-translate-y-0.5 transition-all border border-blue-700 text-[9px] w-full flex items-center justify-center gap-1.5 cursor-pointer">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                            Preview Structure
                        </button>
                        <button type="button" onclick="window.openExperienceView()" class="bg-gradient-to-r from-indigo-500 to-indigo-600 text-white px-4 py-2 rounded-lg font-bold uppercase tracking-widest shadow-md hover:shadow-lg hover:-translate-y-0.5 transition-all border border-indigo-700 text-[9px] w-full flex items-center justify-center gap-1.5 cursor-pointer">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            Experience View
                        </button>
                    </div>
                </div>
            </header>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
                
                <div class="lg:col-span-8">
                    <div class="flex justify-between items-center mb-4 border-b border-slate-300/60 pb-2 drop-shadow-sm">
                        <h3 class="text-xs font-black text-[#00205b] uppercase tracking-widest flex items-center gap-2">
                            Active Questions 
                            <span class="bg-[#c5a02c]/20 text-[#997a1d] px-2 py-0.5 rounded text-[8px] border border-[#c5a02c]/30 shadow-sm flex items-center gap-1"><span class="w-1 h-1 rounded-full bg-[#c5a02c]"></span> <?= count($all_questions) ?> Total</span>
                        </h3>
                    </div>
                    
                    <div id="questions-wrapper" class="space-y-5 mb-8">
                        <?php $q_num = 1; foreach($all_questions as $q): 
                            // Convert DB Seconds back to MM:SS for the UI
                            $m = floor($q['time_limit_seconds'] / 60);
                            $s = $q['time_limit_seconds'] % 60;
                            $m_str = str_pad($m, 2, '0', STR_PAD_LEFT);
                            $s_str = str_pad($s, 2, '0', STR_PAD_LEFT);
                        ?>
                        <div class="bg-white/95 backdrop-blur-md border border-slate-200 rounded-xl shadow-lg p-5 md:p-6 border-l-[4px] border-l-[#00205b] relative transition-all duration-300 hover:shadow-xl group step-card">
                            
                            <div class="flex justify-between items-center mb-4 border-b border-slate-200/60 pb-3 relative z-10">
                                <span class="step-header text-[10px] font-black text-[#00205b] bg-blue-50/80 backdrop-blur-sm px-3 py-1.5 rounded-lg border border-blue-200/80 shadow-sm uppercase tracking-widest flex items-center gap-1.5">
                                    <span class="w-1.5 h-1.5 rounded-full bg-[#00205b]"></span>
                                    Question #<?= $q_num++ ?> <span class="text-slate-400 font-bold ml-1 text-[8px]">(Sort: <?= $q['sort_order'] ?>)</span>
                                </span>
                                
                                <form class="ajax-form m-0" onsubmit="return confirm('Permanently delete this question?');">
                                    <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                                    <button type="submit" name="delete_question" value="1" class="text-rose-500 hover:text-white bg-white hover:bg-rose-500 px-3 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest transition-all flex items-center gap-1.5 border border-rose-200 shadow-sm hover:shadow-md cursor-pointer">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                                        Drop
                                    </button>
                                </form>
                            </div>
                            
                            <form class="ajax-form space-y-4 relative z-10" enctype="multipart/form-data">
                                <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                                
                                <div class="flex flex-col lg:flex-row gap-4 mb-4 border-b border-slate-200/60 pb-4">
                                    <div class="flex-1">
                                        <label class="block text-[9px] font-black text-slate-600 uppercase tracking-widest mb-1.5">Question Text</label>
                                        <textarea name="q_text" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 h-20 resize-none text-xs font-semibold text-slate-800 shadow-inner leading-relaxed focus:outline-none focus:border-[#00205b] focus:ring-1 focus:ring-[#00205b]/20 transition-all"><?= htmlspecialchars($q['question_text']) ?></textarea>
                                        
                                        <div class="flex items-center gap-2 mt-2 bg-slate-50 p-1.5 rounded-lg border border-slate-200">
                                            <input type="file" name="q_img" class="flex-1 text-[10px] text-slate-500 file:mr-2 file:py-1 file:px-2 file:rounded-md file:border-0 file:text-[9px] file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer bg-white border border-slate-200 rounded shadow-sm">
                                            <?php if(!empty($q['question_image'])): ?>
                                                <a href="<?= $q['question_image'] ?>" target="_blank" class="text-[8px] text-[#00205b] font-black uppercase tracking-widest bg-blue-50 px-2 py-1 rounded border border-blue-200 shadow-sm shrink-0 hover:bg-blue-100 transition-colors">View Attach</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="w-full lg:w-48 flex flex-col justify-start shrink-0">
                                        <div class="bg-[#c5a02c]/5 p-3 rounded-xl border border-[#c5a02c]/30 shadow-sm text-center h-full flex flex-col justify-center">
                                            <label class="block text-[10px] font-black text-[#c5a02c] uppercase tracking-widest mb-2 flex items-center justify-center gap-1">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg> Timer
                                            </label>
                                            <p class="text-[8px] text-slate-500 font-bold uppercase tracking-widest mb-2">Set MM:SS</p>
                                            
                                            <div class="flex items-center justify-center gap-1 bg-white border-2 border-[#c5a02c]/40 rounded-lg px-2 py-1 shadow-inner focus-within:border-[#c5a02c] transition-colors">
                                                <div class="flex flex-col items-center w-12">
                                                    <input type="number" name="time_m" min="0" max="60" value="<?= $m_str ?>" onblur="this.value = this.value.padStart(2, '0')" class="w-full text-center text-xl font-black text-[#c5a02c] focus:outline-none bg-transparent">
                                                    <span class="text-[7px] text-slate-400 uppercase font-black -mt-1">MIN</span>
                                                </div>
                                                <span class="text-xl font-black text-[#c5a02c] mb-2">:</span>
                                                <div class="flex flex-col items-center w-12">
                                                    <input type="number" name="time_s" min="0" max="59" value="<?= $s_str ?>" onblur="this.value = this.value.padStart(2, '0')" class="w-full text-center text-xl font-black text-[#c5a02c] focus:outline-none bg-transparent">
                                                    <span class="text-[7px] text-slate-400 uppercase font-black -mt-1">SEC</span>
                                                </div>
                                            </div>

                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <?php foreach(['a','b','c','d'] as $opt): 
                                        $is_correct = ($q['correct_option'] == strtoupper($opt));
                                    ?>
                                    <div class="p-3 rounded-xl border <?= $is_correct ? 'bg-emerald-50/80 border-emerald-300 shadow-sm' : 'bg-slate-50/80 border-slate-200 hover:bg-white hover:border-[#00205b]/30' ?> transition-colors flex flex-col justify-between">
                                        <div class="flex items-center justify-between mb-2">
                                            <label class="flex items-center gap-1.5 text-[#00205b] block text-[8px] font-black uppercase tracking-widest drop-shadow-sm <?= $is_correct ? '!text-emerald-700' : '' ?> m-0">
                                                <span class="<?= $is_correct ? 'bg-emerald-500 text-white border-emerald-600' : 'bg-slate-200 text-slate-700 border-slate-300' ?> px-2 py-0.5 rounded shadow-sm border transition-colors"><?= strtoupper($opt) ?></span> Option
                                            </label>
                                            <?php if(!empty($q['image_'.$opt])): ?>
                                                <a href="<?= $q['image_'.$opt] ?>" target="_blank" class="text-[8px] text-blue-700 font-black uppercase tracking-widest hover:underline bg-blue-50 px-1.5 py-0.5 rounded shadow-sm border border-blue-200">View Img</a>
                                            <?php endif; ?>
                                        </div>
                                        <input type="text" name="opt_<?= strtoupper($opt) ?>" value="<?= htmlspecialchars($q['option_'.$opt]) ?>" required class="w-full mb-2 text-xs font-semibold py-1.5 px-3 bg-white border rounded-lg shadow-inner focus:outline-none transition-all <?= $is_correct ? 'border-emerald-200 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20' : 'border-slate-300 focus:border-[#00205b] focus:ring-1 focus:ring-[#00205b]/20' ?>">
                                        <input type="file" name="img_<?= strtoupper($opt) ?>" class="w-full text-[10px] text-slate-500 file:mr-2 file:py-1 file:px-2 file:rounded-md file:border-0 file:text-[8px] file:font-bold file:bg-slate-200 file:text-slate-700 hover:file:bg-slate-300 cursor-pointer bg-white border border-slate-200 rounded-md shadow-sm">
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="mt-4 pt-4 border-t border-slate-200/60 flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-50 p-3 rounded-xl border border-slate-200 shadow-inner">
                                    <div class="flex items-center gap-3">
                                        <label class="mb-0 text-[#00205b] font-black flex items-center gap-1.5 text-[9px] uppercase tracking-widest drop-shadow-sm">
                                            Correct Key:
                                        </label>
                                        <select name="correct_option" class="w-20 text-center font-black bg-white border border-slate-300 text-[#00205b] cursor-pointer shadow-sm py-1.5 px-2 text-xs rounded-lg focus:outline-none focus:border-[#00205b] focus:ring-1 focus:ring-[#00205b]/20">
                                            <option value="A" <?= $q['correct_option'] == 'A' ? 'selected' : '' ?>>A</option>
                                            <option value="B" <?= $q['correct_option'] == 'B' ? 'selected' : '' ?>>B</option>
                                            <option value="C" <?= $q['correct_option'] == 'C' ? 'selected' : '' ?>>C</option>
                                            <option value="D" <?= $q['correct_option'] == 'D' ? 'selected' : '' ?>>D</option>
                                        </select>
                                    </div>
                                    <button type="submit" name="edit_question" value="1" class="w-full sm:w-auto bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-400 hover:to-emerald-500 text-white border border-emerald-700 px-6 py-2 rounded-lg text-[9px] font-black uppercase tracking-widest shadow-md transition-all hover:-translate-y-0.5 cursor-pointer">Update Block</button>
                                </div>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Add New Question Block -->
                    <div class="bg-white/95 backdrop-blur-md border border-slate-200 rounded-xl shadow-lg p-5 md:p-6 border-t-[3px] border-t-[#c5a02c] animate-up step-card" style="animation-delay: 0.2s;">
                        <div class="flex items-center gap-2 border-b border-slate-200/60 pb-3 mb-4">
                            <div class="p-1.5 rounded-md bg-[#c5a02c]/10"><svg class="w-4 h-4 text-[#c5a02c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg></div>
                            <h3 class="step-header text-[11px] font-black text-[#00205b] uppercase tracking-[0.2em] drop-shadow-sm">Add New Question Block</h3>
                        </div>
                        
                        <form class="ajax-form space-y-5" enctype="multipart/form-data">
                            <div class="flex flex-col lg:flex-row gap-4 mb-4 border-b border-slate-200/60 pb-4">
                                <div class="flex-1">
                                    <label class="block text-[9px] font-black text-slate-600 uppercase tracking-widest mb-1.5">Question Text & Media</label>
                                    <textarea name="new_q_text" required class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 h-20 resize-none mb-3 text-sm font-semibold shadow-inner focus:outline-none focus:border-[#00205b] focus:ring-1 focus:ring-[#00205b]/20" placeholder="Type the new question scenario..."></textarea>
                                    
                                    <div class="bg-slate-50 p-2 rounded-lg border border-slate-200">
                                        <p class="text-[8px] font-bold text-slate-400 uppercase tracking-widest mb-1 ml-1">Attach Context Image (Optional)</p>
                                        <input type="file" name="new_q_img" class="w-full text-[10px] text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-[9px] file:font-black file:uppercase file:tracking-widest file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer bg-white border border-slate-200 rounded-lg shadow-sm">
                                    </div>
                                </div>
                                <div class="w-full lg:w-48 flex flex-col justify-start shrink-0">
                                    <div class="bg-[#c5a02c]/5 p-3 rounded-xl border border-[#c5a02c]/30 shadow-sm text-center h-full flex flex-col justify-center">
                                        <label class="block text-[9px] font-black text-[#c5a02c] uppercase tracking-widest mb-2 flex items-center justify-center gap-1">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg> Timer
                                        </label>
                                        <p class="text-[8px] text-slate-500 font-bold uppercase tracking-widest mb-2">Set MM:SS</p>
                                        
                                        <div class="flex items-center justify-center gap-1 bg-white border-2 border-[#c5a02c]/40 rounded-lg px-2 py-1 shadow-inner focus-within:border-[#c5a02c] transition-colors">
                                            <div class="flex flex-col items-center w-12">
                                                <input type="number" name="new_time_m" min="0" max="60" value="01" onblur="this.value = this.value.padStart(2, '0')" class="w-full text-center text-xl font-black text-[#c5a02c] focus:outline-none bg-transparent">
                                                <span class="text-[7px] text-slate-400 uppercase font-black -mt-1">MIN</span>
                                            </div>
                                            <span class="text-xl font-black text-[#c5a02c] mb-2">:</span>
                                            <div class="flex flex-col items-center w-12">
                                                <input type="number" name="new_time_s" min="0" max="59" value="00" onblur="this.value = this.value.padStart(2, '0')" class="w-full text-center text-xl font-black text-[#c5a02c] focus:outline-none bg-transparent">
                                                <span class="text-[7px] text-slate-400 uppercase font-black -mt-1">SEC</span>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <?php foreach(['a','b','c','d'] as $opt): ?>
                                <div class="p-3 bg-slate-50/80 rounded-xl border border-slate-200 shadow-sm transition-all hover:bg-white hover:border-[#00205b]/30 group flex flex-col justify-between">
                                    <label class="flex items-center gap-2 text-[#00205b] block text-[9px] font-black uppercase tracking-widest mb-2 drop-shadow-sm">
                                        <span class="bg-slate-200 text-slate-700 px-2 py-0.5 rounded shadow-sm border border-slate-300 group-hover:bg-[#00205b] group-hover:text-white group-hover:border-[#00205b] transition-colors"><?= strtoupper($opt) ?></span> Option
                                    </label>
                                    <input type="text" name="new_opt_<?= $opt ?>" required class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 mb-2 text-xs font-semibold text-slate-700 shadow-inner focus:outline-none focus:border-[#00205b] focus:ring-1 focus:ring-[#00205b]/20 transition-all" placeholder="Enter answer text...">
                                    <div class="relative overflow-hidden">
                                        <input type="file" name="new_img_<?= $opt ?>" class="w-full text-[10px] text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-[9px] file:font-black file:uppercase file:tracking-widest file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer bg-white border border-slate-200 rounded-lg shadow-sm">
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="mt-5 pt-5 border-t border-slate-200/60 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-emerald-50/60 p-4 rounded-xl border border-emerald-200 shadow-inner relative z-10">
                                <div class="flex-1">
                                    <label class="mb-1 text-emerald-800 font-black flex items-center gap-2 text-[11px] uppercase tracking-widest drop-shadow-sm">
                                        <div class="p-1.5 rounded-md bg-emerald-100 shadow-sm border border-emerald-200"><svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg></div>
                                        Correct Answer Key
                                    </label>
                                    <p class="text-[8px] text-emerald-600/80 font-bold uppercase tracking-widest">Select the correct option</p>
                                </div>
                                <div class="flex gap-2 w-full md:w-1/2">
                                    <?php foreach(['A','B','C','D'] as $opt): ?>
                                        <label class="cursor-pointer relative group flex-1">
                                            <input type="radio" name="new_correct" value="<?= $opt ?>" required class="hidden peer">
                                            <div class="w-full h-10 flex items-center justify-center rounded-lg border-2 border-slate-200 bg-white text-slate-400 font-black text-sm transition-all duration-200 peer-checked:bg-emerald-500 peer-checked:border-emerald-600 peer-checked:text-white peer-checked:shadow-[0_4px_10px_rgba(16,185,129,0.4)] shadow-sm hover:border-emerald-300"><?= $opt ?></div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="pt-4">
                                <button type="submit" name="add_single_question" value="1" class="bg-gradient-to-r from-[#dfb944] to-[#c5a02c] text-white px-6 py-4 rounded-xl font-black uppercase tracking-widest shadow-[0_8px_20px_-6px_rgba(197,160,44,0.6)] hover:shadow-[0_12px_25px_-8px_rgba(197,160,44,0.8)] hover:-translate-y-0.5 transition-all border border-[#997a1d] text-[11px] w-full cursor-pointer flex items-center justify-center gap-2">
                                    <svg class="w-4 h-4 opacity-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4"/></svg>
                                    Append New Question Block
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Right Side Sidebar (Schedule & Edit Mode) -->
                <div class="lg:col-span-4 animate-up delay-3 mt-6 lg:mt-0">
                    <div class="bg-white/95 backdrop-blur-md border border-slate-200 rounded-xl shadow-lg p-5 sticky top-6">
                        <h3 class="text-[11px] font-black text-[#c5a02c] uppercase tracking-widest mb-4 border-b border-slate-200/60 pb-3 flex items-center gap-2 relative z-10 drop-shadow-sm">
                            <div class="p-1 rounded-md bg-[#c5a02c]/10"><svg class="w-4 h-4 text-[#c5a02c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg></div>
                            Current Exam Profile
                        </h3>
                        
                        <div class="space-y-4 relative z-10">
                            <?php 
                                $current_time = date('Y-m-d H:i:s');
                                if ($current_time < $exam_data['activation_time']) {
                                    $status = 'Upcoming';
                                    $border_glow = 'border-l-[4px] border-l-amber-400';
                                    $badge_style = 'bg-amber-50 text-amber-700 border-amber-200';
                                } elseif ($current_time >= $exam_data['activation_time'] && $current_time <= $exam_data['deadline_time']) {
                                    $status = 'Live Now';
                                    $border_glow = 'border-l-[4px] border-l-emerald-400';
                                    $badge_style = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                                } else {
                                    $status = 'Closed';
                                    $border_glow = 'border-l-[4px] border-l-slate-400';
                                    $badge_style = 'bg-slate-100 text-slate-500 border-slate-200';
                                }
                                $exact_duration_str = formatSeconds($exam_data['total_seconds']);
                            ?>
                            
                            <div class="bg-slate-50/80 border border-slate-200 rounded-xl p-4 relative <?= $border_glow ?> shadow-inner">
                                <div class="flex justify-between items-start mb-4 gap-2">
                                    <h4 class="font-black text-[#00205b] text-[11px] uppercase tracking-wide leading-snug drop-shadow-sm break-words flex-1"><?= htmlspecialchars($exam_data['exam_title']) ?></h4>
                                    <span class="<?= $badge_style ?> border px-2 py-1 rounded-md text-[8px] font-black uppercase whitespace-nowrap shadow-sm">
                                        <?= $status ?>
                                    </span>
                                </div>
                                
                                <div class="space-y-3 mb-4">
                                    <div class="bg-white rounded-lg p-3 text-[10px] font-mono font-bold text-slate-700 space-y-2 border border-slate-200 shadow-sm">
                                        <p class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shadow-[0_0_5px_rgba(16,185,129,0.8)]"></span> <?= date('M j, Y g:i A', strtotime($exam_data['activation_time'])) ?></p>
                                        <div class="h-px w-full bg-slate-100"></div>
                                        <p class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-rose-500 shadow-[0_0_5px_rgba(244,63,94,0.8)]"></span> <?= date('M j, Y g:i A', strtotime($exam_data['deadline_time'])) ?></p>
                                    </div>
                                    <div class="flex justify-between items-center bg-blue-50/50 rounded-lg p-2 border border-blue-100 px-3">
                                        <div class="text-center">
                                            <p class="text-[8px] text-slate-400 uppercase tracking-widest font-black mb-0.5">Passing</p>
                                            <p class="text-[10px] font-black text-emerald-700 font-mono"><?= htmlspecialchars($exam_data['passing_score']) ?> Pts</p>
                                        </div>
                                        <div class="w-px h-6 bg-blue-200/60"></div>
                                        <div class="text-center">
                                            <p class="text-[8px] text-slate-400 uppercase tracking-widest font-black mb-0.5">Duration</p>
                                            <p class="text-[10px] font-black text-[#00205b] font-mono"><?= $exact_duration_str ?></p>
                                        </div>
                                    </div>
                                </div>
                                
                                <button type="button" onclick="window.openEditModal(<?= $exam_data['id'] ?>, '<?= htmlspecialchars($exam_data['exam_title'], ENT_QUOTES) ?>', '<?= date('Y-m-d\TH:i', strtotime($exam_data['activation_time'])) ?>', '<?= date('Y-m-d\TH:i', strtotime($exam_data['deadline_time'])) ?>', <?= $exam_data['passing_score'] ?>)" class="w-full bg-white hover:bg-amber-50 text-amber-600 border border-amber-200 hover:border-amber-300 text-[9px] font-black py-2.5 rounded-lg text-center transition-all uppercase tracking-widest shadow-sm cursor-pointer mt-2 flex items-center justify-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                    Edit Meta Settings
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Preview Structure Modal (List View) -->
    <div id="previewExamModal" class="fixed inset-0 z-[110] hidden flex-col items-center justify-center p-4 bg-slate-900/80 backdrop-blur-sm transition-opacity modal-overlay">
        <div class="bg-slate-50 rounded-2xl shadow-2xl w-full max-w-4xl border-t-[6px] border-t-blue-500 modal-content flex flex-col h-[90vh]">
            <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-white rounded-t-xl shrink-0 shadow-sm z-20">
                <h3 class="font-black text-[#00205b] uppercase tracking-widest text-[13px] flex items-center gap-2 drop-shadow-sm">
                    <div class="p-1.5 rounded-md bg-blue-50"><svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg></div>
                    Exam Structure Preview
                </h3>
                <button type="button" onclick="window.closeModal('previewExamModal')" class="text-slate-400 hover:text-rose-500 p-2 bg-slate-100 hover:bg-rose-50 border border-slate-200 rounded-lg transition-colors cursor-pointer shadow-sm"><svg class="w-5 h-5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
            </div>
            
            <div class="flex-1 overflow-y-auto custom-scrollbar p-6 bg-slate-100/50 space-y-6">
                <?php $q_num = 1; foreach($all_questions as $q): ?>
                <div class="bg-white rounded-xl shadow-md border border-slate-200 p-6 md:p-8 relative overflow-hidden pointer-events-none select-none">
                    
                    <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-100">
                        <span class="text-xs font-black text-[#00205b] uppercase tracking-widest bg-blue-50 px-3 py-1.5 rounded-lg border border-blue-100 shadow-sm">
                            Question <?= $q_num++ ?>
                        </span>
                        <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest border border-slate-200 px-3 py-1.5 rounded-lg bg-slate-50 flex items-center gap-1.5 shadow-sm">
                            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg> Timer Hidden
                        </span>
                    </div>

                    <div class="mb-6">
                        <p class="text-slate-800 font-semibold text-sm md:text-base leading-relaxed"><?= nl2br(htmlspecialchars($q['question_text'])) ?></p>
                        <?php if(!empty($q['question_image'])): ?>
                            <div class="mt-4 rounded-lg overflow-hidden border border-slate-200 bg-slate-50 p-2 w-max max-w-full">
                                <img src="<?= $q['question_image'] ?>" class="max-h-64 object-contain rounded">
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach(['a','b','c','d'] as $opt): 
                            $is_correct = ($q['correct_option'] == strtoupper($opt));
                        ?>
                        <label class="flex items-start gap-4 p-4 rounded-xl border-2 transition-all <?= $is_correct ? 'bg-emerald-50 border-emerald-500 shadow-sm' : 'bg-white border-slate-200' ?>">
                            <div class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-black shrink-0 mt-0.5 <?= $is_correct ? 'bg-emerald-500 text-white shadow-sm' : 'bg-slate-100 text-slate-500 border border-slate-300' ?>">
                                <?= strtoupper($opt) ?>
                            </div>
                            <div class="flex-1">
                                <span class="text-xs font-bold leading-snug <?= $is_correct ? 'text-emerald-900' : 'text-slate-700' ?>"><?= htmlspecialchars($q['option_'.$opt]) ?></span>
                                <?php if(!empty($q['image_'.$opt])): ?>
                                    <div class="mt-3 rounded-lg overflow-hidden border border-slate-200 bg-white p-1.5 w-max max-w-full shadow-sm">
                                        <img src="<?= $q['image_'.$opt] ?>" class="max-h-32 object-contain rounded">
                                    </div>
                                <?php endif; ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if(empty($all_questions)): ?>
                    <div class="text-center py-20 bg-white rounded-xl border border-slate-200 shadow-sm">
                        <svg class="w-12 h-12 text-slate-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">No questions added yet.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="p-5 border-t border-slate-200 bg-white rounded-b-xl shrink-0 flex justify-center">
                <button type="button" onclick="window.closeModal('previewExamModal')" class="bg-[#00205b] hover:bg-[#003882] text-white px-8 py-3 rounded-lg font-black uppercase tracking-widest shadow-md transition-colors text-[10px] cursor-pointer w-full md:w-auto text-center">Close Preview</button>
            </div>
        </div>
    </div>

    <!-- FULL SCREEN EXPERIENCE VIEW OVERLAY -->
    <div id="experienceViewOverlay" class="fixed inset-0 z-[200] hidden bg-[#f4f6f9] overflow-y-auto w-full h-full custom-scrollbar">
        
        <!-- Close Button -->
        <button type="button" onclick="window.closeExperienceView()" class="fixed top-4 right-4 sm:top-6 sm:right-6 z-[210] bg-white text-rose-500 hover:bg-rose-500 hover:text-white rounded-full p-3 shadow-[0_10px_20px_rgba(0,0,0,0.1)] border border-slate-200 transition-colors cursor-pointer group">
            <svg class="w-6 h-6 transform group-hover:rotate-90 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>

        <div class="p-4 md:p-8 max-w-4xl mx-auto relative z-10 min-h-screen flex flex-col pt-16 sm:pt-8">
            <div class="timer-sticky rounded-2xl p-4 md:p-6 mb-8 flex flex-col sm:flex-row justify-between items-center shadow-md gap-4 bg-white/90 backdrop-blur-md border border-slate-100">
                <div>
                    <h1 class="text-xl md:text-2xl font-black text-[#00205b] font-academic uppercase tracking-tight"><?= htmlspecialchars($exam_data['exam_title']) ?></h1>
                    <p class="text-xs text-slate-500 font-bold uppercase tracking-widest mt-1">Student: STAFF PREVIEW (ADM-0000)</p>
                </div>
                <div class="bg-rose-50 border border-rose-200 px-6 py-3 rounded-xl flex items-center gap-3">
                    <svg class="w-5 h-5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <div class="text-right">
                        <span class="block text-[10px] text-rose-600 font-black uppercase tracking-widest leading-none">Global Timer (Frozen)</span>
                        <span class="font-mono text-xl font-black text-rose-700 leading-none"><?= formatSeconds($exam_data['total_seconds']) ?></span>
                    </div>
                </div>
            </div>

            <div class="space-y-6 flex-1">
                <?php 
                $exp_q_counter = 1;
                $exp_total_q = count($all_questions);
                if ($exp_total_q > 0):
                    foreach($all_questions as $q): 
                ?>
                    <div id="q_block_exp_<?= $exp_q_counter ?>" class="question-block-exp glossy-panel p-6 md:p-8 <?= $exp_q_counter > 1 ? 'hidden' : '' ?>">
                        <div class="glossy-panel-header"></div>
                        
                        <div class="flex justify-between items-center mb-5 bg-slate-50 p-3 rounded-xl border border-slate-200">
                            <div class="flex items-center gap-3">
                                <span class="bg-[#00205b] text-white w-8 h-8 flex items-center justify-center rounded-lg font-black text-sm shrink-0 shadow-sm"><?= $exp_q_counter ?></span>
                                <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Question <?= $exp_q_counter ?> of <?= $exp_total_q ?></span>
                            </div>
                            <div class="flex items-center gap-2 bg-amber-50 text-amber-700 px-4 py-1.5 rounded-lg border border-amber-200 shadow-inner">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                <span class="font-mono font-black text-lg leading-none"><?= $q['time_limit_seconds'] ?>s</span>
                            </div>
                        </div>

                        <div class="mb-5 pb-5 border-b border-slate-200">
                            <p class="text-base md:text-lg font-semibold text-slate-800 leading-relaxed"><?= nl2br(htmlspecialchars($q['question_text'])) ?></p>
                            
                            <?php if (!empty($q['question_image'])): ?>
                                <div class="mt-4 rounded-xl overflow-hidden border border-slate-200 inline-block">
                                    <img src="<?= htmlspecialchars($q['question_image']) ?>" alt="Question Media" class="max-h-64 object-contain">
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php foreach(['a', 'b', 'c', 'd'] as $opt): ?>
                                <label class="custom-radio cursor-pointer group">
                                    <input type="radio" name="exp_answer_<?= $q['id'] ?>" value="<?= strtoupper($opt) ?>" class="hidden" onclick="window.showExpNextButton(<?= $exp_q_counter ?>)">
                                    <div class="p-4 rounded-xl border-2 border-slate-200 bg-white hover:border-[#00205b]/30 hover:bg-blue-50/30 transition-all flex items-center gap-4">
                                        <span class="bg-slate-100 text-slate-500 group-hover:bg-slate-200 w-8 h-8 rounded-lg flex items-center justify-center font-black text-xs uppercase shrink-0"><?= strtoupper($opt) ?></span>
                                        
                                        <div class="flex-1">
                                            <span class="text-sm font-medium"><?= htmlspecialchars($q['option_'.$opt]) ?></span>
                                            <?php if (!empty($q['image_'.$opt])): ?>
                                                <img src="<?= htmlspecialchars($q['image_'.$opt]) ?>" alt="Option Image" class="mt-2 max-h-32 object-contain border border-slate-200 rounded-md">
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($exp_q_counter < $exp_total_q): ?>
                        <div class="mt-6 text-right">
                            <button type="button" id="exp_next_btn_<?= $exp_q_counter ?>" onclick="window.nextExpQuestion(<?= $exp_q_counter ?>)" class="bg-[#c5a02c] hover:bg-[#997a1d] text-white px-8 py-3 rounded-xl font-black uppercase tracking-widest text-[10px] transition-colors shadow-md hidden cursor-pointer">
                                Confirm & Next &rarr;
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="mt-6 text-right">
                            <button type="button" id="exp_next_btn_<?= $exp_q_counter ?>" onclick="window.closeExperienceView()" class="bg-[#00205b] hover:bg-[#003882] text-white px-8 py-3 rounded-xl font-black uppercase tracking-widest text-[10px] transition-colors shadow-md hidden cursor-pointer">
                                Finish Preview
                            </button>
                        </div>
                        <?php endif; ?>

                    </div>
                <?php 
                    $exp_q_counter++;
                    endforeach; 
                else:
                ?>
                    <div class="glossy-panel p-10 text-center">
                        <h3 class="text-slate-500 font-bold">No questions found for this examination module.</h3>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Edit Exam Settings Modal -->
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
    <script src="manage_questions.js?v=<?= time() ?>"></script>
    
<script>
        document.getElementById('mobileMenuBtn')?.addEventListener('click', () => {
            document.getElementById('sidebar')?.classList.toggle('-translate-x-full');
            document.getElementById('sidebarOverlay')?.classList.toggle('hidden');
        });
        document.getElementById('sidebarOverlay')?.addEventListener('click', () => {
            document.getElementById('sidebar')?.classList.add('-translate-x-full');
            document.getElementById('sidebarOverlay')?.classList.add('hidden');
        });

        let isModalOpen = false;
        function openModal(modalId) {
            isModalOpen = true;
            const modal = document.getElementById(modalId);
            if (!modal) return;
            modal.classList.remove('hidden'); modal.classList.add('flex'); void modal.offsetWidth; modal.classList.add('modal-active');
        }
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;
            modal.classList.remove('modal-active');
            setTimeout(() => { modal.classList.add('hidden'); modal.classList.remove('flex'); isModalOpen = false; }, 300);
        }

        document.addEventListener('DOMContentLoaded', () => {
            function showToast(message, type = 'success') {
                const container = document.getElementById('toastContainer');
                if (!container) return;
                const toast = document.createElement('div');
                toast.className = `flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg border text-sm font-bold text-white transition-all transform duration-300 translate-x-full opacity-0 ${type === 'success' ? 'bg-gradient-to-r from-emerald-500 to-emerald-600 border-emerald-400' : 'bg-gradient-to-r from-rose-500 to-rose-600 border-rose-400'}`;
                
                const icon = type === 'success' 
                    ? '<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
                    : '<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
                    
                toast.innerHTML = `${icon} <span>${message}</span>`;
                container.appendChild(toast);
                
                requestAnimationFrame(() => {
                    toast.classList.remove('translate-x-full', 'opacity-0');
                    toast.classList.add('translate-x-0', 'opacity-100');
                });
                
                setTimeout(() => {
                    toast.classList.remove('translate-x-0', 'opacity-100');
                    toast.classList.add('translate-x-full', 'opacity-0');
                    setTimeout(() => toast.remove(), 300);
                }, 4000);
            }

            window.showToast = showToast;
            window.openModal = openModal;
            window.closeModal = closeModal;
        });
    </script>
</body>
</html>