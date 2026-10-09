// =========================================================
// APPLICATION MANAGEMENT SPECIFIC LOGIC (SPA ENGINE)
// =========================================================

window.isAdmissionModalOpen = false;

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

    toast.className = `flex items-center gap-2 px-4 py-3 rounded-lg shadow-xl border ${bgColor} font-bold text-sm z-[9999] transition-all duration-300 translate-x-full opacity-0 mb-2`;
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
// ACADEMIC YEAR ARCHIVE & FILTER CONTROLS (CONNECTED TO PORTAL SETTINGS)
// -------------------------------------------------------------
window.handleYearFilterChange = function(select) {
    if (select.value === 'open_all_years_modal') {
        const modal = document.getElementById('allYearsModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => modal.classList.add('modal-active'), 10);
            window.isAdmissionModalOpen = true;
        }
        
        let lastVal = select.getAttribute('data-last-value');
        select.value = lastVal ? lastVal : '';
        return;
    }
    
    select.setAttribute('data-last-value', select.value);
    window.triggerDynamicFetch(true);
};

window.closeAllYearsModal = function() {
    const modal = document.getElementById('allYearsModal');
    if (modal) {
        modal.classList.remove('modal-active');
        setTimeout(() => { 
            modal.classList.add('hidden'); 
            modal.classList.remove('flex'); 
            window.isAdmissionModalOpen = false;
        }, 200);
    }
};

window.selectYearFilter = function(year) {
    const select = document.getElementById('f_year');
    if (select) {
        let optionExists = false;
        for (let i = 0; i < select.options.length; i++) {
            if (select.options[i].value === year) {
                optionExists = true;
                break;
            }
        }
        
        if (!optionExists && year !== 'open_all_years_modal') {
            const newOpt = document.createElement('option');
            newOpt.value = year;
            newOpt.text = year;
            const viewAllOpt = select.querySelector('option[value="open_all_years_modal"]');
            if (viewAllOpt) {
                select.insertBefore(newOpt, viewAllOpt);
            } else {
                select.appendChild(newOpt);
            }
        }
        select.value = year;
        select.setAttribute('data-last-value', year);
    }
    
    document.querySelectorAll('.year-btn').forEach(btn => {
        btn.classList.remove('border-[#00205b]', 'bg-blue-50', 'text-[#00205b]');
        btn.classList.add('border-slate-200', 'bg-white', 'text-slate-600');
    });
    
    const safeYearId = year.replace('-', '_');
    const activeBtn = document.getElementById('btn_year_' + safeYearId);
    if (activeBtn) {
        activeBtn.classList.remove('border-slate-200', 'bg-white', 'text-slate-600');
        activeBtn.classList.add('border-[#00205b]', 'bg-blue-50', 'text-[#00205b]');
    }
    
    window.closeAllYearsModal();
    window.triggerDynamicFetch(true);
};

// -------------------------------------------------------------
// DOCUMENT VIEWER ENGINE
// -------------------------------------------------------------
let currentZoom = 1;
let isDragging = false;
let startX, startY, translateX = 0, translateY = 0;

window.viewDocument = function(url) {
    window.openModal('documentModal');
    
    document.getElementById('docLoader').classList.remove('hidden');
    document.getElementById('docIframe').classList.add('hidden');
    document.getElementById('docImage').classList.add('hidden');
    document.getElementById('docError').classList.add('hidden');
    document.getElementById('docError').classList.remove('flex');
    
    const dlBtn = document.getElementById('docDownloadBtn');
    const fallbackLink = document.getElementById('docFallbackLink');
    if (dlBtn) dlBtn.href = url;
    if (fallbackLink) fallbackLink.href = url;
    
    const cleanUrl = url.split('?')[0];
    const ext = cleanUrl.split('.').pop().toLowerCase();
    
    currentZoom = 1; translateX = 0; translateY = 0;
    window.updateTransform();
    
    if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) { 
        const img = document.getElementById('docImage');
        if (img) { img.src = url; img.classList.remove('hidden'); }
    } else if (['pdf', 'txt'].includes(ext)) { 
        const iframe = document.getElementById('docIframe');
        if (iframe) { 
            iframe.src = url; 
            iframe.classList.remove('hidden'); 
            document.getElementById('docLoader').classList.add('hidden');
        }
    } else {
        document.getElementById('docLoader').classList.add('hidden');
        document.getElementById('docError').classList.remove('hidden'); 
        document.getElementById('docError').classList.add('flex');
    }
};

window.closeDocModal = function() { 
    window.closeModal('documentModal');
    setTimeout(() => { 
        const iframe = document.getElementById('docIframe');
        const img = document.getElementById('docImage');
        if (iframe) iframe.src = ''; 
        if (img) { img.src = ''; img.style.transform = 'translate(0px, 0px) scale(1)'; }
    }, 300);
};

window.zoomDoc = function(step) {
    currentZoom += step;
    if (currentZoom < 0.5) currentZoom = 0.5;
    if (currentZoom > 5) currentZoom = 5;
    window.updateTransform();
};

window.updateTransform = function() {
    const img = document.getElementById('docImage');
    if(img) img.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentZoom})`;
};

window.startDrag = function(e) {
    e.preventDefault();
    isDragging = true;
    startX = e.clientX - translateX;
    startY = e.clientY - translateY;
    const img = document.getElementById('docImage');
    if(img) { img.classList.add('cursor-grabbing'); img.classList.remove('cursor-grab'); }
};

window.addEventListener('mousemove', (e) => {
    if (!isDragging) return;
    translateX = e.clientX - startX;
    translateY = e.clientY - startY;
    window.updateTransform();
});

window.addEventListener('mouseup', () => {
    isDragging = false;
    const img = document.getElementById('docImage');
    if(img) { img.classList.remove('cursor-grabbing'); img.classList.add('cursor-grab'); }
});

// -------------------------------------------------------------
// DOCUMENT VERIFICATION ENGINE
// -------------------------------------------------------------
window.openDocReviewModal = function(admId, studentName, docsJson, savedStatusesJson) {
    const docs = JSON.parse(docsJson || '[]');
    const savedStatuses = JSON.parse(savedStatusesJson || '{}');
    
    document.getElementById('review_adm_id').value = admId;
    document.getElementById('review_student_name').innerText = studentName;
    
    let html = `
        <div class="border border-slate-200 rounded-lg overflow-hidden bg-white shadow-sm">
            <table class="w-full text-left border-collapse text-sm">
                <thead class="bg-slate-50 border-b border-slate-200 text-slate-600 text-[10px] uppercase font-bold tracking-wider">
                    <tr>
                        <th class="px-4 py-3 w-[40%]">Document Requirement</th>
                        <th class="px-4 py-3 w-[20%] text-center">Attachment</th>
                        <th class="px-4 py-3 w-[40%]">Verification Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
    `;

    docs.forEach((doc, idx) => {
        const currStatus = savedStatuses[idx] ? savedStatuses[idx].status : 'pending';
        const currReason = savedStatuses[idx] ? savedStatuses[idx].reason : '';
        
        const acceptChecked = (currStatus === 'accept') ? 'checked' : '';
        const rejectChecked = (currStatus === 'reject') ? 'checked' : '';
        const reasonHidden = (currStatus === 'reject') ? '' : 'hidden';

        html += `
        <tr class="hover:bg-slate-50/50 transition-colors">
            <td class="px-4 py-4 align-top">
                <div class="font-semibold text-slate-800 text-xs uppercase tracking-wide">${doc.label}</div>
                <span class="hidden" id="doc_label_${idx}">${doc.label}</span>
            </td>
            <td class="px-4 py-4 align-top text-center">
                <button type="button" onclick="window.viewDocument('${doc.url}')" class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 bg-blue-50 text-blue-700 hover:bg-blue-600 hover:text-white border border-blue-200 rounded text-[10px] font-bold uppercase tracking-wider transition-colors w-full sm:w-auto">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                    View
                </button>
            </td>
            <td class="px-4 py-4 align-top">
                <div class="flex items-center gap-4 mb-2">
                    <label class="flex items-center gap-1.5 text-xs font-semibold text-emerald-700 cursor-pointer hover:opacity-80 transition-opacity">
                        <input type="radio" name="doc_status_${idx}" value="accept" required ${acceptChecked} onchange="window.toggleDocReason(${idx}, false)" class="w-3.5 h-3.5 text-emerald-600 focus:ring-emerald-500 cursor-pointer">
                        Valid
                    </label>
                    <label class="flex items-center gap-1.5 text-xs font-semibold text-rose-700 cursor-pointer hover:opacity-80 transition-opacity">
                        <input type="radio" name="doc_status_${idx}" value="reject" required ${rejectChecked} onchange="window.toggleDocReason(${idx}, true)" class="w-3.5 h-3.5 text-rose-600 focus:ring-rose-500 cursor-pointer">
                        Invalid
                    </label>
                </div>
                <div id="reason_container_${idx}" class="${reasonHidden} mt-2">
                    <input type="text" id="doc_reason_${idx}" value="${currReason}" placeholder="State specific reason for rejection..." class="w-full bg-rose-50/50 border border-rose-200 rounded px-2.5 py-2 text-xs text-rose-800 focus:outline-none focus:border-rose-400 placeholder-rose-300">
                </div>
            </td>
        </tr>`;
    });
    
    html += `
                </tbody>
            </table>
        </div>
    `;
    
    document.getElementById('docReviewList').innerHTML = html;
    window.openModal('docReviewModal');
};

window.closeDocReviewModal = function() {
    window.closeModal('docReviewModal');
};

window.toggleDocReason = function(idx, showReason) {
    const container = document.getElementById('reason_container_' + idx);
    const input = document.getElementById('doc_reason_' + idx);
    if (showReason) {
        container.classList.remove('hidden');
        input.required = true;
        input.focus();
    } else {
        container.classList.add('hidden');
        input.required = false;
        input.value = '';
    }
};

window.submitDocReview = async function(e) {
    e.preventDefault();
    const admId = document.getElementById('review_adm_id').value;
    const form = document.getElementById('docReviewForm');
    const submitBtn = document.getElementById('submit_review_btn');
    
    submitBtn.innerHTML = '<svg class="w-3.5 h-3.5 animate-spin mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Saving...';
    submitBtn.disabled = true;

    let allValid = true;
    let reasons = [];
    let docStatuses = {};
    
    const docCount = form.querySelectorAll('input[type="radio"][value="accept"]').length;
    
    for (let i = 0; i < docCount; i++) {
        const statusRadio = form.elements['doc_status_' + i].value;
        const label = document.getElementById('doc_label_' + i).innerText;
        let reasonText = '';
        
        if (statusRadio === 'reject') {
            allValid = false;
            reasonText = document.getElementById('doc_reason_' + i).value.trim() || 'Invalid document';
            reasons.push(`[${label}] - ${reasonText}`);
        }
        
        docStatuses[i] = { status: statusRadio, reason: reasonText };
    }
    
    const formData = new FormData();
    formData.append('save_doc_verification', '1');
    formData.append('admission_id', admId);
    formData.append('doc_statuses', JSON.stringify(docStatuses));
    
    if (allValid) {
        formData.append('overall_status', 'Accepted');
    } else {
        formData.append('overall_status', 'Denied');
        formData.append('denial_reason', "Document Verification Failed:\n" + reasons.join('\n'));
    }
    
    try {
        const res = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await res.json();
        if(data.status === 'success') {
            window.showToast(data.message, 'success');
            window.closeDocReviewModal();
            window.triggerDynamicFetch(true); 
        }
    } catch(err) {
        window.showToast("Network Error saving document statuses.", 'error');
    } finally {
        submitBtn.innerHTML = 'Submit Verification <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
        submitBtn.disabled = false;
    }
};

// -------------------------------------------------------------
// PROVISION ACCOUNT MODAL LOGIC 
// -------------------------------------------------------------
window.openProvisionModal = async function(id, adm_no, lname, fname, mname, email, status, evaluated_year, default_prog) {
    const modal = document.getElementById('provisionModal');
    if (!modal) return;

    document.getElementById('prov_adm_id').value = id;
    document.getElementById('prov_adm_no').innerText = adm_no;
    
    // Auto-scrub numbers that might have been typed into names
    document.getElementById('prov_lname').value = (lname || '').replace(/[0-9]/g, '');
    document.getElementById('prov_fname').value = (fname || '').replace(/[0-9]/g, '');
    document.getElementById('prov_mname').value = (mname || '').replace(/[0-9]/g, '');
    document.getElementById('prov_personal_email').value = email;

    const instEmailInput = document.getElementById('prov_inst_email');
    if (instEmailInput) instEmailInput.value = '';

    const progSelect = document.getElementById('prov_program');
    if (progSelect && default_prog) {
        progSelect.selectedIndex = 0; // reset
        for (let i = 0; i < progSelect.options.length; i++) {
            if (progSelect.options[i].value === default_prog) {
                progSelect.selectedIndex = i;
                break;
            }
        }
    }

    // CLASSIFICATION MAPPING (Freshman -> Regular, Transferee -> Irregular)
    let mappedStatus = (status === 'Transferee') ? 'Irregular' : 'Regular'; 
    const classSelect = document.getElementById('prov_class');
    if (classSelect) {
        classSelect.value = mappedStatus;
    }
    
    const termSelect = document.getElementById('prov_term');
    if (termSelect && evaluated_year) {
        for (let i = 0; i < termSelect.options.length; i++) {
            if (termSelect.options[i].value.startsWith(evaluated_year)) {
                termSelect.selectedIndex = i;
                break;
            }
        }
    }

    // Default to Miscellaneous Fee automatically
    const miscFeeRadio = modal.querySelector('input[name="initial_fee"]');
    if (miscFeeRadio) {
        miscFeeRadio.checked = true;
    }

    const pinInput = document.getElementById('prov_pin');
    if (pinInput) {
        pinInput.value = '';
    }

    try {
        const res = await fetch(window.location.href.split('?')[0] + '?api_next_id=1');
        const data = await res.json();
        document.getElementById('prov_student_id').value = data.next_id;
    } catch (err) {
        const yr = new Date().getFullYear().toString().slice(-2);
        document.getElementById('prov_student_id').value = `${yr}-XXXXX`;
    }

    window.openModal('provisionModal');
};

window.closeProvisionModal = function() { 
    window.closeModal('provisionModal');
};

window.denyWithReason = function(id) {
    window.isAdmissionModalOpen = true;
    const r = prompt("Enter specific reason for manual denial:");
    if (r != null && r.trim() != "") {
        const reasonInput = document.getElementById('reasonInput_' + id);
        if (reasonInput) {
            reasonInput.value = r;
            document.getElementById('denyForm_' + id).dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        }
    } else if (r != null) {
        if (typeof window.showToast === 'function') window.showToast("A reason must be provided to deny an application.", 'error');
    }
    window.isAdmissionModalOpen = false;
};

// -------------------------------------------------------------
// STUDENT PROFILE MODAL (EXACT VERIFICATION MANAGEMENT ARCHITECTURE)
// -------------------------------------------------------------
window.openStudentModal = function(btn) {
    try {
        const rawProfile = btn.getAttribute('data-profile');
        if (!rawProfile) return;

        const data = JSON.parse(rawProfile);
        window.currentProfileData = data;

        const initialEl = document.getElementById('v_initial');
        const picEl = document.getElementById('v_profile_pic');
        
        let profilePicUrl = '';
        
        if (data.uploaded_files) {
            const trimmedFiles = data.uploaded_files.trim();
            if (trimmedFiles !== '') {
                const files = trimmedFiles.split(',');
                for (let i = 0; i < files.length; i++) {
                    if (files[i].trim() !== '') {
                        profilePicUrl = files[0].trim().replace('../', '');
                        break;
                    }
                }
            }
        }
        
        if (profilePicUrl !== '') {
            if (picEl) {
                picEl.src = '../' + profilePicUrl;
                picEl.classList.remove('hidden');
            }
            if (initialEl) initialEl.classList.add('opacity-0');
        } else {
            if (picEl) {
                picEl.classList.add('hidden');
                picEl.src = '';
            }
            if (initialEl) {
                initialEl.classList.remove('opacity-0');
                const tName = data.first_name ? data.first_name : (data.applicant_name ? data.applicant_name : '?');
                initialEl.innerText = tName.charAt(0).toUpperCase();
            }
        }

        const safeSet = (id, value) => {
            const el = document.getElementById(id);
            if (el) {
                if (value && value !== 'null' && value !== 'undefined' && String(value).trim() !== '') {
                    el.innerText = value;
                } else {
                    el.innerText = '---';
                }
            }
        };

        const fName = data.first_name ? data.first_name : '';
        const mName = data.middle_name ? ' ' + data.middle_name : '';
        const lName = data.last_name ? data.last_name + ', ' : '';
        
        let constructedName = (lName + fName + mName).trim();
        const finalName = data.applicant_name ? data.applicant_name : constructedName;

        safeSet('v_detail_name', finalName);
        
        let admNoValue = data.actual_student_id || data.admission_number || data.identifier || data.s_id || 'Pending';
        
        const idBadge = document.getElementById('v_detail_id');
        if (idBadge) {
            idBadge.innerHTML = `ID: <span class="text-[#c5a02c]">${admNoValue}</span>`;
        }

        const statusBadge = document.getElementById('v_detail_status');
        if (statusBadge) {
            let statText = data.student_type ? data.student_type : (data.student_status ? data.student_status : 'Regular');
            if (data.evaluated_admission_type) { statText = data.evaluated_admission_type; }
            statusBadge.innerText = statText;
        }

        const progLabel = document.getElementById('v_detail_placement');
        if (progLabel) {
            let pText = data.evaluated_program ? data.evaluated_program : (data.program ? data.program : 'UNASSIGNED');
            let yText = data.evaluated_year ? data.evaluated_year : (data.year_level ? data.year_level : '1st Year');
            let sText = data.evaluated_section ? data.evaluated_section : (data.assigned_section ? data.assigned_section : 'N/A');
            
            progLabel.innerText = `${pText} / ${yText} / Sec ${sText}`;
        }
        
        safeSet('v_email', data.email ? data.email : data.personal_email);
        safeSet('v_inst_email', data.institutional_email ? data.institutional_email : 'Not Provisioned');
        safeSet('v_phone', data.phone ? data.phone : data.contact_number);
        
        let genderVal = data.gender ? data.gender : (data.u_gender ? data.u_gender : 'Unspecified');
        safeSet('v_gender', genderVal);
        
        safeSet('v_dob', data.dob ? data.dob : data.date_of_birth);
        
        let pobVal = data.pob ? data.pob : (data.place_of_birth ? data.place_of_birth : 'Not Provided');
        safeSet('v_pob', pobVal);
        
        safeSet('v_address', data.address ? data.address : data.home_address);

        // ACADEMIC INTENT MAPPINGS
        let progVal = data.evaluated_program ? data.evaluated_program : (data.program ? data.program : 'Not Provided');
        safeSet('v_program', progVal);

        let typeVal = data.evaluated_admission_type ? data.evaluated_admission_type : (data.student_type ? data.student_type : 'New');
        safeSet('v_type', typeVal);

        let ylVal = data.evaluated_year ? data.evaluated_year : (data.year_level ? data.year_level : '1st Year');
        safeSet('v_year_level', ylVal);

        let schoolVal = data.school_last_attended ? data.school_last_attended : (data.last_school ? data.last_school : 'Not Provided');
        safeSet('v_school', schoolVal);
        
        let syVal = data.school_year_attended ? data.school_year_attended : (data.year_graduated ? data.year_graduated : 'Not Provided');
        safeSet('v_school_year', syVal);
        
        let infVal = data.influence_source ? data.influence_source : (data.source_of_info ? data.source_of_info : 'Not Provided');
        safeSet('v_influence', infVal);

        // FAMILY & EMERGENCY
        safeSet('v_father_name', data.father_name ? data.father_name : 'Not Provided');
        safeSet('v_father_occ', data.father_occupation ? data.father_occupation : 'Not Provided');
        safeSet('v_father_con', data.father_contact ? data.father_contact : 'Not Provided');
        
        safeSet('v_mother_name', data.mother_name ? data.mother_name : 'Not Provided');
        safeSet('v_mother_occ', data.mother_occupation ? data.mother_occupation : 'Not Provided');
        safeSet('v_mother_con', data.mother_contact ? data.mother_contact : 'Not Provided');
        
        let emName = data.emergency_contact_name ? data.emergency_contact_name : (data.emergency_name ? data.emergency_name : 'Not Provided');
        safeSet('v_em_name', emName);
        
        let emCon = data.emergency_contact_number ? data.emergency_contact_number : (data.emergency_number ? data.emergency_number : 'Not Provided');
        safeSet('v_em_con', emCon);

        // DYNAMIC DOCUMENTS RENDERING (VIEWABLE FILES & IMAGES)
        const docList = document.getElementById('v_documents_list');
        if (docList) {
            docList.innerHTML = '';
            
            if (data.uploaded_files) {
                let validFiles = [];
                const rawFiles = data.uploaded_files.split(',');
                for (let i = 0; i < rawFiles.length; i++) {
                    if (rawFiles[i].trim() !== '') {
                        validFiles.push(rawFiles[i].trim());
                    }
                }
                
                let studentType = data.student_type ? data.student_type : '';
                let labels = ['2x2 ID Photo', 'PSA Birth Certificate'];
                if (!studentType.includes('Freshman') && !studentType.includes('Transferee') && !studentType.includes('New')) {
                    labels = ['Clearance Document', 'Supporting Document'];
                }
                
                if (validFiles.length === 0) {
                    docList.innerHTML = '<span class="text-[9px] text-slate-400 italic">No documents uploaded.</span>';
                } else {
                    validFiles.forEach((file, i) => {
                        const cleanFile = file.replace('../', '').trim();
                        const fileUrl = '../' + cleanFile;
                        const label = labels[i] ? labels[i] : `Document ${i + 1}`;
                        
                        const btnHtml = `
                            <button type="button" onclick="window.viewDocument('${fileUrl}')" class="flex items-center gap-2 px-3 py-1.5 bg-slate-50 border border-slate-200 rounded hover:border-[#00205b] hover:bg-blue-50 transition-colors shadow-sm group cursor-pointer">
                                <div class="p-1 bg-[#00205b]/10 rounded text-[#00205b] group-hover:bg-[#00205b] group-hover:text-white transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </div>
                                <div class="text-left">
                                    <span class="block text-[8px] font-black text-[#00205b] uppercase tracking-widest">${label}</span>
                                    <span class="block text-[7px] font-bold text-slate-400 uppercase tracking-widest group-hover:text-[#00205b]">View File</span>
                                </div>
                            </button>
                        `;
                        docList.innerHTML += btnHtml;
                    });
                }
            } else {
                docList.innerHTML = '<span class="text-[9px] text-slate-400 italic">No documents uploaded.</span>';
            }
        }

        window.openModal('studentProfileModal');
    } catch(e) {
        console.error("Profile Modal Error:", e);
        if (window.showToast) window.showToast("Failed to parse profile data.", "error");
    }
};

// Alias to ensure backward compatibility
window.openProfileModal = window.openStudentModal;

window.closeStudentModal = function() {
    window.closeModal('studentProfileModal');
};
window.closeProfileModal = window.closeStudentModal;

// -------------------------------------------------------------
// SPA FORM ENGINE (SILENT SUBMISSIONS)
// -------------------------------------------------------------
window.attachSpaListeners = function() {
    document.querySelectorAll('.spa-form').forEach(form => {
        if (!form.hasAttribute('data-spa-attached') && !form.hasAttribute('onsubmit')) {
            form.addEventListener('submit', function(e) {
                if (e.defaultPrevented) return;
                window.handleFormSubmit(e, this);
            });
            form.setAttribute('data-spa-attached', 'true');
        }
    });
};

window.handleFormSubmit = async function(e, form) {
    e.preventDefault();
    const submitBtn = Array.from(form.elements).find(el => el.type === 'submit' && el.matches(':focus')) || form.querySelector('button[type="submit"]');
    const originalBtnHTML = submitBtn ? submitBtn.innerHTML : 'Processing...';
    
    if (submitBtn) {
        submitBtn.innerHTML = '<svg class="w-3 h-3 animate-spin inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Wait...';
        submitBtn.disabled = true;
    }
    
    const formData = new FormData(form);
    formData.append('ajax_post', '1');
    if (submitBtn && submitBtn.name) formData.append(submitBtn.name, '1');
    
    try {
        const res = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.status === 'success') {
            if(window.showToast) window.showToast(data.message, 'success');
            if (typeof window.closeProvisionModal === 'function') window.closeProvisionModal();
            
            // INSTANT BACKGROUND REFRESH INSTEAD OF RELOAD
            window.triggerDynamicFetch(true);
        } else {
            if(window.showToast) window.showToast(data.message, 'error');
        }
    } catch (error) {
        if(window.showToast) window.showToast("Network Error.", 'error');
    } finally {
        if (submitBtn) { 
            submitBtn.innerHTML = originalBtnHTML; 
            submitBtn.disabled = false; 
        }
    }
};

// -------------------------------------------------------------
// LIVE BACKGROUND REFRESH ENGINE (WITH YEAR CONTEXT)
// -------------------------------------------------------------
let lastQueueDataString = '';

window.triggerDynamicFetch = async function(force = false) {
    if (!force && window.isAdmissionModalOpen) return;

    const f_year_el = document.getElementById('f_year');
    const f_year = f_year_el ? f_year_el.value : '';

    const params = new URLSearchParams({
        api_refresh: 1,
        f_year: f_year
    });

    const cleanUrl = window.location.pathname + '?' + params.toString().replace('api_refresh=1&', '').replace('&api_refresh=1', '');
    window.history.replaceState({}, '', cleanUrl);

    try {
        const response = await fetch(`${window.location.pathname}?${params.toString()}`, { 
            headers: { 'X-Requested-With': 'XMLHttpRequest' } 
        });
        
        if (!response.ok) return;
        const data = await response.json();
        
        const newDataString = JSON.stringify(data.updates);
        if (!force && newDataString === lastQueueDataString) return; 
        lastQueueDataString = newDataString;

        if (data.updates) {
            for (const [id, html] of Object.entries(data.updates)) {
                const el = document.getElementById(id);
                if (el) el.innerHTML = html;
            }

            // Sync total count badge
            if (data.counts && data.counts.Applications !== undefined) {
                const countBadge = document.getElementById('count-Applications');
                if (countBadge) countBadge.innerText = data.counts.Applications;
            }

            // Re-attach listeners to newly injected SPA forms
            window.attachSpaListeners();

            // Re-apply live search filter if user was currently typing
            const activeSearch = document.querySelector('input[id="liveSearch1"]');
            if (activeSearch && activeSearch.value.trim() !== '') {
                if (typeof window.filterTable === 'function') {
                    window.filterTable(activeSearch);
                }
            }
        }
    } catch (err) {
        console.error("Auto-sync error:", err);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    // Attach listeners on first load
    window.attachSpaListeners();

    const docContainer = document.getElementById('doc-container');
    if (docContainer) {
        docContainer.addEventListener('wheel', (e) => {
            const img = document.getElementById('docImage');
            if (img && !img.classList.contains('hidden')) {
                e.preventDefault();
                window.zoomDoc(e.deltaY > 0 ? -0.1 : 0.1);
            }
        }, { passive: false });
    }

    // Ping server every 5 seconds for live updates
    setInterval(() => window.triggerDynamicFetch(), 5000);
});