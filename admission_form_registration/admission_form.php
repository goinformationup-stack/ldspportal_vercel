<?php 
// --- 0. AJAX EMAIL VERIFICATION (STRICT DUPLICATE PREVENTION V2) ---
if (isset($_POST['ajax_check_email'])) {
    include "../dbconn.php"; 
    header('Content-Type: application/json');
    
    $email = trim($conn->real_escape_string($_POST['email'] ?? ''));
    $sy = trim($conn->real_escape_string($_POST['school_year'] ?? ''));
    
    $exists = false;
    
    // 1. Check pending/existing admissions
    $check_cols = $conn->query("SHOW COLUMNS FROM admissions LIKE 'school_year'");
    if ($check_cols && $check_cols->num_rows > 0) {
        $q = $conn->query("SELECT admission_number FROM admissions WHERE email = '$email' AND school_year = '$sy' LIMIT 1");
    } else {
        $q = $conn->query("SELECT admission_number FROM admissions WHERE email = '$email' LIMIT 1");
    }
    if ($q && $q->num_rows > 0) $exists = true;

    // 2. Check official users table (V2 Integration Failsafe)
    $q_user = $conn->query("SELECT id FROM users WHERE email = '$email' LIMIT 1");
    if ($q_user && $q_user->num_rows > 0) $exists = true;
    
    echo json_encode(['exists' => $exists]);
    exit;
}

// --- 0.5 AJAX AUTO-LEARN NEW SCHOOLS (SELF-UPDATING MASTERLIST) ---
if (isset($_POST['ajax_add_school'])) {
    include "../dbconn.php"; 
    header('Content-Type: application/json');
    $school = trim($conn->real_escape_string($_POST['school_name'] ?? ''));
    if (!empty($school)) {
        // Create table if not exists (Failsafe)
        $conn->query("CREATE TABLE IF NOT EXISTS tbl_schools (id INT AUTO_INCREMENT PRIMARY KEY, school_name VARCHAR(255) UNIQUE)");
        // Insert new school
        $conn->query("INSERT IGNORE INTO tbl_schools (school_name) VALUES ('$school')");
    }
    echo json_encode(['status' => 'success']);
    exit;
}

include "../dbconn.php"; 

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

$academic_year = $settings['academic_year'] ?? '2026-2027';
$attachment_instructions = $settings['attachment_instructions'] ?? 'Please upload all required documents listed in the instructions.';

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
    $adm_no = $conn->real_escape_string($_GET['reapply']);
    $res = $conn->query("SELECT * FROM admissions WHERE admission_number = '$adm_no'");
    if ($res && $res->num_rows > 0) {
        $reapply_data = $res->fetch_assoc();
    }
}

// --- 4. FETCH ACADEMIC PROGRAMS (OPTIMIZED V2) ---
$active_programs = [];
try {
    $res_prog = $conn->query("SELECT program_name FROM programs WHERE is_archived = 0 OR is_archived IS NULL ORDER BY program_name ASC");
    if (!$res_prog) $res_prog = $conn->query("SELECT program_name FROM programs ORDER BY program_name ASC"); // Fallback
    
    if ($res_prog && $res_prog->num_rows > 0) {
        while ($row = $res_prog->fetch_assoc()) {
            $active_programs[] = $row['program_name'];
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
    <title>Application Form - Lyceum de San Pablo</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- REQUIRED: PapaParse for CSV School Masterlist -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/PapaParse/5.4.1/papaparse.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../Style.css">
    
    <style>
        /* Custom Loading Spinner Animation */
        @keyframes custom-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .loading-spinner {
            animation: custom-spin 1.2s linear infinite;
        }
        
        /* Smooth transition for highlighting missing inputs */
        .input-glossy-smooth {
            transition: all 0.3s ease;
        }
        
        /* Highlight Class for missing fields */
        .error-highlight {
            transition: all 0.3s ease !important;
        }
    </style>
</head>
<body class="flex items-center justify-center p-4 sm:p-6 md:p-8 relative">

    <!-- FULL SCREEN SUBMISSION LOADING OVERLAY -->
    <div id="full-screen-loader" class="fixed inset-0 z-[100000] hidden items-center justify-center bg-slate-900/80 backdrop-blur-sm transition-opacity duration-300 opacity-0">
        <div class="bg-white p-8 rounded-2xl shadow-2xl flex flex-col items-center max-w-sm w-full text-center border-t-4 border-[#00205b] transform transition-all scale-95 opacity-0" id="loader-content">
            <!-- Dark Indigo Spinner -->
            <svg class="w-12 h-12 text-indigo-900 mb-4 loading-spinner" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <h3 class="text-lg font-black text-[#00205b] uppercase tracking-wider mb-2">Processing Application</h3>
            <p class="text-xs text-slate-500 font-medium">Please wait while we generate your secure admission number and send your confirmation email...</p>
        </div>
    </div>

    <!-- FLOATING FILE SIZE DENIAL TOAST -->
    <div id="file-size-toast" class="fixed top-6 left-1/2 -translate-x-1/2 z-[10000] hidden bg-white/95 backdrop-blur-md border border-rose-200 shadow-2xl p-4 rounded-2xl transform transition-all duration-300 translate-y-[-20px] opacity-0 w-[90vw] sm:w-[400px]">
        <div class="flex items-start gap-4">
            <div class="bg-rose-50 border border-rose-100 p-2.5 rounded-xl shrink-0 shadow-sm mt-0.5">
                <svg class="w-6 h-6 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
            </div>
            <div class="flex-1 pt-1">
                <p class="text-[14px] font-black text-[#00205b] tracking-tight">File Exceeds Limit</p>
                <p class="text-[12px] text-gray-500 font-medium mt-1 leading-relaxed">The selected file is larger than 5MB. Please compress it or select a smaller one.</p>
            </div>
            <button type="button" onclick="document.getElementById('file-size-toast').classList.add('hidden')" class="text-gray-400 hover:text-rose-600 hover:bg-rose-50 transition-colors p-1.5 rounded-lg shrink-0 mt-0.5 focus:outline-none">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
    </div>

    <!-- FLOATING VALIDATION WARNING TOAST (DARK INDIGO THEME) -->
    <div id="validation-toast" class="fixed top-6 left-1/2 -translate-x-1/2 z-[10000] hidden bg-white/95 backdrop-blur-md border border-indigo-300 shadow-2xl p-4 rounded-2xl transform transition-all duration-300 translate-y-[-20px] opacity-0 w-[90vw] sm:w-[400px]">
        <div class="flex items-start gap-4">
            <div class="bg-indigo-900 border border-indigo-800 p-2.5 rounded-xl shrink-0 shadow-sm mt-0.5 text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
            </div>
            <div class="flex-1 pt-1">
                <p class="text-[14px] font-black text-[#00205b] tracking-tight">Missing Information</p>
                <p id="validation-toast-message" class="text-[12px] text-gray-500 font-medium mt-1 leading-relaxed">Please complete the required fields.</p>
            </div>
            <button type="button" onclick="hideValidationToast()" class="text-gray-400 hover:text-indigo-900 hover:bg-indigo-50 transition-colors p-1.5 rounded-lg shrink-0 mt-0.5 focus:outline-none">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
    </div>

    <div class="ambient-orb-1"></div>
    <div class="ambient-orb-2"></div>

    <div class="max-w-4xl w-full glossy-panel p-6 sm:p-8 relative z-10 animate-up view-active" id="form-view">
        
        <header class="mb-8 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-gray-200/60 pb-6">
            <div class="flex items-center gap-4">
                <a href="admission_portal.php" class="text-gray-400 hover:text-[#00205b] bg-white p-2.5 rounded-full border border-gray-200 shadow-sm hover:shadow-md transition-all focus:outline-none flex items-center justify-center" title="Return to Instructions">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                </a>
                <img src="../logo.jpg" alt="LDSP Logo" class="w-10 h-10 object-contain hidden sm:block rounded-full shadow-sm border border-white">
                <div>
                    <h2 class="text-xl sm:text-2xl font-black text-[#00205b] font-academic tracking-tight leading-tight">Application Form</h2>
                </div>
            </div>
        </header>

        <?php if (isset($_GET['error']) && $_GET['error'] == 'email_exists'): ?>
            <div class="bg-rose-50/90 backdrop-blur-sm border border-rose-200 text-rose-800 p-4 rounded-xl mb-8 flex items-center gap-3 shadow-sm">
                <svg class="w-5 h-5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <span class="text-sm font-semibold">Error: This email address has already been used for an application this school year.</span>
            </div>
        <?php endif; ?>

        <form action="../submit_admission.php" method="POST" enctype="multipart/form-data" class="space-y-6" id="admissionForm" novalidate onsubmit="return lockSubmitButton(event);">
            <input type="hidden" name="academic_year" value="<?= htmlspecialchars($academic_year) ?>">
            
            <?php if ($reapply_data): ?>
                <input type="hidden" name="reapply_adm_no" id="reapply_adm_no" value="<?= htmlspecialchars($reapply_data['admission_number']) ?>">
                <input type="hidden" id="original_reapply_email" value="<?= htmlspecialchars($reapply_data['email']) ?>">
            <?php endif; ?>

            <section class="animate-up delay-1 relative z-50">
                <h3 class="section-header">1. Academic Intent</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 glass-section p-5">
                    <div>
                        <label>Applicant Type <span class="text-rose-500">*</span></label>
                        <select name="student_type" required class="input-glossy-smooth cursor-pointer font-semibold text-[#00205b]">
                            <option value="Freshman" <?= (isset($reapply_data['student_type']) && $reapply_data['student_type'] == 'Freshman') ? 'selected' : '' ?>>Freshman (New)</option>
                            <option value="Transferee" <?= (isset($reapply_data['student_type']) && $reapply_data['student_type'] == 'Transferee') ? 'selected' : '' ?>>Transferee (New)</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2 md:col-span-1">
                        <label>Target Program <span class="text-rose-500">*</span></label>
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
                        <label>Year Level <span class="text-rose-500">*</span></label>
                        <select name="year_level" required class="input-glossy-smooth cursor-pointer">
                            <option value="" disabled <?= empty($reapply_data['year_level']) ? 'selected' : '' ?>>Select Year...</option>
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
                        <label>Applicant Name <span class="text-rose-500">*</span></label>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <input type="text" name="last_name" required value="<?= htmlspecialchars($reapply_data['last_name'] ?? '') ?>" placeholder="Last Name" class="input-glossy-smooth" oninput="formatProperName(this)">
                            <input type="text" name="first_name" required value="<?= htmlspecialchars($reapply_data['first_name'] ?? '') ?>" placeholder="First Name" class="input-glossy-smooth" oninput="formatProperName(this)">
                            <input type="text" name="middle_name" required value="<?= htmlspecialchars($reapply_data['middle_name'] ?? '') ?>" placeholder="Middle Name" class="input-glossy-smooth" oninput="formatProperName(this)">
                        </div>
                    </div>

                    <div>
                        <label>Email Address <span class="text-rose-500">*</span></label>
                        <input type="email" name="email" id="applicant_email" required value="<?= htmlspecialchars($reapply_data['email'] ?? '') ?>" placeholder="name@example.com" class="input-glossy-smooth lowercase" oninput="this.value = this.value.toLowerCase();">
                        <p id="email_warning" class="hidden text-rose-500 text-[10px] font-bold mt-1.5 tracking-widest uppercase flex items-center gap-1">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Email already used.
                        </p>
                        <p id="new_email_notice" class="hidden text-emerald-600 text-[10px] font-bold mt-1.5 tracking-widest uppercase flex items-center gap-1">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            New email detected. A new tracking number will be generated.
                        </p>
                    </div>
                    <div>
                        <label>Contact Number <span class="text-rose-500">*</span></label>
                        <input type="text" name="phone" required value="<?= htmlspecialchars($reapply_data['phone'] ?? '') ?>" placeholder="09XXXXXXXXX" class="input-glossy-smooth font-mono" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    </div>
                    
                    <div>
                        <label>Gender <span class="text-rose-500">*</span></label>
                        <select name="gender" required class="input-glossy-smooth cursor-pointer">
                            <option value="" disabled <?= empty($reapply_data['gender']) ? 'selected' : '' ?>>Select Gender</option>
                            <option value="Male" <?= (isset($reapply_data['gender']) && $reapply_data['gender'] == 'Male') ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= (isset($reapply_data['gender']) && $reapply_data['gender'] == 'Female') ? 'selected' : '' ?>>Female</option>
                        </select>
                    </div>

                    <div>
                        <label>Date of Birth <span class="text-rose-500">*</span></label>
                        <div class="grid grid-cols-3 gap-2 relative transition-all duration-300" id="dob_container">
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
                        
                        <input type="hidden" id="dob_month_val">
                        <input type="hidden" id="dob_day_val">
                        <input type="hidden" id="dob_year_val">
                        <input type="hidden" name="dob" id="actual_dob" required value="<?= htmlspecialchars($reapply_data['dob'] ?? '') ?>">
                    </div>

                    <!-- OpenStreetMap Map Search for Place of Birth -->
                    <div class="relative w-full md:col-span-2" id="pob-search-container">
                        <div class="flex justify-between items-center mb-1">
                            <label class="!mb-0">Place of Birth (Map Search) <span class="text-rose-500">*</span></label>
                            <div id="pob-loader" class="hidden w-3 h-3 border-2 border-blue-200 border-t-blue-600 rounded-full animate-spin"></div>
                        </div>
                        <input type="text" id="live-pob-input" name="pob" required 
                               value="<?= htmlspecialchars($reapply_data['pob'] ?? '') ?>" 
                               placeholder="Search city, province..." 
                               class="input-glossy-smooth font-semibold w-full" autocomplete="off">
                        <ul id="custom-pob-dropdown" class="absolute z-50 w-full bg-white/95 backdrop-blur-md border border-gray-200 mt-1 rounded-lg hidden max-h-48 overflow-y-auto shadow-xl custom-scrollbar text-xs divide-y divide-gray-100">
                        </ul>
                    </div>
                    
                    <div class="md:col-span-2 mt-4">
                        <label>Complete Address <span class="text-rose-500">*</span></label>
                        
                        <?php if(!empty($reapply_data['address'])): ?>
                            <input type="text" readonly value="<?= htmlspecialchars($reapply_data['address']) ?>" class="input-glossy-smooth mb-3 !bg-[#00205b]/5 !text-[#00205b] font-semibold cursor-not-allowed border-[#00205b]/20" placeholder="Current Address">
                        <?php endif; ?>

                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 mb-1 transition-all duration-300" id="address_container">
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
                    
                    <!-- HYBRID SCHOOL SEARCH LOGIC -->
                    <div class="relative w-full md:col-span-2" id="school-search-container">
                        <div class="flex justify-between items-center mb-1">
                            <label class="!mb-0">School Last Attended (Global Search) <span class="text-rose-500">*</span></label>
                            <div id="school-loader" class="hidden w-3 h-3 border-2 border-blue-200 border-t-blue-600 rounded-full animate-spin"></div>
                        </div>
                        <input type="text" id="live-school-input" name="school_last_attended" required 
                               value="<?= htmlspecialchars($reapply_data['school_last_attended'] ?? '') ?>" 
                               placeholder="Search school name or location..." 
                               class="input-glossy-smooth font-semibold w-full" autocomplete="off">
                        <ul id="custom-school-dropdown" class="absolute z-50 w-full bg-white/95 backdrop-blur-md border border-gray-200 mt-1 rounded-lg hidden max-h-48 overflow-y-auto shadow-xl custom-scrollbar text-xs divide-y divide-gray-100">
                        </ul>
                    </div>
                    
                    <!-- NEW SPLIT SCHOOL YEAR BOXES -->
                    <div class="md:col-span-1">
                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">School Year Attended <span class="text-rose-500">*</span></label>
                        <div class="flex items-center gap-2 transition-all duration-300" id="sy_container">
                            <input type="text" id="sy_start" maxlength="4" placeholder="Start" class="input-glossy-smooth font-mono text-center !px-2 w-full" oninput="this.value = this.value.replace(/[^0-9]/g, ''); window.updateSchoolYear();">
                            <span class="text-slate-400 font-black">-</span>
                            <input type="text" id="sy_end" maxlength="4" placeholder="End" readonly class="input-glossy-smooth font-mono text-center !px-2 w-full !bg-slate-100 text-slate-500 cursor-not-allowed border-slate-200">
                        </div>
                        <input type="hidden" name="school_year_attended" id="actual_school_year" required value="<?= htmlspecialchars($reapply_data['school_year_attended'] ?? '') ?>">
                    </div>

                </div>
            </section>

            <section class="animate-up delay-4 relative z-20">
                <h3 class="section-header">4. Family & Emergency</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 glass-section p-5">
                    
                    <!-- Clean Professional Notice -->
                    <div class="md:col-span-3 mb-1 border-b border-gray-100 pb-3">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.1em] flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Note: Parent details are optional.
                        </p>
                    </div>

                    <!-- Optional Fields -->
                    <div>
                        <label>Father's Name</label>
                        <input type="text" name="father_name" value="<?= htmlspecialchars($reapply_data['father_name'] ?? '') ?>" placeholder="First Last" class="input-glossy-smooth" oninput="formatProperName(this)">
                    </div>
                    <div>
                        <label>Occupation</label>
                        <input type="text" name="father_occupation" value="<?= htmlspecialchars($reapply_data['father_occupation'] ?? '') ?>" placeholder="Occupation" class="input-glossy-smooth">
                    </div>
                    <div>
                        <label>Contact Number</label>
                        <input type="text" name="father_contact" value="<?= htmlspecialchars($reapply_data['father_contact'] ?? '') ?>" placeholder="09XXXXXXXXX" class="input-glossy-smooth font-mono" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    </div>
                    
                    <div>
                        <label>Mother's Name</label>
                        <input type="text" name="mother_name" value="<?= htmlspecialchars($reapply_data['mother_name'] ?? '') ?>" placeholder="First Last" class="input-glossy-smooth" oninput="formatProperName(this)">
                    </div>
                    <div>
                        <label>Occupation</label>
                        <input type="text" name="mother_occupation" value="<?= htmlspecialchars($reapply_data['mother_occupation'] ?? '') ?>" placeholder="Occupation" class="input-glossy-smooth">
                    </div>
                    <div>
                        <label>Contact Number</label>
                        <input type="text" name="mother_contact" value="<?= htmlspecialchars($reapply_data['mother_contact'] ?? '') ?>" placeholder="09XXXXXXXXX" class="input-glossy-smooth font-mono" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    </div>

                    <!-- STRIPPED GOLD FROM HERE: Now strictly Indigo/Navy -->
                    <div class="md:col-span-3 mt-2">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-gradient-to-br from-[#00205b]/5 to-transparent border border-[#00205b]/20 p-4 rounded-xl shadow-inner relative overflow-hidden">
                            <div class="absolute top-0 right-0 w-24 h-24 bg-[#00205b] rounded-full blur-2xl opacity-10"></div>
                            <div class="sm:col-span-2 text-[10px] font-black text-[#00205b] uppercase tracking-[0.1em] mb-[-5px] relative z-10">
                                In Case of Emergency <span class="text-rose-500">*</span>
                            </div>
                            <div class="relative z-10">
                                <label class="!text-[#00205b]">Contact Name <span class="text-rose-500">*</span></label>
                                <input type="text" name="emergency_contact_name" required value="<?= htmlspecialchars($reapply_data['emergency_contact_name'] ?? '') ?>" placeholder="Full Name" class="input-glossy-smooth !bg-white/80 focus:!bg-white focus:!border-[#00205b]" oninput="formatProperName(this)">
                            </div>
                            <div class="relative z-10">
                                <label class="!text-[#00205b]">Contact Number <span class="text-rose-500">*</span></label>
                                <input type="text" name="emergency_contact_number" required value="<?= htmlspecialchars($reapply_data['emergency_contact_number'] ?? '') ?>" placeholder="09XXXXXXXXX" class="input-glossy-smooth !bg-white/80 focus:!bg-white focus:!border-[#00205b] font-mono" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="animate-up delay-4 relative z-10">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 glass-section p-5">
                    <div class="md:col-span-2">
                        <label>Source of Information / Influence <span class="text-rose-500">*</span></label>
                        <select name="influence_source" required class="input-glossy-smooth cursor-pointer w-full md:w-1/2">
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

            <!-- 5. DOCUMENT ATTACHMENT SECTION -->
            <section class="animate-up delay-4 relative z-0">
                <h3 class="section-header">5. Document Attachment</h3>
                
                <!-- STRIPPED GOLD FROM REAPPLY NOTICE: Now Indigo/Navy -->
                <?php if ($reapply_data): ?>
                    <div class="mb-4 bg-[#00205b]/10 border border-[#00205b]/30 text-[#00205b] p-3 text-[9px] font-black tracking-[0.05em] uppercase rounded-lg flex items-center gap-2 shadow-sm backdrop-blur-sm">
                        <svg class="w-5 h-5 shrink-0 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                        Action Required: You must re-upload all correct documents to proceed.
                    </div>
                <?php endif; ?>

                <div class="glass-section p-6 sm:p-8 space-y-8">
                    
                    <!-- 2x2 ID Photo -->
                    <div class="relative">
                        <label class="block text-[#1f2937] font-bold mb-3 text-base">2x2 ID Photo <span class="text-rose-500">*</span></label>
                        <input type="file" id="file_2x2" name="file_2x2" required accept=".jpg,.jpeg,.png" class="hidden" onchange="handleSingleFileUpload(this, 'card_2x2'); document.getElementById('upload_btn_2x2').classList.remove('!bg-rose-500', 'ring-4', 'ring-rose-200', 'error-highlight');">
                        
                        <div id="btn_container_2x2">
                            <button type="button" onclick="document.getElementById('file_2x2').click()" id="upload_btn_2x2" class="bg-[#00205b] hover:bg-[#003882] text-white font-semibold py-2.5 px-5 rounded-md flex items-center gap-2 transition-colors duration-300 shadow-sm w-fit">
                                <svg class="w-5 h-5 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                Choose Photo
                            </button>
                            <p class="text-gray-500 text-sm mt-3">Accepted formats: JPG, PNG (Max: 5MB)</p>
                        </div>

                        <!-- STRIPPED GOLD FROM 2x2 CARD: Now Indigo/Navy -->
                        <div id="card_2x2" class="hidden mt-2 border-2 border-dashed border-[#00205b]/40 p-4 rounded-xl bg-white/50">
                            <div class="flex justify-between items-center mb-3 pb-3 border-b border-gray-200">
                                <span class="text-[10px] font-black text-[#00205b] uppercase tracking-widest flex items-center gap-2">
                                    <svg class="w-4 h-4 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    Attached File
                                </span>
                                <button type="button" class="text-[10px] font-bold uppercase tracking-widest bg-[#00205b]/10 text-[#00205b] px-3 py-1.5 rounded-full hover:bg-[#00205b] hover:text-white transition-all shadow-sm border border-[#00205b]/20" onclick="document.getElementById('file_2x2').click()">+ Replace</button>
                            </div>
                            <div class="flex items-center justify-between bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                                <div class="flex items-center gap-3 overflow-hidden w-full pr-3">
                                    <div class="bg-[#00205b]/10 p-2 rounded-md shrink-0">
                                        <svg class="w-5 h-5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                    </div>
                                    <div class="truncate">
                                        <p class="text-sm font-bold text-[#00205b] truncate filename-text" id="name_file_2x2">FileName.jpg</p>
                                        <p class="text-[10px] text-gray-500 font-semibold tracking-wider filesize-text">1.2 MB</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <button type="button" class="preview-btn p-2 bg-[#00205b]/5 text-[#00205b] border border-[#00205b]/10 rounded-md hover:bg-[#00205b] hover:text-white transition-all shadow-sm" title="Preview Document">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                    </button>
                                    <button type="button" class="p-2 bg-white text-gray-400 border border-gray-200 rounded-md hover:border-rose-500 hover:bg-rose-500 hover:text-white transition-all shadow-sm" title="Remove Document" onclick="removeSingleFile('file_2x2', 'card_2x2')">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PSA Birth Certificate -->
                    <div class="relative">
                        <label class="block text-[#1f2937] font-bold mb-3 text-base">PSA Birth Certificate <span class="text-rose-500">*</span></label>
                        <input type="file" id="file_psa" name="file_psa" required accept=".jpg,.jpeg,.png,.pdf" class="hidden" onchange="handleSingleFileUpload(this, 'card_psa'); document.getElementById('upload_btn_psa').classList.remove('!bg-rose-500', 'ring-4', 'ring-rose-200', 'error-highlight');">
                        
                        <div id="btn_container_psa">
                            <button type="button" onclick="document.getElementById('file_psa').click()" id="upload_btn_psa" class="bg-[#00205b] hover:bg-[#003882] text-white font-semibold py-2.5 px-5 rounded-md flex items-center gap-2 transition-colors duration-300 shadow-sm w-fit">
                                <svg class="w-5 h-5 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                                Choose File
                            </button>
                            <p class="text-gray-500 text-sm mt-3">Accepted formats: JPG, PNG, PDF (Max: 5MB)</p>
                        </div>

                        <!-- STRIPPED GOLD FROM PSA CARD: Now Indigo/Navy -->
                        <div id="card_psa" class="hidden mt-2 border-2 border-dashed border-[#00205b]/40 p-4 rounded-xl bg-white/50">
                            <div class="flex justify-between items-center mb-3 pb-3 border-b border-gray-200">
                                <span class="text-[10px] font-black text-[#00205b] uppercase tracking-widest flex items-center gap-2">
                                    <svg class="w-4 h-4 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    Attached File
                                </span>
                                <button type="button" class="text-[10px] font-bold uppercase tracking-widest bg-[#00205b]/10 text-[#00205b] px-3 py-1.5 rounded-full hover:bg-[#00205b] hover:text-white transition-all shadow-sm border border-[#00205b]/20" onclick="document.getElementById('file_psa').click()">+ Replace</button>
                            </div>
                            <div class="flex items-center justify-between bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                                <div class="flex items-center gap-3 overflow-hidden w-full pr-3">
                                    <div class="bg-[#00205b]/10 p-2 rounded-md shrink-0">
                                        <svg class="w-5 h-5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                    </div>
                                    <div class="truncate">
                                        <p class="text-sm font-bold text-[#00205b] truncate filename-text" id="name_file_psa">FileName.pdf</p>
                                        <p class="text-[10px] text-gray-500 font-semibold tracking-wider filesize-text">1.2 MB</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <button type="button" class="preview-btn p-2 bg-[#00205b]/5 text-[#00205b] border border-[#00205b]/10 rounded-md hover:bg-[#00205b] hover:text-white transition-all shadow-sm" title="Preview Document">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                    </button>
                                    <button type="button" class="p-2 bg-white text-gray-400 border border-gray-200 rounded-md hover:border-rose-500 hover:bg-rose-500 hover:text-white transition-all shadow-sm" title="Remove Document" onclick="removeSingleFile('file_psa', 'card_psa')">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                    </button>
                                </div>
                            </div>
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
    <div id="doc-modal" class="fixed inset-0 z-[9999] hidden flex-col items-center justify-center bg-black/95 backdrop-blur-md transition-opacity duration-300 opacity-0">
        <!-- Image Container -->
        <div class="relative w-full h-full flex items-center justify-center overflow-hidden" id="doc-container">
            <img id="doc-image" src="" alt="Document Preview" class="max-w-[90vw] max-h-[90vh] object-contain transition-transform duration-100 hidden">
            <iframe id="doc-iframe" src="" class="w-[90vw] h-[90vh] bg-white rounded-xl shadow-2xl hidden border-0"></iframe>
        </div>

        <!-- Close Button -->
        <button type="button" onclick="document.getElementById('doc-modal').classList.add('hidden', 'opacity-0'); document.getElementById('doc-modal').classList.remove('flex');" class="absolute top-5 right-5 p-2 bg-white/20 text-white rounded-full hover:bg-white/30 hover:text-rose-400 transition-colors z-[10000] cursor-pointer">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
    </div>

    <script>
        window.LDSP_CONFIG = {
            academicYear: "<?= htmlspecialchars($academic_year) ?>",
            fetchUrl: "admission_form.php<?= isset($_GET['reapply']) ? '?reapply='.urlencode($_GET['reapply']) : '' ?>",
            dbSchools: <?= json_encode($db_schools_array) ?>,
            csvPath: "../schools_masterlist.csv"
        };
    </script>
    <script src="../script.js"></script>
    <script src="admission_form_registration.js?v=<?php echo time(); ?>"></script>
    
    <script>
        // Custom Validation Toast Logic
        let validationToastTimeout;

        function showValidationToast(message) {
            const toast = document.getElementById('validation-toast');
            const msgEl = document.getElementById('validation-toast-message');
            if (!toast || !msgEl) return;
            
            msgEl.innerText = message;
            toast.classList.remove('hidden');
            
            // Trigger animation
            setTimeout(() => {
                toast.classList.remove('translate-y-[-20px]', 'opacity-0');
                toast.classList.add('translate-y-0', 'opacity-100');
            }, 10);
            
            // Auto hide
            clearTimeout(validationToastTimeout);
            validationToastTimeout = setTimeout(hideValidationToast, 5000); 
        }

        function hideValidationToast() {
            const toast = document.getElementById('validation-toast');
            if (!toast) return;
            toast.classList.remove('translate-y-0', 'opacity-100');
            toast.classList.add('translate-y-[-20px]', 'opacity-0');
            
            setTimeout(() => {
                toast.classList.add('hidden');
            }, 300);
        }

        // Display Full Screen Loading Overlay
        function showFullScreenLoader() {
            const loader = document.getElementById('full-screen-loader');
            const content = document.getElementById('loader-content');
            if (!loader || !content) return;

            loader.classList.remove('hidden');
            loader.classList.add('flex');
            
            // Small delay for smooth entry animation
            setTimeout(() => {
                loader.classList.remove('opacity-0');
                content.classList.remove('scale-95', 'opacity-0');
                content.classList.add('scale-100', 'opacity-100');
            }, 50);
        }

        // COMPLETELY BYPASS NATIVE VALIDATION & MANUALLY CHECK ALL FIELDS
        function lockSubmitButton(event) {
            // Prevent the native form submission so we can intercept it completely
            event.preventDefault();
            
            const form = document.getElementById('admissionForm');
            const requiredElements = form.querySelectorAll('[required]');
            let firstErrorElement = null;
            let hasError = false;

            // Reset previous highlights before evaluating again
            document.querySelectorAll('.error-highlight').forEach(el => {
                 el.classList.remove('!border-rose-500', '!bg-rose-50', 'ring-2', 'ring-rose-500', 'ring-4', 'ring-rose-200', 'error-highlight', '!bg-rose-500');
            });

            for (let i = 0; i < requiredElements.length; i++) {
                const el = requiredElements[i];
                let isMissing = false;
                let currentErrorNode = null;
                
                // If it's a file input, we do a special check because it's hidden
                if (el.type === 'file') {
                    const hasFile = el.files.length > 0;
                    const cardText = document.getElementById('name_' + el.id);
                    const hasUploadedBefore = cardText && !cardText.textContent.includes('FileName');
                    
                    if (!hasFile && !hasUploadedBefore) {
                        isMissing = true;
                        const btn = document.getElementById('upload_btn_' + el.id.split('_')[1]);
                        if (btn) {
                            btn.classList.add('!bg-rose-500', 'ring-4', 'ring-rose-200', 'error-highlight');
                            currentErrorNode = btn;
                        }
                    }
                } 
                // For all other regular inputs (text, email, select)
                else if (!el.value || el.value.trim() === '') {
                    isMissing = true;
                    
                    if (el.type !== 'hidden') {
                        el.classList.add('!border-rose-500', '!bg-rose-50', 'error-highlight');
                        currentErrorNode = el;
                    } else {
                        // Custom handlers for hidden fields
                        if (el.name === 'dob') {
                            const dobContainer = document.getElementById('dob_container');
                            if (dobContainer) {
                                dobContainer.classList.add('ring-2', 'ring-rose-500', 'rounded-lg', 'error-highlight');
                                currentErrorNode = dobContainer;
                            }
                        } else if (el.name === 'address') {
                            const addContainer = document.getElementById('address_container');
                            if (addContainer) {
                                addContainer.classList.add('ring-2', 'ring-rose-500', 'rounded-lg', 'error-highlight');
                                currentErrorNode = addContainer;
                            }
                        } else if (el.name === 'school_year_attended') {
                            const syContainer = document.getElementById('sy_container');
                            if (syContainer) {
                                syContainer.classList.add('ring-2', 'ring-rose-500', 'rounded-lg', 'error-highlight');
                                currentErrorNode = syContainer;
                            }
                        }
                    }
                }

                if (isMissing) {
                    hasError = true;
                    
                    // Capture the first error node for scrolling
                    if (!firstErrorElement && currentErrorNode) {
                        firstErrorElement = currentErrorNode;
                        
                        // Generate a user-friendly name for the missing field in the toast
                        const nameMap = {
                            'program': 'Target Program',
                            'year_level': 'Year Level',
                            'last_name': 'Last Name',
                            'first_name': 'First Name',
                            'middle_name': 'Middle Name',
                            'email': 'Email Address',
                            'phone': 'Contact Number',
                            'gender': 'Gender',
                            'dob': 'Date of Birth',
                            'pob': 'Place of Birth',
                            'address': 'Complete Address',
                            'school_last_attended': 'School Last Attended',
                            'school_year_attended': 'School Year Attended',
                            'emergency_contact_name': 'Emergency Contact Name',
                            'emergency_contact_number': 'Emergency Contact Number',
                            'influence_source': 'Source of Information',
                            'file_2x2': '2x2 ID Photo',
                            'file_psa': 'PSA Birth Certificate'
                        };
                        const fieldName = nameMap[el.name] || el.name.replace('_', ' ');
                        showValidationToast("Please complete: " + fieldName);
                    }
                }
            }

            if (hasError) {
                // Smooth scroll to the very first error element
                if (firstErrorElement) {
                    firstErrorElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    
                    // Optionally try to focus the element slightly after scrolling
                    if (typeof firstErrorElement.focus === 'function' && firstErrorElement.tagName !== 'DIV') {
                        setTimeout(() => firstErrorElement.focus(), 400);
                    }
                }
                return false; // Stop form submission completely
            }

            // All required fields passed validation!
            const submitBtn = document.getElementById('submit_btn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            }
            
            showFullScreenLoader();
            
            // Proceed with the actual submission since validation passed
            form.submit();
            return true; 
        }

        // Event Listeners to instantly remove red highlights when user starts typing/selecting
        document.addEventListener('DOMContentLoaded', () => {
            
            // Standard Inputs & Selects
            document.querySelectorAll('input, select, textarea').forEach(el => {
                el.addEventListener('input', function() {
                    this.classList.remove('!border-rose-500', '!bg-rose-50', 'error-highlight');
                    if (this.id.startsWith('sy_')) {
                        document.getElementById('sy_container')?.classList.remove('ring-2', 'ring-rose-500', 'rounded-lg', 'error-highlight');
                    }
                });
                
                el.addEventListener('change', function() {
                    this.classList.remove('!border-rose-500', '!bg-rose-50', 'error-highlight');
                    if (this.id.startsWith('addr_')) {
                        document.getElementById('address_container')?.classList.remove('ring-2', 'ring-rose-500', 'rounded-lg', 'error-highlight');
                    }
                });
            });

            // Specific click clear for the Date of Birth dropdown container
            const dobContainer = document.getElementById('dob_container');
            if (dobContainer) {
                dobContainer.addEventListener('click', function() {
                    this.classList.remove('ring-2', 'ring-rose-500', 'rounded-lg', 'error-highlight');
                });
            }
        });

        // Properly formats multi-word names with spaces (e.g. "James Warren", "De Ocampo")
        function formatProperName(input) {
            // Save cursor position to prevent jumping while typing
            let start = input.selectionStart;
            
            // Remove numbers and special characters (allows letters, ñ, spaces, hyphens, and periods)
            let val = input.value.replace(/[^a-zA-ZñÑ\s\-\.]/g, '');
            
            // Capitalize the first letter of EVERY word
            let words = val.split(' ');
            for (let i = 0; i < words.length; i++) {
                if (words[i].length > 0) {
                    words[i] = words[i].charAt(0).toUpperCase() + words[i].substring(1).toLowerCase();
                }
            }
            input.value = words.join(' ');
            
            // Restore cursor
            input.setSelectionRange(start, start);
        }
    </script>
</body>
</html>