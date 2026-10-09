<?php
// 1. ISOLATED SESSION HANDLER FOR STUDENT
if (session_status() === PHP_SESSION_NONE) {
    $session_lifetime = 60 * 60 * 24 * 30; // 30 days
    ini_set('session.gc_maxlifetime', $session_lifetime);
    session_set_cookie_params($session_lifetime, '/'); 
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

include "../dbconn.php";

// =========================================================
// STRICT STUDENT-ONLY ACCESS
// =========================================================
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php"); 
    exit();
}

$student_email = $conn->real_escape_string($_SESSION['email']);

// =========================================================
// FINALIZED DATA FETCH: UNIFIED USER & PROFILE
// =========================================================
$user_q = $conn->query("
    SELECT u.id AS user_internal_id, u.student_id, u.role, p.* 
    FROM users u 
    LEFT JOIN user_profiles p ON u.id = p.user_id 
    WHERE u.email = '$student_email'
");
$user_data = ($user_q && $user_q->num_rows > 0) ? $user_q->fetch_assoc() : [];
$user_internal_id = (int)($user_data['user_internal_id'] ?? 0);
$student_id = $user_data['student_id'] ?? '';
$account_on_hold = empty($user_data['program']);

// =========================================================
// FETCH UPLOADED ADMISSION DOCUMENTS 
// =========================================================
$admission_files = [];
$doc_q = $conn->query("SELECT uploaded_files FROM admissions WHERE provisioned_user_id = $user_internal_id OR email = '$student_email' ORDER BY id DESC LIMIT 1");
if ($doc_q && $doc_q->num_rows > 0) {
    $doc_row = $doc_q->fetch_assoc();
    if (!empty($doc_row['uploaded_files'])) {
        $files = explode(',', $doc_row['uploaded_files']);
        foreach($files as $f) {
            if(!empty(trim($f))) {
                $admission_files[] = trim($f);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Profile - Student Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css">
    <style>
        .input-locked { background-color: #f1f5f9 !important; border-color: #e2e8f0 !important; color: #64748b !important; cursor: not-allowed !important; font-weight: 600; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 8px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .animate-up { opacity: 0; animation: fadeUpSmooth 0.5s ease-out forwards; }
        @keyframes fadeUpSmooth { 0% { opacity: 0; transform: translateY(15px); } 100% { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="flex h-screen overflow-hidden antialiased relative bg-[#f8fafc]">

    <div class="ambient-orb-1 no-print"></div>
    <div class="ambient-orb-2 no-print"></div>

    <?php include 'sidebar.php'; ?>

    <main id="mainScrollArea" class="flex-1 overflow-y-auto h-full w-full pt-20 md:pt-0 relative custom-scrollbar z-10">
        <div id="spa-content-root" class="p-4 md:p-8 lg:p-10 max-w-[1200px] mx-auto relative z-20">
            
            <header class="mb-8 flex flex-col md:flex-row md:justify-between items-start md:items-end gap-4 border-b border-slate-300/60 pb-6 no-print animate-up">
                <div>
                    <h1 class="text-3xl md:text-4xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">Master Profile & Records</h1>
                    <p class="text-slate-500 text-sm flex items-center gap-2 font-medium mt-2">View your permanent personal and academic identity details.</p>
                </div>
            </header>

            <?php if ($account_on_hold): ?>
                <div class="glossy-panel p-8 md:p-12 text-center border-t-[5px] !border-t-rose-500 max-w-2xl mx-auto shadow-[0_10px_30px_rgba(244,63,94,0.1)] mt-10 animate-up delay-1">
                    <div class="glossy-panel-header bg-gradient-to-r from-rose-400 to-rose-600"></div>
                    <h2 class="text-2xl font-black text-[#00205b] uppercase tracking-widest mb-4 drop-shadow-sm">Action Required</h2>
                    <div class="bg-rose-50/80 backdrop-blur-sm border border-rose-200 text-rose-700 p-5 rounded-xl text-sm font-bold mb-6 text-left shadow-sm">
                        <strong class="uppercase text-[10px] tracking-widest block mb-1">Hold Reason:</strong> No program assigned.
                    </div>
                    <p class="text-slate-500 text-sm mb-6 font-medium">Please contact the Registrar's Office to correct your master profile.</p>
                </div>
            <?php else: ?>

                <div class="relative w-full h-full animate-up delay-1">
                    <!-- MASTER PROFILE RECORDS (PERMANENTLY LOCKED) -->
                    <div class="glossy-panel p-5 md:p-7 rounded-2xl mb-10 border-t-[4px] !border-t-slate-400 shadow-sm relative z-20 text-sm">
                        <h3 class="font-black text-[#00205b] text-lg uppercase tracking-tight font-academic mb-1 flex items-center gap-2">
                            <svg class="w-5 h-5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2-2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            Official Profile Data
                        </h3>
                        
                        <!-- LOCKED BANNER -->
                        <div class="bg-slate-100 border border-slate-200 px-4 py-3 rounded-md mt-3 mb-6 flex items-center gap-3">
                            <svg class="w-5 h-5 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <p class="text-[10px] text-slate-600 uppercase tracking-widest font-bold">These records are officially locked as part of your permanent academic file. To request corrections or updates (e.g., change of address), please contact the Registrar's Office.</p>
                        </div>
                        
                        <div class="space-y-5 relative z-20">
                            
                            <!-- 1. Academic Identity -->
                            <div>
                                <div class="flex items-center gap-2 border-b border-slate-200/60 pb-2 mb-3">
                                    <div class="p-1 rounded bg-slate-100"><svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg></div>
                                    <h3 class="text-slate-600 font-black uppercase text-[10px] tracking-widest drop-shadow-sm">1. Academic Identity</h3>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-slate-50/40 rounded-xl p-2 border border-slate-100 shadow-[inset_0_1px_3px_rgba(0,0,0,0.02)]">
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Master Program Record</label>
                                        <input type="text" value="<?= htmlspecialchars($user_data['program'] ?? 'Unassigned') ?>" readonly class="input-glossy-smooth input-locked shadow-inner">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Admission Type</label>
                                        <input type="text" value="<?= htmlspecialchars($user_data['admission_type'] ?? 'Unknown') ?>" readonly class="input-glossy-smooth input-locked shadow-inner">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- 2. Personal Profile -->
                            <div>
                                <div class="flex items-center gap-2 border-b border-slate-200/60 pb-2 mb-3">
                                    <div class="p-1 rounded bg-slate-100"><svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></div>
                                    <h3 class="text-slate-600 font-black uppercase text-[10px] tracking-widest drop-shadow-sm">2. Personal Profile</h3>
                                </div>
                                
                                <div class="bg-slate-50/40 rounded-xl border border-slate-100 p-4 shadow-[inset_0_1px_3px_rgba(0,0,0,0.02)] space-y-4">
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div><label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Last Name</label><input type="text" value="<?= htmlspecialchars($user_data['last_name'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner"></div>
                                        <div><label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">First Name</label><input type="text" value="<?= htmlspecialchars($user_data['first_name'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner"></div>
                                        <div><label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Middle Name</label><input type="text" value="<?= htmlspecialchars($user_data['middle_name'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner"></div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div><label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Email</label><input type="email" value="<?= htmlspecialchars($student_email) ?>" readonly class="input-glossy-smooth input-locked shadow-inner"></div>
                                        <div><label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Gender</label><input type="text" value="<?= htmlspecialchars($user_data['gender'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner"></div>
                                        <div><label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Contact Number</label><input type="text" value="<?= htmlspecialchars($user_data['phone'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner font-mono"></div>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div>
                                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Date of Birth</label>
                                            <input type="date" value="<?= htmlspecialchars($user_data['dob'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner">
                                        </div>
                                        <div class="md:col-span-2">
                                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Place of Birth</label>
                                            <input type="text" value="<?= htmlspecialchars($user_data['pob'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner">
                                        </div>
                                    </div>
                                    
                                    <div class="pt-3 mt-3 border-t border-slate-200/50">
                                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Complete Address</label>
                                        <input type="text" value="<?= htmlspecialchars($user_data['address'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner">
                                    </div>
                                </div>
                            </div>

                            <!-- 3. Educational Background -->
                            <div>
                                <div class="flex items-center gap-2 border-b border-slate-200/60 pb-2 mb-3">
                                    <div class="p-1 rounded bg-slate-100"><svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14v7"/></svg></div>
                                    <h3 class="text-slate-600 font-black uppercase text-[10px] tracking-widest drop-shadow-sm">3. Educational Background</h3>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 bg-slate-50/40 rounded-xl p-4 border border-slate-100 shadow-[inset_0_1px_3px_rgba(0,0,0,0.02)]">
                                    <div class="md:col-span-2">
                                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">School Last Attended</label>
                                        <input type="text" value="<?= htmlspecialchars($user_data['school_last_attended'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">School Year Attended</label>
                                        <input type="text" value="<?= htmlspecialchars($user_data['school_year_attended'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner font-mono">
                                    </div>
                                </div>
                            </div>

                            <!-- 4. Family & Emergency Contacts -->
                            <div>
                                <div class="flex items-center gap-2 border-b border-slate-200/60 pb-2 mb-3">
                                    <div class="p-1 rounded bg-slate-100"><svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg></div>
                                    <h3 class="text-slate-600 font-black uppercase text-[10px] tracking-widest drop-shadow-sm">4. Family & Emergency Contacts</h3>
                                </div>
                                
                                <div class="bg-slate-50/40 rounded-xl border border-slate-100 p-4 shadow-[inset_0_1px_3px_rgba(0,0,0,0.02)] space-y-4">
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div class="md:col-span-3 text-[10px] font-black text-slate-500 uppercase tracking-widest border-b border-slate-200/60 pb-1.5 drop-shadow-sm">Father's Details</div>
                                        <div><label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Father's Name</label><input type="text" value="<?= htmlspecialchars($user_data['father_name'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner"></div>
                                        <div><label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Occupation</label><input type="text" value="<?= htmlspecialchars($user_data['father_occupation'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner"></div>
                                        <div><label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Contact Number</label><input type="text" value="<?= htmlspecialchars($user_data['father_contact'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner font-mono"></div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div class="md:col-span-3 text-[10px] font-black text-slate-500 uppercase tracking-widest border-b border-slate-200/60 pb-1.5 drop-shadow-sm">Mother's Details</div>
                                        <div><label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Mother's Name</label><input type="text" value="<?= htmlspecialchars($user_data['mother_name'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner"></div>
                                        <div><label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Occupation</label><input type="text" value="<?= htmlspecialchars($user_data['mother_occupation'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner"></div>
                                        <div><label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Contact Number</label><input type="text" value="<?= htmlspecialchars($user_data['mother_contact'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner font-mono"></div>
                                    </div>

                                    <div class="bg-slate-100 border border-slate-200 p-4 rounded-lg relative overflow-hidden">
                                        <div class="text-[9px] font-black text-slate-500 uppercase tracking-[0.2em] mb-3 drop-shadow-sm relative z-10">In Case of Emergency</div>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 relative z-10">
                                            <div><label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Emergency Contact Name</label><input type="text" value="<?= htmlspecialchars($user_data['emergency_contact_name'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner"></div>
                                            <div><label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 ml-1 tracking-widest drop-shadow-sm">Emergency Contact Number</label><input type="text" value="<?= htmlspecialchars($user_data['emergency_contact_number'] ?? '') ?>" readonly class="input-glossy-smooth input-locked shadow-inner font-mono"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-slate-50/40 rounded-xl p-4 border border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-4 mt-2">
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-0 tracking-widest drop-shadow-sm">Source of Information / Influence</label>
                                <input type="text" value="<?= htmlspecialchars($user_data['source_of_info'] ?? 'Unspecified') ?>" readonly class="input-glossy-smooth input-locked shadow-inner w-full md:w-1/2">
                            </div>

                            <!-- 5. ADMISSION DOCUMENTS VIEWER -->
                            <div>
                                <div class="flex items-center gap-2 border-b border-slate-200/60 pb-2 mb-3 mt-6">
                                    <div class="p-1 rounded bg-slate-100"><svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg></div>
                                    <h3 class="text-slate-600 font-black uppercase text-[10px] tracking-widest drop-shadow-sm">5. Submitted Admission Documents</h3>
                                </div>
                                
                                <?php if (!empty($admission_files)): ?>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-slate-50/40 rounded-xl p-4 border border-slate-100 shadow-[inset_0_1px_3px_rgba(0,0,0,0.02)]">
                                    <?php foreach($admission_files as $index => $filepath): 
                                        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
                                        $filename = basename($filepath);
                                        $label = ($index === 0) ? '2x2 ID Photo' : (($index === 1) ? 'PSA Birth Certificate' : 'Document ' . ($index + 1));
                                    ?>
                                    <div class="border-2 border-dashed border-slate-200 p-4 rounded-xl bg-white/50 relative overflow-hidden transition-colors">
                                        <div class="flex justify-between items-center mb-3 pb-3 border-b border-gray-200 relative z-10">
                                            <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest flex items-center gap-2">
                                                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                                <?= $label ?>
                                            </span>
                                            <span class="text-[8px] font-bold uppercase tracking-widest bg-emerald-50 text-emerald-600 px-2 py-1 rounded shadow-sm border border-emerald-200 flex items-center gap-1">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Verified
                                            </span>
                                        </div>
                                        <div class="flex items-center justify-between bg-white p-3 rounded-lg border border-gray-200 shadow-sm relative z-10">
                                            <div class="flex items-center gap-3 overflow-hidden w-full pr-3">
                                                <div class="bg-slate-100 p-2 rounded-md shrink-0">
                                                    <svg class="w-5 h-5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                                </div>
                                                <div class="truncate">
                                                    <p class="text-[11px] font-bold text-[#00205b] truncate"><?= htmlspecialchars($filename) ?></p>
                                                    <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest mt-0.5">Uploaded via Portal</p>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-2 shrink-0">
                                                <button type="button" onclick="window.openDocModal('../<?= addslashes(htmlspecialchars($filepath)) ?>', '<?= $ext === 'pdf' ? 'application/pdf' : 'image/'.$ext ?>')" class="p-2 bg-slate-100 text-slate-600 border border-slate-200 rounded-md hover:bg-[#00205b] hover:text-white transition-all shadow-sm" title="Preview Document">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                    <div class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-center shadow-inner">
                                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest italic">No admission documents found linked to this account.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
        
        <button id="floatingBackToTop" onclick="scrollToTop()" class="fixed bottom-6 right-6 md:bottom-10 md:right-10 bg-white border border-slate-200 text-[#00205b] hover:bg-slate-50 hover:border-[#00205b] w-14 h-14 rounded-full shadow-lg z-[90] flex items-center justify-center transition-all duration-300 opacity-0 pointer-events-none translate-y-4 focus:outline-none group">
            <svg class="w-6 h-6 drop-shadow-sm group-hover:-translate-y-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7" /></svg>
        </button>

        <!-- Toast Notification Container -->
        <div id="toast-container" class="fixed top-6 right-6 z-[100] flex flex-col gap-3 pointer-events-none"></div>
    </main>

    <!-- DOCUMENT OVERLAY MODAL (Messenger-Style) -->
    <div id="doc-modal" class="fixed inset-0 z-[9999] hidden flex-col items-center justify-center bg-black/95 backdrop-blur-md transition-opacity duration-300 opacity-0">
        <!-- Image Container -->
        <div class="relative w-full h-full flex items-center justify-center overflow-hidden" id="doc-container">
            <img id="doc-image" src="" alt="Document Preview" class="max-w-[90vw] max-h-[90vh] object-contain transition-transform duration-100 hidden" onmousedown="startDrag(event)">
            <iframe id="doc-iframe" src="" class="w-[90vw] h-[90vh] bg-white rounded-xl shadow-2xl hidden border-0"></iframe>
        </div>

        <!-- Close Button -->
        <button type="button" onclick="document.getElementById('doc-modal').classList.add('hidden', 'opacity-0'); document.getElementById('doc-modal').classList.remove('flex');" class="absolute top-5 right-5 p-2 bg-white/20 text-white rounded-full hover:bg-white/30 hover:text-rose-400 transition-colors z-[10000] cursor-pointer">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
    </div>

    <!-- SCRIPT INJECTIONS -->
    <script src="student.js?v=<?= time() ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="enrollment_form.js?v=<?= time() ?>"></script>

</body>
</html>