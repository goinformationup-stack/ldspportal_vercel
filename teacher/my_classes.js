// =========================================================
// MY CLASSES LOGIC (my_classes.js)
// =========================================================

let currentTab = 'active';
let lastDataString = "";
let isModalOpen = false;
let isFormDirty = false;

// ---------------------------------------------------------
// 1. GLOBAL OVERRIDES FOR LOCALHOST ALERTS
// ---------------------------------------------------------
window.alert = function(msg) {
    showToast(msg, 'error');
};
window.confirm = function(msg) {
    console.warn("Native confirm blocked.");
    return false;
};

// ---------------------------------------------------------
// 2. TOAST & CUSTOM MODAL CONTROLLERS
// ---------------------------------------------------------
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    const isSuccess = type === 'success';
    const bgColor = isSuccess ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800';
    const iconColor = isSuccess ? 'text-emerald-500' : 'text-rose-500';
    const iconPath = isSuccess ? 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
    
    toast.className = `flex items-center gap-3 px-4 py-3 rounded-md shadow-lg border ${bgColor} toast-enter pointer-events-auto z-[9999]`;
    toast.innerHTML = `<svg class="w-5 h-5 ${iconColor}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${iconPath}" /></svg><span class="text-sm font-semibold">${message}</span>`;
    
    container.appendChild(toast);
    setTimeout(() => { 
        toast.classList.replace('toast-enter', 'toast-exit'); 
        setTimeout(() => toast.remove(), 400); 
    }, 3000);
}

window.customConfirm = function(title, message) {
    return new Promise((resolve) => {
        isModalOpen = true;
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
            isModalOpen = false;
            resolve(false);
        };
        document.getElementById('mini-confirm-btn').onclick = () => {
            card.remove();
            isModalOpen = false;
            resolve(true);
        };
    });
};

// ---------------------------------------------------------
// 3. TAB CONTROLLER & FETCHING
// ---------------------------------------------------------
window.switchTab = function(tabName) {
    currentTab = tabName;
    lastDataString = ""; // Force a UI refresh on tab switch
    
    const tabActive = document.getElementById('tab_active');
    const tabArchive = document.getElementById('tab_archive');
    
    if(!tabActive || !tabArchive) return;
    
    // Reset styles
    tabActive.className = "pb-3 text-xs sm:text-sm font-black uppercase tracking-widest border-b-[3px] border-transparent text-slate-400 hover:text-slate-600 transition-colors focus:outline-none flex items-center gap-2";
    tabArchive.className = "pb-3 text-xs sm:text-sm font-black uppercase tracking-widest border-b-[3px] border-transparent text-slate-400 hover:text-slate-600 transition-colors focus:outline-none flex items-center gap-2";
    
    // Apply active styles
    if (tabName === 'active') {
        tabActive.classList.add('border-[#00205b]', 'text-[#00205b]');
        tabActive.classList.remove('border-transparent', 'text-slate-400');
    } else {
        tabArchive.classList.add('border-[#00205b]', 'text-[#00205b]');
        tabArchive.classList.remove('border-transparent', 'text-slate-400');
    }
    
    const searchInput = document.getElementById("classSearch");
    if(searchInput) searchInput.value = "";
    
    fetchClassesUI(true);
};

async function fetchClassesUI(force = false) {
    const searchInput = document.getElementById("classSearch");
    const isSearching = document.activeElement === searchInput;

    try {
        const res = await fetch(`my_classes.php?ajax_fetch_classes=1&tab=${currentTab}`);
        if (!res.ok) return;
        
        const data = await res.json();
        const str = JSON.stringify(data);
        
        if (!force && str === lastDataString) return;
        lastDataString = str;

        if (document.getElementById('active_count') && currentTab === 'active') {
            document.getElementById('active_count').innerText = data.count || '0';
        } else if (document.getElementById('archive_count') && currentTab === 'archive') {
            document.getElementById('archive_count').innerText = data.count || '0';
        }

        if (!isSearching) {
            const container = document.getElementById('classes_container');
            if (container) {
                if (force) container.classList.add('opacity-50', 'transition-opacity');
                container.innerHTML = data.html;
                if (force) setTimeout(() => container.classList.remove('opacity-50'), 150);
            }
            window.filterClasses();
        }
    } catch(e) {
        console.error("Failed to sync classes", e);
    }
}

// ---------------------------------------------------------
// 4. CLIENT-SIDE ROBUST FILTERING
// ---------------------------------------------------------
window.filterClasses = function() {
    let searchInput = document.getElementById("classSearch")?.value.toUpperCase() || "";
    let progInput = document.getElementById("filterProgram")?.value.toUpperCase() || "";
    let yearInput = document.getElementById("filterYear")?.value.toUpperCase() || "";
    
    let table = document.getElementById("classesTable");
    if(!table) return;
    
    let trs = table.getElementsByTagName("tbody")[0].getElementsByTagName("tr");

    for (let i = 0; i < trs.length; i++) {
        if (trs[i].getElementsByTagName("td").length === 1) continue;

        let codeText = trs[i].getAttribute('data-code') || "";
        let titleText = trs[i].getAttribute('data-title') || "";
        let progText = trs[i].getAttribute('data-program') || "";
        let yearText = trs[i].getAttribute('data-year') || "";
        
        let matchesSearch = searchInput === "" || (codeText.indexOf(searchInput) > -1 || titleText.indexOf(searchInput) > -1);
        let matchesProg = progInput === "" || progText === progInput || (progInput === "GENERAL / CORE" && progText.trim() === "");
        let matchesYear = yearInput === "" || yearText === yearInput;
        
        trs[i].style.display = (matchesSearch && matchesProg && matchesYear) ? "" : "none";
    }
};

// ---------------------------------------------------------
// 5. SPA FORM ENGINE & GRADING LOGIC
// ---------------------------------------------------------
function initSPAEngine() {
    document.querySelectorAll('.spa-form').forEach(form => {
        if(form.dataset.spaBound) return;
        form.dataset.spaBound = 'true';
        
        form.addEventListener('submit', async function(e) {
            if (e.target.hasAttribute('data-no-spa') || (!e.target.hasAttribute('data-confirm-passed') && e.target.id === 'gradingForm')) return;
            e.preventDefault();
            
            const submitBtn = Array.from(e.target.elements).find(el => el.type === 'submit' && el.matches(':focus')) || e.target.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn ? submitBtn.innerHTML : 'Processing...';
            
            if (submitBtn) {
                submitBtn.innerHTML = '<svg class="w-3 h-3 animate-spin inline-block mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Saving...';
                submitBtn.disabled = true;
            }
            
            const formData = new FormData(this);
            formData.append('ajax_post', '1');
            if (submitBtn && submitBtn.name) formData.append(submitBtn.name, '1');
            
            try {
                const res = await fetch(window.location.href, { method: 'POST', body: formData });
                const data = await res.json();
                
                if (submitBtn) { submitBtn.innerHTML = originalBtnText; submitBtn.disabled = false; }
                
                if(data.status === 'success') {
                    showToast(data.message, 'success');
                    isFormDirty = false; 
                    
                    const btnWrap = document.getElementById('floating_group_btn_wrap');
                    if(btnWrap) btnWrap.classList.add('hidden');

                    if (data.updates) {
                        for (const [id, html] of Object.entries(data.updates)) {
                            const el = document.getElementById(id);
                            if (el) el.innerHTML = html;
                        }
                    }
                } else { showToast(data.message, 'error'); }
            } catch (error) {
                showToast("Network Error: Could not save data.", 'error');
                if (submitBtn) { submitBtn.innerHTML = originalBtnText; submitBtn.disabled = false; }
            }
        });
    });
}

function markFormDirty() { isFormDirty = true; }

document.addEventListener('input', function(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') { markFormDirty(); }
});

async function triggerDynamicFetch() {
    if (isModalOpen || isFormDirty) return; 

    // If we are on the main class list view, poll the class list
    if (document.getElementById('classes_container')) {
        fetchClassesUI();
        return;
    }

    // If we are on the grading view, poll the grades
    const urlParams = new URLSearchParams(window.location.search);
    const subject_id = urlParams.get('subject_id') || '';
    if (subject_id === '') return;
    
    try {
        const response = await fetch(`my_classes.php?api_refresh=1&subject_id=${encodeURIComponent(subject_id)}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) return;
        const data = await response.json();
        const str = JSON.stringify(data);
        
        if (str === lastDataString) return; 
        lastDataString = str;

        if (data.updates && data.updates.grading_tbody) {
            const tbody = document.getElementById('grading_tbody');
            if (tbody) tbody.innerHTML = data.updates.grading_tbody;
        }
    } catch (err) {}
}

function updateGroupGradeButton() {
    const count = document.querySelectorAll('.grade-cb:checked').length;
    const btnWrap = document.getElementById('floating_group_btn_wrap');
    const countDisplay = document.getElementById('checked_count');
    
    if (btnWrap && countDisplay) {
        if (count > 0) {
            countDisplay.innerText = count;
            btnWrap.classList.remove('hidden');
        } else {
            btnWrap.classList.add('hidden');
        }
    }
}

window.handleCheckboxChange = function() { markFormDirty(); updateGroupGradeButton(); };
window.toggleAllGrades = function(source) { document.querySelectorAll('.grade-cb').forEach(cb => { cb.checked = source.checked; }); markFormDirty(); updateGroupGradeButton(); };

let currentGradeTarget = null; 

window.openGlobalGradeModal = function(studentId) {
    currentGradeTarget = studentId;
    document.getElementById('gradeModalTitle').innerText = "Assign Grade";
    isModalOpen = true;
    const modal = document.getElementById('globalGradeModal');
    modal.classList.remove('hidden');
    setTimeout(() => { modal.firstElementChild.classList.remove('scale-95'); }, 10);
};

window.openGroupGradeModal = function() {
    const selected = document.querySelectorAll('.grade-cb:checked');
    if (selected.length === 0) { showToast("Please check at least one student.", "error"); return; }
    currentGradeTarget = 'group';
    document.getElementById('gradeModalTitle').innerText = `Grade ${selected.length} Students`;
    isModalOpen = true;
    const modal = document.getElementById('globalGradeModal');
    modal.classList.remove('hidden');
    setTimeout(() => { modal.firstElementChild.classList.remove('scale-95'); }, 10);
};

window.closeGlobalGradeModal = function() {
    const modal = document.getElementById('globalGradeModal');
    modal.firstElementChild.classList.add('scale-95');
    setTimeout(() => { modal.classList.add('hidden'); isModalOpen = false; }, 200);
};

window.applyGrade = function(val) {
    const displayVal = val === '' ? '--' : val;
    if (currentGradeTarget === 'group') {
        document.querySelectorAll('.grade-cb:checked').forEach(cb => { updateStudentGradeUI(cb.value, val, displayVal); });
    } else {
        updateStudentGradeUI(currentGradeTarget, val, displayVal);
    }
    markFormDirty();
    closeGlobalGradeModal();
};

function updateStudentGradeUI(sid, val, displayVal) {
    const wrapper = document.getElementById('grade_wrapper_' + sid);
    if (wrapper) {
        const isFailed = (val === '5.00' || val === '5.0' || val === '5');
        
        wrapper.querySelector('.grade-input-hidden').value = val;
        wrapper.querySelector('.grade-display').innerText = displayVal;
        const btn = wrapper.querySelector('button');
        
        // Remove active class colors
        btn.classList.remove('text-[#00205b]', 'text-emerald-700', 'text-rose-700', 'text-blue-800', 'border-slate-300', 'border-rose-300', 'border-blue-300');
        
        // Re-apply new text/border color based on grade
        if (isFailed) {
            btn.classList.add('text-rose-700', 'border-rose-300');
        } else if (val !== '') {
            btn.classList.add('text-emerald-700', 'border-slate-300');
        } else {
            btn.classList.add('text-[#00205b]', 'border-slate-300');
        }

        // Pulse Animation
        btn.classList.add('bg-indigo-50', 'border-[#00205b]');
        setTimeout(() => btn.classList.remove('bg-indigo-50', 'border-[#00205b]'), 1000);
        
        // Teacher Override Visuals - If teacher overrides a registrar grade, the blue should disappear
        const tr = wrapper.closest('tr');
        if (tr) {
            tr.classList.remove('bg-blue-50/80', 'hover:bg-blue-100', 'bg-rose-50', 'hover:bg-rose-100');
            if (isFailed) {
                tr.classList.add('bg-rose-50', 'hover:bg-rose-100');
            } else {
                tr.classList.add('hover:bg-slate-50');
            }
            // Remove shining Registrar badge if the teacher just modified it
            const regBadge = tr.querySelector('.animate-pulse');
            if (regBadge) regBadge.remove();
        }
    }
}

window.exportGradesToCSV = function(tableID, filename = '') {
    const table = document.getElementById(tableID);
    if (!table) return;

    let csv = [];
    let headers = [];
    const headerCells = table.querySelectorAll("thead th");
    for (let i = 1; i < headerCells.length; i++) { // Skip checkbox column
        headers.push('"' + headerCells[i].innerText.replace(/"/g, '""').trim() + '"');
    }
    csv.push(headers.join(","));

    const rows = table.querySelectorAll("tbody tr");
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll("td");
        for (let j = 1; j < cols.length; j++) { // Skip checkbox column
            let input = cols[j].querySelector("input.grade-input-hidden");
            if (input) {
                row.push('"' + (input.value || '').replace(/"/g, '""') + '"');
            } else {
                // Strip HTML tags for clean CSV
                let rawText = cols[j].innerHTML;
                let text = rawText.replace(/<[^>]*>?/gm, ' ').replace(/\s+/g, ' ').trim();
                row.push('"' + text + '"');
            }
        }
        csv.push(row.join(","));
    }

    let csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
    let downloadLink = document.createElement("a");
    downloadLink.download = (filename || 'Grades') + ".csv";
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
};

document.addEventListener('DOMContentLoaded', () => {
    initSPAEngine();
    
    // Initial fetch if we are on the class list view
    if (document.getElementById('classes_container')) {
        fetchClassesUI(true);
    }
    
    const gradingForm = document.getElementById('gradingForm');
    if (gradingForm) {
        gradingForm.addEventListener('submit', async function(e) {
            if(e.target.hasAttribute('data-confirm-passed')) return; 
            e.preventDefault();
            const form = this;
            
            const confirmed = await window.customConfirm('Save Official Grades', 'Are you sure you want to permanently save these grades into the registry?');
            if (confirmed) {
                form.setAttribute('data-confirm-passed', 'true');
                form.querySelector('button[type="submit"]').click(); 
                form.removeAttribute('data-confirm-passed');
            }
        });
    }
    setInterval(triggerDynamicFetch, 5000);
});