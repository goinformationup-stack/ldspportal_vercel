<?php 
// --- 0. AJAX EMAIL VERIFICATION (STRICT ONE APPLICATION PER SCHOOL YEAR) ---
if (isset($_POST['ajax_check_email'])) {
    include "dbconn.php"; 
    header('Content-Type: application/json');
    
    $email = trim($conn->real_escape_string($_POST['email']));
    $sy = trim($conn->real_escape_string($_POST['school_year']));
    
    $check_cols = $conn->query("SHOW COLUMNS FROM admissions");
    $year_col = '';
    if ($check_cols) {
        while($col = $check_cols->fetch_assoc()) {
            $name = strtolower($col['Field']);
            if (in_array($name, ['school_year', 'academic_year', 'sy', 'ay', 'year'])) {
                $year_col = $col['Field'];
                break;
            }
        }
    }
    
    $exists = false;
    if (!empty($year_col)) {
        // Strict check: Is there ANY application with this email for the CURRENT school year?
        $q = $conn->query("SELECT admission_number FROM admissions WHERE email = '$email' AND `$year_col` = '$sy' LIMIT 1");
        if ($q && $q->num_rows > 0) $exists = true;
    } else {
        // Failsafe
        $q = $conn->query("SELECT admission_number FROM admissions WHERE email = '$email' LIMIT 1");
        if ($q && $q->num_rows > 0) $exists = true;
    }
    
    echo json_encode(['exists' => $exists]);
    exit;
}

include "dbconn.php"; 

// --- 1. FETCH DYNAMIC SETTINGS ---
$settings = [];
try {
    $check = $conn->query("SHOW TABLES LIKE 'portal_settings'");
    if ($check && $check->num_rows > 0) {
        $res = $conn->query("SELECT * FROM portal_settings");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
    }
} catch (Exception $e) {}

$admission_status = $settings['admission_status'] ?? 'Open';
$academic_year = $settings['academic_year'] ?? '2026-2027';
$semester = $settings['semester'] ?? '1st Semester';
$portal_title = $settings['portal_title'] ?? 'Admission Portal';
$announcement = $settings['announcement'] ?? 'Welcome to the Lyceum de San Pablo digital gateway. Online enrollment is now officially ongoing.';
$requirements = $settings['requirements'] ?? "1. Form 138 (Original Report Card)\n2. PSA Birth Certificate\n3. Certificate of Good Moral\n4. 2x2 ID Picture\n5. Long brown envelope & colored folder";
$attachment_instructions = $settings['attachment_instructions'] ?? 'Please upload all required documents listed in the instructions.';

// Get dynamic step count
$process_steps_count = isset($settings['process_steps_count']) ? (int)$settings['process_steps_count'] : 3;

// --- 2. THE SELF-LEARNING MASTERLIST FETCH ---
$db_schools_array = [];
try {
    $check_schools = $conn->query("SHOW TABLES LIKE 'tbl_schools'");
    if ($check_schools && $check_schools->num_rows > 0) {
        $res_schools = $conn->query("SELECT school_name FROM tbl_schools");
        if ($res_schools) {
            while ($row = $res_schools->fetch_assoc()) {
                $db_schools_array[] = $row['school_name'];
            }
        }
    }
} catch (Exception $e) {}

// --- 3. PRE-FILL LOGIC FOR RE-APPLYING ---
$reapply_data = null;
if (isset($_GET['reapply'])) {
    $adm_no = mysqli_real_escape_string($conn, $_GET['reapply']);
    $res = $conn->query("SELECT * FROM admissions WHERE admission_number = '$adm_no'");
    if ($res && $res->num_rows > 0) {
        $reapply_data = $res->fetch_assoc();
    }
}

// --- 4. AUTO-DETECT & FETCH ACADEMIC PROGRAMS ---
$active_programs = [];
try {
    $tables = ['programs', 'courses', 'tbl_programs', 'tbl_courses', 'academic_programs'];
    $target_table = '';
    
    foreach ($tables as $tbl) {
        $check = $conn->query("SHOW TABLES LIKE '$tbl'");
        if ($check && $check->num_rows > 0) {
            $target_table = $tbl;
            break;
        }
    }

    if ($target_table !== '') {
        $cols = $conn->query("SHOW COLUMNS FROM `$target_table`");
        $target_col = '';
        $has_archive_col = false;
        
        if ($cols) {
            while($col = $cols->fetch_assoc()) {
                $c = strtolower($col['Field']);
                if (in_array($c, ['program_name', 'course_name', 'name', 'title', 'program', 'course'])) {
                    $target_col = $col['Field'];
                }
                if ($c === 'is_archived') {
                    $has_archive_col = true;
                }
            }
        }

        if ($target_col !== '') {
            // Build query based on whether the archive column exists
            $sql = "SELECT `$target_col` FROM `$target_table`";
            if ($has_archive_col) {
                $sql .= " WHERE is_archived = 0 OR is_archived IS NULL";
            }
            $sql .= " ORDER BY `$target_col` ASC";
            
            $res_prog = $conn->query($sql);
            if ($res_prog && $res_prog->num_rows > 0) {
                while ($row = $res_prog->fetch_assoc()) {
                    $active_programs[] = $row[$target_col];
                }
            }
        }
    }
} catch (Exception $e) {}

if (empty($active_programs)) {
    $active_programs = [
        'BS Accountancy', 'BS Criminology', 'BS Information Systems', 
        'BS Tourism Management', 'BS Psychology', 
        'Bachelor of Technology & Livelihood Education', 
        'Bachelor of Early Childhood Education'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($portal_title) ?> - Lyceum de San Pablo</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/PapaParse/5.4.1/papaparse.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Ambient Background Orbs */
        .ambient-orb-1 {
            position: fixed; top: -10%; left: -10%; width: 50vw; height: 50vw;
            background: radial-gradient(circle, rgba(0,32,91,0.06) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%; pointer-events: none; z-index: 0;
        }
        .ambient-orb-2 {
            position: fixed; bottom: -10%; right: -10%; width: 60vw; height: 60vw;
            background: radial-gradient(circle, rgba(197,160,44,0.06) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%; pointer-events: none; z-index: 0;
        }

        .font-academic { font-family: 'Crimson Pro', serif; }
        
        /* Premium Glossy Panel */
        .glossy-panel { 
            background: linear-gradient(145deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.85) 100%);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 1);
            border-radius: 1rem; 
            box-shadow: 0 10px 30px -10px rgba(0, 32, 91, 0.1), inset 0 2px 4px rgba(255,255,255,1);
            position: relative;
            z-index: 10;
            overflow: hidden;
            transition: transform 0.5s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.5s ease;
        }

        .glossy-panel::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
            background: linear-gradient(90deg, var(--ldsp-blue) 0%, var(--ldsp-blue-light) 50%, var(--ldsp-gold) 100%);
            opacity: 0.9;
        }

        /* Animated Smooth Inputs */
        .input-glossy-smooth { 
            background: rgba(249, 250, 251, 0.7);
            border: 1px solid rgba(0, 32, 91, 0.12); 
            border-radius: 0.5rem; 
            padding: 0.625rem 0.875rem; 
            font-size: 0.875rem; 
            color: #111827; 
            width: 100%;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            outline: none;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.015), 0 1px 0 rgba(255,255,255,1);
        }
        .input-glossy-smooth:focus { 
            background: #ffffff;
            border-color: var(--ldsp-gold); 
            box-shadow: 0 0 0 3px rgba(197, 160, 44, 0.15), inset 0 1px 2px rgba(0,0,0,0.01); 
            transform: translateY(-1px);
        }
        .input-glossy-smooth:disabled { background: rgba(229, 231, 235, 0.5); color: #6b7280; cursor: not-allowed; box-shadow: none; }

        /* Glossy Animated Button */
        .btn-glossy-animated {
            background: linear-gradient(135deg, var(--ldsp-blue-light) 0%, var(--ldsp-blue) 100%);
            color: white; 
            font-weight: 600; 
            border-radius: 0.5rem; 
            padding: 0.75rem 1.5rem;
            font-size: 0.9rem;
            display: inline-flex; justify-content: center; align-items: center; gap: 8px;
            border: 1px solid rgba(0, 20, 60, 0.8);
            box-shadow: 0 6px 15px -4px rgba(0, 32, 91, 0.5), inset 0 2px 0 rgba(255, 255, 255, 0.2);
            position: relative; overflow: hidden; cursor: pointer;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .btn-glossy-animated::after {
            content: ''; position: absolute; top: 0; left: -100%; width: 50%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
            transform: skewX(-20deg);
            animation: shineSweep 5s infinite cubic-bezier(0.4, 0, 0.2, 1);
        }
        @keyframes shineSweep { 0% { left: -100%; } 20% { left: 200%; } 100% { left: 200%; } }
        
        .btn-glossy-animated:hover:not(:disabled) { 
            transform: translateY(-2px) scale(1.01);
            box-shadow: 0 8px 20px -6px rgba(0, 32, 91, 0.6), inset 0 2px 0 rgba(255, 255, 255, 0.3);
        }
        .btn-glossy-animated:active:not(:disabled) { 
            transform: translateY(1px) scale(0.99); 
            box-shadow: 0 2px 6px rgba(0, 32, 91, 0.4), inset 0 3px 5px rgba(0,0,0,0.2); 
        }

        .btn-gold {
            background: linear-gradient(135deg, #d4b242 0%, var(--ldsp-gold) 100%);
            color: white; border: 1px solid #a3821f;
            box-shadow: 0 4px 10px -3px rgba(197, 160, 44, 0.4), inset 0 2px 0 rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
        }
        .btn-gold:hover { transform: translateY(-1px); box-shadow: 0 6px 15px -3px rgba(197, 160, 44, 0.5), inset 0 2px 0 rgba(255, 255, 255, 0.4); }

        .glass-section {
            background: rgba(255, 255, 255, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.8);
            border-radius: 0.75rem;
            box-shadow: inset 0 1px 4px rgba(0,0,0,0.02);
        }

        .section-header {
            color: var(--ldsp-blue); font-weight: 700; font-size: 1rem;
            display: flex; align-items: center; gap: 0.5rem;
            margin-bottom: 1rem; border-bottom: 2px solid rgba(0,32,91,0.05); padding-bottom: 0.5rem;
        }
        
        .animate-up { opacity: 0; animation: fadeUpSmooth 0.7s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; } .delay-4 { animation-delay: 0.4s; }
        
        @keyframes fadeUpSmooth { 
            0% { opacity: 0; transform: translateY(20px) scale(0.99); filter: blur(2px); } 
            100% { opacity: 1; transform: translateY(0) scale(1); filter: blur(0); } 
        }
        
        .view-hidden { opacity: 0; visibility: hidden; position: absolute; pointer-events: none; transform: translateY(10px) scale(0.99); }
        .view-active { opacity: 1; visibility: visible; position: relative; transform: translateY(0) scale(1); transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1); }

        /* --- ADVANCED FILE UPLOAD ANIMATIONS --- */
        .file-spinner {
            width: 40px; height: 40px;
            border: 3px solid rgba(0, 32, 91, 0.08);
            border-top-color: var(--ldsp-gold);
            border-right-color: var(--ldsp-blue);
            border-radius: 50%;
            animation: spinFast 0.8s cubic-bezier(0.6, 0.2, 0.4, 0.8) infinite;
        }
        @keyframes spinFast { to { transform: rotate(360deg); } }

        .file-card {
            animation: slideInRight 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
            transform: translateX(10px);
        }
        @keyframes slideInRight { to { opacity: 1; transform: translateX(0); } }

        .custom-scrollbar::-webkit-scrollbar { width: 5px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(0,0,0,0.02); border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(0,32,91,0.2); border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(0,32,91,0.4); }
        
        .loading-icon { display: none; width: 14px; height: 14px; border: 2px solid rgba(0,32,91,0.2); border-top-color: var(--ldsp-blue); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        label { display: block; font-size: 0.7rem; font-weight: 700; color: var(--ldsp-blue-light); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.35rem; opacity: 0.85; }
    </style>
</head>
<body class="flex items-center justify-center p-4 sm:p-6 md:p-8">

    <div class="ambient-orb-1"></div>
    <div class="ambient-orb-2"></div>

    <!-- INSTRUCTIONS VIEW -->
    <div id="instructions-view" class="max-w-3xl w-full glossy-panel p-6 sm:p-8 md:p-10 <?= isset($_GET['reapply']) ? 'view-hidden' : 'view-active' ?>">
        
        <div class="text-center mb-8 pb-6 border-b border-gray-200/60 animate-up">
            <div class="inline-block mb-4 relative group">
                <div class="absolute inset-0 bg-gradient-to-r from-[var(--ldsp-blue)] to-[var(--ldsp-gold)] rounded-full blur-lg opacity-20 group-hover:opacity-40 transition-opacity duration-500"></div>
                <img src="logo.jpg" alt="LDSP Logo" class="relative w-20 h-20 sm:w-24 sm:h-24 object-contain mx-auto drop-shadow-md rounded-full bg-white p-1.5 border border-white">
            </div>
            
            <?php 
                $title_parts = explode(' ', htmlspecialchars($portal_title));
                $last_word = array_pop($title_parts);
                $first_part = implode(' ', $title_parts);
            ?>
            <h1 class="text-3xl sm:text-4xl font-black text-[#00205b] font-academic tracking-tight mb-3 drop-shadow-sm">
                <?= $first_part ?> <span class="text-transparent bg-clip-text bg-gradient-to-r from-[#c5a02c] to-[#d4b242]"><?= $last_word ?></span>
            </h1>
            
            <span class="inline-block bg-gradient-to-r from-[#003882] to-[#00205b] text-white font-bold text-[10px] uppercase tracking-widest px-4 py-1.5 rounded-full shadow-[inset_0_2px_0_rgba(255,255,255,0.2),0_2px_5px_rgba(0,32,91,0.2)]">
                <?= htmlspecialchars($semester) ?> &bull; A.Y. <?= htmlspecialchars($academic_year) ?>
            </span>
        </div>
        
        <div class="mb-8 bg-[#00205b]/5 border border-[#00205b]/10 rounded-xl p-4 sm:p-5 animate-up delay-1 hover:bg-[#00205b]/[0.07] transition-colors duration-300">
            <div class="flex flex-col sm:flex-row gap-4 items-start sm:items-center">
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-[#003882] to-[#00205b] text-white flex items-center justify-center shrink-0 shadow-md border border-white">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
                </div>
                <div class="text-gray-700 leading-relaxed text-sm font-medium">
                    <?= nl2br(htmlspecialchars($announcement)) ?>
                </div>
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
            <div class="glass-section p-5 animate-up delay-2 hover:-translate-y-1 transition-transform duration-300">
                <h3 class="section-header">
                    <svg class="w-4 h-4 text-[#c5a02c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Required Documents
                </h3>
                <div class="text-xs text-gray-700 font-medium leading-relaxed drop-shadow-sm">
                    <?= nl2br(htmlspecialchars($requirements)) ?>
                </div>
            </div>

            <div class="glass-section p-5 animate-up delay-3 hover:-translate-y-1 transition-transform duration-300">
                <h3 class="section-header">
                    <svg class="w-4 h-4 text-[#c5a02c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    Process Overview
                </h3>
                <ul class="text-xs text-gray-700 font-medium space-y-4">
                    <?php 
                    for ($i = 1; $i <= $process_steps_count; $i++) {
                        $step_title = $settings["step_{$i}_title"] ?? "Step {$i}";
                        $isActive = ($i < $process_steps_count);
                        $circle_bg = $isActive ? 'bg-gradient-to-br from-[#003882] to-[#00205b] text-white border-none shadow-[inset_0_2px_0_rgba(255,255,255,0.3),0_2px_4px_rgba(0,32,91,0.3)]' : 'bg-gray-100/50 text-gray-400 border border-gray-300';
                        $text_color = $isActive ? 'text-[#00205b]' : 'text-gray-400';

                        echo '
                        <li class="flex items-center gap-3 group">
                            <span class="w-6 h-6 ' . $circle_bg . ' flex items-center justify-center text-[10px] font-black rounded-full shrink-0 transition-transform group-hover:scale-110">' . $i . '</span> 
                            <span class="font-bold ' . $text_color . '">' . htmlspecialchars($step_title) . '</span>
                        </li>';
                    }
                    ?>
                </ul>
            </div>
        </div>

        <div class="flex flex-col items-center gap-6 pt-6 border-t border-gray-200/60 animate-up delay-4">
            <?php if ($admission_status === 'Open'): ?>
                <button onclick="switchView('form-view')" class="btn-glossy-animated w-full sm:w-auto text-sm sm:text-base">
                    Proceed to Application Form &rarr;
                </button>
            <?php else: ?>
                <button disabled class="btn-glossy-animated w-full sm:w-auto text-sm sm:text-base opacity-60">
                    Enrollment Currently Closed
                </button>
            <?php endif; ?>

            <div class="w-full max-w-sm mt-2 glass-section p-5 text-center shadow-md">
                <h4 class="text-[9px] font-black text-[#c5a02c] uppercase tracking-[0.15em] mb-3 drop-shadow-sm">Track Application Status</h4>
                <form action="track_status.php" method="GET" class="flex flex-col sm:flex-row gap-2">
                    <input type="text" name="adm_no" required placeholder="ADM-2026-XXXX" 
                           class="input-glossy-smooth font-mono text-center sm:text-left font-bold uppercase tracking-widest flex-1 text-xs">
                    <button type="submit" class="btn-gold px-5 py-2.5 rounded-lg text-xs font-black uppercase tracking-wider">
                        Search
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- FORM VIEW -->
    <div id="form-view" class="<?= isset($_GET['reapply']) ? 'view-active' : 'view-hidden' ?> max-w-4xl w-full glossy-panel p-6 sm:p-8">
        
        <header class="mb-8 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-gray-200/60 pb-6 animate-up">
            <div class="flex items-center gap-4">
                <button type="button" onclick="switchView('instructions-view')" class="text-gray-400 hover:text-[#00205b] bg-white p-2.5 rounded-full border border-gray-200 shadow-sm hover:shadow-md transition-all focus:outline-none" title="Return to Instructions">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                </button>
                
                <img src="logo.jpg" alt="LDSP Logo" class="w-10 h-10 object-contain hidden sm:block rounded-full shadow-sm border border-white">

                <div>
                    <h2 class="text-xl sm:text-2xl font-black text-[#00205b] font-academic tracking-tight leading-tight">Application Form</h2>
                    <p class="text-[9px] text-[#c5a02c] uppercase tracking-widest font-black">Student Registry Entry</p>
                </div>
            </div>
        </header>

        <?php if (isset($_GET['error']) && $_GET['error'] == 'email_exists'): ?>
            <div class="bg-rose-50/90 backdrop-blur-sm border border-rose-200 text-rose-800 p-4 rounded-xl mb-8 flex items-center gap-3 shadow-sm animate-up">
                <svg class="w-5 h-5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <span class="text-sm font-semibold">Error: This email address has already been used for an application this school year.</span>
            </div>
        <?php endif; ?>

            <form action="submit_admission.php" method="POST" enctype="multipart/form-data" class="space-y-6">
            <!-- Hidden field to explicitly send the Academic Year -->
            <input type="hidden" name="academic_year" value="<?= htmlspecialchars($academic_year) ?>">
            
            <?php if ($reapply_data): ?>
                <input type="hidden" name="reapply_adm_no" id="reapply_adm_no" value="<?= htmlspecialchars($reapply_data['admission_number']) ?>">
                <input type="hidden" id="original_reapply_email" value="<?= htmlspecialchars($reapply_data['email']) ?>">
            <?php endif; ?>

            <section class="animate-up delay-1 relative z-50">
                <h3 class="section-header">1. Academic Intent</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 glass-section p-5">
                    <div>
                        <label>Applicant Type</label>
                        <select name="student_type" class="input-glossy-smooth cursor-pointer font-semibold text-[#00205b]">
                            <option value="Freshman" <?= (isset($reapply_data['student_type']) && $reapply_data['student_type'] == 'Freshman') ? 'selected' : '' ?>>Freshman (New)</option>
                            <option value="Transferee" <?= (isset($reapply_data['student_type']) && $reapply_data['student_type'] == 'Transferee') ? 'selected' : '' ?>>Transferee (New)</option>
                            <option value="Regular" <?= (isset($reapply_data['student_type']) && $reapply_data['student_type'] == 'Regular') ? 'selected' : '' ?>>Regular (Old)</option>
                            <option value="Irregular" <?= (isset($reapply_data['student_type']) && $reapply_data['student_type'] == 'Irregular') ? 'selected' : '' ?>>Irregular (Old)</option>
                            <option value="Returnee" <?= (isset($reapply_data['student_type']) && $reapply_data['student_type'] == 'Returnee') ? 'selected' : '' ?>>Returnee (Old)</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2 md:col-span-1">
                        <label>Target Program</label>
                        <select name="program" required class="input-glossy-smooth cursor-pointer">
                            <option value="" disabled <?= empty($reapply_data['program']) ? 'selected' : '' ?>>Select Course...</option>
                            <?php foreach ($active_programs as $prog): ?>
                                <option value="<?= htmlspecialchars($prog) ?>" <?= (isset($reapply_data['program']) && $reapply_data['program'] == $prog) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($prog) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sm:col-span-2 md:col-span-1">
                        <label>Year Level</label>
                        <select name="year_level" required class="input-glossy-smooth cursor-pointer">
                            <option value="1st Year" <?= (isset($reapply_data['year_level']) && $reapply_data['year_level'] == '1st Year') ? 'selected' : '' ?>>1st Year</option>
                            <option value="2nd Year" <?= (isset($reapply_data['year_level']) && $reapply_data['year_level'] == '2nd Year') ? 'selected' : '' ?>>2nd Year</option>
                            <option value="3rd Year" <?= (isset($reapply_data['year_level']) && $reapply_data['year_level'] == '3rd Year') ? 'selected' : '' ?>>3rd Year</option>
                            <option value="4th Year" <?= (isset($reapply_data['year_level']) && $reapply_data['year_level'] == '4th Year') ? 'selected' : '' ?>>4th Year</option>
                        </select>
                    </div>
                </div>
            </section>

            <section class="animate-up delay-2 relative z-40">
                <h3 class="section-header">2. Personal Details</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 glass-section p-5">
                    
                    <div class="md:col-span-2">
                        <label>Applicant Name</label>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <input type="text" name="last_name" required value="<?= htmlspecialchars($reapply_data['last_name'] ?? '') ?>" placeholder="Last Name" class="input-glossy-smooth">
                            <input type="text" name="first_name" required value="<?= htmlspecialchars($reapply_data['first_name'] ?? '') ?>" placeholder="First Name" class="input-glossy-smooth">
                            
                            <!-- MIDDLE NAME IS CORRECTLY SET WITHOUT REQUIRED TAG -->
                            <input type="text" name="middle_name" value="<?= htmlspecialchars($reapply_data['middle_name'] ?? '') ?>" placeholder="Middle Name (Optional)" class="input-glossy-smooth">
                        </div>
                    </div>

                    <div>
                        <label>Email Address</label>
                        <input type="email" name="email" id="applicant_email" required value="<?= htmlspecialchars($reapply_data['email'] ?? '') ?>" placeholder="name@example.com" class="input-glossy-smooth">
                        <p id="email_warning" class="hidden text-rose-500 text-[10px] font-bold mt-1.5 tracking-widest uppercase flex items-center gap-1">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Email already used for this A.Y.
                        </p>
                        <p id="new_email_notice" class="hidden text-emerald-600 text-[10px] font-bold mt-1.5 tracking-widest uppercase flex items-center gap-1">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            New email detected. A new tracking number will be generated.
                        </p>
                    </div>
                    <div>
                        <label>Contact Number</label>
                        <input type="text" name="phone" required value="<?= htmlspecialchars($reapply_data['phone'] ?? '') ?>" placeholder="09XXXXXXXXX" class="input-glossy-smooth font-mono">
                    </div>
                    
                    <div>
                        <label>Gender</label>
                        <select name="gender" required class="input-glossy-smooth cursor-pointer">
                            <option value="" disabled <?= empty($reapply_data['gender']) ? 'selected' : '' ?>>Select Gender</option>
                            <option value="Male" <?= (isset($reapply_data['gender']) && $reapply_data['gender'] == 'Male') ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= (isset($reapply_data['gender']) && $reapply_data['gender'] == 'Female') ? 'selected' : '' ?>>Female</option>
                        </select>
                    </div>

                    <div>
                        <label>Date of Birth</label>
                        <div class="grid grid-cols-3 gap-2 relative">
                            
                            <!-- Custom Month Dropdown -->
                            <div class="relative dropdown-container">
                                <button type="button" class="input-glossy-smooth flex justify-between items-center w-full px-2 cursor-pointer bg-white" onclick="toggleDobDropdown('month_list')">
                                    <span id="display_month" class="truncate text-gray-500">MM</span>
                                    <svg class="w-3.5 h-3.5 text-gray-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </button>
                                <ul id="month_list" class="absolute z-[60] left-0 right-0 bg-white/95 backdrop-blur-md border border-gray-200 mt-1 rounded-lg hidden max-h-40 overflow-y-auto shadow-xl custom-scrollbar text-xs divide-y divide-gray-100">
                                    <?php
                                    $months = ["01"=>"Jan", "02"=>"Feb", "03"=>"Mar", "04"=>"Apr", "05"=>"May", "06"=>"Jun", "07"=>"Jul", "08"=>"Aug", "09"=>"Sep", "10"=>"Oct", "11"=>"Nov", "12"=>"Dec"];
                                    foreach($months as $num => $name) {
                                        echo "<li class=\"px-3 py-2 hover:bg-[#00205b]/5 cursor-pointer transition-colors text-center font-semibold text-[#00205b]\" onclick=\"selectDob('month', '$num', '$name')\">$name</li>";
                                    }
                                    ?>
                                </ul>
                            </div>
                            
                            <!-- Custom Day Dropdown -->
                            <div class="relative dropdown-container">
                                <button type="button" class="input-glossy-smooth flex justify-between items-center w-full px-2 cursor-pointer bg-white" onclick="toggleDobDropdown('day_list')">
                                    <span id="display_day" class="truncate text-gray-500">DD</span>
                                    <svg class="w-3.5 h-3.5 text-gray-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </button>
                                <ul id="day_list" class="absolute z-[60] left-0 right-0 bg-white/95 backdrop-blur-md border border-gray-200 mt-1 rounded-lg hidden max-h-40 overflow-y-auto shadow-xl custom-scrollbar text-xs divide-y divide-gray-100">
                                    <?php 
                                    for($i=1; $i<=31; $i++) { 
                                        $d = str_pad($i, 2, '0', STR_PAD_LEFT); 
                                        echo "<li class=\"px-3 py-2 hover:bg-[#00205b]/5 cursor-pointer transition-colors text-center font-semibold text-[#00205b]\" onclick=\"selectDob('day', '$d', '$d')\">$d</li>"; 
                                    } 
                                    ?>
                                </ul>
                            </div>

                            <!-- Custom Year Dropdown -->
                            <div class="relative dropdown-container">
                                <button type="button" class="input-glossy-smooth flex justify-between items-center w-full px-2 cursor-pointer bg-white" onclick="toggleDobDropdown('year_list')">
                                    <span id="display_year" class="truncate text-gray-500">YYYY</span>
                                    <svg class="w-3.5 h-3.5 text-gray-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </button>
                                <ul id="year_list" class="absolute z-[60] left-0 right-0 bg-white/95 backdrop-blur-md border border-gray-200 mt-1 rounded-lg hidden max-h-40 overflow-y-auto shadow-xl custom-scrollbar text-xs divide-y divide-gray-100">
                                    <?php 
                                    $currYear = date('Y'); 
                                    for($i=$currYear-5; $i>=$currYear-60; $i--) { 
                                        echo "<li class=\"px-3 py-2 hover:bg-[#00205b]/5 cursor-pointer transition-colors text-center font-semibold text-[#00205b]\" onclick=\"selectDob('year', '$i', '$i')\">$i</li>"; 
                                    } 
                                    ?>
                                </ul>
                            </div>
                        </div>
                        
                        <!-- Hidden Inputs to store the actual data for the form submission -->
                        <input type="hidden" id="dob_month_val">
                        <input type="hidden" id="dob_day_val">
                        <input type="hidden" id="dob_year_val">
                        <input type="hidden" name="dob" id="actual_dob" required value="<?= htmlspecialchars($reapply_data['dob'] ?? '') ?>">
                    </div>

                    <div class="md:col-span-2">
                        <label>Place of Birth</label>
                        <input type="text" name="pob" required value="<?= htmlspecialchars($reapply_data['pob'] ?? '') ?>" placeholder="City, Province" class="input-glossy-smooth">
                    </div>
                    
                    <div class="md:col-span-2">
                        <label>Complete Address</label>
                        
                        <?php if(!empty($reapply_data['address'])): ?>
                            <input type="text" readonly value="<?= htmlspecialchars($reapply_data['address']) ?>" class="input-glossy-smooth mb-3 !bg-[#00205b]/5 !text-[#00205b] font-semibold cursor-not-allowed border-[#00205b]/20" placeholder="Current Address">
                        <?php endif; ?>

                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 mb-1">
                            <select id="addr_region" class="input-glossy-smooth cursor-pointer"><option value="" disabled selected>Region</option></select>
                            <select id="addr_province" class="input-glossy-smooth cursor-pointer" disabled><option value="" disabled selected>Province</option></select>
                            <select id="addr_city" class="input-glossy-smooth cursor-pointer" disabled><option value="" disabled selected>City / Mun</option></select>
                            <select id="addr_brgy" class="input-glossy-smooth cursor-pointer" disabled><option value="" disabled selected>Barangay</option></select>
                        </div>
                        
                        <input type="hidden" name="address" id="actual_address" required value="<?= htmlspecialchars($reapply_data['address'] ?? '') ?>">
                    </div>
                </div>
            </section>

            <section class="animate-up delay-3 relative z-30">
                <h3 class="section-header">3. Educational Background</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 glass-section p-5">
                    <div class="relative w-full md:col-span-2" id="school-search-container">
                        <div class="flex justify-between items-center mb-1">
                            <label class="!mb-0">School Last Attended (Directory)</label>
                            <div id="school-loader" class="loading-icon"></div>
                        </div>
                        <input type="text" id="live-school-input" name="school_last_attended" required 
                               value="<?= htmlspecialchars($reapply_data['school_last_attended'] ?? '') ?>" 
                               placeholder="Start typing to search..." 
                               class="input-glossy-smooth" autocomplete="off">
                        <ul id="custom-school-dropdown" class="absolute z-50 w-full bg-white/95 backdrop-blur-md border border-gray-200 mt-1 rounded-lg hidden max-h-48 overflow-y-auto shadow-xl custom-scrollbar text-xs divide-y divide-gray-100">
                        </ul>
                    </div>
                    <div class="md:col-span-1">
                        <label>School Year Attended</label>
                        <input type="text" name="school_year_attended" required value="<?= htmlspecialchars($reapply_data['school_year_attended'] ?? '') ?>" placeholder="e.g. 2025-2026" class="input-glossy-smooth font-mono">
                    </div>
                </div>
            </section>

            <section class="animate-up delay-4 relative z-20">
                <h3 class="section-header">4. Family & Emergency</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 glass-section p-5">
                    <div>
                        <label>Father's Name</label>
                        <input type="text" name="father_name" required value="<?= htmlspecialchars($reapply_data['father_name'] ?? '') ?>" placeholder="First Last" class="input-glossy-smooth">
                    </div>
                    <div>
                        <label>Occupation</label>
                        <input type="text" name="father_occupation" required value="<?= htmlspecialchars($reapply_data['father_occupation'] ?? '') ?>" placeholder="Occupation" class="input-glossy-smooth">
                    </div>
                    <div>
                        <label>Contact Number</label>
                        <input type="text" name="father_contact" required value="<?= htmlspecialchars($reapply_data['father_contact'] ?? '') ?>" placeholder="09XXXXXXXXX" class="input-glossy-smooth font-mono">
                    </div>
                    
                    <div>
                        <label>Mother's Name</label>
                        <input type="text" name="mother_name" required value="<?= htmlspecialchars($reapply_data['mother_name'] ?? '') ?>" placeholder="First Last" class="input-glossy-smooth">
                    </div>
                    <div>
                        <label>Occupation</label>
                        <input type="text" name="mother_occupation" required value="<?= htmlspecialchars($reapply_data['mother_occupation'] ?? '') ?>" placeholder="Occupation" class="input-glossy-smooth">
                    </div>
                    <div>
                        <label>Contact Number</label>
                        <input type="text" name="mother_contact" required value="<?= htmlspecialchars($reapply_data['mother_contact'] ?? '') ?>" placeholder="09XXXXXXXXX" class="input-glossy-smooth font-mono">
                    </div>

                    <div class="md:col-span-3 mt-2">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-gradient-to-br from-[#c5a02c]/10 to-transparent border border-[#c5a02c]/30 p-4 rounded-xl shadow-inner relative overflow-hidden">
                            <div class="absolute top-0 right-0 w-24 h-24 bg-[#c5a02c] rounded-full blur-2xl opacity-10"></div>
                            <div class="sm:col-span-2 text-[10px] font-black text-[#00205b] uppercase tracking-[0.1em] mb-[-5px] relative z-10">
                                In Case of Emergency
                            </div>
                            <div class="relative z-10">
                                <label class="!text-[#00205b]">Contact Name</label>
                                <input type="text" name="emergency_contact_name" required value="<?= htmlspecialchars($reapply_data['emergency_contact_name'] ?? '') ?>" placeholder="Full Name" class="input-glossy-smooth !bg-white/80 focus:!bg-white focus:!border-[#c5a02c]">
                            </div>
                            <div class="relative z-10">
                                <label class="!text-[#00205b]">Contact Number</label>
                                <input type="text" name="emergency_contact_number" required value="<?= htmlspecialchars($reapply_data['emergency_contact_number'] ?? '') ?>" placeholder="09XXXXXXXXX" class="input-glossy-smooth !bg-white/80 focus:!bg-white focus:!border-[#c5a02c] font-mono">
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="animate-up delay-4 relative z-10">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 glass-section p-5">
                    <div class="md:col-span-2">
                        <label>Source of Information / Influence</label>
                        <select name="influence_source" class="input-glossy-smooth cursor-pointer w-full md:w-1/2">
                            <option value="" disabled <?= empty($reapply_data['influence_source']) ? 'selected' : '' ?>>Select an option...</option>
                            <option value="Parents" <?= (isset($reapply_data['influence_source']) && $reapply_data['influence_source'] == 'Parents') ? 'selected' : '' ?>>Parents</option>
                            <option value="Friends" <?= (isset($reapply_data['influence_source']) && $reapply_data['influence_source'] == 'Friends') ? 'selected' : '' ?>>Friends</option>
                            <option value="Location" <?= (isset($reapply_data['influence_source']) && $reapply_data['influence_source'] == 'Location') ? 'selected' : '' ?>>Location</option>
                            <option value="Flyers/Tarpaulin" <?= (isset($reapply_data['influence_source']) && $reapply_data['influence_source'] == 'Flyers/Tarpaulin') ? 'selected' : '' ?>>Flyers / Tarpaulin</option>
                            <option value="Faculty" <?= (isset($reapply_data['influence_source']) && $reapply_data['influence_source'] == 'Faculty') ? 'selected' : '' ?>>Faculty</option>
                            <option value="Social Media" <?= (isset($reapply_data['influence_source']) && $reapply_data['influence_source'] == 'Social Media') ? 'selected' : '' ?>>Social Media (Facebook / Google)</option>
                            <option value="School Counselor/Teacher" <?= (isset($reapply_data['influence_source']) && $reapply_data['influence_source'] == 'School Counselor/Teacher') ? 'selected' : '' ?>>School Counselor / Teacher</option>
                            <option value="Relative Studying Here" <?= (isset($reapply_data['influence_source']) && $reapply_data['influence_source'] == 'Relative Studying Here') ? 'selected' : '' ?>>Relative Studying Here</option>
                            <option value="Referral" <?= (isset($reapply_data['influence_source']) && $reapply_data['influence_source'] == 'Referral') ? 'selected' : '' ?>>Referral</option>
                        </select>
                    </div>
                </div>
            </section>

            <section class="animate-up delay-4 relative z-0">
                <h3 class="section-header">5. Document Attachment</h3>
                <?php if ($reapply_data): ?>
                    <div class="mb-4 bg-[#c5a02c]/20 border border-[#c5a02c] text-[#00205b] p-3 text-[9px] font-black tracking-[0.05em] uppercase rounded-lg flex items-center gap-2 shadow-sm backdrop-blur-sm">
                        <svg class="w-5 h-5 shrink-0 text-[#c5a02c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                        Action Required: You must re-upload all correct documents to proceed.
                    </div>
                <?php endif; ?>
                
                <!-- NEW INTERACTIVE MULTI-FILE UPLOAD SYSTEM -->
                <div class="glass-section p-5 sm:p-6 border-dashed border-2 border-gray-300 transition-all relative overflow-hidden" id="upload-wrapper">
                    
                    <input type="file" id="file-upload" name="requirements[]" multiple required class="hidden" onchange="handleFileSelection(this)">
                    
                    <!-- 1. Default State -->
                    <div id="upload-default" class="flex flex-col items-center justify-center cursor-pointer group py-3 transition-all duration-300 relative z-10" onclick="document.getElementById('file-upload').click()">
                        <div class="absolute inset-0 bg-[#00205b] opacity-0 group-hover:opacity-[0.02] transition-opacity rounded-xl"></div>
                        <svg class="w-10 h-10 text-gray-400 mb-3 transition-colors group-hover:text-[var(--ldsp-gold)]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                        <p class="text-gray-600 text-xs mb-4 font-semibold max-w-sm text-center mx-auto relative z-10"><?= nl2br(htmlspecialchars($attachment_instructions)) ?></p>
                        <span class="btn-glossy-animated !py-2 !px-6 !text-[11px] !rounded-full pointer-events-none relative z-10">Select Documents</span>
                    </div>

                    <!-- 2. Loading State -->
                    <div id="upload-loading" class="hidden flex-col items-center justify-center py-6 relative z-10">
                        <div class="file-spinner mb-4"></div>
                        <p class="text-[#00205b] font-black text-[10px] tracking-[0.1em] uppercase animate-pulse">Processing Files...</p>
                    </div>

                    <!-- 3. Success / List State -->
                    <div id="upload-success" class="hidden flex-col w-full text-left relative z-10">
                        <div class="flex justify-between items-center mb-4 pb-2 border-b border-gray-200/60">
                            <span class="text-[10px] font-black text-[#00205b] uppercase tracking-widest flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5 text-[#c5a02c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                Attached Files
                            </span>
                            <button type="button" class="text-[9px] font-black uppercase tracking-widest bg-[#c5a02c]/10 text-[#c5a02c] px-3 py-1 rounded-full hover:bg-[#c5a02c] hover:text-white transition-all shadow-sm border border-[#c5a02c]/20" onclick="document.getElementById('file-upload').click()">+ Add More</button>
                        </div>
                        
                        <!-- Scrollable list where file cards are dynamically inserted -->
                        <div id="file-list-container" class="max-h-48 overflow-y-auto custom-scrollbar pr-2 space-y-2">
                            <!-- Populated via Javascript -->
                        </div>
                    </div>

                </div>
            </section>

            <div class="pt-6 border-t border-gray-200/60 flex justify-end animate-up delay-4">
                <button type="submit" id="submit_btn" class="btn-glossy-animated w-full sm:w-auto text-sm sm:text-base">
                    <?= isset($reapply_data) ? 'Submit Updated Record' : 'Submit Official Application' ?>
                </button>
            </div>
        </form>
    </div>

    <!-- DOCUMENT OVERLAY MODAL (Messenger-Style) -->
    <div id="doc-modal" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/95 backdrop-blur-md transition-opacity duration-300 opacity-0">
        <!-- Close Button -->
        <button onclick="closeDocModal()" class="absolute top-5 right-5 p-2 bg-white/10 text-white rounded-full hover:bg-white/20 hover:text-[var(--ldsp-gold)] transition-colors z-[10000]">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>

        <!-- Zoom Controls (Only visible for Images) -->
        <div id="doc-zoom-controls" class="absolute bottom-6 left-1/2 -translate-x-1/2 flex items-center gap-4 bg-white/10 px-6 py-3 rounded-full backdrop-blur-lg border border-white/20 z-[10000] hidden">
            <button onclick="zoomDoc(-0.25)" class="text-white hover:text-[var(--ldsp-gold)] transition-colors p-1" title="Zoom Out">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" style="display:none"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path></svg>
            </button>
            <div class="h-4 w-px bg-white/30"></div>
            <button onclick="resetZoom()" class="text-xs font-bold tracking-widest text-white uppercase hover:text-[var(--ldsp-gold)] transition-colors">Reset</button>
            <div class="h-4 w-px bg-white/30"></div>
            <button onclick="zoomDoc(0.25)" class="text-white hover:text-[var(--ldsp-gold)] transition-colors p-1" title="Zoom In">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            </button>
        </div>

        <!-- Viewing Container -->
        <div class="relative w-full h-full flex items-center justify-center overflow-hidden" id="doc-container">
            <!-- Image Viewer (Pan & Zoom capable) -->
            <img id="doc-image" src="" alt="Document Preview" class="max-w-[90vw] max-h-[90vh] object-contain transition-transform duration-100 hidden cursor-grab" onmousedown="startDrag(event)">
            <!-- iFrame Fallback for PDFs -->
            <iframe id="doc-iframe" src="" class="w-[90vw] h-[90vh] bg-white rounded-xl shadow-2xl hidden border-0"></iframe>
        </div>
    </div>

    <script>
        // --- EMAIL DUPLICATION & NEW TRACKING NUMBER LOGIC ---
        const emailInput = document.getElementById('applicant_email');
        const emailWarning = document.getElementById('email_warning');
        const newEmailNotice = document.getElementById('new_email_notice');
        const submitBtn = document.getElementById('submit_btn');
        const activeSchoolYear = "<?= htmlspecialchars($academic_year) ?>";
        const origEmailEl = document.getElementById('original_reapply_email');
        const reapplyAdmEl = document.getElementById('reapply_adm_no');

        if (emailInput) {
            emailInput.addEventListener('input', function() {
                emailWarning.classList.add('hidden');
                emailInput.classList.remove('!border-rose-500', 'focus:!border-rose-500', 'bg-rose-50');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                }

                // Check for new email in reapply mode to trigger new Tracking Number
                if (origEmailEl && reapplyAdmEl) {
                    if (this.value.trim().toLowerCase() !== origEmailEl.value.trim().toLowerCase() && this.value.trim() !== '') {
                        reapplyAdmEl.disabled = true; // Omitting this triggers generation of a new ADM No. on the backend
                        if (newEmailNotice) newEmailNotice.classList.remove('hidden');
                    } else {
                        reapplyAdmEl.disabled = false; // Reuse the same tracking number
                        if (newEmailNotice) newEmailNotice.classList.add('hidden');
                    }
                }
            });

            emailInput.addEventListener('blur', function() {
                const email = this.value.trim();

                if (email) {
                    const formData = new FormData();
                    formData.append('ajax_check_email', '1');
                    formData.append('email', email);
                    formData.append('school_year', activeSchoolYear);

                    fetch('admission.php<?= isset($_GET['reapply']) ? "?reapply=".htmlspecialchars($_GET['reapply']) : "" ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.exists) {
                            emailWarning.classList.remove('hidden');
                            if (newEmailNotice) newEmailNotice.classList.add('hidden');
                            emailInput.classList.add('!border-rose-500', 'focus:!border-rose-500', 'bg-rose-50');
                            if (submitBtn) {
                                submitBtn.disabled = true;
                                submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                            }
                        }
                    })
                    .catch(error => console.error('Error checking email:', error));
                }
            });
            
            // Trigger blur on load to validate immediately if re-applying in the same year
            if (emailInput.value) {
                emailInput.dispatchEvent(new Event('blur'));
            }
        }

        // --- MULTI-FILE UPLOAD SYSTEM (STATE & UI) ---
        let currentFilesDataTransfer = new DataTransfer();

        function handleFileSelection(input) {
            if (!input.files || input.files.length === 0) {
                // If user hits cancel in dialog, restore the previously accumulated files
                input.files = currentFilesDataTransfer.files;
                return;
            }

            // Transition UI to loading state
            document.getElementById('upload-default').classList.add('hidden');
            document.getElementById('upload-success').classList.add('hidden');
            document.getElementById('upload-success').classList.remove('flex');
            document.getElementById('upload-wrapper').classList.add('border-[var(--ldsp-gold)]');
            
            const loadingState = document.getElementById('upload-loading');
            loadingState.classList.remove('hidden');
            loadingState.classList.add('flex');

            // Accumulate new files into our persistent DataTransfer object
            for(let i = 0; i < input.files.length; i++) {
                currentFilesDataTransfer.items.add(input.files[i]);
            }
            
            // Sync actual HTML input with our accumulated files
            input.files = currentFilesDataTransfer.files;

            // Simulate slight delay for animation effect
            setTimeout(() => {
                loadingState.classList.add('hidden');
                loadingState.classList.remove('flex');
                
                renderFileCards();
                
                document.getElementById('upload-success').classList.remove('hidden');
                document.getElementById('upload-success').classList.add('flex');
            }, 600); 
        }

        function renderFileCards() {
            const container = document.getElementById('file-list-container');
            container.innerHTML = ''; // clear current list

            const files = currentFilesDataTransfer.files;
            
            Array.from(files).forEach((file, index) => {
                const objectURL = URL.createObjectURL(file);
                const fileSize = (file.size / (1024 * 1024)).toFixed(2) + ' MB';
                
                const cardHtml = `
                    <div class="file-card flex items-center justify-between bg-white/70 p-2 sm:p-3 rounded-lg border border-gray-200 shadow-sm hover:shadow-md hover:bg-white transition-all group" style="animation-delay: ${index * 0.05}s">
                        <div class="flex items-center gap-2 sm:gap-3 overflow-hidden w-full pr-3">
                            <div class="bg-[#00205b]/5 p-1.5 rounded-md shrink-0 group-hover:bg-[#00205b]/10 transition-colors">
                                <svg class="w-5 h-5 text-[#c5a02c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            </div>
                            <div class="truncate">
                                <p class="text-xs font-bold text-[#00205b] truncate">${file.name}</p>
                                <p class="text-[9px] text-gray-500 font-semibold tracking-wider">${fileSize}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 shrink-0">
                            <!-- In-App Viewer Button -->
                            <button type="button" onclick="openDocModal('${objectURL}', '${file.type}'); event.stopPropagation()" class="p-1.5 bg-[#00205b]/5 text-[#00205b] border border-[#00205b]/10 rounded-md hover:bg-[#00205b] hover:text-white transition-all shadow-sm" title="Preview Document">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                            </button>
                            <!-- Remove Button -->
                            <button type="button" class="p-1.5 bg-white text-gray-400 border border-gray-200 rounded-md hover:border-[#c5a02c] hover:bg-[#c5a02c] hover:text-white transition-all shadow-sm" title="Remove Document" onclick="removeSpecificFile(${index}); event.stopPropagation()">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            </button>
                        </div>
                    </div>
                `;
                container.insertAdjacentHTML('beforeend', cardHtml);
            });
        }

        function removeSpecificFile(indexToRemove) {
            const tempTransfer = new DataTransfer();
            const currentFiles = currentFilesDataTransfer.files;
            
            // Repopulate without the deleted index
            for(let i = 0; i < currentFiles.length; i++) {
                if(i !== indexToRemove) {
                    tempTransfer.items.add(currentFiles[i]);
                }
            }
            
            // Update global state and hidden input
            currentFilesDataTransfer = tempTransfer;
            document.getElementById('file-upload').files = currentFilesDataTransfer.files;
            
            // Check if all files were removed
            if (currentFilesDataTransfer.files.length === 0) {
                document.getElementById('upload-success').classList.add('hidden');
                document.getElementById('upload-success').classList.remove('flex');
                document.getElementById('upload-wrapper').classList.remove('border-[var(--ldsp-gold)]');
                document.getElementById('upload-default').classList.remove('hidden');
            } else {
                renderFileCards();
            }
        }

        // --- DOCUMENT OVERLAY (MESSENGER-STYLE ZOOM / PAN) LOGIC ---
        let currentZoom = 1;
        let isDragging = false;
        let startX, startY, translateX = 0, translateY = 0;
        
        function openDocModal(url, type) {
            const modal = document.getElementById('doc-modal');
            const img = document.getElementById('doc-image');
            const iframe = document.getElementById('doc-iframe');
            const controls = document.getElementById('doc-zoom-controls');
            
            // Show Modal Container
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            // Trigger Fade-in
            setTimeout(() => { modal.classList.remove('opacity-0'); }, 10);
            
            // Reset transforms
            currentZoom = 1; translateX = 0; translateY = 0;
            updateTransform();

            // Check if it's an image or a PDF/Other
            if (type.startsWith('image/')) {
                img.src = url;
                img.classList.remove('hidden');
                iframe.classList.add('hidden');
                controls.classList.remove('hidden');
            } else {
                iframe.src = url;
                iframe.classList.remove('hidden');
                img.classList.add('hidden');
                controls.classList.add('hidden'); // Iframe handles its own zooming for PDFs
            }
        }

        function closeDocModal() {
            const modal = document.getElementById('doc-modal');
            modal.classList.add('opacity-0'); // Fade out
            
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                document.getElementById('doc-image').src = '';
                document.getElementById('doc-iframe').src = '';
            }, 300);
        }

        function zoomDoc(step) {
            currentZoom += step;
            if (currentZoom < 0.5) currentZoom = 0.5;
            if (currentZoom > 4) currentZoom = 4;
            updateTransform();
        }

        function resetZoom() {
            currentZoom = 1;
            translateX = 0;
            translateY = 0;
            updateTransform();
        }

        function updateTransform() {
            const img = document.getElementById('doc-image');
            img.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentZoom})`;
        }

        // Mouse Wheel Zooming
        document.getElementById('doc-container').addEventListener('wheel', (e) => {
            const img = document.getElementById('doc-image');
            if (!img.classList.contains('hidden')) {
                e.preventDefault();
                zoomDoc(e.deltaY > 0 ? -0.1 : 0.1);
            }
        }, { passive: false });

        // Click and Drag Panning
        function startDrag(e) {
            e.preventDefault();
            isDragging = true;
            startX = e.clientX - translateX;
            startY = e.clientY - translateY;
            document.getElementById('doc-image').classList.add('cursor-grabbing');
            document.getElementById('doc-image').classList.remove('cursor-grab');
        }

        window.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            translateX = e.clientX - startX;
            translateY = e.clientY - startY;
            updateTransform();
        });

        window.addEventListener('mouseup', () => {
            isDragging = false;
            document.getElementById('doc-image').classList.remove('cursor-grabbing');
            document.getElementById('doc-image').classList.add('cursor-grab');
        });

        // --- VIEW TRANSITIONS (SMOOTH) ---
        function switchView(viewId) {
            if (viewId === 'instructions-view' && window.location.search.includes('reapply')) {
                window.location.href = 'admission.php';
                return;
            }
            
            const formView = document.getElementById('form-view');
            const instView = document.getElementById('instructions-view');

            if (viewId === 'form-view') {
                instView.classList.remove('view-active');
                instView.classList.add('view-hidden');
                setTimeout(() => {
                    formView.classList.remove('view-hidden');
                    formView.classList.add('view-active');
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }, 400); 
            } else {
                formView.classList.remove('view-active');
                formView.classList.add('view-hidden');
                setTimeout(() => {
                    instView.classList.remove('view-hidden');
                    instView.classList.add('view-active');
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }, 400);
            }
        }

        // --- CUSTOM DATE OF BIRTH DROPDOWNS ---
        function toggleDobDropdown(id) {
            // Close all other dropdowns first
            document.querySelectorAll('.dropdown-container ul').forEach(ul => {
                if(ul.id !== id) ul.classList.add('hidden');
            });
            
            // Toggle the target dropdown
            const list = document.getElementById(id);
            list.classList.toggle('hidden');
        }

        function selectDob(type, value, text) {
            // Update the visual text on the button
            const displaySpan = document.getElementById('display_' + type);
            displaySpan.innerText = text;
            displaySpan.classList.remove('text-gray-500');
            displaySpan.classList.add('text-[#111827]', 'font-semibold');
            
            // Store the value in the dedicated hidden input
            document.getElementById('dob_' + type + '_val').value = value;
            
            // Hide the list
            document.getElementById(type + '_list').classList.add('hidden');
            
            // Update the final actual_dob input for form submission
            const m = document.getElementById('dob_month_val').value;
            const d = document.getElementById('dob_day_val').value;
            const y = document.getElementById('dob_year_val').value;
            const actualDob = document.getElementById('actual_dob');
            
            if (m && d && y) {
                actualDob.value = `${y}-${m}-${d}`;
            }
        }

        // Global listener to close dropdowns when clicking outside of them
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dropdown-container')) {
                document.querySelectorAll('.dropdown-container ul').forEach(ul => {
                    ul.classList.add('hidden');
                });
            }
        });

        // Pre-fill logic for re-applying students
        document.addEventListener("DOMContentLoaded", () => {
            const existingDob = document.getElementById('actual_dob').value;
            if(existingDob) {
                const parts = existingDob.split('-'); 
                if(parts.length === 3) {
                    // yyyy-mm-dd format
                    const months = {"01":"Jan", "02":"Feb", "03":"Mar", "04":"Apr", "05":"May", "06":"Jun", "07":"Jul", "08":"Aug", "09":"Sep", "10":"Oct", "11":"Nov", "12":"Dec"};
                    selectDob('year', parts[0], parts[0]);
                    selectDob('month', parts[1], months[parts[1]]);
                    selectDob('day', parts[2], parts[2]);
                }
            }
        });

        // --- SCHOOL SEARCH LOGIC ---
        const schoolInput = document.getElementById('live-school-input');
        const dropdown = document.getElementById('custom-school-dropdown');
        const loader = document.getElementById('school-loader');
        let nationalSchoolRegistry = [];
        const dbSchools = <?php echo json_encode($db_schools_array); ?>;

        document.addEventListener("DOMContentLoaded", () => {
            loader.style.display = 'block';
            dbSchools.forEach(school => { nationalSchoolRegistry.push(school); });

            Papa.parse("schools_masterlist.csv", {
                download: true,
                header: true,
                skipEmptyLines: true,
                complete: function(results) {
                    const csvSchools = results.data.map(row => row.school_name).filter(name => name);
                    nationalSchoolRegistry = [...new Set([...nationalSchoolRegistry, ...csvSchools])];
                    loader.style.display = 'none';
                },
                error: function(err) {
                    console.error("Could not find CSV data.", err);
                    loader.style.display = 'none';
                }
            });
        });

        window.selectSchool = function(fullSchoolName) {
            schoolInput.value = fullSchoolName;
            dropdown.classList.add('hidden');
        };

        window.forceRecordSchool = function(newSchoolName) {
            schoolInput.value = newSchoolName;
            dropdown.classList.add('hidden');
        };

        document.addEventListener('click', function(e) {
            if (!document.getElementById('school-search-container').contains(e.target)) {
                dropdown.classList.add('hidden');
            }
        });

        schoolInput.addEventListener('input', function(e) {
            const query = e.target.value.trim().toLowerCase();
            if (query.length < 2) {
                dropdown.classList.add('hidden');
                return;
            }

            let optionsHtml = `
                <li class="px-4 py-3 bg-[#f8fafc] hover:bg-[#f1f5f9] cursor-pointer transition-colors" onclick="forceRecordSchool('${e.target.value.replace(/'/g, "\\'")}')">
                    <div class="text-[var(--ldsp-blue)] font-black text-[9px] uppercase tracking-widest mb-1">Unlisted Institution?</div>
                    <div class="text-gray-600 text-xs font-medium">Record exactly as typed: <span class="font-bold text-[#00205b] bg-white px-1.5 py-0.5 border border-gray-200 rounded shadow-sm">"${e.target.value}"</span></div>
                </li>
            `;

            let matchCount = 0;
            for (let i = 0; i < nationalSchoolRegistry.length; i++) {
                if (nationalSchoolRegistry[i].toLowerCase().includes(query)) {
                    optionsHtml += `
                        <li class="px-4 py-2.5 hover:bg-[#f8fafc] cursor-pointer transition-colors flex justify-between items-center gap-3" onclick="selectSchool('${nationalSchoolRegistry[i].replace(/'/g, "\\'")}')">
                            <div class="text-[#00205b] font-semibold text-xs truncate">${nationalSchoolRegistry[i]}</div>
                            <span class="text-[8px] px-1.5 py-0.5 uppercase font-black tracking-[0.1em] bg-[var(--ldsp-blue)] text-white rounded">Directory</span>
                        </li>
                    `;
                    matchCount++;
                    if (matchCount >= 25) break; 
                }
            }

            dropdown.innerHTML = optionsHtml;
            dropdown.classList.remove('hidden');
        });

        schoolInput.addEventListener('focus', function() {
            if (dropdown.innerHTML.trim() !== '') {
                dropdown.classList.remove('hidden');
            }
        });

        // --- PSGC COMPLETE ADDRESS LOGIC ---
        const regionSel = document.getElementById('addr_region');
        const provSel = document.getElementById('addr_province');
        const citySel = document.getElementById('addr_city');
        const brgySel = document.getElementById('addr_brgy');
        const actualAddr = document.getElementById('actual_address');

        if (regionSel) {
            async function fetchLocation(url) {
                try {
                    const res = await fetch(url);
                    return await res.json();
                } catch(e) { return []; }
            }

            async function loadRegions() {
                const regions = await fetchLocation('https://psgc.gitlab.io/api/regions/');
                regions.sort((a,b) => a.name.localeCompare(b.name)).forEach(r => {
                    regionSel.add(new Option(r.name, r.code));
                });
            }

            regionSel.addEventListener('change', async () => {
                provSel.innerHTML = '<option value="" disabled selected>Province</option>';
                citySel.innerHTML = '<option value="" disabled selected>City / Mun</option>';
                brgySel.innerHTML = '<option value="" disabled selected>Barangay</option>';
                provSel.disabled = false; citySel.disabled = true; brgySel.disabled = true;

                const provinces = await fetchLocation(`https://psgc.gitlab.io/api/regions/${regionSel.value}/provinces/`);
                if (provinces.length > 0) {
                    provinces.sort((a,b) => a.name.localeCompare(b.name)).forEach(p => provSel.add(new Option(p.name, p.code)));
                } else {
                    provSel.add(new Option('- Metro Manila (NCR) -', 'NCR'));
                    provSel.value = 'NCR';
                    provSel.dispatchEvent(new Event('change'));
                }
                updateAddress();
            });

            provSel.addEventListener('change', async () => {
                citySel.innerHTML = '<option value="" disabled selected>City / Mun</option>';
                brgySel.innerHTML = '<option value="" disabled selected>Barangay</option>';
                citySel.disabled = false; brgySel.disabled = true;

                let cities = [];
                if (provSel.value === 'NCR') {
                    cities = await fetchLocation(`https://psgc.gitlab.io/api/regions/${regionSel.value}/cities-municipalities/`);
                } else {
                    cities = await fetchLocation(`https://psgc.gitlab.io/api/provinces/${provSel.value}/cities-municipalities/`);
                }
                cities.sort((a,b) => a.name.localeCompare(b.name)).forEach(c => citySel.add(new Option(c.name, c.code)));
                updateAddress();
            });

            citySel.addEventListener('change', async () => {
                brgySel.innerHTML = '<option value="" disabled selected>Barangay</option>';
                brgySel.disabled = false;
                
                const brgys = await fetchLocation(`https://psgc.gitlab.io/api/cities-municipalities/${citySel.value}/barangays/`);
                brgys.sort((a,b) => a.name.localeCompare(b.name)).forEach(b => brgySel.add(new Option(b.name, b.code)));
                updateAddress();
            });

            brgySel.addEventListener('change', updateAddress);

            function updateAddress() {
                const parts = [];
                if (brgySel.selectedIndex > 0) parts.push(brgySel.options[brgySel.selectedIndex].text);
                if (citySel.selectedIndex > 0) parts.push(citySel.options[citySel.selectedIndex].text);
                if (provSel.selectedIndex > 0 && provSel.value !== 'NCR') parts.push(provSel.options[provSel.selectedIndex].text);
                if (regionSel.selectedIndex > 0) parts.push(regionSel.options[regionSel.selectedIndex].text);
                
                if (parts.length > 0) {
                    actualAddr.value = parts.join(', ');
                }
            }

            loadRegions();
        }
    </script>
</body>
</html>