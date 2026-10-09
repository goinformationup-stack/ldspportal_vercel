// =========================================================
// MANAGE TEACHING SPECIFIC LOGIC (manage_teaching.js)
// =========================================================

let lastDataString = "";

// ---------------------------------------------------------
// 1. GLOBAL OVERRIDES FOR LOCALHOST ALERTS
// ---------------------------------------------------------
window.alert = function(msg) {
    showToast(msg, 'error');
};
window.confirm = function(msg) {
    console.warn("Native confirm blocked. Use window.customConfirm instead.");
    return false;
};

// ---------------------------------------------------------
// 2. MODAL & TOAST CONTROLLERS 
// ---------------------------------------------------------
window.openModal = window.openModal || function(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex'); 
    void modal.offsetWidth;
    modal.classList.add('modal-active');
};

window.closeModal = window.closeModal || function(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    modal.classList.remove('modal-active');
    setTimeout(() => {
        modal.classList.remove('flex'); 
        modal.classList.add('hidden');
    }, 300);
};

window.showToast = window.showToast || function(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    const isSuccess = type === 'success';
    const bgColor = isSuccess ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-slate-50 border-slate-300 text-slate-800';
    const iconPath = isSuccess ? 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
    const iconColor = isSuccess ? 'text-emerald-500' : 'text-slate-500';
    
    toast.className = `flex items-center gap-2 px-3 py-2 rounded-md shadow-lg border ${bgColor} toast-enter z-[9999] pointer-events-auto`;
    toast.innerHTML = `<svg class="w-4 h-4 ${iconColor}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${iconPath}" /></svg><span class="text-[11px] font-semibold tracking-wide">${message}</span>`;
    
    container.appendChild(toast);
    setTimeout(() => {
        toast.classList.replace('toast-enter', 'toast-exit');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
};

// ---------------------------------------------------------
// 3. MINI FLOATING CONFIRMATION CARD 
// ---------------------------------------------------------
window.customConfirm = function(title, message) {
    return new Promise((resolve) => {
        const existing = document.getElementById('mini-confirm-card');
        if (existing) existing.remove();

        const card = document.createElement('div');
        card.id = 'mini-confirm-card';
        card.className = 'fixed top-6 left-1/2 transform -translate-x-1/2 z-[9999] bg-white rounded-lg shadow-2xl border border-slate-200 p-4 w-80 animate-down pointer-events-auto flex flex-col gap-3';
        
        card.innerHTML = `
            <div class="flex items-start gap-3">
                <div class="bg-indigo-50 text-[#00205b] p-1.5 rounded-md shrink-0 border border-indigo-100">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <div>
                    <h4 class="text-sm font-black text-[#00205b] leading-tight">${title}</h4>
                    <p class="text-[0.65rem] font-medium text-slate-500 mt-1 leading-relaxed">${message}</p>
                </div>
            </div>
            <div class="flex gap-2 justify-end mt-1 border-t border-slate-100 pt-3">
                <button id="mini-cancel-btn" class="px-4 py-1.5 bg-white border border-slate-300 hover:bg-slate-50 text-slate-600 rounded text-[10px] font-bold uppercase tracking-widest transition-colors focus:outline-none">Cancel</button>
                <button id="mini-confirm-btn" class="px-4 py-1.5 bg-[#00205b] hover:bg-[#001233] text-white rounded text-[10px] font-bold uppercase tracking-widest transition-colors shadow-sm focus:outline-none border border-[#001233]">Confirm</button>
            </div>
        `;
        
        if (!document.getElementById('mini-card-style')) {
            const style = document.createElement('style');
            style.id = 'mini-card-style';
            style.innerHTML = `@keyframes slideDownFade { from { opacity: 0; transform: translate(-50%, -20px); } to { opacity: 1; transform: translate(-50%, 0); } } .animate-down { animation: slideDownFade 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards; }`;
            document.head.appendChild(style);
        }

        document.body.appendChild(card);

        document.getElementById('mini-cancel-btn').onclick = () => {
            card.remove();
            resolve(false);
        };
        document.getElementById('mini-confirm-btn').onclick = () => {
            card.remove();
            resolve(true);
        };
    });
};

// ---------------------------------------------------------
// 4. ZERO-LAG ASYNCHRONOUS LIVE DATA POLLING
// ---------------------------------------------------------
window.triggerDynamicFetch = async function(force = false) {
    const searchInput = document.getElementById('subjectSearch');
    const isSearching = document.activeElement === searchInput && searchInput.value.trim() !== '';
    
    try {
        const url = `manage_teaching.php?api_refresh=1&program_id=${encodeURIComponent(window.TEACHING_INIT_DATA.activeProgramId)}`;
        const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        
        if (!response.ok) return;
        
        const data = await response.json();
        const newDataString = JSON.stringify(data);
        
        if (!force && newDataString === lastDataString) return; 
        lastDataString = newDataString;

        const assignModalActive = document.getElementById('assignTeacherModal')?.classList.contains('flex');

        // 1. UPDATE ROSTER (Only if not assigning/searching)
        if (!isSearching && !assignModalActive && !document.getElementById('mini-confirm-card')) {
            const container = document.getElementById('master_roster_container');
            if (container && data.html) {
                if (force) container.classList.add('opacity-50'); 
                container.innerHTML = data.html;
                if (force) setTimeout(() => container.classList.remove('opacity-50'), 150);
            }
            
            if (document.getElementById('stat_assigned')) {
                document.getElementById('stat_assigned').innerText = data.assigned;
            }
            if (document.getElementById('stat_unassigned')) {
                document.getElementById('stat_unassigned').innerText = data.unassigned;
            }
            if (searchInput && searchInput.value.trim() !== '') {
                window.filterSubjects();
            }
        }

        // 2. UPDATE APPLICATIONS QUEUE (Always run so new requests pop up live)
        if (document.getElementById('applications_tbody') && data.applications_html) {
            document.getElementById('applications_tbody').innerHTML = data.applications_html;
        }
        if (document.getElementById('applications_badge_count') && data.applications_count !== undefined) {
            document.getElementById('applications_badge_count').innerText = data.applications_count + ' Pending';
            const cardBadge = document.getElementById('applications_card_count');
            if(cardBadge) {
                if(data.applications_count > 0) {
                    cardBadge.innerText = data.applications_count;
                    cardBadge.classList.remove('hidden');
                } else {
                    cardBadge.classList.add('hidden');
                }
            }
        }

        // 3. UPDATE TEACHER LIST in Modal (Only if modal is closed so we don't reset radio buttons)
        if (!assignModalActive && data.teachers_html) {
            const teacherContainer = document.getElementById('teacher_options_container');
            if (teacherContainer) {
                teacherContainer.innerHTML = data.teachers_html;
            }
        }
        
    } catch (err) {
        console.error("Auto-sync error:", err);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('master_roster_container')) {
        setInterval(() => window.triggerDynamicFetch(), 5000);
    }
});

// ---------------------------------------------------------
// 5. UI AND AJAX ACTIONS
// ---------------------------------------------------------
window.changeProgram = function(selectElement) {
    window.TEACHING_INIT_DATA.activeProgramId = selectElement.value;
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    document.getElementById('active_program_label').innerText = selectedOption.getAttribute('data-name') || 'Unassigned Program';
    
    window.triggerDynamicFetch(true);
};

window.submitTeacherAssignment = async function(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.innerHTML = '<svg class="w-4 h-4 animate-spin inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Processing...';
    submitBtn.disabled = true;
    
    try {
        const res = await fetch('manage_teaching.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.status === 'success') {
            window.showToast(data.message, 'success');
            window.closeModal('assignTeacherModal');
            window.triggerDynamicFetch(true); 
        } else {
            window.showToast(data.message || 'An error occurred.', 'error');
        }
    } catch (err) {
        window.showToast('Failed to connect to the server.', 'error');
    } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
};

window.denyApplication = async function(appId) {
    const confirmed = await window.customConfirm("Deny Request", "Are you sure you want to deny this faculty teaching application?");
    if (!confirmed) return;

    const formData = new URLSearchParams();
    formData.append('action', 'deny_application_ajax');
    formData.append('application_id', appId);

    try {
        const res = await fetch('manage_teaching.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        });
        const data = await res.json();
        window.showToast(data.message, data.status);
        if (data.status === 'success') {
            window.triggerDynamicFetch(true);
        }
    } catch (err) {
        window.showToast('Failed to connect to server.', 'error');
    }
};

window.openAssignModal = function(subId, subCode, subDesc, prog, yr, sem, currentTeacherId) {
    document.getElementById('mod_sub_id').value = subId;
    document.getElementById('mod_sub_code').innerText = subCode;
    document.getElementById('mod_sub_desc').innerText = subDesc;
    
    const searchInput = document.getElementById('teacherSearch');
    if (searchInput) {
        searchInput.value = '';
        window.filterTeachers();
    }
    
    document.querySelectorAll('input[name="teacher_id"]').forEach(radio => radio.checked = false);

    if (currentTeacherId) {
        const radio = document.getElementById('teacher_radio_' + currentTeacherId);
        if (radio) {
            radio.checked = true;
            setTimeout(() => radio.scrollIntoView({ behavior: 'smooth', block: 'center' }), 100);
        } else {
            const unassignedRadio = document.getElementById('teacher_radio_unassigned');
            if(unassignedRadio) unassignedRadio.checked = true;
        }
    } else {
        const unassignedRadio = document.getElementById('teacher_radio_unassigned');
        if(unassignedRadio) {
            unassignedRadio.checked = true;
            setTimeout(() => unassignedRadio.scrollIntoView({ behavior: 'smooth', block: 'center' }), 100);
        }
    }
    
    window.openModal('assignTeacherModal');
};

// ---------------------------------------------------------
// 6. CLIENT-SIDE FILTERING 
// ---------------------------------------------------------
window.filterTeachers = function() {
    let input = document.getElementById("teacherSearch").value.toUpperCase();
    let items = document.getElementsByClassName("teacher-list-item");
    
    for (let i = 0; i < items.length; i++) {
        let item = items[i];
        let text = item.textContent || item.innerText;
        if (text.toUpperCase().indexOf(input) > -1) {
            item.style.display = "";
        } else {
            item.style.display = "none";
        }
    }
};

window.filterSubjects = function() {
    let input = document.getElementById("subjectSearch").value.toUpperCase();
    let termBlocks = document.getElementsByClassName("term-block");
    
    for (let i = 0; i < termBlocks.length; i++) {
        let block = termBlocks[i];
        let trs = block.getElementsByClassName("subject-row");
        let hasVisibleRow = false;
        
        for (let j = 0; j < trs.length; j++) {
            let tr = trs[j];
            let text = tr.textContent || tr.innerText;
            
            if (text.toUpperCase().indexOf(input) > -1) {
                tr.style.display = "";
                hasVisibleRow = true;
            } else {
                tr.style.display = "none";
            }
        }
        
        if (hasVisibleRow) {
            block.style.display = "";
        } else {
            block.style.display = "none";
        }
    }
};