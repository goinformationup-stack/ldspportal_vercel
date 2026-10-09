// =========================================================
// AVAILABLE SUBJECTS LOGIC (available_subjects.js)
// =========================================================

let lastAppsData = "";
let lastSubjData = "";

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
// 2. TOAST & MINI CARD CONTROLLERS
// ---------------------------------------------------------
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    const isSuccess = type === 'success';
    const bgColor = isSuccess ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-slate-50 border-slate-300 text-slate-800';
    const iconColor = isSuccess ? 'text-emerald-500' : 'text-slate-500';
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

function toggleApplicationsCard() {
    const appsCard = document.getElementById('floating_apps_card');
    if (appsCard.classList.contains('dropdown-closed')) {
        appsCard.classList.remove('dropdown-closed');
        appsCard.classList.add('dropdown-open');
    } else {
        appsCard.classList.add('dropdown-closed');
        appsCard.classList.remove('dropdown-open');
    }
}

// ---------------------------------------------------------
// 3. ZERO-LAG BACKGROUND SYNC & UI ACTIONS
// ---------------------------------------------------------
async function fetchApplicationsUI(force = false) {
    try {
        const res = await fetch('available_subjects.php?ajax_fetch=applications');
        const data = await res.json();
        
        const str = JSON.stringify(data);
        if (!force && str === lastAppsData) return;
        lastAppsData = str;

        document.getElementById('my_applications_container').innerHTML = data.html;
        document.getElementById('my_apps_count').innerText = data.count || '0';
    } catch(e) {}
}

async function fetchAvailableSubjectsUI(force = false) {
    const searchInput = document.getElementById("applySearch");
    const isSearching = document.activeElement === searchInput && searchInput.value.trim() !== '';

    try {
        const res = await fetch('available_subjects.php?ajax_fetch=available_subjects');
        const data = await res.json();
        
        const str = JSON.stringify(data);
        if (!force && str === lastSubjData) return;
        lastSubjData = str;

        if (!isSearching && !document.getElementById('mini-confirm-card')) {
            document.getElementById('available_subjects_container').innerHTML = data.html;
            filterApplySubjects();
        }
    } catch(e) {}
}

async function handleApplication(action, subjectId) {
    if (action === 'unapply') {
        const confirmed = await window.customConfirm("Revoke Application", "Are you sure you want to revoke your application for this subject?");
        if (!confirmed) return;
    } else if (action === 'apply') {
        const confirmed = await window.customConfirm("Submit Application", "Confirm your teaching application for this subject?");
        if (!confirmed) return;
    }

    const formData = new URLSearchParams();
    formData.append('ajax_action', action);
    formData.append('subject_id', subjectId);
    
    try {
        const res = await fetch('available_subjects.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        });
        const data = await res.json();
        showToast(data.message, data.status);
        if (data.status === 'success') {
            fetchApplicationsUI(true);
            fetchAvailableSubjectsUI(true);
        }
    } catch (err) { showToast('Failed to connect to server.', 'error'); }
}

function filterApplySubjects() {
    let searchInput = document.getElementById("applySearch").value.toUpperCase();
    let progInput = document.getElementById("filterProgram").value.toUpperCase();
    let yearInput = document.getElementById("filterYear").value.toUpperCase();
    
    let table = document.getElementById("applyTable");
    if(!table) return;
    let tr = table.getElementsByClassName("apply-subject-row");

    for (let i = 0; i < tr.length; i++) {
        let codeCell = tr[i].getElementsByTagName("td")[0];
        let titleCell = tr[i].getElementsByTagName("td")[1];
        let progCell = tr[i].getElementsByTagName("td")[2];
        let yearCell = tr[i].getElementsByTagName("td")[3];
        
        if (codeCell && titleCell && progCell && yearCell) {
            let codeText = codeCell.textContent || codeCell.innerText;
            let titleText = titleCell.textContent || titleCell.innerText;
            let progText = progCell.textContent || progCell.innerText;
            let yearText = yearCell.textContent || yearCell.innerText;
            
            let matchesSearch = (codeText.toUpperCase().indexOf(searchInput) > -1 || titleText.toUpperCase().indexOf(searchInput) > -1);
            let matchesProg = (progInput === "" || progText.toUpperCase() === progInput || (progInput === "GENERAL" && progText.trim() === ""));
            let matchesYear = (yearInput === "" || yearText.toUpperCase() === yearInput);
            
            tr[i].style.display = (matchesSearch && matchesProg && matchesYear) ? "" : "none";
        }
    }
}

function triggerBackgroundSync() {
    fetchApplicationsUI();
    fetchAvailableSubjectsUI();
}

window.onload = () => {
    triggerBackgroundSync(); 
    setInterval(triggerBackgroundSync, 5000);
};