<?php
// 1. UNIFIED SESSION HANDLER
$session_lifetime = 60 * 60 * 24 * 30; 
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params($session_lifetime, '/');

session_start();

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: admission_form_registration/admission_portal.php");
    exit();
}

include "dbconn.php"; 

date_default_timezone_set('Asia/Manila');

$error_msg = "";
$is_logged_in = false;
$exam_data = null;
$gate_status = "";
$can_take = false;
$adm_data = null;
$exam_result_data = null; 

// ==========================================
// 2. AUTO-AUTHENTICATION LOGIC (URL ONLY)
// ==========================================

if (isset($_GET['adm_no']) && !empty(trim($_GET['adm_no']))) {
    $login_adm_no = mysqli_real_escape_string($conn, trim($_GET['adm_no']));
    
    $adm_query = $conn->query("SELECT * FROM admissions WHERE admission_number = '$login_adm_no'");
    
    if ($adm_query && $adm_query->num_rows > 0) {
        $data = $adm_query->fetch_assoc();
        
        $first = !empty($data['first_name']) ? $data['first_name'] : '';
        $last = !empty($data['last_name']) ? $data['last_name'] . ', ' : '';
        $full_name = trim($last . $first);

        $_SESSION['email'] = $data['email'];
        $_SESSION['student_name'] = $full_name;
        $_SESSION['firstname'] = $first;
        $_SESSION['admission_number'] = $login_adm_no; 
        $_SESSION['student_id'] = $login_adm_no;
        $_SESSION['role'] = 'student';
        
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
        exit();
    } else {
        $error_msg = "Invalid Admission Number.";
    }
}

// ==========================================
// 3. EXAM DASHBOARD LOGIC (If Logged In)
// ==========================================
if (isset($_SESSION['role']) && $_SESSION['role'] === 'student' && isset($_SESSION['admission_number'])) {
    $is_logged_in = true;
    $clean_adm_no = mysqli_real_escape_string($conn, $_SESSION['admission_number']);
    
    $adm_query = $conn->query("SELECT * FROM admissions WHERE admission_number = '$clean_adm_no'");
    
    if ($adm_query->num_rows > 0) {
        $adm_data = $adm_query->fetch_assoc();
        
        if ($adm_data['status'] === 'Pending') {
            $error_msg = "Your application is still under review. You cannot take the exam yet.";
            $is_logged_in = false;
        } elseif ($adm_data['status'] === 'Denied') {
            $error_msg = "Your application requires document updates. Please check your tracking portal.";
            $is_logged_in = false;
        } else {
            $check_result = $conn->query("
                SELECT er.*, e.passing_score 
                FROM exam_results er 
                LEFT JOIN entrance_exams e ON er.exam_id = e.id 
                WHERE er.student_id = '$clean_adm_no'
                ORDER BY er.time_finished DESC LIMIT 1
            ");
            
            if ($check_result && $check_result->num_rows > 0) {
                $exam_result_data = $check_result->fetch_assoc();
                
                $current_passing = (int)$exam_result_data['passing_score'];
                $actual_score = (int)$exam_result_data['score'];
                $correct_status = ($actual_score >= $current_passing) ? 'Passed' : 'Failed';
                
                if ($exam_result_data['status'] !== $correct_status) {
                    $exam_result_data['status'] = $correct_status;
                    $conn->query("UPDATE exam_results SET status = '$correct_status' WHERE student_id = '$clean_adm_no' AND exam_id = " . $exam_result_data['exam_id']);
                }
                
                $gate_status = "Your scholarship examination results have been securely recorded.";
            } else {
                $exam_q = $conn->query("SELECT * FROM entrance_exams ORDER BY id DESC LIMIT 1");
                
                if ($exam_q->num_rows > 0) {
                    $exam_data = $exam_q->fetch_assoc();
                    $now = date('Y-m-d H:i:s');
                    
                    if ($now < $exam_data['activation_time']) {
                        $gate_status = "LOCKED: The examination module is scheduled to open on <br><strong class='text-[#00205b] mt-2 block text-sm'>" . date('l, M j, Y @ g:i A', strtotime($exam_data['activation_time'])) . "</strong>";
                    } elseif ($now > $exam_data['deadline_time']) {
                        $gate_status = "CLOSED: The deadline for this examination has passed. Please contact the Admissions Office.";
                    } else {
                        $can_take = true;
                        $gate_status = "AUTHORIZATION GRANTED: Your examination module is currently active and ready to begin.";
                    }
                } else {
                    $gate_status = "STANDBY: No examination module has been scheduled yet. Please await further instructions.";
                }
            }
        }
    } else {
        $is_logged_in = false;
        $error_msg = "Your admission status is no longer valid for examination.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Exam Gateway - LDSP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --ldsp-blue: #00205b;
            --ldsp-blue-light: #003882;
            --ldsp-gold: #c5a02c;
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f4f6f9;
            color: #1f2937; 
            overflow-x: hidden; 
            min-height: 100vh;
        }
        .font-academic { font-family: 'Crimson Pro', serif; }

        .ambient-bg {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: radial-gradient(circle at center, #ffffff 0%, #f1f5f9 100%);
            z-index: 0;
        }

        .clean-panel { 
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 1rem; 
            box-shadow: 0 10px 25px -5px rgba(0, 32, 91, 0.05), 0 8px 10px -6px rgba(0, 32, 91, 0.01);
            position: relative;
            z-index: 10;
            overflow: hidden;
        }

        .clean-panel-header {
            height: 4px; width: 100%; position: absolute; top: 0; left: 0;
            background: var(--ldsp-blue);
        }
        
        .btn-primary {
            background: var(--ldsp-blue);
            color: white; font-weight: 700; border-radius: 0.5rem; padding: 0.875rem 1.5rem;
            display: inline-flex; justify-content: center; align-items: center; gap: 8px;
            cursor: pointer; transition: all 0.2s ease; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.875rem;
        }
        .btn-primary:hover:not(:disabled) { background: var(--ldsp-blue-light); transform: translateY(-1px); box-shadow: 0 4px 6px -1px rgba(0, 32, 91, 0.1); }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; background: #94a3b8; }

        .animate-up { opacity: 0; animation: fadeUp 0.5s ease-out forwards; }
        .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; } .delay-3 { animation-delay: 0.3s; }
        @keyframes fadeUp { 0% { opacity: 0; transform: translateY(10px); } 100% { opacity: 1; transform: translateY(0); } }

        .custom-checkbox { 
            appearance: none; background-color: #fff; margin: 0; color: currentColor; width: 1.25em; height: 1.25em; 
            border: 2px solid #cbd5e1; border-radius: 0.25rem; display: grid; place-content: center; cursor: pointer; transition: all 0.2s;
        }
        .custom-checkbox::before { 
            content: ""; width: 0.65em; height: 0.65em; transform: scale(0); transition: 120ms transform ease-in-out; 
            box-shadow: inset 1em 1em white; background-color: white; transform-origin: center; clip-path: polygon(14% 44%, 0 65%, 50% 100%, 100% 16%, 80% 0%, 43% 62%); 
        }
        .custom-checkbox:checked { border-color: var(--ldsp-blue); background-color: var(--ldsp-blue); }
        .custom-checkbox:checked::before { transform: scale(1); }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center p-4 sm:p-8 relative">
    
    <div class="ambient-bg"></div>

    <div class="max-w-2xl w-full relative z-10 my-auto">

        <?php if (!$is_logged_in): ?>
            <!-- ========================================== -->
            <!-- ERROR / INVALID LINK PANEL -->
            <!-- ========================================== -->
            <div class="clean-panel border-t-[4px] !border-t-rose-600 p-8 md:p-10 text-center animate-up">
                <div class="w-16 h-16 bg-white text-rose-600 rounded-full flex items-center justify-center mx-auto mb-5 border border-slate-200 shadow-sm">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <h1 class="text-2xl font-black text-slate-800 font-academic uppercase tracking-tight mb-4">Access Denied</h1>
                
                <div class="bg-slate-50 border border-slate-200 text-slate-700 p-4 rounded-lg mb-8 text-sm font-medium">
                    <?= empty($error_msg) ? "Invalid or missing secure exam link. Please check your email." : htmlspecialchars($error_msg) ?>
                </div>

                <a href="admission_form_registration/admission_portal.php" class="text-slate-500 hover:text-[#00205b] text-xs font-bold uppercase tracking-widest inline-flex items-center gap-2 transition-colors bg-white px-5 py-2.5 rounded border border-slate-200 shadow-sm hover:bg-slate-50">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg> Return to Portal
                </a>
            </div>

        <?php else: ?>
            <!-- ========================================== -->
            <!-- EXAM GATEWAY DASHBOARD -->
            <!-- ========================================== -->
            <div class="clean-panel p-8 md:p-10 border-t-[4px] !border-t-[#00205b] animate-up shadow-xl">
                
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4 border-b border-slate-200 pb-5">
                    <div>
                        <h2 class="text-xl sm:text-2xl font-black text-[#00205b] font-academic uppercase tracking-tight">Welcome, <?= htmlspecialchars($_SESSION['student_name']) ?></h2>
                        <div class="flex items-center gap-2 mt-2">
                            <span class="text-slate-500 font-mono text-xs font-semibold tracking-wider">ID: <?= htmlspecialchars($_SESSION['admission_number']) ?></span> 
                            <span class="text-slate-400">|</span>
                            <span class="text-slate-500 text-[10px] font-bold uppercase tracking-widest">Universal Access</span>
                        </div>
                    </div>
                    <div class="shrink-0">
                        <a href="?logout=true" class="text-slate-500 hover:text-slate-700 hover:bg-slate-100 text-[10px] font-bold uppercase tracking-widest bg-white px-4 py-2 rounded border border-slate-200 transition-colors shadow-sm inline-block">Disconnect</a>
                    </div>
                </div>

                <?php 
                // =====================================================================
                // IF STUDENT HAS ALREADY TAKEN THE EXAM (SHOW RESULTS)
                // =====================================================================
                if ($exam_result_data): 
                    $score = $exam_result_data['score'];
                    $total_questions = $exam_result_data['total_questions'];
                    $status = $exam_result_data['status'];
                    $deadline_date = date('l, F j, Y', strtotime($exam_result_data['time_finished'] . ' + 14 days'));
                    
                    $req_q = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'enrollment_requirements'");
                    $req_text = ($req_q && $req_q->num_rows > 0) ? $req_q->fetch_assoc()['setting_value'] : "Please contact the Admissions Office for requirements.";
                    $requirements_array = array_filter(array_map('trim', explode("\n", $req_text)));
                ?>
                    <div class="animate-up delay-1">
                        <?php if ($status === 'Passed'): ?>
                            <div class="text-center mb-8">
                                <div class="w-16 h-16 bg-white text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-4 border border-slate-200 shadow-sm">
                                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                </div>
                                <h1 class="text-2xl font-black text-slate-800 font-academic uppercase tracking-tight mb-1">Examination <span class="text-emerald-600">Passed</span></h1>
                                <p class="text-slate-400 font-bold text-[10px] uppercase tracking-widest">Score is strictly confidential</p>
                            </div>
                            
                            <div class="bg-slate-50 border border-slate-200 rounded-lg p-6 md:p-8 text-left mb-6">
                                <p class="text-sm text-slate-700 leading-relaxed mb-6 font-medium">
                                    Congratulations, <strong class="text-[#00205b]"><?= htmlspecialchars($_SESSION['student_name']) ?></strong>! You have successfully met the qualifications to enroll at Lyceum de San Pablo.
                                </p>
                                <h4 class="text-slate-800 font-bold text-xs uppercase tracking-widest mb-4 border-b border-slate-200 pb-2">
                                    Next Steps: Enrollment Requirements
                                </h4>
                                <p class="text-xs text-slate-600 mb-3 font-medium">Please bring the following physical documents to the Admissions Office:</p>
                                
                                <ul class="text-sm text-slate-700 space-y-2 list-disc pl-5 marker:text-slate-400 mb-6">
                                    <?php foreach ($requirements_array as $req): ?>
                                        <li><?= htmlspecialchars($req) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                
                                <div class="bg-white border border-slate-200 p-4 rounded-lg flex flex-col sm:flex-row sm:justify-between sm:items-center gap-2">
                                    <span class="text-[10px] text-slate-500 font-bold uppercase tracking-widest">Submission Deadline</span>
                                    <span class="text-[#00205b] font-bold text-sm"><?= $deadline_date ?></span>
                                </div>
                            </div>

                        <?php else: ?>
                            <div class="text-center mb-8">
                                <div class="w-16 h-16 bg-white text-rose-600 rounded-full flex items-center justify-center mx-auto mb-4 border border-slate-200 shadow-sm">
                                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </div>
                                <h1 class="text-2xl font-black text-slate-800 font-academic uppercase tracking-tight mb-1">Examination <span class="text-rose-600">Failed</span></h1>
                                <p class="text-slate-400 font-bold text-[10px] uppercase tracking-widest">Score is strictly confidential</p>
                            </div>
                            
                            <div class="bg-slate-50 border border-slate-200 rounded-lg p-6 md:p-8 text-left mb-6">
                                <p class="text-sm text-slate-700 leading-relaxed mb-4">
                                    Dear <strong class="text-[#00205b]"><?= htmlspecialchars($_SESSION['student_name']) ?></strong>, we appreciate the time and effort you put into taking the examination. 
                                    Unfortunately, your score did not meet the minimum passing threshold required for admission to Lyceum de San Pablo for this academic year. 
                                </p>
                                <p class="text-sm text-slate-600 italic">We wish you the very best in your future academic endeavors.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php 
                // =====================================================================
                // IF STUDENT HAS NOT TAKEN EXAM YET (SHOW START FORM)
                // =====================================================================
                else: ?>
                    <div class="bg-slate-50 border border-slate-200 rounded-lg p-5 mb-8 text-center animate-up delay-1">
                        <h3 class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-2 flex items-center justify-center gap-1.5">
                            <svg class="w-3.5 h-3.5 <?= $can_take ? 'text-[#00205b]' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            System Status
                        </h3>
                        <p class="text-sm leading-relaxed text-slate-800 font-medium">
                            <?= $gate_status ?>
                        </p>
                    </div>

                    <?php if ($can_take && $exam_data): ?>
                        <div class="border border-slate-200 rounded-lg p-6 mb-8 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 animate-up delay-2">
                            <div>
                                <p class="text-[10px] text-slate-500 mb-1 font-bold uppercase tracking-widest">Selected Module</p>
                                <h4 class="font-bold text-[#00205b] text-base uppercase tracking-tight"><?= htmlspecialchars($exam_data['exam_title']) ?></h4>
                            </div>
                            <div class="text-left sm:text-right bg-slate-50 px-4 py-2.5 rounded border border-slate-200 w-full sm:w-auto">
                                <span class="block text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-0.5">Time Limit</span>
                                <span class="font-mono text-slate-800 text-lg font-bold"><?= $exam_data['duration_minutes'] ?> <span class="text-[10px] text-slate-500 uppercase tracking-widest ml-0.5">Mins</span></span>
                            </div>
                        </div>

                        <div class="mb-8 animate-up delay-3">
                            <h4 class="text-slate-800 font-bold uppercase tracking-widest text-[11px] mb-4 border-b border-slate-200 pb-2">
                                Examination Rules & Guidelines
                            </h4>
                            <ul class="text-sm text-slate-600 space-y-4 list-none mb-6">
                                <li class="flex items-start gap-3"><svg class="w-5 h-5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> <span><strong class="text-slate-800 font-semibold">Stable Connection:</strong> Ensure your internet connection is stable before proceeding. Do not close your browser.</span></li>
                                <li class="flex items-start gap-3"><svg class="w-5 h-5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> <span><strong class="text-slate-800 font-semibold">Time Constraint:</strong> The timer begins immediately. If time expires, the system will auto-submit your current answers.</span></li>
                                <li class="flex items-start gap-3"><svg class="w-5 h-5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 00-2-2H6a2 2 0 00-2-2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg> <span><strong class="text-slate-800 font-semibold">Anti-Cheat Active:</strong> Navigating to other tabs or refreshing the page may void your attempt immediately.</span></li>
                            </ul>
                            
                            <div class="bg-slate-50 p-4 rounded-lg border border-slate-200">
                                <label class="flex items-center gap-3 cursor-pointer group w-fit">
                                    <input type="checkbox" id="agree_rules" class="custom-checkbox shrink-0" onchange="toggleExamButton()">
                                    <span class="text-xs font-bold text-slate-600 group-hover:text-[#00205b] transition-colors select-none uppercase tracking-wider">I acknowledge and agree to the rules.</span>
                                </label>
                            </div>
                        </div>

                        <form action="take_exam.php" method="POST" class="animate-up delay-4">
                            <input type="hidden" name="exam_id" value="<?= $exam_data['id'] ?>">
                            <button type="submit" name="start_exam" id="start_exam_btn" disabled class="btn-primary w-full">
                                Awaiting Consent...
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>

            </div>
        <?php endif; ?>

    </div>

    <script>
        function toggleExamButton() {
            const cb = document.getElementById('agree_rules');
            const btn = document.getElementById('start_exam_btn');
            
            if(cb && btn) {
                if(cb.checked) {
                    btn.disabled = false;
                    btn.innerHTML = "Initialize Examination <svg class='w-4 h-4 opacity-90' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M14 5l7 7m0 0l-7 7m7-7H3' /></svg>";
                } else {
                    btn.disabled = true;
                    btn.innerHTML = "Awaiting Consent...";
                }
            }
        }
    </script>
</body>
</html>