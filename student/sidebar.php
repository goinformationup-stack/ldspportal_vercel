<?php
// Get the current file name to dynamically highlight the active link
$current_page = basename($_SERVER['PHP_SELF']);

// Safe fallback for variables in case a module doesn't declare them
$is_held = !empty($account_on_hold);
$no_active_req = empty($has_active_request);

// ---------------------------------------------------------
// FETCH STUDENT PROFILE DATA FOR SIDEBAR
// ---------------------------------------------------------
$student_name = "Student";
$student_id_disp = "ID: Unknown";
$student_prog = "No Program Assigned";
$profile_img_src = "";
$initial = "?";

if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    // Fetch the user's ID, Name, Program, and the uploaded files from admissions to get their 2x2
    $sb_q = $conn->query("
        SELECT u.student_id, p.first_name, p.last_name, p.program, 
               (SELECT uploaded_files FROM admissions WHERE provisioned_user_id = u.id ORDER BY id DESC LIMIT 1) as uploaded_files
        FROM users u 
        LEFT JOIN user_profiles p ON u.id = p.user_id 
        WHERE u.id = $uid
    ");
    
    if ($sb_q && $sb_q->num_rows > 0) {
        $sb_data = $sb_q->fetch_assoc();
        
        $fname = trim($sb_data['first_name'] ?? '');
        $lname = trim($sb_data['last_name'] ?? '');
        $student_name = trim("$fname $lname");
        if (empty($student_name)) $student_name = "Student User";
        
        $initial = strtoupper(substr($fname, 0, 1));
        if (empty($initial)) $initial = "?";

        if (!empty($sb_data['student_id'])) {
            $student_id_disp = $sb_data['student_id'];
        }
        
        if (!empty($sb_data['program'])) {
            $student_prog = $sb_data['program'];
        }

        // Extract 2x2 Picture and fix the path so it works from inside the /student/ folder
        if (!empty($sb_data['uploaded_files'])) {
            $files = explode(',', $sb_data['uploaded_files']);
            foreach ($files as $file) {
                $clean_file = trim($file);
                if ($clean_file !== '') {
                    // Strip any existing ../ just in case, then explicitly prepend one
                    $clean_file = preg_replace('/^(\.\.\/)+/', '', $clean_file); 
                    $clean_file = ltrim($clean_file, '/');
                    $profile_img_src = '../' . $clean_file;
                    break;
                }
            }
        }
    }
}
?>

<!-- Mobile Header (Now z-[80] to stay above page content) -->
<div class="md:hidden fixed w-full top-0 left-0 bg-[#001233] z-[80] px-4 py-3 flex items-center gap-3 shadow-md border-b border-white/10 no-print">
    
    <!-- Hamburger Menu Button -->
    <button id="mobileMenuBtn" class="text-white focus:outline-none p-1.5 -ml-1.5 active:scale-95 transition-transform" onclick="window.toggleMenu()">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
    </button>

    <!-- Logo and Title -->
    <div class="flex items-center gap-2.5">
        <div class="w-7 h-7 rounded-full bg-white flex items-center justify-center shadow-inner overflow-hidden border border-white/20 p-0.5 shrink-0">
            <img src="../logo.jpg" onerror="this.src='logo.jpg'" alt="LDSP Logo" class="w-full h-full object-contain rounded-full">
        </div>
        <h2 class="text-sm font-bold text-white font-academic uppercase tracking-widest drop-shadow-md">LDSP <span class="gold-gradient-text">STUDENT</span></h2>
    </div>
</div>

<!-- Sidebar Overlay (Now z-[90] to cover the mobile header when open) -->
<div id="sidebarOverlay" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-[90] hidden md:hidden no-print transition-opacity duration-300 opacity-0" onclick="window.toggleMenu()"></div>

<!-- Personalized Student Sidebar (Slimmed down to w-[230px]) -->
<aside id="sidebar" class="fixed inset-y-0 left-0 transform -translate-x-full md:relative md:translate-x-0 transition-transform duration-300 ease-in-out w-[230px] bg-[#001233] sidebar flex flex-col z-[100] h-full no-print shrink-0 border-r border-white/5 shadow-2xl md:shadow-none">
    
    <!-- Mobile Close Button -->
    <button id="closeMenuBtn" class="md:hidden absolute top-3 right-3 text-white/50 hover:text-white bg-white/5 hover:bg-white/10 p-1.5 rounded-full transition-all z-20" onclick="window.toggleMenu()">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
    </button>

    <!-- STUDENT PROFILE HEADER -->
    <div class="p-5 text-center border-b border-white/10 relative bg-gradient-to-b from-white/5 to-transparent">
        
        <!-- Profile Picture Container -->
        <div class="w-16 h-16 mx-auto rounded-full flex items-center justify-center mb-3 shadow-[0_0_15px_rgba(0,0,0,0.5)] border-[2px] border-[#c5a02c] relative overflow-hidden group bg-[#00205b]">
            <?php if (!empty($profile_img_src)): ?>
                <!-- If image fails to load, hide the image and show the span fallback -->
                <img src="<?= htmlspecialchars($profile_img_src) ?>" class="w-full h-full object-cover relative z-10" alt="Profile" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <span class="text-xl font-black text-white hidden relative z-0"><?= $initial ?></span>
            <?php else: ?>
                <span class="text-xl font-black text-white relative z-0"><?= $initial ?></span>
            <?php endif; ?>
        </div>
        
        <!-- Profile Info -->
        <div class="space-y-1">
            <h2 class="text-xs font-black text-white uppercase tracking-wider drop-shadow-md truncate px-1" title="<?= htmlspecialchars($student_name) ?>"><?= htmlspecialchars($student_name) ?></h2>
            <p class="text-[9px] font-mono font-bold text-[#c5a02c] tracking-widest"><?= htmlspecialchars($student_id_disp) ?></p>
            <p class="text-[8px] font-semibold text-blue-200/70 uppercase tracking-widest truncate px-2 leading-tight mt-1" title="<?= htmlspecialchars($student_prog) ?>"><?= htmlspecialchars($student_prog) ?></p>
        </div>
    </div>
    
    <!-- NAVIGATION LINKS -->
    <nav class="flex-1 p-3 space-y-1 mt-2 overflow-y-auto custom-scrollbar pb-24 md:pb-4">
        <div class="pt-1 px-2">
            <p class="text-[9px] font-bold text-white/30 uppercase tracking-[0.2em] mb-3 ml-1">My Modules</p>
            <div class="space-y-1">
                <a href="home_page.php" class="w-full nav-link <?= ($current_page == 'home_page.php' || $current_page == 'dashboard.php') ? 'active' : '' ?> flex items-center gap-3 px-3 py-2.5 rounded-lg text-[11px] font-bold uppercase tracking-widest text-left transition <?= $is_held ? 'disabled' : '' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                    Home Page
                </a>
                
                <a href="enrollment_form.php" class="w-full nav-link <?= ($current_page == 'enrollment_form.php') ? 'active' : '' ?> flex items-center gap-3 px-3 py-2.5 rounded-lg text-[11px] font-bold uppercase tracking-widest text-left transition <?= $is_held ? 'disabled' : '' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                    Enrollment Form
                </a>
                

                <a href="payment_fees.php" class="w-full nav-link <?= ($current_page == 'payment_fees.php') ? 'active text-emerald-400' : 'text-emerald-400/80 hover:text-emerald-400 hover:bg-emerald-500/10' ?> flex items-center gap-3 px-3 py-2.5 rounded-lg text-[11px] font-bold uppercase tracking-widest text-left transition <?= $is_held ? 'disabled' : '' ?>">
                    <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z"></path>
                        <path d="M8 8H13C14.1046 8 15 8.89543 15 10C15 11.1046 14.1046 12 13 12H8"></path>
                        <path d="M8 8V16"></path>
                        <path d="M7 10H16"></path>
                        <path d="M7 12H14"></path>
                    </svg>
                    Payment Fees
                </a>

                <a href="digital_cor_record.php" class="w-full nav-link <?= ($current_page == 'digital_cor_record.php') ? 'active text-[#c5a02c]' : 'hover:text-[#c5a02c] hover:bg-[#c5a02c]/10' ?> flex items-center gap-3 px-3 py-2.5 rounded-lg text-[11px] font-bold uppercase tracking-widest text-left transition <?= $is_held ? 'disabled' : '' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Digital COR
                </a>
                
                <a href="year_curriculum.php" class="w-full nav-link <?= ($current_page == 'year_curriculum.php') ? 'active' : '' ?> flex items-center gap-3 px-3 py-2.5 rounded-lg text-[11px] font-bold uppercase tracking-widest text-left transition <?= $is_held ? 'disabled' : '' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477-4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                    Curriculum
                </a>
                
                <a href="my_grades.php" class="w-full nav-link <?= ($current_page == 'my_grades.php') ? 'active' : '' ?> flex items-center gap-3 px-3 py-2.5 rounded-lg text-[11px] font-bold uppercase tracking-widest text-left transition <?= $is_held ? 'disabled' : '' ?>">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    My Grades
                </a>
            </div>
        </div>
    </nav>
    
    <div class="p-5 border-t border-white/5 bg-black/20 shrink-0">
        <a href="logout.php" class="text-rose-400 hover:text-rose-300 text-[10px] font-black uppercase tracking-widest flex items-center gap-2 transition-colors w-max mx-auto md:mx-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg> Sign Out
        </a>
    </div>
</aside>