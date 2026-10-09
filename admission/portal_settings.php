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

// 2. ISOLATED SESSION HANDLER FOR STAFF (Safe Start)
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

// ---------------------------------------------------------
// UNIVERSAL ACCESS + EDIT PERMISSIONS LOGIC
// ---------------------------------------------------------
$allowed_roles = ['admin', 'admission', 'registrar', 'accounting'];

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../index.php");
    exit();
}

$can_edit = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'admission');
$success_msg = "";
$error_msg = "";

// =========================================================
// AUTO-CREATE TABLE FAILSAFE & SEED DEFAULTS FOR PORTAL SETTINGS
// =========================================================
try {
    $conn->query("CREATE TABLE IF NOT EXISTS portal_settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT NOT NULL
    )");

    $defaults = [
        'admission_status' => 'Open',
        'enrollment_status' => 'Closed',
        'academic_year' => '2026-2027',
        'semester' => '1st Semester',
        'active_school_year' => '2024-2025',
        'active_semester' => '1st Semester',
        'program_shift_period' => 'Every New Semester or Year',
        'available_types' => 'Freshman (New),Transferee (New),Returnee (Old),Irregular (Old),Regular (Old)',
        'accepted_types' => 'Freshman (New),Transferee (New),Returnee (Old),Irregular (Old),Regular (Old)',
        'portal_title' => 'Admission Portal',
        'announcement' => 'Welcome to LDSP! Online enrollment is now officially ongoing. Please ensure all requirements are submitted upon approval.',
        'attachment_instructions' => 'Please upload all required documents listed in the instructions.',
        'process_steps_count' => '3',
        'step_1_title' => 'Complete Applicant Data Entry',
        'step_1_desc' => 'Fill out your personal and academic details carefully.',
        'step_2_title' => 'Secure Requirement Upload',
        'step_2_desc' => 'Attach clear copies of your required documents.',
        'step_3_title' => 'Registrar Verification & Approval',
        'step_3_desc' => 'Wait for the official confirmation from the registrar.'
    ];

    foreach ($defaults as $key => $val) {
        $safe_val = $conn->real_escape_string($val);
        $conn->query("INSERT IGNORE INTO portal_settings (setting_key, setting_value) VALUES ('$key', '$safe_val')");
    }
} catch (Exception $e) {}

// =========================================================
// POST ACTIONS (STANDARD FORM SUBMISSIONS)
// =========================================================
if ($can_edit && $_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Save General Configuration
    if (isset($_POST['update_general'])) {
        $adm_status = isset($_POST['admission_status']) ? $conn->real_escape_string($_POST['admission_status']) : 'Open';
        $year = isset($_POST['academic_year']) ? $conn->real_escape_string(trim($_POST['academic_year'])) : '';
        $sem = isset($_POST['semester']) ? $conn->real_escape_string($_POST['semester']) : '';
        $title = isset($_POST['portal_title']) ? $conn->real_escape_string(trim($_POST['portal_title'])) : '';
        
        $conn->query("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('admission_status', '$adm_status') ON DUPLICATE KEY UPDATE setting_value = '$adm_status'");
        $conn->query("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('academic_year', '$year') ON DUPLICATE KEY UPDATE setting_value = '$year'");
        $conn->query("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('semester', '$sem') ON DUPLICATE KEY UPDATE setting_value = '$sem'");
        $conn->query("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('portal_title', '$title') ON DUPLICATE KEY UPDATE setting_value = '$title'");
        
        $success_msg = "General Configuration saved successfully.";
    }

    // 2. Save Enrollment Settings
    elseif (isset($_POST['update_enrollment_settings'])) {
        $enr_status = isset($_POST['enrollment_status']) ? $conn->real_escape_string($_POST['enrollment_status']) : 'Closed';
        
        // Handle available and checked admission types dynamically
        $available_types_arr = (isset($_POST['available_types']) && is_array($_POST['available_types'])) ? $_POST['available_types'] : [];
        $accepted_types_arr = (isset($_POST['accepted_types']) && is_array($_POST['accepted_types'])) ? $_POST['accepted_types'] : [];
        
        $available_types_str = $conn->real_escape_string(implode(',', $available_types_arr));
        $accepted_types_str = $conn->real_escape_string(implode(',', $accepted_types_arr));
        
        $active_school_year = isset($_POST['active_school_year']) ? $conn->real_escape_string($_POST['active_school_year']) : '';
        $active_semester = isset($_POST['active_semester']) ? $conn->real_escape_string($_POST['active_semester']) : '';
        $program_shift_period = isset($_POST['program_shift_period']) ? $conn->real_escape_string($_POST['program_shift_period']) : 'Every New Semester or Year';
        
        $conn->query("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('enrollment_status', '$enr_status') ON DUPLICATE KEY UPDATE setting_value = '$enr_status'");
        $conn->query("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('available_types', '$available_types_str') ON DUPLICATE KEY UPDATE setting_value = '$available_types_str'");
        $conn->query("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('accepted_types', '$accepted_types_str') ON DUPLICATE KEY UPDATE setting_value = '$accepted_types_str'");
        $conn->query("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('active_school_year', '$active_school_year') ON DUPLICATE KEY UPDATE setting_value = '$active_school_year'");
        $conn->query("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('active_semester', '$active_semester') ON DUPLICATE KEY UPDATE setting_value = '$active_semester'");
        $conn->query("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('program_shift_period', '$program_shift_period') ON DUPLICATE KEY UPDATE setting_value = '$program_shift_period'");
        
        $success_msg = "Enrollment Settings saved successfully.";
    }

    // 3. Save Announcements
    elseif (isset($_POST['update_announcements'])) {
        $ann = isset($_POST['announcement']) ? $conn->real_escape_string(trim($_POST['announcement'])) : '';
        $att = isset($_POST['attachment_instructions']) ? $conn->real_escape_string(trim($_POST['attachment_instructions'])) : '';
        
        $conn->query("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('announcement', '$ann') ON DUPLICATE KEY UPDATE setting_value = '$ann'");
        $conn->query("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('attachment_instructions', '$att') ON DUPLICATE KEY UPDATE setting_value = '$att'");
        
        $success_msg = "Announcements & Instructions updated.";
    }

    // 4. Save Process Steps
    elseif (isset($_POST['update_steps'])) {
        $titles = (isset($_POST['step_title']) && is_array($_POST['step_title'])) ? $_POST['step_title'] : [];
        $descs = (isset($_POST['step_desc']) && is_array($_POST['step_desc'])) ? $_POST['step_desc'] : [];
        $step_count = count($titles);

        $old_count_res = $conn->query("SELECT setting_value FROM portal_settings WHERE setting_key = 'process_steps_count'");
        $old_count = ($old_count_res && $old_count_res->num_rows > 0) ? (int)$old_count_res->fetch_assoc()['setting_value'] : 0;

        $conn->query("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('process_steps_count', '$step_count') ON DUPLICATE KEY UPDATE setting_value = '$step_count'");

        for ($i = 0; $i < $step_count; $i++) {
            $idx = $i + 1;
            $t = $conn->real_escape_string(trim($titles[$i] ?? ''));
            $d = $conn->real_escape_string(trim($descs[$i] ?? ''));
            $conn->query("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('step_{$idx}_title', '$t') ON DUPLICATE KEY UPDATE setting_value = '$t'");
            $conn->query("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('step_{$idx}_desc', '$d') ON DUPLICATE KEY UPDATE setting_value = '$d'");
        }
        if ($step_count < $old_count) {
            for ($i = $step_count + 1; $i <= $old_count; $i++) {
                $conn->query("DELETE FROM portal_settings WHERE setting_key = 'step_{$i}_title' OR setting_key = 'step_{$i}_desc'");
            }
        }
        $success_msg = "Process Overview steps updated.";
    }

    // 5. Save Post-Admission Requirements
    elseif (isset($_POST['save_requirements'])) {
        $new_reqs = $conn->real_escape_string($_POST['requirements_text'] ?? '');
        $conn->query("UPDATE system_settings SET setting_value = '$new_reqs' WHERE setting_key = 'enrollment_requirements'");
        if ($conn->affected_rows === 0) {
            $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('enrollment_requirements', '$new_reqs')");
        }
        $success_msg = "Enrollment requirements updated.";
    }
}

// ---------------------------------------------------------
// DATA FETCHING
// ---------------------------------------------------------
$settings = [];
$res = $conn->query("SELECT * FROM portal_settings");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}
$current_step_count = isset($settings['process_steps_count']) ? (int)$settings['process_steps_count'] : 3;

$req_query = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'enrollment_requirements'");
$current_reqs = ($req_query && $req_query->num_rows > 0) ? $req_query->fetch_assoc()['setting_value'] : "Original Form 138 (SF9) / Learner's Progress Report Card\nOriginal Certificate of Good Moral Character\nPhotocopy of PSA Birth Certificate\nTwo (2) copies of 2x2 ID Picture with white background";

$available_types = explode(',', $settings['available_types'] ?? 'Freshman (New),Transferee (New),Returnee (Old),Irregular (Old),Regular (Old)');
$current_accepted_types = explode(',', $settings['accepted_types'] ?? '');

$current_shift_period = $settings['program_shift_period'] ?? 'Every New Semester or Year';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Settings - LDSP Command Center</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css?v=<?= time(); ?>">
    <style>
        .toggle-checkbox:checked { right: 0; border-color: #00205b; }
        .toggle-checkbox:checked + .toggle-label { background-color: #00205b; }
        .toggle-checkbox:checked + .toggle-label span.off { display: none; }
        .toggle-checkbox:not(:checked) + .toggle-label span.on { display: none; }
    </style>
</head>

<body class="flex h-screen overflow-hidden antialiased bg-[#f4f6f9]">

    <div class="ambient-orb-1"></div>
    <div class="ambient-orb-2"></div>

    <?php include 'sidebar.php'; ?>

    <main class="flex-1 min-w-0 overflow-y-auto h-full w-full pt-16 md:pt-0 relative z-10 custom-scrollbar">
        <div class="p-4 md:p-6 max-w-[1400px] mx-auto relative z-20 fade-in-up">
            
            <!-- Compact Header -->
            <header class="mb-5 border-b border-slate-300/60 pb-3 drop-shadow-sm flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <div class="flex items-center gap-3 mb-1">
                        <h1 class="text-2xl md:text-3xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">Portal Settings</h1>
                        <?php if (!$can_edit): ?>
                            <span class="px-2 py-0.5 bg-white text-slate-500 border border-slate-300 text-[9px] font-bold rounded uppercase tracking-widest shadow-sm">READ ONLY</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-slate-500 text-xs flex items-center gap-1.5 font-medium mt-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.8)]"></span>
                        Logged in as <?= htmlspecialchars($_SESSION['first_name'] ?? 'User') ?>
                    </p>
                </div>
            </header>

            <div class="mb-5">
                <p class="text-slate-500 text-[11px] font-bold uppercase tracking-widest">Configure public admission pages, manage semesters, and update requirements.</p>
            </div>

            <!-- Section 1: General Config -->
            <form method="POST" class="bg-white/95 backdrop-blur-md rounded-xl p-4 md:p-5 mb-5 shadow-sm border-t-[4px] border-t-[#00205b]">
                <input type="hidden" name="update_general" value="1">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center border-b border-slate-200 pb-3 mb-4 gap-3">
                    <h3 class="text-xs font-black text-[#00205b] uppercase tracking-widest drop-shadow-sm flex items-center gap-2">
                        <div class="p-1 rounded-sm bg-[#00205b]/10"><svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg></div>
                        1. General Configuration
                    </h3>
                    
                    <div class="flex items-center gap-3 bg-slate-50 border border-slate-200 px-3 py-1.5 rounded-lg shadow-sm shrink-0 w-full sm:w-auto">
                        <div class="flex flex-col text-right flex-1 sm:flex-none">
                            <span class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Global Status</span>
                            <span id="gen_status_text" class="text-[10px] font-black uppercase tracking-widest <?= ($settings['admission_status'] ?? 'Open') === 'Open' ? 'text-[#00205b]' : 'text-slate-500' ?>"><?= ($settings['admission_status'] ?? 'Open') === 'Open' ? 'Portal Open' : 'Portal Closed' ?></span>
                        </div>
                        
                        <div class="relative inline-block w-10 align-middle select-none transition duration-200 ease-in <?= !$can_edit ? 'opacity-50 pointer-events-none' : '' ?>">
                            <input type="hidden" name="admission_status" id="hidden_status" value="<?= htmlspecialchars($settings['admission_status'] ?? 'Open') ?>">
                            <input type="checkbox" name="toggle" id="toggle_general" <?= ($settings['admission_status'] ?? 'Open') === 'Open' ? 'checked' : '' ?> <?= !$can_edit ? 'disabled' : '' ?> class="toggle-checkbox absolute block w-5 h-5 rounded-full bg-white border-4 appearance-none cursor-pointer" onchange="document.getElementById('hidden_status').value = this.checked ? 'Open' : 'Closed'; document.getElementById('gen_status_text').className = 'text-[10px] font-black uppercase tracking-widest ' + (this.checked ? 'text-[#00205b]' : 'text-slate-500'); document.getElementById('gen_status_text').innerText = this.checked ? 'Portal Open' : 'Portal Closed';"/>
                            <label for="toggle_general" class="toggle-label block overflow-hidden h-5 rounded-full bg-slate-300 cursor-pointer flex items-center justify-between px-1">
                                <span class="on text-[7px] font-bold text-white px-1">ON</span>
                                <span class="off text-[7px] font-bold text-slate-500 px-1 ml-auto">OFF</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2 lg:col-span-1">
                        <label class="block text-[9px] font-bold text-[#00205b] uppercase tracking-widest mb-1 drop-shadow-sm">Portal Page Title</label>
                        <input type="text" name="portal_title" <?= !$can_edit ? 'disabled' : '' ?> value="<?= htmlspecialchars($settings['portal_title'] ?? 'Admission Portal') ?>" required class="w-full bg-slate-50 border border-slate-300 rounded px-2.5 py-1.5 text-[11px] font-bold text-[#00205b] focus:outline-none focus:border-[#00205b] transition-colors shadow-inner" placeholder="e.g. Admission Portal">
                    </div>
                    
                    <div class="md:col-span-2 lg:col-span-1">
                        <label class="block text-[9px] font-bold text-[#00205b] uppercase tracking-widest mb-1 drop-shadow-sm">Active Academic Term</label>
                        <div class="flex items-center gap-1.5">
                            <button type="button" onclick="stepTerm('general', -1)" <?= !$can_edit ? 'disabled' : '' ?> class="bg-white border border-slate-300 text-slate-500 hover:bg-slate-50 hover:text-[#00205b] px-2 py-1.5 rounded transition-colors shadow-sm disabled:opacity-50 cursor-pointer">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M15 19l-7-7 7-7"></path></svg>
                            </button>
                            
                            <div class="flex-1 bg-slate-50 border border-slate-300 rounded px-2 py-1.5 text-center shadow-inner flex flex-wrap items-center justify-center gap-1">
                                <span id="display_general_year" class="text-[11px] font-mono font-black text-[#00205b] tracking-wider"><?= htmlspecialchars($settings['academic_year'] ?? '2025-2026') ?></span>
                                <span class="text-slate-300 hidden sm:inline mx-1">|</span>
                                <span id="display_general_sem" class="text-[10px] font-bold text-slate-600 uppercase tracking-widest"><?= htmlspecialchars($settings['semester'] ?? '1st Semester') ?></span>
                            </div>

                            <button type="button" onclick="stepTerm('general', 1)" <?= !$can_edit ? 'disabled' : '' ?> class="bg-white border border-slate-300 text-slate-500 hover:bg-slate-50 hover:text-[#00205b] px-2 py-1.5 rounded transition-colors shadow-sm disabled:opacity-50 cursor-pointer">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M9 5l7 7-7 7"></path></svg>
                            </button>
                        </div>
                        <input type="hidden" name="academic_year" id="hidden_general_year" value="<?= htmlspecialchars($settings['academic_year'] ?? '2025-2026') ?>">
                        <input type="hidden" name="semester" id="hidden_general_sem" value="<?= htmlspecialchars($settings['semester'] ?? '1st Semester') ?>">
                    </div>
                </div>
                
                <?php if ($can_edit): ?>
                    <div class="pt-3 flex justify-end border-t border-slate-100 mt-4">
                        <button type="submit" class="bg-[#00205b] hover:bg-[#001233] text-white px-4 py-1.5 rounded text-[9px] font-bold uppercase tracking-widest transition-colors flex items-center gap-1.5 shadow-sm cursor-pointer">
                            Save Changes <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        </button>
                    </div>
                <?php endif; ?>
            </form>

            <!-- Section 2: Enrollment Settings -->
            <form method="POST" class="bg-white/95 backdrop-blur-md rounded-xl p-4 md:p-5 mb-5 shadow-sm border-t-[4px] border-t-[#00205b]">
                <input type="hidden" name="update_enrollment_settings" value="1">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center border-b border-slate-200 pb-3 mb-4 gap-3">
                    <h3 class="text-xs font-black text-[#00205b] uppercase tracking-widest drop-shadow-sm flex items-center gap-2">
                        <div class="p-1 rounded-sm bg-[#00205b]/10"><svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg></div>
                        2. Enrollment Settings
                    </h3>
                    
                    <div class="flex items-center gap-3 bg-slate-50 border border-slate-200 px-3 py-1.5 rounded-lg shadow-sm shrink-0 w-full sm:w-auto">
                        <div class="flex flex-col text-right flex-1 sm:flex-none">
                            <span class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Enrollment Portal</span>
                            <span id="enr_status_text" class="text-[10px] font-black uppercase tracking-widest <?= ($settings['enrollment_status'] ?? 'Closed') === 'Open' ? 'text-[#00205b]' : 'text-slate-500' ?>"><?= ($settings['enrollment_status'] ?? 'Closed') === 'Open' ? 'Portal Open' : 'Portal Closed' ?></span>
                        </div>
                        
                        <div class="relative inline-block w-10 align-middle select-none transition duration-200 ease-in <?= !$can_edit ? 'opacity-50 pointer-events-none' : '' ?>">
                            <input type="hidden" name="enrollment_status" id="hidden_enrollment_status" value="<?= htmlspecialchars($settings['enrollment_status'] ?? 'Closed') ?>">
                            <input type="checkbox" name="toggle_enrollment" id="toggle_enrollment" <?= ($settings['enrollment_status'] ?? 'Closed') === 'Open' ? 'checked' : '' ?> <?= !$can_edit ? 'disabled' : '' ?> class="toggle-checkbox absolute block w-5 h-5 rounded-full bg-white border-4 appearance-none cursor-pointer" onchange="document.getElementById('hidden_enrollment_status').value = this.checked ? 'Open' : 'Closed'; document.getElementById('enr_status_text').className = 'text-[10px] font-black uppercase tracking-widest ' + (this.checked ? 'text-[#00205b]' : 'text-slate-500'); document.getElementById('enr_status_text').innerText = this.checked ? 'Portal Open' : 'Portal Closed';"/>
                            <label for="toggle_enrollment" class="toggle-label block overflow-hidden h-5 rounded-full bg-slate-300 cursor-pointer flex items-center justify-between px-1">
                                <span class="on text-[7px] font-bold text-white px-1">ON</span>
                                <span class="off text-[7px] font-bold text-slate-500 px-1 ml-auto">OFF</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2 lg:col-span-1">
                        <label class="block text-[9px] font-bold text-[#00205b] uppercase tracking-widest mb-1 drop-shadow-sm">Active School Term</label>
                        <div class="flex items-center gap-1.5">
                            <button type="button" onclick="stepTerm('enrollment', -1)" <?= !$can_edit ? 'disabled' : '' ?> class="bg-white border border-slate-300 text-slate-500 hover:bg-slate-50 hover:text-[#00205b] px-2 py-1.5 rounded transition-colors shadow-sm disabled:opacity-50 cursor-pointer">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M15 19l-7-7 7-7"></path></svg>
                            </button>
                            
                            <div class="flex-1 bg-slate-50 border border-slate-300 rounded px-2 py-1.5 text-center shadow-inner flex flex-wrap items-center justify-center gap-1">
                                <span id="display_enrollment_year" class="text-[11px] font-mono font-black text-[#00205b] tracking-wider"><?= htmlspecialchars($settings['active_school_year'] ?? '2025-2026') ?></span>
                                <span class="text-slate-300 hidden sm:inline mx-1">|</span>
                                <span id="display_enrollment_sem" class="text-[10px] font-bold text-slate-600 uppercase tracking-widest"><?= htmlspecialchars($settings['active_semester'] ?? '1st Semester') ?></span>
                            </div>

                            <button type="button" onclick="stepTerm('enrollment', 1)" <?= !$can_edit ? 'disabled' : '' ?> class="bg-white border border-slate-300 text-slate-500 hover:bg-slate-50 hover:text-[#00205b] px-2 py-1.5 rounded transition-colors shadow-sm disabled:opacity-50 cursor-pointer">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M9 5l7 7-7 7"></path></svg>
                            </button>
                        </div>
                        <input type="hidden" name="active_school_year" id="hidden_enrollment_year" value="<?= htmlspecialchars($settings['active_school_year'] ?? '2025-2026') ?>">
                        <input type="hidden" name="active_semester" id="hidden_enrollment_sem" value="<?= htmlspecialchars($settings['active_semester'] ?? '1st Semester') ?>">
                    </div>

                    <!-- Program Shifting Policy (Per-Semester vs Annual) -->
                    <div class="md:col-span-2 lg:col-span-1">
                        <label class="block text-[9px] font-bold text-[#00205b] uppercase tracking-widest mb-1 drop-shadow-sm">Program Shifting Policy</label>
                        <select name="program_shift_period" <?= !$can_edit ? 'disabled' : '' ?> class="w-full bg-slate-50 border border-slate-300 rounded px-2.5 py-1.5 text-[11px] font-bold text-[#00205b] focus:outline-none focus:border-[#00205b] transition-colors shadow-inner">
                            <option value="Every New Semester or Year" <?= $current_shift_period === 'Every New Semester or Year' ? 'selected' : '' ?>>Every New Semester or Academic Year (Open Every Term)</option>
                            <option value="Every Academic Year" <?= $current_shift_period === 'Every Academic Year' ? 'selected' : '' ?>>Every Academic Year Only (1st Semester Only)</option>
                        </select>
                        <p class="text-[8px] text-slate-400 font-bold uppercase tracking-widest mt-1">Allows students to request program shifting every time a new term opens.</p>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-bold text-[#00205b] uppercase tracking-widest mb-2 drop-shadow-sm">Accepted Admission Types</label>
                        
                        <!-- List of Admission Types Container -->
                        <div id="admission-types-container" class="flex flex-wrap gap-1.5 mb-2">
                            <?php foreach($available_types as $type): 
                                $type = trim($type); 
                                if(empty($type)) continue; 
                            ?>
                                <div class="admission-type-item flex items-stretch relative group">
                                    <input type="hidden" name="available_types[]" value="<?= htmlspecialchars($type) ?>">
                                    
                                    <label class="cursor-pointer relative flex">
                                        <input type="checkbox" name="accepted_types[]" value="<?= htmlspecialchars($type) ?>" <?= in_array($type, $current_accepted_types) ? 'checked' : '' ?> <?= !$can_edit ? 'disabled' : '' ?> class="peer sr-only">
                                        <div class="px-2 py-1 <?= $can_edit ? 'rounded-l border-r-0' : 'rounded' ?> border border-slate-300 bg-white text-slate-500 font-bold text-[9px] uppercase tracking-widest transition-all duration-200 peer-checked:border-[#00205b] peer-checked:bg-blue-50 peer-checked:text-[#00205b] shadow-sm hover:border-[#00205b] flex items-center gap-1">
                                            <svg class="w-2.5 h-2.5 opacity-0 peer-checked:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                            <span><?= htmlspecialchars($type) ?></span>
                                        </div>
                                    </label>
                                    
                                    <?php if ($can_edit): ?>
                                    <button type="button" onclick="removeAdmissionType(this)" class="px-1.5 py-1 bg-slate-50 border border-slate-300 rounded-r text-rose-400 hover:text-white hover:bg-rose-500 hover:border-rose-600 transition-colors shadow-sm focus:outline-none flex items-center justify-center" title="Delete Type">
                                        <svg class="w-2.5 h-2.5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Add New Type Tool -->
                        <?php if ($can_edit): ?>
                        <div class="flex items-center gap-2 mt-2 pt-2 border-t border-slate-100">
                            <input type="text" id="new_admission_type" class="bg-slate-50 border border-slate-300 rounded px-2 py-1 text-[10px] font-bold text-[#00205b] focus:outline-none focus:border-[#00205b] shadow-inner w-full max-w-[200px]" placeholder="e.g. Transferee (New)">
                            <button type="button" onclick="addAdmissionType()" class="bg-white hover:bg-slate-50 border border-slate-300 text-slate-600 px-2 py-1 rounded text-[9px] font-bold uppercase tracking-widest transition-colors flex items-center gap-1 shadow-sm shrink-0">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4" /></svg> Add Type
                            </button>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>

                <?php if ($can_edit): ?>
                    <div class="pt-3 flex justify-end border-t border-slate-100 mt-4">
                        <button type="submit" class="bg-[#00205b] hover:bg-[#003882] text-white px-4 py-1.5 rounded text-[9px] font-bold uppercase tracking-widest transition-colors flex items-center gap-1.5 shadow-sm cursor-pointer">
                            Save Changes <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        </button>
                    </div>
                <?php endif; ?>
            </form>

            <!-- Section 3: Announcements -->
            <form method="POST" class="bg-white/95 backdrop-blur-md rounded-xl p-4 md:p-5 mb-5 shadow-sm border-t-[4px] border-t-[#00205b]">
                <input type="hidden" name="update_announcements" value="1">
                <h3 class="text-xs font-black text-[#00205b] uppercase tracking-widest border-b border-slate-200 pb-3 mb-4 drop-shadow-sm flex items-center gap-2">
                    <div class="p-1 rounded-sm bg-[#00205b]/10"><svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg></div>
                    3. Announcements & Content
                </h3>
                <div class="grid grid-cols-1 gap-3">
                    <div>
                        <label class="block text-[9px] font-bold text-[#00205b] uppercase tracking-widest mb-1 drop-shadow-sm">Public Welcome Message</label>
                        <textarea name="announcement" <?= !$can_edit ? 'disabled' : '' ?> rows="3" required class="w-full bg-slate-50 border border-slate-300 rounded px-2.5 py-2 text-[11px] font-semibold text-slate-700 leading-relaxed focus:outline-none focus:border-[#00205b] shadow-inner" placeholder="Type a message to display..."><?= htmlspecialchars($settings['announcement'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label class="block text-[9px] font-bold text-[#00205b] uppercase tracking-widest mb-1 drop-shadow-sm">Attachment Instructions (File Upload Area)</label>
                        <textarea name="attachment_instructions" <?= !$can_edit ? 'disabled' : '' ?> rows="3" required class="w-full bg-slate-50 border border-slate-300 rounded px-2.5 py-2 text-[11px] font-semibold text-slate-700 leading-relaxed focus:outline-none focus:border-[#00205b] shadow-inner" placeholder="Instructions for the upload area..."><?= htmlspecialchars($settings['attachment_instructions'] ?? '') ?></textarea>
                    </div>
                </div>
                
                <?php if ($can_edit): ?>
                    <div class="pt-3 flex justify-end border-t border-slate-100 mt-4">
                        <button type="submit" class="bg-[#00205b] hover:bg-[#003882] text-white px-4 py-1.5 rounded text-[9px] font-bold uppercase tracking-widest transition-colors flex items-center gap-1.5 shadow-sm cursor-pointer">
                            Save Changes <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        </button>
                    </div>
                <?php endif; ?>
            </form>

            <!-- Section 4: Process Steps -->
            <form method="POST" class="bg-white/95 backdrop-blur-md rounded-xl p-4 md:p-5 mb-5 shadow-sm border-t-[4px] border-t-[#00205b]">
                <input type="hidden" name="update_steps" value="1">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center border-b border-slate-200 pb-3 mb-4 gap-2">
                    <h3 class="text-xs font-black text-[#00205b] uppercase tracking-widest drop-shadow-sm flex items-center gap-2">
                        <div class="p-1 rounded-sm bg-[#00205b]/10"><svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg></div>
                        4. Process Overview Steps
                    </h3>
                    <?php if ($can_edit): ?>
                        <button type="button" onclick="window.addStep()" class="bg-white border border-slate-300 text-slate-600 px-3 py-1.5 rounded text-[9px] font-bold uppercase tracking-widest hover:bg-slate-50 transition-colors shadow-sm flex items-center gap-1.5 cursor-pointer">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4" /></svg> 
                            Add Step
                        </button>
                    <?php endif; ?>
                </div>

                <div id="steps-container" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    <?php 
                    for ($i = 1; $i <= $current_step_count; $i++): 
                        $t_val = htmlspecialchars($settings["step_{$i}_title"] ?? '');
                        $d_val = htmlspecialchars($settings["step_{$i}_desc"] ?? '');
                    ?>
                        <div class="step-card bg-slate-50 p-3 relative group transition-all duration-300 z-20 border border-slate-200 rounded-lg shadow-inner">
                            <div class="flex justify-between items-center mb-2 border-b border-slate-200 pb-1.5">
                                <h4 class="font-black text-[#00205b] text-[9px] uppercase tracking-widest step-header drop-shadow-sm flex items-center gap-1">
                                    <div class="w-3.5 h-3.5 rounded bg-[#00205b]/10 flex items-center justify-center text-[#00205b] text-[8px]"><?= $i ?></div>
                                    Step <?= $i ?>
                                </h4>
                                <?php if ($can_edit): ?>
                                    <div class="flex gap-1 opacity-80 group-hover:opacity-100 transition-opacity">
                                        <button type="button" onclick="window.removeSpecificStep(this)" class="text-rose-500 hover:text-white bg-rose-50 hover:bg-rose-500 p-1 rounded border border-rose-200 hover:border-rose-600 shadow-sm transition-colors cursor-pointer" title="Remove Step">
                                            <svg class="w-2.5 h-2.5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="space-y-2">
                                <div>
                                    <label class="block text-[8px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">Title</label>
                                    <input type="text" name="step_title[]" <?= !$can_edit ? 'disabled' : '' ?> value="<?= $t_val ?>" required class="w-full bg-white border border-slate-300 rounded px-2 py-1 text-[10px] font-bold text-[#00205b] focus:outline-none focus:border-[#00205b] shadow-sm">
                                </div>
                                <div>
                                    <label class="block text-[8px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">Description</label>
                                    <textarea name="step_desc[]" <?= !$can_edit ? 'disabled' : '' ?> rows="2" class="w-full bg-white border border-slate-300 rounded px-2 py-1 text-[10px] leading-relaxed text-slate-600 font-semibold focus:outline-none focus:border-[#00205b] shadow-sm"><?= $d_val ?></textarea>
                                </div>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
                
                <?php if ($can_edit): ?>
                    <div class="pt-3 flex justify-end border-t border-slate-100 mt-4">
                        <button type="submit" class="bg-[#00205b] hover:bg-[#003882] text-white px-4 py-1.5 rounded text-[9px] font-bold uppercase tracking-widest transition-colors flex items-center gap-1.5 shadow-sm cursor-pointer">
                            Save Changes <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        </button>
                    </div>
                <?php endif; ?>
            </form>

            <!-- Section 5: Enrollment Requirements -->
            <form method="POST" class="bg-white/95 backdrop-blur-md rounded-xl p-4 md:p-5 shadow-sm mb-10 border-t-[4px] border-t-[#00205b]">
                <input type="hidden" name="save_requirements" value="1">
                <h3 class="text-xs font-black text-[#00205b] uppercase tracking-widest border-b border-slate-200 pb-3 mb-3 drop-shadow-sm flex items-center gap-2">
                    <div class="p-1 rounded-sm bg-[#00205b]/10"><svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg></div>
                    5. Post-Admission Physical Requirements
                </h3>
                <p class="text-slate-500 text-[9px] font-bold uppercase tracking-widest mb-3 leading-relaxed drop-shadow-sm">Update the list of physical documents that students must submit. Put each requirement on a new line.</p>
                
                <div class="bg-slate-50 p-2.5 rounded border border-slate-200 shadow-inner">
                    <textarea name="requirements_text" <?= !$can_edit ? 'disabled' : '' ?> rows="5" class="w-full bg-white border border-slate-300 rounded px-2.5 py-2 text-[11px] font-semibold leading-relaxed text-slate-700 focus:outline-none focus:border-[#00205b]" required><?= htmlspecialchars($current_reqs) ?></textarea>
                </div>

                <?php if ($can_edit): ?>
                    <div class="pt-3 flex justify-end border-t border-slate-100 mt-4">
                        <button type="submit" class="bg-[#00205b] hover:bg-[#003882] text-white px-4 py-1.5 rounded text-[9px] font-bold uppercase tracking-widest transition-colors flex items-center gap-1.5 shadow-sm cursor-pointer">
                            Save Changes <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        </button>
                    </div>
                <?php endif; ?>
            </form>

        </div>
    </main>

    <!-- TOAST NOTIFICATION CONTAINER -->
    <div id="toastContainer" class="fixed top-5 right-5 z-[100] flex flex-col gap-2 pointer-events-none"></div>

    <!-- LINK SCRIPTS -->
    <script src="admission.js?v=<?= time() ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="portal_settings.js?v=<?= time() ?>"></script>

    <!-- DATA BRIDGE FOR ALERTS -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            window.showToast = function(message, type) {
                let container = document.getElementById('toastContainer');
                if (!container) return;

                const toast = document.createElement('div');
                const safeType = type ? type : 'success';
                const isSuccess = safeType === 'success';
                
                let bgColor = 'bg-rose-50 border-rose-200 text-rose-800';
                let iconPath = 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
                let iconColor = 'text-rose-500';
                
                if (isSuccess) {
                    bgColor = 'bg-emerald-50 border-emerald-200 text-emerald-800';
                    iconPath = 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z';
                    iconColor = 'text-emerald-500';
                }

                toast.className = `flex items-center gap-2 px-4 py-3 rounded-lg shadow-xl border ${bgColor} font-bold text-sm z-[9999] transition-all duration-300 translate-x-full opacity-0 mb-2`;
                toast.innerHTML = `<svg class="w-5 h-5 ${iconColor}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${iconPath}" /></svg><span class="tracking-wide">${message}</span>`;

                container.appendChild(toast);
                requestAnimationFrame(() => toast.classList.remove('translate-x-full', 'opacity-0'));
                setTimeout(() => {
                    toast.classList.add('translate-x-full', 'opacity-0');
                    setTimeout(() => toast.remove(), 400);
                }, 3500);
            };

            <?php if (!empty($success_msg)): ?> window.showToast("<?= addslashes($success_msg) ?>", 'success'); <?php endif; ?>
            <?php if (!empty($error_msg)): ?> window.showToast("<?= addslashes($error_msg) ?>", 'error'); <?php endif; ?>
        });
    </script>

</body>
</html>