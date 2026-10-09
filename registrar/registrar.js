// =========================================================
// REGISTRAR GLOBAL UTILITIES (registrar.js)
// =========================================================

window.isRegistrarModalOpen = false;

window.showToast = function(message, type = 'success') {
    let container = document.getElementById('toastContainer');
    if (!container) return;

    const toast = document.createElement('div');
    const isSuccess = type === 'success';
    const bgColor = isSuccess ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800';
    const iconPath = isSuccess ? 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
    const iconColor = isSuccess ? 'text-emerald-500' : 'text-rose-500';

    toast.className = `flex items-center gap-2 px-3 py-2 rounded-lg shadow-lg border ${bgColor} toast-enter z-[9999]`;
    toast.innerHTML = `<svg class="w-4 h-4 ${iconColor}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${iconPath}" /></svg><span class="text-[11px] font-semibold tracking-wide">${message}</span>`;

    container.appendChild(toast);
    requestAnimationFrame(() => toast.classList.remove('translate-x-full', 'opacity-0'));
    setTimeout(() => {
        toast.classList.replace('toast-enter', 'toast-exit');
        setTimeout(() => toast.remove(), 400);
    }, 3000);
};

window.openModal = function(modalId) {
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
    setTimeout(() => { modal.classList.add('hidden'); modal.classList.remove('flex'); }, 200); 
};

window.scrollToTop = function() {
    const mainScrollArea = document.getElementById('mainScrollArea');
    if (mainScrollArea) mainScrollArea.scrollTo({ top: 0, behavior: 'smooth' });
};

// -------------------------------------------------------------
// PROGRESS TRACKER ENGINE (BULLETPROOF STRICT EVALUATION)
// -------------------------------------------------------------
window.updateProgressTracker = function(data) {
    if (!data) return;

    // Strict Evaluation logic: The Registrar sees students actively at Step 3.
    // If they were completely enrolled, they'd be in the Master List at Step 4.
    let s1 = 'completed', s2 = 'completed', s3 = 'active', s4 = 'pending';
    let lineWidth = '66%';

    const isEnrolled = (data.final_status === 'Enrolled' || parseInt(data.registrar_evaluated) === 1);
    const isProvisioned = (data.provisioned_user_id && parseInt(data.provisioned_user_id) > 0);

    if (isEnrolled && isProvisioned) {
        s3 = 'completed'; s4 = 'completed'; lineWidth = '100%';
    } else if (isEnrolled) {
        s3 = 'completed'; s4 = 'active'; lineWidth = '100%';
    }

    const line = document.getElementById('tracker-line');
    if (line) line.style.width = lineWidth;

    const setStepState = (stepNum, state) => {
        const icon = document.getElementById(`step${stepNum}-icon`);
        const text = document.getElementById(`step${stepNum}-text`);
        if (!icon || !text) return;

        icon.className = `w-7 h-7 rounded-full flex items-center justify-center font-bold text-[10px] border-[2px] transition-all duration-500 z-10`;
        text.className = `absolute top-8 text-[8px] font-black uppercase tracking-wider text-center w-20`;

        if (state === 'completed') {
            icon.classList.add('border-emerald-500', 'bg-emerald-500', 'text-white');
            icon.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>';
            text.classList.add('text-emerald-700');
        } else if (state === 'active') {
            icon.classList.add('border-blue-500', 'bg-blue-50', 'text-blue-600', 'shadow-[0_0_0_4px_rgba(59,130,246,0.2)]', 'animate-pulse');
            icon.innerHTML = stepNum;
            text.classList.add('text-blue-700');
        } else {
            icon.classList.add('border-slate-300', 'bg-white', 'text-slate-400');
            icon.innerHTML = stepNum;
            text.classList.add('text-slate-400');
        }
    };

    setStepState(1, s1);
    setStepState(2, s2);
    setStepState(3, s3);
    setStepState(4, s4);
};

// -------------------------------------------------------------
// SHARED STUDENT PROFILE MODAL
// -------------------------------------------------------------
window.openStudentModal = function(buttonElement) {
    try {
        window.isRegistrarModalOpen = true; 
        const rawProfile = buttonElement.getAttribute('data-profile');
        if (!rawProfile) return;

        const data = JSON.parse(rawProfile);
        const parseVal = (val) => (val !== null && val !== undefined && String(val).trim() !== '') ? String(val) : 'Not Provided';
        
        window.updateProgressTracker(data);

        const first = data.first_name || ''; 
        const middle = data.middle_name ? ' ' + data.middle_name : ''; 
        const last = data.last_name ? data.last_name + ', ' : '';
        const constructedName = (last + first + middle).trim();
        const builtName = data.applicant_name || constructedName || 'Unknown Applicant';

        const setText = (id, text) => { const el = document.getElementById(id); if (el) el.innerText = text; };

        setText('m_initial', builtName.charAt(0).toUpperCase());
        setText('m_applicant_name', builtName);
        setText('m_studentid', `ID: ${parseVal(data.student_id || data.identifier || data.admission_number)}`);
        setText('m_student_type', parseVal(data.record_type === 'online' ? 'Online Enrollee' : 'New Applicant'));
        
        setText('m_email', parseVal(data.user_email || data.personal_email || data.email));
        setText('m_gender', parseVal(data.u_gender || data.gender));
        
        const dobRaw = data.dob || data.date_of_birth;
        const dob = dobRaw && dobRaw !== '0000-00-00' ? new Date(dobRaw).toLocaleDateString() : 'N/A';
        setText('m_dob', dob);
        
        setText('m_pob', parseVal(data.pob || data.place_of_birth));
        setText('m_address', parseVal(data.address || data.home_address));
        
        setText('m_modality', parseVal(data.learning_mode || 'Face-to-Face'));
        setText('m_semester', parseVal(data.semester || document.querySelector('select[name="f_semester"]')?.value || '1st Semester'));
        setText('m_school', parseVal(data.school_last_attended || data.last_school));
        setText('m_school_year', parseVal(data.school_year_attended || data.year_graduated));
        
        setText('m_father_name', parseVal(data.father_name));
        setText('m_father_details', `${parseVal(data.father_occupation)} | ${parseVal(data.father_contact)}`);
        
        setText('m_mother_name', parseVal(data.mother_name));
        setText('m_mother_details', `${parseVal(data.mother_occupation)} | ${parseVal(data.mother_contact)}`);
        
        setText('m_em_name', parseVal(data.emergency_contact_name || data.emergency_name));
        setText('m_em_con', parseVal(data.emergency_contact_number || data.emergency_number));
        
        window.openModal('studentProfileModal');
    } catch (err) {
        console.error("Profile modal error:", err);
    }
};

window.closeStudentModal = function() {
    window.closeModal('studentProfileModal'); 
    window.isRegistrarModalOpen = false;
};