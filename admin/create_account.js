// =========================================================
// CREATE ACCOUNT SPECIFIC LOGIC (create_account.js)
// =========================================================

let lastDataString = ''; // CACHE VAR to prevent UI flickering

// CENTERED MODAL FUNCTION FOR ALERTS
function showCenteredAlert(type, title, message) {
    // Remove existing modal if any
    const existing = document.getElementById('centered-alert-modal');
    if (existing) existing.remove();

    const overlay = document.createElement('div');
    overlay.id = 'centered-alert-modal';
    overlay.className = 'fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm transition-opacity';
    
    let iconHtml = '';
    let buttonHtml = '';
    let borderClass = '';

    if (type === 'loading') {
        borderClass = 'border-[#c5a02c]';
        iconHtml = `<svg class="animate-spin w-12 h-12 text-[#c5a02c] mx-auto mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;
    } else if (type === 'success') {
        borderClass = 'border-emerald-500';
        iconHtml = `<div class="w-12 h-12 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-4"><svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg></div>`;
        buttonHtml = `<button onclick="document.getElementById('centered-alert-modal').remove()" class="mt-6 w-full bg-[#00205b] hover:bg-[#003882] text-white font-bold py-2 px-4 rounded-lg uppercase tracking-widest text-[10px] transition-colors shadow-sm">Continue</button>`;
    } else if (type === 'error') {
        borderClass = 'border-rose-500';
        iconHtml = `<div class="w-12 h-12 bg-rose-100 rounded-full flex items-center justify-center mx-auto mb-4"><svg class="w-6 h-6 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></div>`;
        buttonHtml = `<button onclick="document.getElementById('centered-alert-modal').remove()" class="mt-6 w-full bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold py-2 px-4 rounded-lg uppercase tracking-widest text-[10px] transition-colors">Close</button>`;
    }

    overlay.innerHTML = `
        <div class="bg-white rounded-xl shadow-2xl p-6 md:p-8 max-w-sm w-full mx-4 transform transition-all scale-100 text-center border-t-4 ${borderClass}">
            ${iconHtml}
            <h3 class="text-lg font-black text-[#00205b] uppercase tracking-wide mb-2">${title}</h3>
            <p class="text-sm text-slate-500 leading-relaxed">${message}</p>
            ${buttonHtml}
        </div>
    `;
    document.body.appendChild(overlay);
}

// ZERO-LAG ASYNCHRONOUS FORM SUBMISSION WITH CENTERED UI
window.handleFormSubmit = async function(e, form) {
    e.preventDefault();
    const fd = new FormData(form);

    // 1. Show centered loading alert
    showCenteredAlert('loading', 'Processing Request', 'Provisioning account and sending welcome email. Please do not close this window...');

    try {
        const res = await fetch('create_account.php', { method: 'POST', body: fd });
        const data = await res.json();
        
        if (data.status === 'success') {
            showCenteredAlert('success', 'Account Created!', data.message);
            if (form.querySelector('input[value="create"]')) {
                closeStaffModal();
            }
            form.reset();
            triggerDynamicFetch(); // Refresh Table Instantly
        } else {
            showCenteredAlert('error', 'Creation Failed', data.message);
        }
    } catch(error) {
        showCenteredAlert('error', 'Connection Error', 'Failed to communicate with the server. Please check your connection and try again.');
    }
};

// ZERO-LAG ASYNCHRONOUS LINKS (Archive, Restore, Delete)
window.handleAction = async function(action, id, confirmMsg) {
    if (confirmMsg && !confirm(confirmMsg)) return;

    const fd = new URLSearchParams();
    fd.append('ajax_action', action);
    fd.append('id', id);

    try {
        const res = await fetch('create_account.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (typeof showToast === 'function') showToast(data.message, data.status);
        
        if(data.status === 'success') {
            triggerDynamicFetch(); // Refresh Table Instantly
        }
    } catch(e) {
        if (typeof showToast === 'function') showToast("Connection failed.", 'error');
    }
};

// ZERO-LAG ASYNCHRONOUS LIVE DATA POLLING
window.triggerDynamicFetch = async function() {
    const tab = typeof currentTab !== 'undefined' ? currentTab : 'teachers';
    let url = `create_account.php?api_refresh=1&status=${tab}`;
    
    if (tab === 'staff') {
        const f_dept = document.getElementById('f_dept')?.value || 'All';
        url += `&f_dept=${encodeURIComponent(f_dept)}`;
    }

    try {
        const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) return;
        const data = await response.json();
        
        const newDataString = JSON.stringify(data);
        if (newDataString === lastDataString) return; 
        lastDataString = newDataString;

        const tbody = document.getElementById(`${tab}_tbody`);
        if (tbody) tbody.innerHTML = data.html;

        const countBadge = document.getElementById(`${tab}_count`);
        if (countBadge && data.count_text) countBadge.innerText = data.count_text;
        
    } catch (err) {
        console.error("Auto-sync error:", err);
    }
};

// Trigger on load, and poll every 5 seconds
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('teachers_tbody') || document.getElementById('staff_tbody') || document.getElementById('archived_tbody')) {
        triggerDynamicFetch();
        setInterval(triggerDynamicFetch, 5000);
    }
});

// -------------------------------------------------------------
// MODALS SPECIFIC TO CREATE ACCOUNT
// -------------------------------------------------------------

window.openStaffModal = async function(type) {
    const roleSelect = document.getElementById('staff_role_select');
    const roleContainer = document.getElementById('staff_role_container');
    const modalTitle = document.getElementById('staff_modal_title');
    const modalInner = document.querySelector('#staffAccountModal .glossy-panel');
    const submitBtn = document.getElementById('staff_submit_btn');
    
    if (!roleSelect || !modalInner) return;

    roleSelect.innerHTML = ''; 

    modalInner.classList.remove('border-t-[#00205b]', 'border-t-indigo-500');
    modalTitle.parentElement.classList.remove('text-[#00205b]', 'text-indigo-800');
    modalTitle.previousElementSibling.classList.remove('bg-[#00205b]/10', 'bg-indigo-50');
    modalTitle.previousElementSibling.firstElementChild.classList.remove('text-[#00205b]', 'text-indigo-500');
    submitBtn.classList.remove('from-[var(--ldsp-blue-light)]', 'to-[var(--ldsp-blue)]', 'border-[rgba(0,20,60,0.8)]', 'from-indigo-400', 'to-indigo-600', 'border-indigo-700');

    if (type === 'teacher') {
        modalInner.classList.add('border-t-indigo-500');
        modalTitle.innerText = "Provision Teacher";
        modalTitle.parentElement.classList.add('text-indigo-800');
        modalTitle.previousElementSibling.classList.add('bg-indigo-50');
        modalTitle.previousElementSibling.firstElementChild.classList.add('text-indigo-500');
        
        submitBtn.classList.add('from-indigo-400', 'to-indigo-600', 'border-indigo-700');

        roleSelect.innerHTML = '<option value="teacher" selected>Teacher</option>';
        roleContainer.style.display = 'none';
    } else {
        modalInner.classList.add('border-t-[#00205b]');
        modalTitle.innerText = "Provision System Staff";
        modalTitle.parentElement.classList.add('text-[#00205b]');
        modalTitle.previousElementSibling.classList.add('bg-[#00205b]/10');
        modalTitle.previousElementSibling.firstElementChild.classList.add('text-[#00205b]');
        
        submitBtn.classList.add('from-[var(--ldsp-blue-light)]', 'to-[var(--ldsp-blue)]', 'border-[rgba(0,20,60,0.8)]');

        roleSelect.innerHTML = `
            <option value="admin">System Admin</option>
            <option value="admission">Admission Staff</option>
            <option value="registrar">Registrar Staff</option>
            <option value="accounting">Accounting Staff</option>
        `;
        roleContainer.style.display = 'block'; 
    }
    
    if(typeof window.openModal === 'function') {
        window.openModal('staffAccountModal');
    } else {
        const modal = document.getElementById('staffAccountModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
};

window.closeStaffModal = function() { 
    if(typeof window.closeModal === 'function') {
        window.closeModal('staffAccountModal');
    } else {
        const modal = document.getElementById('staffAccountModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
};