<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- Mobile Header -->
<div class="md:hidden fixed w-full top-0 left-0 bg-[#001233] z-30 px-5 py-3 flex justify-between items-center shadow-md border-b border-white/10 no-print">
    <div class="flex items-center gap-2">
        <div class="w-7 h-7 rounded-full bg-white flex items-center justify-center shadow-inner overflow-hidden border border-white/20 p-0.5">
            <img src="../logo.jpg" onerror="this.src='logo.jpg'" alt="LDSP Logo" class="w-full h-full object-contain rounded-full">
        </div>
        <h2 class="text-base font-bold text-white font-academic uppercase tracking-widest drop-shadow-md">LDSP <span class="gold-gradient-text">FACULTY</span></h2>
    </div>
    <button id="mobileMenuBtn" class="text-white focus:outline-none">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
    </button>
</div>

<!-- Sidebar Overlay -->
<div id="sidebarOverlay" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-40 hidden md:hidden transition-opacity no-print"></div>

<!-- Sleek Professional Sidebar -->
<aside id="sidebar" class="fixed inset-y-0 left-0 transform -translate-x-full md:relative md:translate-x-0 transition-transform duration-300 ease-in-out w-60 shrink-0 bg-[#001233] sidebar flex flex-col z-50 h-full border-r border-white/10 shadow-2xl no-print">
    <div class="p-6 text-center border-b border-white/5 relative bg-white/5">
        <div class="w-16 h-16 mx-auto bg-white rounded-full flex items-center justify-center mb-4 shadow-[0_0_15px_rgba(0,0,0,0.5)] border-2 border-[#c5a02c]/50 p-0.5 relative overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-tr from-[var(--ldsp-blue)] to-[var(--ldsp-gold)] opacity-0 group-hover:opacity-20 transition-opacity duration-500 z-10"></div>
            <img src="../logo.jpg" onerror="this.src='logo.jpg'" alt="LDSP Logo" class="w-full h-full object-contain rounded-full shadow-inner relative z-0">
        </div>
        <h2 class="text-lg font-bold text-white font-academic uppercase tracking-[0.15em] hidden md:block drop-shadow-md">LDSP <span class="text-[#c5a02c]">FACULTY</span></h2>
        <button id="closeMenuBtn" class="md:hidden absolute top-4 right-4 text-white/50 hover:text-white transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>

    <nav class="flex-1 p-3 space-y-1 mt-2 overflow-y-auto custom-scrollbar">
        <div class="pt-2 px-1">
            <p class="text-[9px] font-bold text-white/40 uppercase tracking-[0.2em] mb-3 px-3">Faculty Menu</p>
            <div class="space-y-1">
                <a href="home_page.php" class="w-full nav-link <?= ($current_page == 'home_page.php') ? 'active text-white bg-white/10' : 'text-slate-300 hover:text-white hover:bg-white/5' ?> flex items-center gap-3 px-4 py-2.5 rounded-lg text-xs font-semibold uppercase tracking-wider text-left transition">
                    Home Page
                </a>
                <a href="available_subjects.php" class="w-full nav-link <?= ($current_page == 'available_subjects.php') ? 'active text-white bg-white/10' : 'text-slate-300 hover:text-white hover:bg-white/5' ?> flex items-center gap-3 px-4 py-2.5 rounded-lg text-xs font-semibold uppercase tracking-wider text-left transition">
                    Available Subjects
                </a>
                <a href="my_classes.php" class="w-full nav-link <?= ($current_page == 'my_classes.php') ? 'active text-white bg-white/10' : 'text-slate-300 hover:text-white hover:bg-white/5' ?> flex items-center gap-3 px-4 py-2.5 rounded-lg text-xs font-semibold uppercase tracking-wider text-left transition">
                    My Classes
                </a>
            </div>
        </div>
    </nav>

    <div class="p-5 border-t border-white/5 bg-black/20 shrink-0">
        <a href="../logout.php" class="text-rose-400 hover:text-rose-300 text-[10px] font-black uppercase tracking-widest flex items-center gap-2 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg> Logout
        </a>
    </div>
</aside>