<?php
// Automatically detect the current page to highlight the active menu item
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- MOBILE HEADER (Hidden on Desktop) -->
<div class="md:hidden fixed w-full top-0 left-0 bg-[#001233] z-[80] px-5 py-3 flex justify-between items-center shadow-[0_5px_15px_rgba(0,0,0,0.4)] border-b border-white/10 no-print">
    <div class="flex items-center gap-2">
        <div class="w-7 h-7 rounded-full bg-white flex items-center justify-center shadow-inner overflow-hidden border border-white/20 p-0.5 shrink-0">
            <img src="../logo.jpg" onerror="this.src='logo.jpg'" alt="LDSP Logo" class="w-full h-full object-contain rounded-full">
        </div>
        <h2 class="text-base font-bold text-white font-academic uppercase tracking-widest drop-shadow-md">LDSP <span class="gold-gradient-text">ADMIN</span></h2>
    </div>
    <button id="mobileMenuBtn" class="text-white focus:outline-none p-2 -ml-2 active:scale-95 transition-transform" onclick="toggleMenu()">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
    </button>
</div>

<!-- SIDEBAR OVERLAY FOR MOBILE -->
<div id="sidebarOverlay" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-[90] hidden md:hidden no-print transition-opacity duration-300 opacity-0" onclick="toggleMenu()"></div>

<!-- MAIN SIDEBAR -->
<aside id="sidebar" class="fixed inset-y-0 left-0 transform -translate-x-full md:relative md:translate-x-0 transition-transform duration-300 ease-in-out w-60 shrink-0 bg-[#001233] sidebar flex flex-col z-[100] h-full border-r border-white/10 shadow-2xl no-print">
    
    <!-- Sidebar Header / Logo -->
    <div class="p-6 text-center border-b border-white/5 relative bg-white/5">
        <div class="w-16 h-16 mx-auto bg-white rounded-full flex items-center justify-center mb-4 shadow-[0_0_15px_rgba(0,0,0,0.5)] border-2 border-[#c5a02c]/50 p-0.5 relative overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-tr from-[#00205b] to-[#c5a02c] opacity-0 group-hover:opacity-20 transition-opacity duration-500 z-10"></div>
            <img src="../logo.jpg" onerror="this.src='logo.jpg'" alt="LDSP Logo" class="w-full h-full object-contain rounded-full shadow-inner relative z-0">
        </div>
        <h2 class="text-lg font-bold text-white font-academic uppercase tracking-[0.15em] hidden md:block drop-shadow-md">LDSP <span class="text-[#c5a02c]">ADMIN</span></h2>
        
        <!-- Mobile Close Button inside Sidebar -->
        <button id="closeMenuBtn" class="md:hidden absolute top-4 right-4 text-white/50 hover:text-white transition-colors" onclick="toggleMenu()">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>

    <!-- Navigation Links -->
    <nav class="flex-1 p-3 space-y-1 mt-2 overflow-y-auto custom-scrollbar pb-24 md:pb-4">
        <a href="dashboard.php" class="nav-link <?= ($current_page == 'dashboard.php') ? 'active text-[#c5a02c] bg-white/10' : 'text-slate-300 hover:text-white hover:bg-white/5' ?> flex items-center gap-3 px-4 py-2.5 rounded-lg text-xs font-semibold uppercase tracking-wider transition">Dashboard</a>
        
        <a href="create_account.php" class="nav-link <?= ($current_page == 'create_account.php') ? 'active text-[#c5a02c] bg-white/10' : 'text-slate-300 hover:text-white hover:bg-white/5' ?> flex items-center gap-3 px-4 py-2.5 rounded-lg text-xs font-semibold uppercase tracking-wider transition">Accounts</a>
        
        <a href="manage_programs.php" class="nav-link <?= ($current_page == 'manage_programs.php') ? 'active text-[#c5a02c] bg-white/10' : 'text-slate-300 hover:text-white hover:bg-white/5' ?> flex items-center gap-3 px-4 py-2.5 rounded-lg text-xs font-semibold uppercase tracking-wider transition">Programs</a>
        
        <a href="manage_prospectus.php" class="nav-link <?= ($current_page == 'manage_prospectus.php') ? 'active text-[#c5a02c] bg-white/10' : 'text-slate-300 hover:text-white hover:bg-white/5' ?> flex items-center gap-3 px-4 py-2.5 rounded-lg text-xs font-semibold uppercase tracking-wider transition">Curriculum</a>
        
        <a href="manage_teaching.php" class="nav-link <?= ($current_page == 'manage_teaching.php') ? 'active text-[#c5a02c] bg-white/10' : 'text-slate-300 hover:text-white hover:bg-white/5' ?> flex items-center gap-3 px-4 py-2.5 rounded-lg text-xs font-semibold uppercase tracking-wider transition">Teaching Load</a>
        
        <a href="manage_banks.php" class="nav-link <?= ($current_page == 'manage_banks.php') ? 'active text-[#c5a02c] bg-white/10' : 'text-slate-300 hover:text-white hover:bg-white/5' ?> flex items-center gap-3 px-4 py-2.5 rounded-lg text-xs font-semibold uppercase tracking-wider transition">Bank Accounts</a>
        

    </nav>

    <!-- Logout Section -->
    <div class="p-5 border-t border-white/5 bg-black/20 shrink-0 mt-auto">
        <a href="../logout.php" class="text-rose-400 hover:text-rose-300 text-[10px] font-black uppercase tracking-widest flex items-center gap-2 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg> 
            Logout
        </a>
    </div>
</aside>