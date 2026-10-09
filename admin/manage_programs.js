// =========================================================
// MANAGE PROGRAMS SPECIFIC LOGIC (manage_programs.js)
// =========================================================

let lastDataString = ''; // CACHE VAR to prevent UI flickering

// ZERO-LAG ASYNCHRONOUS LIVE DATA POLLING (FETCH API)
window.triggerDynamicFetch = async function() {
    // PROTECTION: If the user has an inline edit form open, do NOT refresh.
    // This prevents their typed text from being erased by the background update.
    const isEditing = document.querySelector('form[id^="edit-prog-"]:not(.hidden)');
    if (isEditing) {
        return;
    }

    // Safely get the active tab passed from PHP
    const tab = typeof currentTab !== 'undefined' ? currentTab : 'active';

    try {
        const response = await fetch(`manage_programs.php?api_refresh=1&status=${tab}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!response.ok) return;
        
        const data = await response.json();
        const newDataString = JSON.stringify(data);
        
        // Prevent DOM redraws if data hasn't changed
        if (newDataString === lastDataString) return; 
        lastDataString = newDataString;

        // Update Table Body
        const tbody = document.getElementById('programs_tbody');
        if (tbody) tbody.innerHTML = data.html;

        // Update Count Badge
        const countBadge = document.getElementById('programs_count');
        if (countBadge && data.count_text) countBadge.innerText = data.count_text;
        
    } catch (err) {
        console.error("Auto-sync error:", err);
    }
};

// Start polling every 5 seconds seamlessly in the background
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('programs_tbody')) {
        window.triggerDynamicFetch(); // Run once immediately
        setInterval(window.triggerDynamicFetch, 5000); // Poll every 5s
    }
});

// =========================================================
// UI TOGGLE: DISPLAY MODE VS. EDIT MODE
// =========================================================
window.toggleEdit = function(id, isEditing) {
    const displayEl = document.getElementById('display-prog-' + id);
    const actionsEl = document.getElementById('actions-' + id);
    const editEl = document.getElementById('edit-prog-' + id);
    
    if (!editEl) return; // Prevent errors if clicking this on the archive tab

    if (isEditing) {
        // Enter Edit Mode
        displayEl.classList.add('hidden');
        actionsEl.classList.add('hidden');
        editEl.classList.remove('hidden');
    } else {
        // Cancel / Return to Display Mode
        displayEl.classList.remove('hidden');
        actionsEl.classList.remove('hidden');
        editEl.classList.add('hidden');
    }
};