// =========================================================
// SIDEBAR INTERACTION LOGIC
// =========================================================

document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const openBtn = document.getElementById('mobileMenuBtn');
    const closeBtn = document.getElementById('closeMenuBtn');

    window.toggleMenu = function() {
        if (!sidebar || !overlay) return;
        
        sidebar.classList.toggle('-translate-x-full');
        
        if (overlay.classList.contains('hidden')) {
            overlay.classList.remove('hidden');
            setTimeout(() => overlay.classList.remove('opacity-0'), 10);
            setTimeout(() => overlay.classList.add('opacity-100'), 10);
        } else {
            overlay.classList.remove('opacity-100');
            overlay.classList.add('opacity-0');
            setTimeout(() => overlay.classList.add('hidden'), 300);
        }
    };

    if (openBtn) openBtn.addEventListener('click', (e) => {
        e.preventDefault();
        window.toggleMenu();
    });
    
    if (closeBtn) closeBtn.addEventListener('click', (e) => {
        e.preventDefault();
        window.toggleMenu();
    });
    
    if (overlay) overlay.addEventListener('click', window.toggleMenu);
});