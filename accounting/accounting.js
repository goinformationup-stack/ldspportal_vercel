// =========================================================
// ACCOUNTING PANEL GLOBAL UTILITIES (accounting.js)
// =========================================================

// Global flag to tell background polling to pause if ANY modal is open
window.isAccountingModalOpen = false;

// -------------------------------------------------------------
// GLOBAL TOAST NOTIFICATIONS
// -------------------------------------------------------------
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

// -------------------------------------------------------------
// CORE MODAL CONTROLS
// -------------------------------------------------------------
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

// -------------------------------------------------------------
// SHARED RECEIPT VIEWER
// -------------------------------------------------------------
window.openReceiptModal = function(fileUrl) {
    window.isAccountingModalOpen = true; 
    const inner = document.getElementById('receiptModalInner');
    const viewer = document.getElementById('receiptViewer');
    const downloadBtn = document.getElementById('receiptDownloadBtn');

    if (!inner || !viewer || !downloadBtn) return;
    viewer.src = fileUrl; 
    downloadBtn.href = fileUrl;
    
    window.openModal('receiptModal');
    setTimeout(() => { inner.classList.remove('scale-95', 'opacity-0'); }, 10);
};

window.closeReceiptModal = function() {
    const inner = document.getElementById('receiptModalInner');
    const viewer = document.getElementById('receiptViewer');

    if (inner) inner.classList.add('scale-95', 'opacity-0');
    setTimeout(() => { 
        window.closeModal('receiptModal'); 
        if (viewer) viewer.src = ''; 
        window.isAccountingModalOpen = false; 
    }, 200);
};

// -------------------------------------------------------------
// PROGRESS TRACKER ENGINE (DYNAMIC FOR BOTH STUDENT TYPES)
// -------------------------------------------------------------
window.updateProgressTracker = function(data) {
    let pta = 0, ptr = 0, evalDone = 0, prov = 0;

    if (data.final_status) {
        pta = 1; 
        ptr = (data.final_status === 'Pending Registrar' || data.final_status === 'Enrolled') ? 1 : 0;
        evalDone = (data.final_status === 'Enrolled') ? 1 : 0;
        prov = (data.final_status === 'Enrolled') ? 1 : 0; 
    } else {
        pta = parseInt(data.pushed_to_accounting) || 0;
        ptr = parseInt(data.pushed_to_registrar) || 0;
        evalDone = parseInt(data.registrar_evaluated) || 0;
        prov = data.provisioned_user_id ? 1 : 0;
    }

    const s1 = 'completed'; 
    const s2 = pta ? 'completed' : 'active';
    const s3 = ptr ? (evalDone ? 'completed' : 'active') : 'pending';
    const s4 = evalDone ? (prov ? 'completed' : 'active') : 'pending';

    let lineWidth = '0%';
    if (prov || (evalDone && data.final_status)) lineWidth = '100%';
    else if (evalDone) lineWidth = '100%'; 
    else if (ptr) lineWidth = '66%';
    else if (pta) lineWidth = '33%';

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
            icon.classList.add('border-slate-200', 'bg-white', 'text-slate-400');
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
// SHARED STUDENT PROFILE VIEWER (WITH FAILSAFE BUILDER)
// -------------------------------------------------------------
window.openStudentModal = function(buttonElement) {
    try {
        window.isAccountingModalOpen = true; 
        const rawProfile = buttonElement.getAttribute('data-profile');
        if (!rawProfile) return;

        const data = JSON.parse(rawProfile);
        const parseVal = (val) => (val !== null && val !== undefined && String(val).trim() !== '') ? String(val) : 'Not Provided';
        
        window.updateProgressTracker(data);

        const builtName = data.applicant_name || 'Unknown Applicant';
        const setText = (id, text) => { const el = document.getElementById(id); if (el) el.innerText = text; };

        setText('m_student_type', parseVal(data.student_status || data.student_type));
        setText('m_modality', parseVal(data.learning_mode));
        setText('m_semester', parseVal(data.semester));
        
        const dateVal = data.transaction_date || data.approved_at || data.created_at || data.t_date;
        setText('m_date', dateVal ? new Date(dateVal).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : 'N/A');
        
        setText('m_detail_name', builtName);
        setText('m_applicant_name', builtName);
        
        const initialEl = document.getElementById('m_initial');
        if (initialEl) initialEl.innerText = builtName.charAt(0).toUpperCase();

        setText('m_studentid', 'ID: ' + parseVal(data.student_id || data.identifier || data.s_id || data.admission_number));
        setText('m_email', parseVal(data.email || data.user_email || data.personal_email));
        setText('m_phone', parseVal(data.phone || data.contact_number));
        setText('m_gender', parseVal(data.u_gender || data.gender || data.u_gen || data.a_gen));
        setText('m_dob', parseVal(data.dob || data.date_of_birth || data.u_dob || data.a_dob));
        setText('m_pob', parseVal(data.pob || data.place_of_birth));
        setText('m_address', parseVal(data.address || data.home_address || data.u_addr || data.a_addr));
        
        setText('m_school', parseVal(data.school_last_attended || data.last_school));
        setText('m_school_year', parseVal(data.school_year_attended || data.year_graduated));
        
        setText('m_program', parseVal(data.program || data.up_program || data.program_choice));
        setText('m_type', parseVal(data.student_type || data.admission_type));
        
        setText('m_father_name', parseVal(data.father_name));
        setText('m_father_occ', parseVal(data.father_occupation));
        setText('m_father_con', parseVal(data.father_contact));
        
        setText('m_mother_name', parseVal(data.mother_name));
        setText('m_mother_occ', parseVal(data.mother_occupation));
        setText('m_mother_con', parseVal(data.mother_contact));
        
        setText('m_em_name', parseVal(data.emergency_contact_name || data.emergency_name));
        setText('m_em_con', parseVal(data.emergency_contact_number || data.emergency_number));
        
        setText('m_influence', parseVal(data.influence_source || data.source_of_info));
        
        const docContainer = document.getElementById('m_documents_container');
        if (docContainer) {
            if (data.payment_proof) {
                docContainer.innerHTML = `<button type="button" onclick="window.openReceiptModal('../${data.payment_proof}')" class="bg-white border border-slate-300 hover:bg-slate-50 rounded-lg px-4 py-2 w-full sm:w-auto text-[10px] uppercase tracking-widest font-bold text-slate-600 flex items-center justify-center gap-2 transition-colors"><svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg> View Attached Payment Receipt</button>`;
            } else if (data.uploaded_files) {
                const files = data.uploaded_files.split(',').map(s => s.trim()).filter(Boolean);
                let html = '<div class="flex flex-wrap gap-2">';
                files.forEach((file, index) => {
                    const ext = file.split('.').pop().toLowerCase();
                    const type = (ext === 'pdf') ? 'application/pdf' : 'image/' + ext;
                    const label = `Doc ${index+1}`;
                    html += `<button type="button" onclick="window.openDocModal('../${file}', '${type}')" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 px-3 py-1.5 rounded text-[9px] font-black uppercase tracking-widest transition-colors shadow-sm inline-flex items-center gap-1.5 cursor-pointer"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg> ${label}</button>`;
                });
                html += '</div>';
                docContainer.innerHTML = html;
            } else {
                docContainer.innerHTML = `<span class="text-xs text-slate-500 italic font-medium">No documents uploaded.</span>`;
            }
        }
        
        window.openModal('studentProfileModal');
        const inner = document.getElementById('profileModalInner');
        if (inner) inner.classList.remove('scale-95', 'opacity-0');
        
    } catch (err) {
        console.error("Profile modal error:", err);
    }
};

window.closeStudentModal = function() {
    const inner = document.getElementById('profileModalInner');
    if (inner) inner.classList.add('scale-95', 'opacity-0');
    
    setTimeout(() => { 
        window.closeModal('studentProfileModal'); 
        window.isAccountingModalOpen = false; 
    }, 200);
};