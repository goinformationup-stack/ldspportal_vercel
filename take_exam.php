<?php
// 1. SESSION HANDLER
$session_lifetime = 60 * 60 * 24 * 30; 
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params($session_lifetime, '/');

session_start();
include "dbconn.php";
date_default_timezone_set('Asia/Manila');

// 2. STRICT SECURITY VERIFICATION
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student' || !isset($_SESSION['admission_number'])) {
    die("<div style='padding:40px; font-family:sans-serif; text-align:center; color:#1f2937;'><h2>Unauthorized Access</h2><a href='admission_form_registration/admission_portal.php' style='color:#00205b; font-weight:bold; text-decoration:none;'>Return to Portal</a></div>");
}

$adm_no = mysqli_real_escape_string($conn, $_SESSION['admission_number']);

// Get Exam ID
$exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : (isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0);
if ($exam_id === 0) {
    die("<div style='padding:40px; font-family:sans-serif; text-align:center; color:#1f2937;'><h2>Error: No Examination Module Selected.</h2></div>");
}

// Fetch Exam Details
$exam_q = $conn->query("SELECT * FROM entrance_exams WHERE id = $exam_id");
if ($exam_q->num_rows == 0) {
    die("<div style='padding:40px; font-family:sans-serif; text-align:center; color:#1f2937;'><h2>Error: Examination not found.</h2></div>");
}
$exam_data = $exam_q->fetch_assoc();

// --- ATTEMPT LIMIT & COOLDOWN ENGINE ---
$ay_q = $conn->query("SELECT setting_value FROM portal_settings WHERE setting_key = 'academic_year'");
$current_ay = ($ay_q && $ay_q->num_rows > 0) ? $ay_q->fetch_assoc()['setting_value'] : date('Y').'-'.(date('Y')+1);

$check_taken = $conn->query("SELECT * FROM exam_results WHERE student_id = '$adm_no' AND academic_year = '$current_ay' ORDER BY time_finished DESC");
$attempts = $check_taken->num_rows;

$has_passed = false;
$last_date = '';
while($row = $check_taken->fetch_assoc()) {
    if ($row['status'] == 'Passed') $has_passed = true;
    if ($last_date == '') $last_date = date('Y-m-d', strtotime($row['time_finished']));
}

if ($has_passed) {
    die("<div style='padding:40px; font-family:sans-serif; text-align:center; color:#1f2937;'><h2 style='margin-bottom:20px;'>You have already passed this examination.</h2><a href='track_status.php?adm_no=" . urlencode($adm_no) . "' style='padding:12px 24px; background:#00205b; color:white; text-decoration:none; border-radius:6px; font-weight:bold;'>Track Enrollment Progress</a></div>");
}

// Changed to 2 attempts max
if ($attempts >= 2) {
    die("<div style='padding:40px; font-family:sans-serif; text-align:center; color:#1f2937;'><h2 style='margin-bottom:20px;'>You have exhausted all attempts for this academic year.</h2><a href='student_exam_auth.php?logout=true' style='padding:12px 24px; background:#00205b; color:white; text-decoration:none; border-radius:6px; font-weight:bold;'>Return to Dashboard</a></div>");
}

// 3. EXAM SUBMISSION LOGIC
$is_finished = false;
$status = "";
$total_attempts_now = $attempts;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_exam'])) {
    $score = 0;
    
    // Fetch Correct Answers
    $q_res = $conn->query("SELECT id, correct_option FROM exam_questions WHERE exam_id = $exam_id");
    $total_questions = $q_res->num_rows;

    // Grade the answers.
    while ($row = $q_res->fetch_assoc()) {
        $qid = $row['id'];
        if (isset($_POST['answer_'.$qid])) {
            $student_answer = strtoupper(trim($_POST['answer_'.$qid]));
            if ($student_answer === strtoupper($row['correct_option'])) {
                $score++;
            }
        }
    }

    $status = ($score >= $exam_data['passing_score']) ? 'Passed' : 'Failed';
    $time_finished = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("INSERT INTO exam_results (student_id, exam_id, score, total_questions, status, time_finished, academic_year) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("siiisss", $adm_no, $exam_id, $score, $total_questions, $status, $time_finished, $current_ay);
    
    if ($stmt->execute()) {
        $is_finished = true;
        $total_attempts_now = $attempts + 1;
    } else {
        die("Fatal Database Error: Could not record exam results.");
    }
}

// 4. FETCH QUESTIONS FOR DISPLAY (AUTO-SHUFFLED PER STUDENT)
if (!$is_finished) {
    $questions = $conn->query("SELECT * FROM exam_questions WHERE exam_id = $exam_id ORDER BY RAND()");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($exam_data['exam_title']) ?> - Active Session</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --ldsp-blue: #00205b;
            --ldsp-blue-light: #003882;
        }
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f4f6f9;
            color: #1f2937; 
            min-height: 100vh;
        }
        .font-academic { font-family: 'Crimson Pro', serif; }

        .clean-panel { 
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 1rem; 
            box-shadow: 0 10px 25px -5px rgba(0, 32, 91, 0.05), 0 8px 10px -6px rgba(0, 32, 91, 0.01);
            position: relative;
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
        
        .btn-outline {
            background: transparent;
            color: var(--ldsp-blue); font-weight: 700; border-radius: 0.5rem; padding: 0.875rem 1.5rem;
            display: inline-flex; justify-content: center; align-items: center; gap: 8px; border: 1px solid var(--ldsp-blue);
            cursor: pointer; transition: all 0.2s ease; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.875rem;
        }
        .btn-outline:hover { background: #f8fafc; }

        .custom-radio input[type="radio"]:checked + div {
            background-color: #f8fafc;
            border-color: var(--ldsp-blue);
            box-shadow: 0 0 0 1px var(--ldsp-blue);
        }
        .custom-radio input[type="radio"]:checked + div span.letter-circle {
            background-color: var(--ldsp-blue);
            color: white;
        }
        
        .timer-sticky {
            position: sticky;
            top: 1rem;
            z-index: 50;
        }
    </style>
</head>
<body class="p-4 md:p-8">

    <!-- INSTANT SUBMITTING OVERLAY -->
    <div id="submittingOverlay" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-white/95 backdrop-blur-sm">
        <div class="flex flex-col items-center">
            <svg class="w-10 h-10 text-[#00205b] animate-spin mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            <p class="text-[#00205b] font-bold tracking-widest uppercase text-sm">Recording Answers...</p>
        </div>
    </div>

    <div class="max-w-3xl mx-auto">
        
        <?php if ($is_finished): ?>
            <!-- CONFIDENTIAL SUCCESS/FAIL SCREEN -->
            <?php if ($status === 'Passed'): ?>
                <div class="clean-panel p-10 text-center border-t-[4px] !border-t-[#00205b] mt-10">
                    <div class="w-16 h-16 bg-white text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-5 border border-slate-200 shadow-sm">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <h1 class="text-2xl md:text-3xl font-black text-slate-800 font-academic uppercase mb-2 tracking-tight">Examination <span class="text-emerald-600">Passed</span></h1>
                    <p class="text-slate-400 font-bold text-[10px] uppercase tracking-widest mb-6">Score is strictly confidential</p>
                    
                    <div class="bg-slate-50 border border-slate-200 p-6 rounded-lg mb-8 text-left">
                        <p class="text-slate-700 text-sm font-medium leading-relaxed">Your answers have been securely submitted. You have successfully met the qualifications for this examination.</p>
                    </div>
                    <a href="track_status.php?adm_no=<?= urlencode($adm_no) ?>" class="btn-primary w-full sm:w-auto">Track Enrollment Progress</a>
                </div>
            <?php else: ?>
                <?php if ($total_attempts_now >= 2): ?>
                    <!-- BURNED ALL 2 ATTEMPTS -->
                    <div class="clean-panel p-10 text-center border-t-[4px] !border-t-[#00205b] mt-10">
                        <div class="w-16 h-16 bg-white text-rose-600 rounded-full flex items-center justify-center mx-auto mb-5 border border-slate-200 shadow-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </div>
                        <h1 class="text-2xl md:text-3xl font-black text-slate-800 font-academic uppercase mb-2 tracking-tight">Examination <span class="text-rose-600">Failed</span></h1>
                        <p class="text-slate-400 font-bold text-[10px] uppercase tracking-widest mb-6">Max Attempts Reached</p>
                        
                        <div class="bg-slate-50 border border-slate-200 p-6 rounded-lg mb-8 text-left">
                            <p class="text-slate-700 text-sm font-medium leading-relaxed">Your answers have been securely submitted. Unfortunately, you did not pass and have exhausted all attempts for this academic year.</p>
                        </div>
                        <a href="student_exam_auth.php?logout=true" class="btn-outline w-full sm:w-auto">Return to Dashboard</a>
                    </div>
                <?php else: ?>
                    <!-- FAILED, BUT HAS RETAKES LEFT -->
                    <div class="clean-panel p-10 text-center border-t-[4px] !border-t-[#00205b] mt-10">
                        <div class="w-16 h-16 bg-white text-rose-600 rounded-full flex items-center justify-center mx-auto mb-5 border border-slate-200 shadow-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <h1 class="text-2xl md:text-3xl font-black text-slate-800 font-academic uppercase mb-2 tracking-tight">Examination <span class="text-rose-600">Failed</span></h1>
                        <p class="text-slate-400 font-bold text-[10px] uppercase tracking-widest mb-6"><?= 2 - $total_attempts_now ?> Retake(s) Remaining</p>
                        
                        <div class="bg-slate-50 border border-slate-200 p-6 rounded-lg mb-8 text-left">
                            <p class="text-slate-700 text-sm font-medium leading-relaxed">Your answers have been securely submitted but did not meet the passing threshold. You may retake the exam immediately.</p>
                        </div>
                        <a href="take_exam.php?exam_id=<?= $exam_id ?>" class="btn-primary w-full sm:w-auto">Retake Exam Now</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <script>
                // Prevent going back
                window.history.pushState(null, "", window.location.href);
                window.onpopstate = function() { window.history.pushState(null, "", window.location.href); };
            </script>
        <?php else: ?>

            <!-- PRE-EXAM BRIEFING SCREEN -->
            <div id="pre_exam_wrapper" class="clean-panel p-8 md:p-12 text-center mt-10">
                <div class="clean-panel-header"></div>
                <div class="w-16 h-16 bg-slate-50 text-slate-500 rounded-full flex items-center justify-center mx-auto mb-5 border border-slate-200">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2-2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </div>
                <h1 class="text-2xl font-black text-[#00205b] font-academic uppercase tracking-tight mb-4">Secure Examination Environment</h1>
                <p class="text-slate-600 text-sm mb-8 max-w-lg mx-auto leading-relaxed">By clicking start, this examination will launch in full-screen mode. Do not attempt to exit full-screen, switch tabs, or use external applications. Doing so will result in the immediate and automatic submission of your exam.</p>
                
                <button type="button" onclick="startExamEnvironment()" class="btn-primary w-full sm:w-auto">
                    I am ready. Start Exam
                </button>
            </div>

            <!-- ACTIVE EXAM INTERFACE (HIDDEN INITIALLY) -->
            <div id="exam_content_wrapper" class="hidden">
                <form id="exam_form" method="POST" action="">
                    <input type="hidden" name="exam_id" value="<?= $exam_id ?>">
                    <input type="hidden" name="submit_exam" value="1">
                    
                    <!-- Header & Sticky GLOBAL Timer -->
                    <div class="timer-sticky bg-white border border-slate-200 rounded-xl p-4 md:p-5 mb-8 flex flex-col sm:flex-row justify-between items-center shadow-sm gap-4">
                        <div>
                            <h1 class="text-lg md:text-xl font-black text-[#00205b] font-academic uppercase tracking-tight"><?= htmlspecialchars($exam_data['exam_title']) ?></h1>
                            <p class="text-[10px] text-slate-500 font-bold uppercase tracking-widest mt-1">Student: <?= htmlspecialchars($_SESSION['student_name']) ?> (<?= $adm_no ?>)</p>
                        </div>
                        <div class="bg-slate-50 border border-slate-200 px-5 py-2.5 rounded-lg flex items-center gap-3">
                            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <div class="text-right">
                                <span class="block text-[9px] text-slate-500 font-bold uppercase tracking-widest leading-none">Time Remaining</span>
                                <span id="timer_display" class="font-mono text-lg font-black text-slate-700 leading-none block mt-0.5">00:00:00</span>
                            </div>
                        </div>
                    </div>

                    <!-- Questions List (FOCUS MODE: One Question at a Time) -->
                    <div class="space-y-6">
                        <?php 
                        $q_counter = 1;
                        $total_q = $questions->num_rows;
                        if ($total_q > 0):
                            while($q = $questions->fetch_assoc()): 
                        ?>
                            <div id="q_block_<?= $q_counter ?>" class="question-block clean-panel p-6 md:p-8 <?= $q_counter > 1 ? 'hidden' : '' ?>" data-time="<?= $q['time_limit_seconds'] ?>">
                                <div class="clean-panel-header"></div>
                                
                                <!-- Per-Question Header & Timer -->
                                <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-100">
                                    <div class="flex items-center gap-3">
                                        <span class="bg-[#00205b] text-white w-8 h-8 flex items-center justify-center rounded font-black text-xs shrink-0"><?= $q_counter ?></span>
                                        <span class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Question <?= $q_counter ?> of <?= $total_q ?></span>
                                    </div>
                                    <div class="flex items-center gap-2 bg-slate-50 text-slate-600 px-3 py-1.5 rounded border border-slate-200 transition-colors duration-300" id="q_timer_container_<?= $q_counter ?>">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                        <span id="q_timer_<?= $q_counter ?>" class="font-mono font-bold text-sm leading-none"><?= $q['time_limit_seconds'] ?>s</span>
                                    </div>
                                </div>

                                <div class="mb-6">
                                    <p class="text-base text-slate-800 leading-relaxed font-medium"><?= nl2br(htmlspecialchars($q['question_text'])) ?></p>
                                    
                                    <?php if (!empty($q['question_image'])): ?>
                                        <div class="mt-4 rounded border border-slate-200 inline-block overflow-hidden p-1">
                                            <img src="<?= htmlspecialchars($q['question_image']) ?>" alt="Question Media" class="max-h-64 object-contain">
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="grid grid-cols-1 gap-3">
                                    <?php foreach(['a', 'b', 'c', 'd'] as $opt): ?>
                                        <label class="custom-radio cursor-pointer group">
                                            <!-- Enforces selection to reveal the 'Next' button -->
                                            <input type="radio" name="answer_<?= $q['id'] ?>" value="<?= strtoupper($opt) ?>" class="hidden" onclick="showNextButton(<?= $q_counter ?>)">
                                            <div class="p-3.5 rounded-lg border border-slate-200 bg-white transition-all flex items-center gap-4 hover:border-slate-300">
                                                <span class="letter-circle bg-slate-100 text-slate-500 w-7 h-7 rounded flex items-center justify-center font-bold text-xs uppercase shrink-0 transition-colors"><?= $opt ?></span>
                                                
                                                <div class="flex-1">
                                                    <span class="text-sm text-slate-700"><?= htmlspecialchars($q['option_'.$opt]) ?></span>
                                                    <?php if (!empty($q['image_'.$opt])): ?>
                                                        <img src="<?= htmlspecialchars($q['image_'.$opt]) ?>" alt="Option Image" class="mt-2 max-h-32 object-contain border border-slate-200 rounded p-1">
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Dynamic Next Button -->
                                <?php if ($q_counter < $total_q): ?>
                                <div class="mt-8 text-right border-t border-slate-100 pt-5">
                                    <button type="button" id="next_btn_<?= $q_counter ?>" onclick="nextQuestionManual(<?= $q_counter ?>)" class="btn-primary hidden">
                                        Next Question &rarr;
                                    </button>
                                </div>
                                <?php endif; ?>

                            </div>
                        <?php 
                            $q_counter++;
                            endwhile; 
                        else:
                        ?>
                            <div class="clean-panel p-10 text-center">
                                <h3 class="text-slate-500 font-bold">No questions found for this examination module.</h3>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Submit Area (Hidden until last question is reached) -->
                    <div id="submit_area" class="hidden mt-8 mb-12 bg-white p-6 rounded-xl border border-slate-200 shadow-sm flex flex-col md:flex-row justify-between items-center gap-4">
                        <p class="text-xs font-bold text-slate-500 uppercase tracking-widest text-center md:text-left">
                            <svg class="w-4 h-4 inline text-[#00205b] mb-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> 
                            End of module reached.
                        </p>
                        <!-- INSTANT EXECUTE SUBMIT -->
                        <button type="button" onclick="executeSubmit()" class="btn-primary w-full md:w-auto">
                            Submit Final Answers
                        </button>
                    </div>
                </form>
            </div>

            <!-- JAVASCRIPT: INSTANT ANTI-CHEAT & TIMER LOGIC -->
            <script>
                // State Variables
                let isExamActive = false;
                let examSubmitted = false;
                let totalSeconds = <?= $exam_data['duration_minutes'] ?> * 60;
                let currentQuestion = 1;
                const totalQuestions = <?= isset($total_q) ? $total_q : 0 ?>;
                let questionTimerInterval;
                let qSeconds = 0;

                function startExamEnvironment() {
                    let elem = document.documentElement;
                    if (elem.requestFullscreen) {
                        elem.requestFullscreen().catch(err => console.warn(err));
                    } else if (elem.webkitRequestFullscreen) { /* Safari */
                        elem.webkitRequestFullscreen();
                    } else if (elem.msRequestFullscreen) { /* IE11 */
                        elem.msRequestFullscreen();
                    }

                    document.getElementById('pre_exam_wrapper').classList.add('hidden');
                    document.getElementById('exam_content_wrapper').classList.remove('hidden');
                    
                    isExamActive = true;
                    updateGlobalTimer();
                    if (totalQuestions > 0) {
                        startQuestionTimer(1);
                    }
                }

                function executeSubmit() {
                    if (examSubmitted) return;
                    examSubmitted = true;
                    document.getElementById('submittingOverlay').classList.remove('hidden');
                    document.getElementById('submittingOverlay').classList.add('flex');
                    document.getElementById('exam_form').submit();
                }

                function triggerViolationSubmit() {
                    if (examSubmitted) return;
                    executeSubmit();
                }

                document.addEventListener('visibilitychange', function() {
                    if (isExamActive && document.hidden && !examSubmitted) {
                        triggerViolationSubmit();
                    }
                });

                window.addEventListener('blur', function() {
                    if (isExamActive && !examSubmitted) {
                        triggerViolationSubmit();
                    }
                });

                document.addEventListener('fullscreenchange', function() {
                    if (isExamActive && !document.fullscreenElement && !examSubmitted) {
                        triggerViolationSubmit();
                    }
                });

                window.history.pushState(null, "", window.location.href);
                window.onpopstate = function() {
                    if(isExamActive && !examSubmitted) {
                        triggerViolationSubmit();
                    } else if (!examSubmitted) {
                        window.history.pushState(null, "", window.location.href); 
                    }
                };

                function updateGlobalTimer() {
                    if (examSubmitted) return;

                    if (totalSeconds <= 0) {
                        document.getElementById('timer_display').innerText = "00:00:00";
                        executeSubmit();
                        return;
                    }

                    let hours = Math.floor(totalSeconds / 3600);
                    let minutes = Math.floor((totalSeconds % 3600) / 60);
                    let seconds = totalSeconds % 60;

                    let hStr = hours < 10 ? "0" + hours : hours;
                    let mStr = minutes < 10 ? "0" + minutes : minutes;
                    let sStr = seconds < 10 ? "0" + seconds : seconds;

                    let display = document.getElementById('timer_display');
                    display.innerText = hStr + ":" + mStr + ":" + sStr;
                    
                    if(totalSeconds <= 60) {
                        display.classList.remove('text-slate-700');
                        display.classList.add('text-rose-600');
                        display.parentElement.parentElement.classList.add('border-rose-200', 'bg-rose-50');
                    }
                    
                    totalSeconds--;
                    setTimeout(updateGlobalTimer, 1000);
                }

                function showNextButton(num) {
                    let btn = document.getElementById('next_btn_' + num);
                    if (btn) btn.classList.remove('hidden');
                    
                    if (num === totalQuestions) {
                        document.getElementById('submit_area').classList.remove('hidden');
                    }
                }

                function startQuestionTimer(num) {
                    if (examSubmitted) return;

                    let block = document.getElementById('q_block_' + num);
                    if (!block) return;
                    
                    clearInterval(questionTimerInterval);
                    qSeconds = parseInt(block.getAttribute('data-time'));
                    updateQTimerDisplay(num);

                    questionTimerInterval = setInterval(() => {
                        qSeconds--;
                        updateQTimerDisplay(num);
                        if (qSeconds <= 0) {
                            clearInterval(questionTimerInterval);
                            autoAdvance(num);
                        }
                    }, 1000);
                }

                function updateQTimerDisplay(num) {
                    let el = document.getElementById('q_timer_' + num);
                    if(el) {
                        el.innerText = qSeconds + "s";
                        if(qSeconds <= 10) {
                            el.classList.add('text-rose-600');
                            let container = document.getElementById('q_timer_container_' + num);
                            if(container) {
                                container.classList.replace('bg-slate-50', 'bg-rose-50');
                                container.classList.replace('text-slate-600', 'text-rose-600');
                                container.classList.replace('border-slate-200', 'border-rose-200');
                            }
                        }
                    }
                }

                function autoAdvance(num) {
                    if (examSubmitted) return;

                    if (num < totalQuestions) {
                        document.getElementById('q_block_' + num).classList.add('hidden');
                        currentQuestion++;
                        document.getElementById('q_block_' + currentQuestion).classList.remove('hidden');
                        startQuestionTimer(currentQuestion);
                    } else {
                        executeSubmit();
                    }
                }
                
                function nextQuestionManual(num) {
                    if (num < totalQuestions) {
                        document.getElementById('q_block_' + num).classList.add('hidden');
                        currentQuestion++;
                        document.getElementById('q_block_' + currentQuestion).classList.remove('hidden');
                        startQuestionTimer(currentQuestion);
                    }
                }
            </script>
            
        <?php endif; ?>
    </div>
</body>
</html>