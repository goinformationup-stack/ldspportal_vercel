<?php 
include "dbconn.php"; 
date_default_timezone_set('Asia/Manila');

$search_result = null;
$adm_no = "";
$success_msg = "";
$error_msg = "";
$exam_result = null;
$exam_attempts = 0;

$ay_q = $conn->query("SELECT setting_value FROM portal_settings WHERE setting_key = 'academic_year'");
$current_ay = ($ay_q && $ay_q->num_rows > 0) ? $ay_q->fetch_assoc()['setting_value'] : date('Y').'-'.(date('Y')+1);

// --- FETCH PHYSICAL ENROLLMENT REQUIREMENTS ---
$req_query = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'enrollment_requirements'");
$physical_reqs = ($req_query && $req_query->num_rows > 0) ? $req_query->fetch_assoc()['setting_value'] : "Form 138 (Original Report Card)\nPSA Birth Certificate (Photocopy)\nCertificate of Good Moral Character\nTwo (2) pieces 2x2 ID Picture";

// --- HANDLE DOCUMENT RESUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resubmit_docs'])) {
    $adm_no = $conn->real_escape_string($_POST['adm_no']);
    
    $q = $conn->query("SELECT id, uploaded_files, document_statuses FROM admissions WHERE admission_number = '$adm_no'");
    if ($q && $q->num_rows > 0) {
        $row = $q->fetch_assoc();
        
        $existing_files = explode(',', $row['uploaded_files']);
        $file_2x2_path = $existing_files[0] ?? '';
        $file_psa_path = $existing_files[1] ?? '';
        
        $doc_statuses = json_decode($row['document_statuses'], true) ?: [];
        
        $target_dir = "uploads/admissions/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        
        $updated = false;
        
        if (isset($_FILES['file_2x2']) && $_FILES['file_2x2']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['file_2x2']['name'], PATHINFO_EXTENSION));
            $filename = $adm_no . "_file_2x2_resubmit_" . time() . "." . $ext;
            if (move_uploaded_file($_FILES['file_2x2']['tmp_name'], $target_dir . $filename)) {
                $file_2x2_path = $target_dir . $filename;
                $doc_statuses['0'] = ['status' => 'pending', 'reason' => '']; 
                $updated = true;
            }
        }
        
        if (isset($_FILES['file_psa']) && $_FILES['file_psa']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['file_psa']['name'], PATHINFO_EXTENSION));
            $filename = $adm_no . "_file_psa_resubmit_" . time() . "." . $ext;
            if (move_uploaded_file($_FILES['file_psa']['tmp_name'], $target_dir . $filename)) {
                $file_psa_path = $target_dir . $filename;
                $doc_statuses['1'] = ['status' => 'pending', 'reason' => '']; 
                $updated = true;
            }
        }
        
        if ($updated) {
            $new_files_str = $conn->real_escape_string($file_2x2_path . ',' . $file_psa_path);
            $new_statuses_str = $conn->real_escape_string(json_encode($doc_statuses));
            
            $conn->query("UPDATE admissions SET uploaded_files = '$new_files_str', document_statuses = '$new_statuses_str', status = 'Pending', denial_reason = NULL WHERE admission_number = '$adm_no'");
            $success_msg = "Documents successfully updated! Your application is back under review.";
        } else {
            $error_msg = "No new files were selected. Please replace at least one document to resubmit.";
        }
    }
}

// --- HANDLE SEARCH & EXAM RESULT FETCHING ---
if (isset($_GET['adm_no']) || !empty($adm_no)) {
    $adm_no = $adm_no ?: mysqli_real_escape_string($conn, $_GET['adm_no']);
    $query = $conn->query("SELECT * FROM admissions WHERE admission_number = '$adm_no'");
    
    if ($query && $query->num_rows > 0) {
        $search_result = $query->fetch_assoc();
        
        if ($search_result['status'] === 'Accepted') {
            $exam_q = $conn->query("SELECT status, time_finished FROM exam_results WHERE student_id = '$adm_no' AND academic_year = '$current_ay' ORDER BY time_finished DESC");
            if ($exam_q) {
                $exam_attempts = $exam_q->num_rows;
                if ($exam_attempts > 0) {
                    $exam_result = $exam_q->fetch_assoc(); 
                    
                    // Explicit check if passed in ANY prior attempt to override "latest fail" status
                    $pass_check = $conn->query("SELECT id FROM exam_results WHERE student_id = '$adm_no' AND academic_year = '$current_ay' AND status = 'Passed'");
                    if ($pass_check && $pass_check->num_rows > 0) {
                        $exam_result['status'] = 'Passed';
                    }
                }
            }
        }
    } else {
        $search_result = "not_found";
    }
}

// =========================================================================
// HTML BUFFERING FOR LIVE AJAX SYNC
// =========================================================================
ob_start();

if (!$search_result): ?>
    <div class="glossy-panel p-8 sm:p-12 text-center shadow-[0_20px_50px_rgba(0,32,91,0.12)] animate-up delay-1 mx-auto max-w-2xl">
        <div class="glossy-panel-header"></div>
        
        <div class="flex justify-between items-center mb-8 border-b border-slate-100 pb-6 relative z-20">
            <a href="admission_form_registration/admission_portal.php" class="text-slate-500 hover:text-[#00205b] text-[10px] font-black uppercase tracking-widest inline-flex items-center gap-2 transition-colors bg-white px-4 py-2 rounded-lg border border-slate-200 shadow-sm hover:shadow-md hover:-translate-y-0.5 animate-up w-full sm:w-auto justify-center">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M15 19l-7-7 7-7" /></svg> Back to Portal
            </a>
        </div>

        <div class="inline-block mb-6 relative group">
            <div class="absolute inset-0 bg-gradient-to-r from-[#00205b] to-[#c5a02c] rounded-full blur-xl opacity-10 transition-opacity duration-500"></div>
            <img src="logo.jpg" alt="LDSP Logo" onerror="this.onerror=null; this.src='../logo.jpg';" class="relative w-20 h-20 object-contain mx-auto drop-shadow-md rounded-full bg-white p-1 border border-slate-100">
        </div>

        <h1 class="text-3xl sm:text-4xl font-black text-[#00205b] font-academic uppercase tracking-tight mb-8 drop-shadow-sm">Track <span class="text-[#c5a02c]">Application</span></h1>
        
        <form method="GET" class="space-y-5 relative z-20">
            <input type="text" name="adm_no" required placeholder="ADM-2026-XXXXXX" class="input-glossy-smooth !text-lg !py-4 shadow-inner placeholder:font-sans placeholder:normal-case placeholder:font-medium placeholder:tracking-normal w-full text-center tracking-widest">
            <button type="submit" class="btn-glossy-animated shadow-[0_8px_20px_-6px_rgba(0,32,91,0.5)] hover:shadow-[0_12px_25px_-8px_rgba(0,32,91,0.6)] !py-4 w-full">Search Records</button>
        </form>
    </div>

<?php elseif ($search_result === "not_found"): ?>
    <div class="glossy-panel p-8 sm:p-12 text-center shadow-[0_20px_50px_rgba(0,32,91,0.12)] animate-up delay-1 mx-auto max-w-2xl bg-white">
        <div class="glossy-panel-header"></div>
        
        <div class="flex justify-start items-center mb-8 relative z-20">
            <a href="admission_form_registration/admission_portal.php" class="text-slate-500 hover:text-[#00205b] text-[10px] font-black uppercase tracking-widest inline-flex items-center gap-2 transition-colors bg-white px-4 py-2 rounded-lg border border-slate-200 shadow-sm hover:shadow-md hover:-translate-y-0.5 animate-up">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M15 19l-7-7 7-7" /></svg> Back to Portal
            </a>
        </div>

        <div class="w-16 h-16 bg-rose-50 border border-rose-100 text-rose-500 rounded-full flex items-center justify-center mx-auto mb-6 shadow-sm">
            <svg class="w-8 h-8 drop-shadow-sm" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
        </div>
        
        <h2 class="text-3xl font-black text-[#00205b] font-academic uppercase tracking-tight mb-3 drop-shadow-sm">Record <span class="text-rose-500">Not Found</span></h2>
        
        <p class="text-slate-500 text-sm mb-10 font-medium break-words">
            The Admission Number <span class="text-[#00205b] font-mono font-black drop-shadow-sm whitespace-nowrap bg-slate-50 px-2 py-1 rounded border border-slate-200"><?= htmlspecialchars($adm_no) ?></span> does not exist in our system.
        </p>
        
        <a href="track_status.php" class="text-[#00205b] font-black text-[11px] uppercase tracking-widest hover:text-[#003882] transition-colors pb-1 flex items-center justify-center gap-2 group">
            Try Another Number 
            <svg class="w-3.5 h-3.5 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M9 5l7 7-7 7"/></svg>
        </a>
    </div>

<?php else: ?>
    <?php 
        $status_color = "amber";
        $doc_status_badge = '<span class="bg-amber-100 text-amber-700 px-2.5 py-1 rounded text-[9px] uppercase tracking-widest font-black shadow-sm whitespace-nowrap">Under Review</span>';
        $display_status_text = "Pending Review";
        
        if ($search_result['status'] == 'Accepted') { 
            $status_color = "emerald"; 
            $doc_status_badge = '<span class="bg-emerald-100 text-emerald-700 px-2.5 py-1 rounded text-[9px] uppercase tracking-widest font-black shadow-sm whitespace-nowrap">Verified & Approved</span>';
            $display_status_text = "Accepted";
        }
        if ($search_result['status'] == 'Denied') { 
            $status_color = "amber"; 
            $doc_status_badge = '<span class="bg-amber-100 text-amber-700 px-2.5 py-1 rounded text-[9px] uppercase tracking-widest font-black shadow-sm whitespace-nowrap">Action Required</span>';
            $display_status_text = "Update Required";
        }

        $fn = $search_result['first_name'] ?? '';
        $mn = $search_result['middle_name'] ?? '';
        $ln = $search_result['last_name'] ?? '';
        $display_fullname = trim("$ln, $fn $mn");
        if (empty($display_fullname)) {
            $display_fullname = $search_result['fullname'] ?? 'Unknown Applicant';
        }

        $files = explode(',', $search_result['uploaded_files'] ?? '');
        $doc_labels = ["2x2 ID Photo", "PSA Birth Certificate"];
        $doc_statuses = json_decode($search_result['document_statuses'], true) ?: [];
    ?>
    <div class="glossy-panel p-6 md:p-10 border-t-[6px] !border-t-[#00205b] shadow-[0_20px_50px_rgba(0,32,91,0.12)] animate-up delay-1 mx-auto max-w-3xl bg-white">
        
        <!-- Header Actions -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8 border-b border-slate-100 pb-5 relative z-20">
            <a href="admission_form_registration/admission_portal.php" class="text-slate-500 hover:text-[#00205b] text-[10px] font-black uppercase tracking-widest inline-flex items-center gap-2 transition-colors bg-white px-4 py-2 rounded-lg border border-slate-200 shadow-sm hover:shadow-md hover:-translate-y-0.5 w-full sm:w-auto justify-center">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M15 19l-7-7 7-7" /></svg> Back to Portal
            </a>
            <form method="GET" class="flex items-stretch w-full sm:w-[320px] shadow-sm rounded-lg overflow-hidden border border-slate-200 bg-white min-w-0">
                <input type="text" name="adm_no" required placeholder="ADM-2026-XXXX" class="flex-1 px-3 py-2 text-xs font-bold text-[#00205b] font-mono uppercase focus:outline-none placeholder:text-slate-400 placeholder:font-sans placeholder:normal-case min-w-0">
                <button type="submit" class="bg-[#00205b] text-white px-4 py-2 text-[10px] font-black uppercase tracking-widest hover:bg-[#003882] transition-colors shrink-0">Search</button>
            </form>
        </div>

        <!-- Identity Display -->
        <div class="mb-8 relative z-20 text-center sm:text-left">
            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-1 drop-shadow-sm">Application For</span>
            <h2 class="text-2xl md:text-3xl font-black text-[#00205b] font-academic uppercase tracking-tight drop-shadow-sm break-words"><?= htmlspecialchars($display_fullname) ?></h2>
            
            <div class="inline-block cursor-pointer hover:opacity-80 transition-opacity mt-1.5 p-1 -ml-1 rounded group/copy" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($search_result['admission_number']) ?>'); window.showToast('Tracking number copied!', 'success');" title="Tap to copy">
                <p class="text-[12px] sm:text-xs font-mono text-[#00205b] font-black tracking-wider drop-shadow-sm whitespace-nowrap flex items-center justify-center sm:justify-start gap-1.5">
                    <span style="-webkit-user-select: all; user-select: all; word-break: keep-all;"><?= htmlspecialchars($search_result['admission_number']) ?></span>
                    <svg class="w-3.5 h-3.5 text-[#00205b] opacity-70 group-hover/copy:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                </p>
            </div>
        </div>

        <div class="space-y-6 relative z-20">
            <!-- Status Banner -->
            <div class="p-6 rounded-2xl bg-<?= $status_color ?>-50/80 border border-<?= $status_color ?>-200 text-center shadow-sm relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-<?= $status_color ?>-400 rounded-full blur-3xl opacity-10 pointer-events-none"></div>
                <p class="text-[10px] font-black text-<?= $status_color ?>-700 uppercase tracking-[0.2em] mb-1.5 drop-shadow-sm relative z-10">Current Status</p>
                <p class="text-3xl font-black text-<?= $status_color ?>-600 uppercase tracking-tight font-academic drop-shadow-sm relative z-10 break-words"><?= htmlspecialchars($display_status_text) ?></p>
            </div>

            <?php if ($search_result['status'] == 'Pending'): ?>
                <div class="bg-slate-50 p-6 rounded-2xl border border-slate-200 shadow-sm text-center">
                    <svg class="w-8 h-8 mx-auto text-amber-500 mb-3 animate-pulse drop-shadow-sm" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <p class="text-[11px] sm:text-xs text-slate-600 font-medium leading-relaxed italic px-2">"Your application is currently under manual review by the Admission Committee. Please check back in 24-48 hours."</p>
                </div>

            <?php elseif ($search_result['status'] == 'Accepted'): ?>
                <!-- If Accepted, Check Exam Results First -->
                <?php if ($exam_result && $exam_result['status'] === 'Passed'): ?>
                    
                    <!-- EXAM PASSED: SHOW CONGRATS + PREMIUM ENROLLMENT TRACKER -->
                    <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm mb-6 animate-up">
                        
                        <div class="bg-[#00205b]/5 p-4 sm:p-5 rounded-xl border border-[#00205b]/10 text-xs text-[#00205b] font-medium leading-relaxed shadow-sm mb-8 text-left flex items-start gap-3">
                            <span class="text-lg leading-none">🎉</span>
                            <div>
                                <strong class="font-black text-[#00205b]">Congratulations!</strong> You have successfully passed the entrance examination. Please review your enrollment progress below.
                            </div>
                        </div>
                        
                        <!-- REAL-TIME ENROLLMENT PROGRESS TRACKER (MODERN SAAS STYLE) -->
                        <?php 
                            // --- EVALUATE REAL-TIME ENROLLMENT PROGRESS ---
                            $er_status = null;
                            $active_step = 1;
                            $provisioned_user_id = null;
                            
                            if (isset($search_result['provisioned_user_id']) && $search_result['provisioned_user_id'] > 0) {
                                $provisioned_user_id = (int)$search_result['provisioned_user_id'];
                                
                                // Fetch their latest enrollment request status
                                $er_q = $conn->query("SELECT final_status FROM enrollment_requests WHERE user_id = $provisioned_user_id ORDER BY id DESC LIMIT 1");
                                if ($er_q && $er_q->num_rows > 0) {
                                    $er_status = $er_q->fetch_assoc()['final_status'];
                                }
                                
                                // Step mapping
                                if ($er_status === 'Enrolled') {
                                    $active_step = 5; // All steps completed
                                } elseif ($er_status === 'Pending Registrar') {
                                    $active_step = 3; // Step 3: Registrar
                                } else {
                                    $active_step = 2; // Step 2: Accounting (Pending Payment / Verification)
                                }
                            }
                            
                            $real_steps = [
                                ['title' => 'Admissions', 'desc' => 'Requirements & Setup'],
                                ['title' => 'Accounting', 'desc' => 'Fee Verification'],
                                ['title' => 'Registrar', 'desc' => 'Subject Evaluation'],
                                ['title' => 'Enrolled', 'desc' => 'Digital COR Ready']
                            ];
                            $step_count = count($real_steps);
                        ?>
                        <div class="bg-white p-2 rounded-xl relative mt-2">
                            <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-8 text-center drop-shadow-sm">Live Campus Enrollment Progress</h4>
                            
                            <div class="w-full overflow-x-auto custom-scrollbar pb-8 pt-2 px-2">
                                <div class="relative flex flex-row items-start justify-between min-w-max w-full mx-auto gap-8 sm:gap-14">
                                    
                                    <!-- Connecting Line Background -->
                                    <div class="absolute left-8 right-8 top-[16px] h-[3px] bg-slate-100 rounded z-0"></div>
                                    
                                    <!-- Connecting Line Fill -->
                                    <?php 
                                        $fill_width = ($step_count > 1) ? ((min($active_step, $step_count) - 1) / ($step_count - 1)) * 100 : 0;
                                        if ($active_step > $step_count) $fill_width = 100;
                                    ?>
                                    <div class="absolute left-8 top-[16px] h-[3px] bg-[#00205b] rounded z-0 step-line transition-all duration-1000" style="width: calc(<?= $fill_width ?>% - 64px);"></div>

                                    <?php foreach ($real_steps as $index => $step): 
                                        $step_num = $index + 1;
                                        $is_completed = ($step_num < $active_step) || ($active_step > $step_count);
                                        $is_current = ($step_num == $active_step);
                                        $is_admissions = ($step_num == 1);
                                        
                                        if ($is_completed) {
                                            $icon_class = "border-[#00205b] bg-[#00205b] text-white shadow-md";
                                            $text_class = "text-[#00205b] font-black";
                                            $icon_content = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>';
                                        } elseif ($is_current) {
                                            $icon_class = "border-[#c5a02c] bg-white text-[#c5a02c] shadow-[0_0_0_4px_rgba(197,160,44,0.2)] animate-pulse";
                                            $text_class = "text-[#00205b] font-black";
                                            $icon_content = $step_num;
                                        } else {
                                            $icon_class = "border-slate-200 bg-slate-50 text-slate-400";
                                            $text_class = "text-slate-400 font-bold";
                                            $icon_content = $step_num;
                                        }

                                        $cursor_class = $is_admissions ? "relative group/btn z-20" : "relative z-10";
                                    ?>
                                    <div class="flex flex-col items-center w-24 sm:w-32 shrink-0 <?= $cursor_class ?>">
                                        <div class="w-8 h-8 sm:w-9 sm:h-9 shrink-0 rounded-full flex items-center justify-center font-bold text-[11px] border-[2px] step-circle <?= $icon_class ?>">
                                            <?= $icon_content ?>
                                        </div>
                                        <div class="mt-3 text-center w-full px-1">
                                            <p class="text-[9px] sm:text-[10px] uppercase tracking-wider <?= $text_class ?> leading-tight break-words">
                                                <?= htmlspecialchars($step['title']) ?>
                                            </p>
                                            <p class="text-[7px] sm:text-[8px] text-slate-500 mt-1.5 leading-tight font-medium opacity-80 whitespace-normal break-words">
                                                <?= htmlspecialchars($step['desc']) ?>
                                            </p>
                                            
                                            <!-- EXPLICIT CLICKABLE INDICATOR FOR ADMISSIONS (Step 1) -->
                                            <?php if ($is_admissions): ?>
                                                <button type="button" onclick="window.openReqModal()" class="mt-3 relative inline-flex items-center justify-center gap-1.5 px-3 py-1.5 bg-gradient-to-r from-rose-500 to-rose-600 text-white rounded-full shadow-[0_4px_10px_rgba(225,29,72,0.3)] hover:scale-105 transition-transform animate-bounce cursor-pointer group">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                                    <span class="text-[8px] font-black uppercase tracking-widest whitespace-nowrap">View Reqs!</span>
                                                    <!-- Glowing ping effect -->
                                                    <span class="absolute inset-0 rounded-full ring-2 ring-rose-400 animate-ping opacity-20"></span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Contextual Action Buttons -->
                            <?php if ($active_step > $step_count): ?>
                                <div class="mt-4 p-4 bg-[#00205b]/5 border border-[#00205b]/10 rounded-lg text-center animate-up">
                                    <p class="text-[#00205b] font-black text-xs uppercase tracking-widest mb-1">Congratulations, you are officially enrolled!</p>
                                    <a href="../student/index.php" class="inline-flex items-center gap-2 bg-[#00205b] hover:bg-[#003882] text-white px-5 py-2.5 rounded-lg text-[10px] font-black uppercase tracking-widest shadow-md transition-colors">
                                        Access Student Portal <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                                    </a>
                                </div>
                            <?php elseif ($active_step == 2): ?>
                                <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg text-center animate-up">
                                    <p class="text-[#00205b] font-black text-xs uppercase tracking-widest mb-1">Student Account Provisioned</p>
                                    <p class="text-slate-600 text-[10px] font-medium mb-4">Please log in to your Student Portal to process your payment and proceed.</p>
                                    <a href="../student/index.php" class="inline-flex items-center gap-2 bg-[#00205b] hover:bg-[#003882] text-white px-5 py-2.5 rounded-lg text-[10px] font-black uppercase tracking-widest shadow-md transition-colors">
                                        Go To Student Portal <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- REQUIREMENTS MODAL -->
                    <div id="requirementsModal" class="fixed inset-0 z-[9999] hidden items-center justify-center p-4 modal-overlay bg-slate-900/70 backdrop-blur-sm transition-opacity">
                        <div class="bg-white rounded-2xl shadow-2xl border-t-4 border-t-[#00205b] w-full max-w-md modal-content relative overflow-hidden text-left">
                            <div class="p-6 border-b border-slate-200/60 bg-white">
                                <h3 class="font-black text-[#00205b] text-lg flex items-center gap-3">
                                    <svg class="w-5 h-5 text-[#c5a02c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    Required Documents
                                </h3>
                            </div>
                            <div class="p-6 bg-white space-y-4">
                                <ul class="text-[15px] font-medium text-slate-700 space-y-3 list-decimal list-inside ml-2">
                                    <?php 
                                    $req_lines = explode("\n", trim($physical_reqs));
                                    foreach ($req_lines as $line) {
                                        if (!empty(trim($line))) {
                                            echo "<li><span class='text-slate-800 ml-1'>" . htmlspecialchars(trim($line)) . "</span></li>";
                                        }
                                    }
                                    ?>
                                </ul>
                            </div>
                            <div class="p-4 border-t border-slate-100 bg-slate-50 text-center flex justify-end">
                                <button type="button" onclick="closeReqModal()" class="bg-[#00205b] hover:bg-[#003882] text-white px-6 py-2.5 rounded-lg text-xs font-bold uppercase tracking-widest shadow-md transition-colors cursor-pointer">Got It</button>
                            </div>
                        </div>
                    </div>

                <?php elseif ($exam_result && $exam_result['status'] === 'Failed'): ?>
                    <?php if ($exam_attempts >= 2): ?>
                        <!-- FAILED: MAX ATTEMPTS REACHED -->
                        <div class="bg-rose-50 p-6 md:p-8 rounded-2xl border border-rose-200 shadow-sm mb-6 animate-up text-center mx-4 sm:mx-0">
                            <div class="w-16 h-16 bg-rose-100 text-rose-600 rounded-full flex items-center justify-center mx-auto mb-4 border border-rose-200 shadow-sm">
                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </div>
                            <h4 class="text-rose-700 font-black uppercase text-[12px] tracking-widest mb-2 drop-shadow-sm">Message from Admissions</h4>
                            <p class="text-sm text-slate-600 font-medium leading-relaxed max-w-md mx-auto break-words">
                                Dear <?= htmlspecialchars($display_fullname) ?>, unfortunately, your examination score did not meet the minimum requirements for admission. You have exhausted all 2 attempts for the <strong>SY <?= htmlspecialchars($current_ay) ?></strong> academic year. We wish you the best in your future endeavors.
                            </p>
                        </div>
                    <?php else: ?>
                        <!-- FAILED: BUT CAN RETAKE -->
                        <div class="bg-amber-50 p-6 md:p-8 rounded-2xl border border-amber-200 shadow-sm mb-6 animate-up text-center mx-4 sm:mx-0">
                            <div class="w-16 h-16 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mx-auto mb-4 border border-amber-200 shadow-sm">
                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <h4 class="text-amber-700 font-black uppercase text-[12px] tracking-widest mb-2 drop-shadow-sm">Action Required: Exam Retake</h4>
                            <p class="text-sm text-slate-600 font-medium leading-relaxed max-w-md mx-auto break-words mb-5">
                                You did not pass your recent examination attempt. You have <strong class="text-amber-700"><?= 2 - $exam_attempts ?> retake(s)</strong> remaining for this academic year. 
                            </p>
                            <a href="student_exam_auth.php?adm_no=<?= urlencode($search_result['admission_number']) ?>" class="inline-flex bg-[#00205b] hover:bg-[#003882] text-white px-6 py-3 rounded-lg text-[11px] font-black uppercase tracking-widest shadow-md transition-colors">
                                Retake Exam
                            </a>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- ACCEPTED, BUT HAS NOT TAKEN EXAM YET (SHOW STANDARD CUSTOM MESSAGE) -->
                    <div class="bg-white p-6 rounded-2xl border border-[#00205b]/10 shadow-sm mb-6">
                        <h4 class="text-[#00205b] font-black uppercase text-[10px] tracking-widest mb-4 flex items-center gap-2 drop-shadow-sm">
                            <div class="p-1 rounded bg-[#00205b]/10"><svg class="w-4 h-4 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                            Message from Admissions
                        </h4>
                        
                        <div class="bg-slate-50 p-4 sm:p-5 rounded-xl border border-slate-200 text-xs text-slate-700 font-medium leading-relaxed shadow-inner">
<?php 
if (!empty($search_result['custom_message'])) {
    echo nl2br(htmlspecialchars($search_result['custom_message']));
} else {
    echo "Dear " . htmlspecialchars($display_fullname) . ",<br><br>Congratulations! Your admission application at Lyceum de San Pablo has been officially ACCEPTED.<br><br>Please check your email inbox for the examination link and criteria.<br><br>Good luck,<br>LDSP Admissions Office";
}
?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php elseif ($search_result['status'] == 'Denied'): ?>
                <div class="bg-white p-6 rounded-2xl border border-amber-200 shadow-sm">
                    <h4 class="text-amber-700 font-black uppercase text-[10px] tracking-widest mb-4 flex items-center gap-2 drop-shadow-sm">
                        <div class="p-1 rounded bg-amber-100"><svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg></div>
                        Action Required: Document Update
                    </h4>
                    <div class="bg-amber-50 p-5 rounded-xl border border-amber-200 shadow-inner">
                        <p class="text-[10px] font-black text-amber-800 uppercase tracking-widest mb-2 drop-shadow-sm">Admissions Feedback:</p>
                        <p class="text-xs text-amber-700 font-semibold break-words leading-relaxed whitespace-pre-wrap">"<?= htmlspecialchars($search_result['denial_reason']) ?>"</p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- SHARED DOCUMENT STACKED LIST SECTION (PRIVACY ENFORCED) -->
            <div class="bg-white border border-slate-200 p-6 rounded-2xl shadow-sm mt-6">
                <div class="flex justify-between items-center mb-5 border-b border-slate-100 pb-3 gap-2">
                    <h4 class="text-[#00205b] font-black uppercase text-[10px] tracking-widest flex items-center gap-2 drop-shadow-sm">
                        <div class="p-1 rounded bg-slate-100 hidden sm:block"><svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg></div>
                        Document Status
                    </h4>
                    <?= $doc_status_badge ?>
                </div>

                <?php if ($search_result['status'] == 'Denied'): ?>
                    <form method="POST" enctype="multipart/form-data" id="resubmitForm" class="space-y-4">
                        <input type="hidden" name="resubmit_docs" value="1">
                        <input type="hidden" name="adm_no" value="<?= htmlspecialchars($search_result['admission_number']) ?>">

                        <div class="flex flex-col gap-3">
                            <?php
                            $needs_upload = false;
                            foreach ($files as $idx => $filepath):
                                if (empty(trim($filepath))) continue;
                                
                                $file_status = isset($doc_statuses[$idx]['status']) ? $doc_statuses[$idx]['status'] : 'pending';
                                $file_reason = isset($doc_statuses[$idx]['reason']) ? $doc_statuses[$idx]['reason'] : '';
                                
                                $is_rejected = ($file_status === 'reject');
                                
                                // STRICT PRIVACY ENFORCEMENT: Only render the row if the document is explicitly rejected
                                if (!$is_rejected) continue;

                                $needs_upload = true;
                                $input_id = ($idx === 0) ? 'file_2x2' : 'file_psa';
                                $card_id = ($idx === 0) ? 'name_file_2x2' : 'name_file_psa';
                            ?>
                            <div class="border border-amber-400 bg-amber-50/50 shadow-sm p-4 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 transition-colors min-w-0">
                                <div class="flex items-center gap-3 overflow-hidden w-full min-w-0">
                                    <div class="bg-white border border-slate-200 p-2.5 rounded-lg text-slate-500 shrink-0">
                                        <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                    </div>
                                    <div class="truncate min-w-0">
                                        <p class="text-[11px] font-black text-[#00205b] uppercase tracking-widest truncate"><?= $doc_labels[$idx] ?? 'Document' ?></p>
                                        <p class="text-[10px] text-slate-500 font-semibold tracking-wider mt-0.5 truncate filename-text" id="<?= $card_id ?>"><?= htmlspecialchars(basename($filepath)) ?></p>
                                        <?php if (!empty($file_reason)): ?>
                                            <p class="text-[10px] text-amber-700 font-bold mt-1.5 bg-white inline-block px-2 py-0.5 rounded border border-amber-200 shadow-sm truncate max-w-full">
                                                Fix Required: <?= htmlspecialchars($file_reason) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="shrink-0 flex items-center justify-start sm:justify-end gap-2 w-full sm:w-auto mt-2 sm:mt-0">
                                    <button type="button" id="server_preview_btn_<?= $idx ?>" onclick="window.openDocModal('<?= htmlspecialchars($filepath) ?>', '')" class="p-2 bg-[#00205b]/5 text-[#00205b] rounded-lg shadow-sm hover:bg-[#00205b] hover:text-white border border-[#00205b]/20 transition-colors" title="View Current Rejected File">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                    </button>
                                    
                                    <input type="file" id="<?= $input_id ?>" name="<?= $input_id ?>" accept=".jpg,.jpeg,.png<?= $idx === 1 ? ',.pdf' : '' ?>" class="hidden" onchange="handleRowUpload(this, '<?= $card_id ?>', 'preview_btn_<?= $idx ?>', 'server_preview_btn_<?= $idx ?>')">
                                    
                                    <button type="button" id="preview_btn_<?= $idx ?>" class="hidden p-2 bg-[#00205b] text-white rounded-lg shadow-sm hover:bg-[#003882] transition-colors" title="Preview New Document">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                    </button>

                                    <button type="button" class="w-full sm:w-auto text-[10px] font-bold uppercase tracking-widest bg-amber-100 text-amber-800 px-4 py-2.5 rounded-lg hover:bg-amber-500 hover:text-white transition-all shadow-sm border border-amber-300" onclick="document.getElementById('<?= $input_id ?>').click()">Replace File</button>
                                </div>
                            </div>
                            <?php endforeach; ?>

                            <?php if (!$needs_upload): ?>
                                <p class="text-sm text-slate-500 italic text-center py-4">No rejected documents found. Your files are secured.</p>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($needs_upload): ?>
                        <div class="pt-6 border-t border-slate-100 flex justify-end mt-4">
                            <button type="submit" class="bg-[#00205b] hover:bg-[#003882] text-white shadow-md py-3 px-6 rounded-xl text-[11px] font-black uppercase tracking-widest flex items-center gap-2 transition-transform hover:-translate-y-0.5 w-full sm:w-auto justify-center">
                                Submit Updated Documents <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            </button>
                        </div>
                        <?php endif; ?>
                    </form>

                <?php elseif ($search_result['status'] == 'Pending'): ?>
                    <!-- PRIVACY ENFORCED: PENDING -->
                    <div class="text-center py-6">
                        <div class="w-12 h-12 bg-blue-50 text-blue-500 rounded-full flex items-center justify-center mx-auto mb-3 border border-blue-100">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2-2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                        </div>
                        <p class="text-sm font-bold text-slate-700">Documents Secured</p>
                        <p class="text-xs text-slate-500 mt-1">Your uploaded files are hidden for your privacy while under review.</p>
                    </div>

                <?php else: ?>
                    <!-- PRIVACY ENFORCED: ACCEPTED/VERIFIED -->
                    <div class="text-center py-6">
                        <div class="w-12 h-12 bg-emerald-50 text-emerald-500 rounded-full flex items-center justify-center mx-auto mb-3 border border-emerald-100">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                        <p class="text-sm font-bold text-slate-700">Documents Verified</p>
                        <p class="text-xs text-slate-500 mt-1">Your files have been approved and are now securely stored in your permanent record.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
<?php endif; 
// =========================================================================
// END OF HTML BUFFER
// =========================================================================
$tracker_html = ob_get_clean(); 

// Remove spaces/newlines for strict matching
$minified_new = preg_replace('/\s+/', '', $tracker_html);

if (isset($_GET['ajax_track'])) {
    header('Content-Type: application/json');
    echo json_encode(['html' => $tracker_html, 'hash' => md5($minified_new)]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Track Status - LDSP Admission</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
        
        /* Custom Scrollbar for the horizontal progress tracker */
        .custom-scrollbar::-webkit-scrollbar { height: 8px; width: 8px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 8px; margin: 0 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 8px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        .ambient-orb-1 {
            position: fixed; top: -10%; left: -10%; width: 60vw; height: 60vw;
            background: radial-gradient(circle, rgba(0,32,91,0.06) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%; pointer-events: none; z-index: 0;
        }
        .ambient-orb-2 {
            position: fixed; bottom: -10%; right: -10%; width: 70vw; height: 70vw;
            background: radial-gradient(circle, rgba(197,160,44,0.04) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%; pointer-events: none; z-index: 0;
        }

        .glossy-panel { 
            background: linear-gradient(145deg, rgba(255,255,255,1) 0%, rgba(249,250,251,1) 100%);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 1.5rem; 
            box-shadow: 0 20px 40px -15px rgba(0, 32, 91, 0.08), 0 0 0 1px rgba(0,32,91,0.02);
            position: relative;
            z-index: 10;
            overflow: hidden;
            transition: transform 0.5s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.5s ease;
        }
        
        .glossy-panel-header {
            height: 6px; width: 100%; position: absolute; top: 0; left: 0; opacity: 0.95;
            background: var(--ldsp-blue);
        }

        .input-glossy-smooth { 
            background: #ffffff;
            border: 1px solid #e2e8f0; 
            border-radius: 0.75rem; 
            padding: 1rem 1.25rem; 
            font-size: 0.875rem; 
            color: #111827; 
            width: 100%;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            outline: none;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.01);
        }
        .input-glossy-smooth:focus { 
            border-color: var(--ldsp-blue); 
            box-shadow: 0 0 0 4px rgba(0, 32, 91, 0.1), inset 0 1px 2px rgba(0,0,0,0.01); 
        }

        .btn-glossy-animated {
            background: var(--ldsp-blue);
            color: white; font-weight: 800; border-radius: 0.75rem; padding: 1rem 1.5rem;
            display: inline-flex; justify-content: center; align-items: center; gap: 8px;
            position: relative; overflow: hidden; cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            width: 100%;
            font-size: 0.875rem;
        }
        .btn-glossy-animated:hover { background: var(--ldsp-blue-light); transform: translateY(-2px); box-shadow: 0 12px 25px -8px rgba(0, 32, 91, 0.6); }
        .btn-glossy-animated:active { transform: translateY(1px); box-shadow: 0 2px 8px rgba(0, 32, 91, 0.4); }

        .animate-up { opacity: 0; animation: fadeUpSmooth 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .delay-1 { animation-delay: 0.1s; } 
        @keyframes fadeUpSmooth { 
            0% { opacity: 0; transform: translateY(20px) scale(0.99); filter: blur(2px); } 
            100% { opacity: 1; transform: translateY(0) scale(1); filter: blur(0); } 
        }

        .modal-overlay { opacity: 0; transition: opacity 0.3s ease; pointer-events: none; }
        .modal-content { transform: scale(0.95); opacity: 0; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); pointer-events: none; }
        .modal-active.modal-overlay { opacity: 1; pointer-events: auto; }
        .modal-active .modal-content { transform: scale(1); opacity: 1; pointer-events: auto; }
        
        .step-circle { transition: all 0.3s ease; }
        .step-line { transition: width 0.5s ease; }
    </style>
</head>
<body class="flex flex-col items-center p-4 sm:p-6 bg-[#f4f6f9] relative min-h-screen">
    
    <div id="file-size-toast" class="fixed top-6 left-1/2 -translate-x-1/2 z-[10000] hidden bg-white/95 backdrop-blur-md border border-rose-200 shadow-2xl p-4 rounded-2xl transform transition-all duration-300 translate-y-[-20px] opacity-0 w-[90vw] sm:w-[400px]">
        <div class="flex items-start gap-4">
            <div class="bg-rose-50 border border-rose-100 p-2.5 rounded-xl shrink-0 shadow-sm mt-0.5">
                <svg class="w-6 h-6 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
            </div>
            <div class="flex-1 min-w-0 pt-1">
                <p class="text-[14px] font-black text-[#00205b] tracking-tight">File Exceeds Limit</p>
                <p class="text-[12px] text-gray-500 font-medium mt-1 leading-relaxed">The selected file is larger than 5MB. Please select a smaller one.</p>
            </div>
            <button type="button" onclick="document.getElementById('file-size-toast').classList.add('hidden')" class="text-gray-400 hover:text-rose-600 hover:bg-rose-50 transition-colors p-1.5 rounded-lg shrink-0 mt-0.5 focus:outline-none">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
    </div>

    <div id="toastContainer" class="fixed top-5 right-5 z-[100] flex flex-col gap-2 pointer-events-none w-[90vw] sm:w-auto max-w-[400px]"></div>

    <div class="ambient-orb-1"></div>
    <div class="ambient-orb-2"></div>

    <!-- MAIN DYNAMIC CONTENT CONTAINER (AUTO-REFRESHED VIA AJAX) -->
    <div class="max-w-3xl w-full relative z-10 my-4 sm:my-10" id="live-tracker-content">
        <?= $tracker_html ?>
    </div>

    <!-- DOCUMENT OVERLAY MODAL -->
    <div id="doc-modal" class="fixed inset-0 z-[9999] hidden flex-col items-center justify-center p-2 sm:p-4 modal-overlay bg-slate-900/90 backdrop-blur-md transition-opacity duration-300 opacity-0">
        <div class="relative w-full h-full flex items-center justify-center overflow-hidden" id="doc-container">
            <img id="doc-image" src="" alt="Document Preview" class="max-w-[95vw] max-h-[95vh] object-contain transition-transform duration-100 hidden">
            <iframe id="doc-iframe" src="" class="w-[95vw] h-[95vh] bg-white rounded-xl shadow-2xl hidden border-0"></iframe>
        </div>
        <button type="button" onclick="window.closeDocModal()" class="absolute top-4 right-4 sm:top-5 sm:right-5 p-2 bg-white/10 text-white rounded-full hover:bg-rose-500 hover:text-white transition-colors z-[10000] cursor-pointer border border-white/20">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
    </div>

    <!-- JAVASCRIPT: UPLOAD, MODAL, AND LIVE SYNC ENGINE -->
    <script>
        let currentAdmNo = "<?= htmlspecialchars($adm_no) ?>";
        // Calculate the hash of the current HTML state to prevent false positive DOM refreshes
        let lastTrackerHash = "<?= md5($minified_new) ?>"; 

        document.addEventListener("DOMContentLoaded", () => {
            const contentDiv = document.getElementById('live-tracker-content');

            if (currentAdmNo) {
                // Poll every 3 seconds to auto-update the tracker UI silently
                setInterval(async () => {
                    const docModal = document.getElementById('doc-modal');
                    const reqModal = document.getElementById('requirementsModal');
                    const isModalOpen = (docModal && !docModal.classList.contains('hidden')) || (reqModal && !reqModal.classList.contains('hidden'));
                    
                    let hasFiles = false;
                    document.querySelectorAll('input[type="file"]').forEach(input => {
                        if (input.files && input.files.length > 0) hasFiles = true;
                    });

                    // Pause sync if tab is hidden, modal open, or file selected
                    if (document.hidden || isModalOpen || hasFiles) return;

                    try {
                        const res = await fetch(`track_status.php?ajax_track=1&adm_no=${encodeURIComponent(currentAdmNo)}`);
                        if (!res.ok) return;
                        const data = await res.json();
                        
                        // Strict Hash Check: ONLY refresh DOM if data structurally changed
                        if (data.hash && data.hash !== lastTrackerHash) {
                            lastTrackerHash = data.hash;
                            contentDiv.innerHTML = data.html;
                            
                            // Re-trigger animate-up classes to make the transition look smooth
                            const animatedElements = contentDiv.querySelectorAll('.animate-up');
                            animatedElements.forEach(el => {
                                el.style.animation = 'none';
                                el.offsetHeight; // trigger reflow
                                el.style.animation = null; 
                            });
                        }
                    } catch (err) {
                        console.error("Live tracking sync error", err);
                    }
                }, 3000); 
            }
        });

        window.openReqModal = function() {
            document.getElementById('requirementsModal').classList.remove('hidden');
            document.getElementById('requirementsModal').classList.add('flex');
            setTimeout(() => document.getElementById('requirementsModal').classList.add('modal-active'), 10);
        };

        window.closeReqModal = function() {
            document.getElementById('requirementsModal').classList.remove('modal-active');
            setTimeout(() => { 
                document.getElementById('requirementsModal').classList.add('hidden'); 
                document.getElementById('requirementsModal').classList.remove('flex'); 
            }, 300);
        };

        window.handleRowUpload = function(input, textId, previewBtnId, serverPreviewBtnId) {
            const textEl = document.getElementById(textId);
            const previewBtn = document.getElementById(previewBtnId);
            const serverPreviewBtn = document.getElementById(serverPreviewBtnId);
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const maxSize = 5 * 1024 * 1024; // 5MB Limit
                
                if (file.size > maxSize) {
                    input.value = '';
                    const toast = document.getElementById('file-size-toast');
                    if (toast) {
                        toast.classList.remove('hidden');
                        setTimeout(() => { toast.classList.remove('translate-y-[-20px]', 'opacity-0'); }, 10);
                        setTimeout(() => { toast.classList.add('translate-y-[-20px]', 'opacity-0'); setTimeout(() => toast.classList.add('hidden'), 300); }, 4000);
                    }
                    if (textEl) {
                        textEl.textContent = "File Exceeded 5MB Limit";
                        textEl.classList.remove('text-gray-500', 'text-emerald-600');
                        textEl.classList.add('text-rose-500');
                    }
                    if (previewBtn) previewBtn.classList.add('hidden');
                    if (serverPreviewBtn) serverPreviewBtn.classList.remove('hidden'); // Restore server preview if failed
                    return;
                }
                
                const size = (file.size / (1024 * 1024)).toFixed(2) + ' MB';
                const objectURL = URL.createObjectURL(file);
                
                if (textEl) {
                    textEl.textContent = file.name + ' (' + size + ') - Ready to Submit';
                    textEl.classList.remove('text-rose-500', 'text-gray-500');
                    textEl.classList.add('text-emerald-600');
                    textEl.parentElement.parentElement.parentElement.classList.remove('border-rose-300', 'bg-rose-50/20', 'border-amber-400', 'bg-amber-50/50');
                    textEl.parentElement.parentElement.parentElement.classList.add('border-emerald-300', 'bg-emerald-50/30');
                }

                if (serverPreviewBtn) serverPreviewBtn.classList.add('hidden'); // Hide the old server file button

                if (previewBtn) {
                    previewBtn.classList.remove('hidden');
                    previewBtn.onclick = function(e) {
                        e.preventDefault();
                        window.openDocModal(objectURL, file.type);
                    };
                }
            }
        };

        window.openDocModal = function(url, type) {
            const modal = document.getElementById('doc-modal');
            const img = document.getElementById('doc-image');
            const iframe = document.getElementById('doc-iframe');
            
            if(!modal) return;
            
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => { modal.classList.remove('opacity-0'); }, 10);
            
            if (type && type.startsWith('image/') || url.match(/\.(jpeg|jpg|gif|png|webp)$/i)) { 
                if(img) { img.src = url; img.classList.remove('hidden'); }
                if(iframe) iframe.classList.add('hidden');
            } else {
                if(iframe) { 
                    iframe.src = url; 
                    iframe.classList.remove('hidden'); 
                }
                if(img) img.classList.add('hidden');
            }
        };

        window.closeDocModal = function() {
            const modal = document.getElementById('doc-modal');
            if(!modal) return;
            modal.classList.add('opacity-0'); 
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                const img = document.getElementById('doc-image');
                const iframe = document.getElementById('doc-iframe');
                if(img) img.src = '';
                if(iframe) iframe.src = '';
            }, 300);
        };

        window.showToast = function(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            if (!container) return;
            const toast = document.createElement('div');
            const isSuccess = type === 'success';
            const bgColor = isSuccess ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800';
            const iconPath = isSuccess ? 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
            const iconColor = isSuccess ? 'text-emerald-500' : 'text-rose-500';

            toast.className = `flex items-center gap-3 px-4 py-3 rounded-xl shadow-xl border ${bgColor} font-bold text-sm z-[9999] transition-all duration-300 translate-x-full opacity-0 mb-2 w-full`;
            toast.innerHTML = `<svg class="w-5 h-5 shrink-0 ${iconColor}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${iconPath}" /></svg><span class="tracking-wide">${message}</span>`;
            
            container.appendChild(toast);
            requestAnimationFrame(() => toast.classList.remove('translate-x-full', 'opacity-0'));
            setTimeout(() => {
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => toast.remove(), 400);
            }, 3500);
        };

        <?php if (!empty($success_msg)): ?> window.showToast("<?= addslashes($success_msg) ?>", 'success'); <?php endif; ?>
        <?php if (!empty($error_msg)): ?> window.showToast("<?= addslashes($error_msg) ?>", 'error'); <?php endif; ?>
    </script>
</body>
</html>