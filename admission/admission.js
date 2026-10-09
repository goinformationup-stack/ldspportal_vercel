// =========================================================
// ADMISSION PANEL GLOBAL UTILITIES (admission.js)
// =========================================================

window.isAdmissionModalOpen = false;

// -------------------------------------------------------------
// GLOBAL TOAST NOTIFICATIONS
// -------------------------------------------------------------
window.showToast = function(message, type = 'success') {
    let container = document.getElementById('toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    const isSuccess = type === 'success';
    const bgColor = isSuccess ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800';
    const iconPath = isSuccess ? 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
    const iconColor = isSuccess ? 'text-emerald-500' : 'text-rose-500';

    toast.className = `fixed bottom-6 right-6 flex items-center gap-2 px-4 py-3 rounded-lg shadow-xl border ${bgColor} font-bold text-sm z-[9999] transition-all duration-300 translate-x-full opacity-0`;
    toast.innerHTML = `<svg class="w-5 h-5 ${iconColor}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${iconPath}" /></svg><span class="tracking-wide">${message}</span>`;

    container.appendChild(toast);
    requestAnimationFrame(() => toast.classList.remove('translate-x-full', 'opacity-0'));
    setTimeout(() => {
        toast.classList.add('translate-x-full', 'opacity-0');
        setTimeout(() => toast.remove(), 400);
    }, 3500);
};

// -------------------------------------------------------------
// MODAL ENGINE
// -------------------------------------------------------------
window.openModal = function(modalId) {
    window.isAdmissionModalOpen = true;
    const modal = document.getElementById(modalId);
    if (!modal) return;
    modal.classList.remove('hidden'); 
    modal.classList.add('flex'); 
    void modal.offsetWidth; 
    modal.classList.add('modal-active');
};

window.closeModal = function(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    modal.classList.remove('modal-active');
    setTimeout(() => { 
        modal.classList.add('hidden'); 
        modal.classList.remove('flex'); 
        window.isAdmissionModalOpen = false;
    }, 300); 
};

// -------------------------------------------------------------
// DOCUMENT VIEWER
// -------------------------------------------------------------
window.viewDocument = function(url) {
    window.openModal('documentModal');
    document.getElementById('docLoader').classList.remove('hidden');
    document.getElementById('docIframe').classList.add('hidden');
    document.getElementById('docImage').classList.add('hidden');
    document.getElementById('docError').classList.add('hidden');
    document.getElementById('docError').classList.remove('flex');
    document.getElementById('docDownloadBtn').href = url;
    document.getElementById('docFallbackLink').href = url;
    
    const cleanUrl = url.split('?')[0];
    const ext = cleanUrl.split('.').pop().toLowerCase();
    
    if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) { 
        document.getElementById('docImage').src = url; 
        document.getElementById('docImage').classList.remove('hidden'); 
    } else if (['pdf', 'txt'].includes(ext)) { 
        document.getElementById('docIframe').src = url; 
        document.getElementById('docIframe').classList.remove('hidden'); 
    } else {
        document.getElementById('docLoader').classList.add('hidden');
        document.getElementById('docError').classList.remove('hidden'); 
        document.getElementById('docError').classList.add('flex');
    }
};

window.closeDocModal = function() { 
    window.closeModal('documentModal'); 
    setTimeout(() => {
        document.getElementById('docIframe').src = ''; 
        document.getElementById('docImage').src = ''; 
    }, 300);
};

// -------------------------------------------------------------
// EMAIL DISPATCHER
// -------------------------------------------------------------
window.copyMsg = function() {
    const textEl = document.getElementById("copy-text");
    textEl.select(); 
    document.execCommand("copy");
    window.showToast("Message copied to clipboard!", 'success');
};

window.closeMsgModal = function() { window.closeModal('msg-modal'); };

// -------------------------------------------------------------
// STUDENT PROFILE VIEWER
// -------------------------------------------------------------
window.openProfileModal = function(button) {
    try {
        const rawProfile = button.getAttribute('data-profile');
        if (!rawProfile) return;

        const data = JSON.parse(rawProfile);
        const parseVal = (val) => (val !== null && val !== undefined && String(val).trim() !== '') ? String(val) : 'Not Provided';
        
        const first = data.first_name || ''; 
        const middle = data.middle_name ? ' ' + data.middle_name : ''; 
        const last = data.last_name ? data.last_name + ', ' : '';
        const builtName = (last + first + middle).trim() || 'No Name Provided';
        
        const setText = (id, text) => { const el = document.getElementById(id); if(el) el.innerText = text; };

        setText('mod-applicant-name', builtName);
        setText('mod-adm-no', parseVal(data.admission_number));
        setText('mod-dob', parseVal(data.dob));
        setText('mod-gender', parseVal(data.gender));
        setText('mod-pob', parseVal(data.pob || data.place_of_birth));
        setText('mod-email', parseVal(data.email));
        setText('mod-phone', parseVal(data.phone));
        setText('mod-address', parseVal(data.address));
        setText('mod-program', parseVal(data.program || data.program_choice));
        setText('mod-type', parseVal(data.student_type));
        setText('mod-year-level', parseVal(data.year_level));
        setText('mod-school', parseVal(data.school_last_attended || data.last_school));
        setText('mod-school-year', parseVal(data.school_year_attended));
        setText('mod-father-name', parseVal(data.father_name));
        setText('mod-father-occ', parseVal(data.father_occupation));
        setText('mod-father-con', parseVal(data.father_contact));
        setText('mod-mother-name', parseVal(data.mother_name));
        setText('mod-mother-occ', parseVal(data.mother_occupation));
        setText('mod-mother-con', parseVal(data.mother_contact));
        setText('mod-em-name', parseVal(data.emergency_contact_name));
        setText('mod-em-con', parseVal(data.emergency_contact_number));
        setText('mod-influence', parseVal(data.influence_source));
        
        window.openModal('profileModal');
    } catch(e) {
        console.error("Profile view error", e);
    }
};

window.closeProfileModal = function() { window.closeModal('profileModal'); };