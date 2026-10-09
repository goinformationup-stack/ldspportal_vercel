// =========================================================
// VERIFICATION QUEUE LOGIC (SMART ROUTING & SHIFTING ARCHITECTURE)
// =========================================================

let lastDataString = null; 
window.isRegistrarModalOpen = false;
let isFormDirty = false;
window.isFullCurriculum = false;

document.addEventListener('focusin', (e) => {
    const t = e.target.tagName;
    const ty = e.target.type;
    if (t === 'INPUT') { isFormDirty = true; }
    else if (t === 'TEXTAREA') { isFormDirty = true; }
    else if (t === 'SELECT') { isFormDirty = true; }
    else if (ty === 'checkbox') { isFormDirty = true; }
    else if (ty === 'radio') { isFormDirty = true; }
});

document.addEventListener('focusout', (e) => {
    const t = e.target.tagName;
    const ty = e.target.type;
    
    let isTracked = false;
    if (t === 'INPUT') { isTracked = true; }
    else if (t === 'TEXTAREA') { isTracked = true; }
    else if (t === 'SELECT') { isTracked = true; }
    else if (ty === 'checkbox') { isTracked = true; }
    else if (ty === 'radio') { isTracked = true; }
    
    if (isTracked) {
        setTimeout(() => { 
            const activeElement = document.activeElement;
            if (!activeElement) {
                isFormDirty = false;
                return;
            }
            const activeType = activeElement.type;
            const activeTag = activeElement.tagName;
            
            let isActiveTracked = false;
            if (activeTag === 'INPUT') { isActiveTracked = true; }
            else if (activeTag === 'TEXTAREA') { isActiveTracked = true; }
            else if (activeTag === 'SELECT') { isActiveTracked = true; }
            else if (activeType === 'checkbox') { isActiveTracked = true; }
            else if (activeType === 'radio') { isActiveTracked = true; }
            
            if (!isActiveTracked) {
                isFormDirty = false; 
            }
        }, 150);
    }
});

// -------------------------------------------------------------
// INLINE EDITING: HISTORICAL GRADES
// -------------------------------------------------------------
window.updateHistoricalGrade = async function(inputEl, source, recordId) {
    isFormDirty = true;
    inputEl.classList.add('opacity-50', 'pointer-events-none');
    
    const formData = new FormData();
    formData.append('ajax_post', '1');
    formData.append('update_historical_grade', '1');
    formData.append('source', source);
    formData.append('record_id', recordId);
    formData.append('grade', inputEl.value);

    try {
        const res = await fetch(window.location.href.split('?')[0], { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        if (data.status === 'success') {
            if(window.showToast) window.showToast(data.message, 'success');
            
            // Soft-refresh the table so the progression analyzer re-runs with the newly updated grade
            const btn = document.querySelector('button[onclick="window.openEvalModal(this)"][data-eval*="'+ document.getElementById('eval_id').value +'"]');
            if (btn) {
                // If it's open, reload the academic history to see if the status changes
                if (document.getElementById('evaluationModal').classList.contains('modal-active')) {
                     const uId = document.getElementById('eval_user_internal_id').value;
                     const studentId = document.getElementById('eval_student_identifier').value;
                     const fetchUrl = `verification_management.php?api_student_history=1&student_id=${encodeURIComponent(studentId)}&user_id=${encodeURIComponent(uId)}`;
                     
                     fetch(fetchUrl)
                        .then(r => r.json())
                        .then(resData => {
                            const historyTbody = document.getElementById('eval_history_tbody');
                            if(historyTbody && resData.html) historyTbody.innerHTML = resData.html;
                            
                            const progressBanner = document.getElementById('eval_progress_banner');
                            if(progressBanner) progressBanner.innerHTML = resData.progress_banner || '';
                            
                            const hasShiftRequest = document.getElementById('eval_shift_alert') && !document.getElementById('eval_shift_alert').classList.contains('hidden');
                            
                            let isTransferee = false;
                            const admCb = document.querySelector('input[name="sub_admission_type[]"][value="Transferee"]');
                            if (admCb && admCb.checked) isTransferee = true;

                            let isOldStudent = false;
                            const oldCb = document.querySelector('input[name="base_admission_type"][value="Old"]');
                            if (oldCb && oldCb.checked) isOldStudent = true;

                            // NEW: Check for Returnee
                            let isReturnee = false;
                            const retCb = document.querySelector('input[name="sub_admission_type[]"][value="Returnee"]');
                            if (retCb && retCb.checked) isReturnee = true;

                            if (hasShiftRequest) {
                                const shifterRadio = document.getElementById('mode_shifter');
                                if (shifterRadio && !shifterRadio.checked) { shifterRadio.checked = true; window.triggerCurriculumFetch(); }
                            } else if (resData.has_failing || isReturnee) { // Auto-assign to Manual Irregular
                                const manualIrregRadio = document.getElementById('mode_manual_irregular');
                                if (manualIrregRadio && !manualIrregRadio.checked) { manualIrregRadio.checked = true; window.triggerCurriculumFetch(); }
                            } else if (resData.has_missing || isTransferee || (isOldStudent && resData.count === 0)) {
                                const manualRegRadio = document.getElementById('mode_manual_regular');
                                if (manualRegRadio && !manualRegRadio.checked) { manualRegRadio.checked = true; window.triggerCurriculumFetch(); }
                            } else {
                                const autoRegRadio = document.getElementById('mode_regular');
                                if (autoRegRadio && !autoRegRadio.checked) { autoRegRadio.checked = true; window.triggerCurriculumFetch(); }
                            }
                        });
                }
            } else {
                inputEl.classList.add('border-emerald-500', 'text-emerald-700', 'bg-emerald-50');
                setTimeout(() => inputEl.classList.remove('border-emerald-500', 'text-emerald-700', 'bg-emerald-50'), 2000);
            }
        } else {
            if(window.showToast) window.showToast(data.message, 'error');
        }
    } catch (err) {
        if(window.showToast) window.showToast('Network error updating grade.', 'error');
    } finally {
        inputEl.classList.remove('opacity-50', 'pointer-events-none');
        isFormDirty = false;
    }
};

window.deleteHistoricalSubject = async function(btnEl, source, recordId) {
    if(!confirm("Are you sure you want to completely remove this subject from the student's records?")) return;
    
    isFormDirty = true;
    const tr = btnEl.closest('tr');
    tr.classList.add('opacity-50', 'pointer-events-none');
    
    const formData = new FormData();
    formData.append('ajax_post', '1');
    formData.append('delete_historical_subject', '1');
    formData.append('source', source);
    formData.append('record_id', recordId);

    try {
        const res = await fetch(window.location.href.split('?')[0], { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        if (data.status === 'success') {
            if(window.showToast) window.showToast(data.message, 'success');
            tr.remove();
            
            // Soft-refresh the table so the progression analyzer re-runs with the newly updated grade
            const btn = document.querySelector('button[onclick="window.openEvalModal(this)"][data-eval*="'+ document.getElementById('eval_id').value +'"]');
            if (btn) {
                if (document.getElementById('evaluationModal').classList.contains('modal-active')) {
                     const uId = document.getElementById('eval_user_internal_id').value;
                     const studentId = document.getElementById('eval_student_identifier').value;
                     const fetchUrl = `verification_management.php?api_student_history=1&student_id=${encodeURIComponent(studentId)}&user_id=${encodeURIComponent(uId)}`;
                     
                     fetch(fetchUrl)
                        .then(r => r.json())
                        .then(resData => {
                            const historyTbody = document.getElementById('eval_history_tbody');
                            if(historyTbody && resData.html) historyTbody.innerHTML = resData.html;
                            
                            const progressBanner = document.getElementById('eval_progress_banner');
                            if(progressBanner) progressBanner.innerHTML = resData.progress_banner || '';

                            const hasShiftRequest = document.getElementById('eval_shift_alert') && !document.getElementById('eval_shift_alert').classList.contains('hidden');
                            
                            let isTransferee = false;
                            const admCb = document.querySelector('input[name="sub_admission_type[]"][value="Transferee"]');
                            if (admCb && admCb.checked) isTransferee = true;

                            let isOldStudent = false;
                            const oldCb = document.querySelector('input[name="base_admission_type"][value="Old"]');
                            if (oldCb && oldCb.checked) isOldStudent = true;

                            // NEW: Check for Returnee
                            let isReturnee = false;
                            const retCb = document.querySelector('input[name="sub_admission_type[]"][value="Returnee"]');
                            if (retCb && retCb.checked) isReturnee = true;

                            if (hasShiftRequest) {
                                const shifterRadio = document.getElementById('mode_shifter');
                                if (shifterRadio && !shifterRadio.checked) { shifterRadio.checked = true; window.triggerCurriculumFetch(); }
                            } else if (resData.has_failing || isReturnee) { // Auto-assign to Manual Irregular
                                const manualIrregRadio = document.getElementById('mode_manual_irregular');
                                if (manualIrregRadio && !manualIrregRadio.checked) { manualIrregRadio.checked = true; window.triggerCurriculumFetch(); }
                            } else if (resData.has_missing || isTransferee || (isOldStudent && resData.count === 0)) {
                                const manualRegRadio = document.getElementById('mode_manual_regular');
                                if (manualRegRadio && !manualRegRadio.checked) { manualRegRadio.checked = true; window.triggerCurriculumFetch(); }
                            } else {
                                const autoRegRadio = document.getElementById('mode_regular');
                                if (autoRegRadio && !autoRegRadio.checked) { autoRegRadio.checked = true; window.triggerCurriculumFetch(); }
                            }
                        });
                }
            }
        } else {
            if(window.showToast) window.showToast(data.message, 'error');
            tr.classList.remove('opacity-50', 'pointer-events-none');
        }
    } catch (err) {
        if(window.showToast) window.showToast('Network error removing subject.', 'error');
        tr.classList.remove('opacity-50', 'pointer-events-none');
    } finally {
        isFormDirty = false;
    }
};

// -------------------------------------------------------------
// FILTER CONTROLS
// -------------------------------------------------------------
window.resetFilters = function() {
    const filterProg = document.getElementById('filter_program');
    const filterYear = document.getElementById('filter_year');
    const toggleShift = document.getElementById('toggleShiftOnly');
    
    if (filterProg) filterProg.value = 'All';
    if (filterYear) {
        filterYear.value = 'All';
        filterYear.setAttribute('data-last-value', 'All');
    }
    
    if (toggleShift) {
        toggleShift.checked = false;
        if (typeof window.filterTable === 'function') window.filterTable();
    }
    
    document.querySelectorAll('.year-btn').forEach(btn => {
        btn.classList.remove('border-[#00205b]', 'bg-blue-50', 'text-[#00205b]');
        btn.classList.add('border-slate-200', 'bg-white', 'text-slate-600');
    });
    const activeBtn = document.getElementById('btn_year_All');
    if (activeBtn) {
        activeBtn.classList.remove('border-slate-200', 'bg-white', 'text-slate-600');
        activeBtn.classList.add('border-[#00205b]', 'bg-blue-50', 'text-[#00205b]');
    }
    
    window.triggerDynamicFetch(true);
};

window.handleYearFilterChange = function(select) {
    if (select.value === 'open_all_years_modal') {
        const modal = document.getElementById('allYearsModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => modal.classList.add('modal-active'), 10);
            window.isRegistrarModalOpen = true;
        }
        
        let lastVal = select.getAttribute('data-last-value');
        if (lastVal) { select.value = lastVal; } else { select.value = 'All'; }
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
            window.isRegistrarModalOpen = false;
        }, 200);
    }
};

window.selectYearFilter = function(year) {
    const select = document.getElementById('filter_year');
    if (select) {
        let optionExists = false;
        for (let i = 0; i < select.options.length; i++) {
            if (select.options[i].value === year) {
                optionExists = true;
                break;
            }
        }
        
        if (!optionExists) {
            if (year !== 'open_all_years_modal') {
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
// GLOBAL TOAST NOTIFICATIONS
// -------------------------------------------------------------
window.showToast = function(message, type) {
    let container = document.getElementById('toastContainer');
    if (!container) return;

    const toast = document.createElement('div');
    const safeType = type ? type : 'success';
    const isSuccess = safeType === 'success';
    
    let bgColor = 'bg-rose-50 border-rose-200 text-rose-800';
    let iconPath = 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
    let iconColor = 'text-rose-500';
    
    if (isSuccess) {
        bgColor = 'bg-emerald-50 border-emerald-200 text-emerald-800';
        iconPath = 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z';
        iconColor = 'text-emerald-500';
    }

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
    window.isRegistrarModalOpen = true;
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
        window.isRegistrarModalOpen = false;
    }, 300); 
};

// -------------------------------------------------------------
// DOCUMENT VIEWER ENGINE
// -------------------------------------------------------------
window.viewDocument = function(url) {
    const modal = document.getElementById('doc-modal');
    const img = document.getElementById('docImage');
    const iframe = document.getElementById('docIframe');
    const loader = document.getElementById('docLoader');
    const err = document.getElementById('docError');
    const dlBtn = document.getElementById('docDownloadBtn');
    const fallback = document.getElementById('docFallbackLink');

    if(img) img.classList.add('hidden');
    if(iframe) iframe.classList.add('hidden');
    if(err) err.classList.add('hidden');
    if(loader) loader.classList.remove('hidden');

    if(dlBtn) dlBtn.href = url;
    if(fallback) fallback.href = url;

    const cleanUrl = url.split('?')[0];
    const ext = cleanUrl.split('.').pop().toLowerCase();
    
    let isImage = false;
    if (ext === 'jpg' || ext === 'jpeg' || ext === 'png' || ext === 'gif' || ext === 'webp') {
        isImage = true;
    }

    if (isImage) {
        if(img) {
            img.src = url;
            img.classList.remove('hidden');
        }
    } else if (ext === 'pdf') {
        if(iframe) {
            iframe.src = url;
            iframe.classList.remove('hidden');
        }
    } else {
        if(loader) loader.classList.add('hidden');
        if(err) {
            err.classList.remove('hidden');
            err.classList.add('flex');
        }
    }

    if(modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        window.isRegistrarModalOpen = true;
    }
};

window.closeDocModal = function() { 
    const modal = document.getElementById('doc-modal');
    if(modal) {
        modal.classList.add('hidden'); 
        modal.classList.remove('flex'); 
        window.isRegistrarModalOpen = false; 
        
        const iframe = document.getElementById('docIframe');
        const img = document.getElementById('docImage');
        if (iframe) iframe.src = ''; 
        if (img) img.src = ''; 
    }
};

// -------------------------------------------------------------
// STUDENT PROFILE MODAL
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
            if(picEl) {
                picEl.src = '../' + profilePicUrl;
                picEl.classList.remove('hidden');
            }
            if(initialEl) initialEl.classList.add('opacity-0');
        } else {
            if(picEl) {
                picEl.classList.add('hidden');
                picEl.src = '';
            }
            if(initialEl) {
                initialEl.classList.remove('opacity-0');
                const tName = data.first_name ? data.first_name : '?';
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
        
        let admNoValue = data.s_id ? data.s_id : 'Pending';
        if (data.identifier) { admNoValue = data.identifier; }
        if (data.admission_number) { admNoValue = data.admission_number; }
        if (data.actual_student_id) { admNoValue = data.actual_student_id; }
        
        const idBadge = document.getElementById('v_detail_id');
        if (idBadge) {
            idBadge.innerHTML = `ID: <span class="text-[#c5a02c]">${admNoValue}</span>`;
        }

        const statusBadge = document.getElementById('v_detail_status');
        if (statusBadge) {
            let statText = data.student_status ? data.student_status : 'Regular';
            if (data.final_student_status) { statText = data.final_student_status; }
            statusBadge.innerText = statText;
        }

        const progLabel = document.getElementById('v_detail_placement');
        if(progLabel) {
            let pText = data.evaluated_program ? data.evaluated_program : (data.program ? data.program : 'UNASSIGNED');
            let yText = data.evaluated_year ? data.evaluated_year : (data.year_level ? data.year_level : 'N/A');
            let sText = data.assigned_section ? data.assigned_section : 'N/A';
            if (data.evaluated_section) { sText = data.evaluated_section; }
            
            progLabel.innerText = `${pText} / ${yText} / Sec ${sText}`;
        }
        
        safeSet('v_email', data.personal_email ? data.personal_email : data.email);
        safeSet('v_inst_email', data.institutional_email);
        safeSet('v_phone', data.phone ? data.phone : data.contact_number);
        
        let genderVal = data.gender ? data.gender : 'Unspecified';
        if (data.u_gender) { genderVal = data.u_gender; }
        if (data.sex) { genderVal = data.sex; }
        safeSet('v_gender', genderVal);
        
        safeSet('v_dob', data.dob ? data.dob : data.date_of_birth);
        
        let pobVal = 'Not Provided';
        if (data.pob) { pobVal = data.pob; }
        if (data.place_of_birth) { pobVal = data.place_of_birth; }
        safeSet('v_pob', pobVal);
        
        safeSet('v_address', data.address ? data.address : data.home_address);

        let progVal = data.evaluated_program ? data.evaluated_program : (data.program ? data.program : 'Not Provided');
        safeSet('v_program', progVal);

        let typeVal = data.admission_type ? data.admission_type : (data.student_type ? data.student_type : 'New');
        safeSet('v_type', typeVal);

        let ylVal = data.evaluated_year ? data.evaluated_year : (data.year_level ? data.year_level : 'Not Provided');
        safeSet('v_year_level', ylVal);

        let schoolVal = data.school_last_attended ? data.school_last_attended : 'Not Provided';
        if (data.last_school) { schoolVal = data.last_school; }
        if (data.a_school) { schoolVal = data.a_school; }
        safeSet('v_school', schoolVal);
        
        let syVal = data.school_year_attended ? data.school_year_attended : 'Not Provided';
        if (data.year_graduated) { syVal = data.year_graduated; }
        if (data.a_school_year) { syVal = data.a_school_year; }
        safeSet('v_school_year', syVal);
        
        let modVal = data.learning_mode ? data.learning_mode : 'Face-to-Face';
        if (data.evaluated_modality) { modVal = data.evaluated_modality; }
        safeSet('v_modality', modVal);
        
        let termVal = data.semester ? data.semester : 'N/A';
        if (data.enrollment_term) { termVal = data.enrollment_term; }
        safeSet('v_semester', termVal);

        let infVal = 'Not Provided';
        if (data.influence_source) { infVal = data.influence_source; }
        if (data.source_of_info) { infVal = data.source_of_info; }
        safeSet('v_influence', infVal);

        safeSet('v_father_name', data.father_name ? data.father_name : 'Not Provided');
        safeSet('v_father_occ', data.father_occupation ? data.father_occupation : 'Not Provided');
        safeSet('v_father_con', data.father_contact ? data.father_contact : 'Not Provided');
        
        safeSet('v_mother_name', data.mother_name ? data.mother_name : 'Not Provided');
        safeSet('v_mother_occ', data.mother_occupation ? data.mother_occupation : 'Not Provided');
        safeSet('v_mother_con', data.mother_contact ? data.mother_contact : 'Not Provided');
        
        let emName = 'Not Provided';
        if (data.emergency_contact_name) { emName = data.emergency_contact_name; }
        if (data.emergency_name) { emName = data.emergency_name; }
        safeSet('v_em_name', emName);
        
        let emCon = 'Not Provided';
        if (data.emergency_contact_number) { emCon = data.emergency_contact_number; }
        if (data.emergency_number) { emCon = data.emergency_number; }
        safeSet('v_em_con', emCon);

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
                
                let studentType = data.admission_type || data.student_type || '';
                let labels = ['2x2 Picture'];
                if (studentType.includes('Freshman') || studentType.includes('Transferee') || studentType.includes('New')) { 
                    labels = ['2x2 ID Photo', 'PSA Birth Certificate']; 
                }
                
                if (validFiles.length === 0) {
                    docList.innerHTML = '<span class="text-[9px] text-slate-400 italic">No documents uploaded.</span>';
                } else {
                    validFiles.forEach((file, i) => {
                        const cleanFile = file.replace('../', '');
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
    }
};

window.closeStudentModal = function() {
    const modal = document.getElementById('studentProfileModal');
    if(modal) {
        modal.classList.remove('modal-active');
        setTimeout(() => { 
            modal.classList.add('hidden'); 
            modal.classList.remove('flex'); 
        }, 200);
        window.isRegistrarModalOpen = false;
    }
};

window.openShiftModalFromProfile = function() {
    if(!window.currentProfileData) return;
    const data = window.currentProfileData;
    
    window.closeStudentModal();
    
    const id = data.identifier || data.admission_number || data.actual_student_id;
    const prog = data.evaluated_program || data.program;
    const targetProg = (data.shifting_to && data.shifting_to.trim() !== '') ? data.shifting_to : prog;
    const name = data.applicant_name || data.first_name + " " + data.last_name;
    const yl = data.evaluated_year || data.year_level || '1st Year';
    
    setTimeout(() => {
        window.openShiftModal(id, prog, targetProg, name, yl);
    }, 250);
};

// -------------------------------------------------------------
// EVALUATION MODAL CONTROLS & DYNAMIC CURRICULUM/HISTORY FETCH
// -------------------------------------------------------------
window.toggleFullCurriculum = function() {
    const overlay = document.getElementById('full_curr_overlay');
    if(overlay) {
        overlay.classList.remove('hidden');
        overlay.classList.add('flex');
        setTimeout(() => {
            overlay.classList.remove('opacity-0');
            overlay.classList.add('opacity-100');
        }, 10);
    }
};

window.openEvalModal = function(btn) {
    window.isRegistrarModalOpen = true;
    window.isFullCurriculum = false; 
    
    const rawData = btn.getAttribute('data-eval');
    if (!rawData) return;
    const data = JSON.parse(rawData);

    const profileBtn = btn.closest('.flex-col').querySelector('button[data-profile]');
    let profilePicUrl = '';
    
    if (profileBtn) {
        const rawProfile = profileBtn.getAttribute('data-profile');
        if (rawProfile) {
            const profileData = JSON.parse(rawProfile);
            if (profileData.uploaded_files) {
                const trimmedFiles = profileData.uploaded_files.trim();
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
        }
    }

    document.getElementById('eval_id').value = data.id;
    document.getElementById('eval_type').value = data.type;
    document.getElementById('eval_name').innerText = data.name;
    document.getElementById('eval_identifier').innerText = data.identifier;
    
    const initialEl = document.getElementById('eval_initial');
    const picEl = document.getElementById('eval_profile_pic');
    
    if (profilePicUrl !== '') {
        if(picEl) {
            picEl.src = '../' + profilePicUrl;
            picEl.classList.remove('hidden');
        }
        if(initialEl) initialEl.classList.add('opacity-0');
    } else {
        if(picEl) {
            picEl.classList.add('hidden');
            picEl.src = '';
        }
        if(initialEl) {
            initialEl.classList.remove('opacity-0');
            initialEl.innerText = data.name.charAt(0).toUpperCase();
        }
    }

    if(document.getElementById('eval_student_identifier')) {
        document.getElementById('eval_student_identifier').value = data.identifier;
    }
    if (document.getElementById('eval_user_internal_id')) {
        document.getElementById('eval_user_internal_id').value = data.userId || 0;
    }
    if (document.getElementById('eval_current_program_hidden')) {
        document.getElementById('eval_current_program_hidden').value = data.current_program || '';
    }

    const standingEl = document.getElementById('eval_standing_text');
    const targetEl = document.getElementById('eval_target_text');
    if (standingEl) standingEl.innerText = data.current_standing || 'Incoming New';
    if (targetEl) targetEl.innerText = data.recommended_term || `${data.year} - Active Sem`;

    const shiftAlert = document.getElementById('eval_shift_alert');
    const shiftFrom = document.getElementById('eval_shift_from');
    const shiftTo = document.getElementById('eval_shift_to');
    const hasShiftRequest = (data.shifting_to && data.shifting_to.trim() !== '' && data.shifting_to !== data.current_program);

    if (hasShiftRequest) {
        if (shiftAlert) {
            shiftFrom.innerText = data.current_program;
            shiftTo.innerText = data.shifting_to;
            shiftAlert.classList.remove('hidden');
            shiftAlert.classList.add('flex');
        }
    } else {
        if (shiftAlert) {
            shiftAlert.classList.add('hidden');
            shiftAlert.classList.remove('flex');
        }
    }

    const activeAdmType = data.admType ? data.admType : 'New';
    let admTypes = [];
    const splitTypes = activeAdmType.split(',');
    for (let i = 0; i < splitTypes.length; i++) {
        admTypes.push(splitTypes[i].trim());
    }
    
    const baseRadios = document.querySelectorAll('input[name="base_admission_type"]');
    if (baseRadios.length > 0) {
        let isOld = (admTypes.includes('Old')) || (data.current_standing && !data.current_standing.includes('Incoming'));
        baseRadios.forEach(r => {
            if (r.value === 'Old') { r.checked = isOld; }
            if (r.value === 'New') { r.checked = !isOld; }
        });
    }

    const subCheckboxes = document.querySelectorAll('input[name="sub_admission_type[]"]');
    if (subCheckboxes.length > 0) {
        subCheckboxes.forEach(cb => {
            let isChecked = admTypes.includes(cb.value);
            if (cb.value === 'Change Course' && hasShiftRequest) isChecked = true;
            cb.checked = isChecked;
        });
    }

    const modSelect = document.getElementById('eval_modality');
    if (modSelect) {
        let found = false;
        const targetModality = data.modality ? data.modality : '';
        for (let i = 0; i < modSelect.options.length; i++) {
            if (modSelect.options[i].value.toUpperCase() === targetModality.toUpperCase()) { 
                modSelect.selectedIndex = i; 
                found = true; 
                break; 
            }
        }
        if(!found) { modSelect.value = 'HYBRID'; }
    }

    const progSelect = document.getElementById('eval_program');
    const targetProgramToSelect = hasShiftRequest ? data.shifting_to : data.current_program;
    if (progSelect) {
        for (let i = 0; i < progSelect.options.length; i++) {
            if (progSelect.options[i].value === targetProgramToSelect) {
                progSelect.selectedIndex = i; break;
            }
        }
    }
    
    const yearSelect = document.getElementById('eval_year');
    const targetYearToSelect = data.recommended_year ? data.recommended_year : data.year;
    if (yearSelect && targetYearToSelect) {
        for (let i = 0; i < yearSelect.options.length; i++) {
            if (yearSelect.options[i].value === targetYearToSelect) {
                yearSelect.selectedIndex = i; break;
            }
        }
    }

    document.querySelectorAll('input[name="student_status"]').forEach(r => r.checked = false);
    if (hasShiftRequest) {
        const shifterRadio = document.querySelector('input[name="student_status"][value="Shifter"]');
        if (shifterRadio) shifterRadio.checked = true;
    } else {
        let statusToSelect = data.finalStudentStatus || 'Regular';
        if (statusToSelect === 'Irregular') statusToSelect = 'Manual_Irregular';
        
        let statusRadio = document.querySelector(`input[name="student_status"][value="${statusToSelect}"]`);
        if (!statusRadio) statusRadio = document.querySelector('input[name="student_status"][value="Regular"]');
        if (statusRadio) statusRadio.checked = true;
    }

    const btnText = document.getElementById('btnFinalizeEvalText');
    if (data.type === 'online') {
        if (btnText) btnText.innerHTML = 'Enroll to Master List';
    } else {
        if (btnText) btnText.innerHTML = 'Execute Evaluation';
    }

    const historyWrapper = document.getElementById('eval_history_wrapper');
    const historyTbody = document.getElementById('eval_history_tbody');
    const progressBanner = document.getElementById('eval_progress_banner');
    const vaultWrapper = document.getElementById('eval_cor_vault_wrapper');
    const vaultContainer = document.getElementById('eval_collected_cors');
    const lockoutBanner = document.getElementById('eval_lockout_banner');
    const finalizeBtn = document.getElementById('btnFinalizeEval');
    
    if (historyWrapper) {
        if (progressBanner) { progressBanner.innerHTML = ''; }
        if (vaultWrapper) { vaultWrapper.classList.add('hidden'); vaultContainer.innerHTML = ''; }
        if (lockoutBanner) { lockoutBanner.classList.add('hidden'); lockoutBanner.classList.remove('flex'); }
        if (finalizeBtn) { finalizeBtn.disabled = false; finalizeBtn.classList.remove('opacity-50', 'cursor-not-allowed'); }

        if (historyTbody) {
            historyWrapper.classList.add('hidden');
            historyTbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-[9px] text-slate-400 font-bold uppercase tracking-widest"><svg class="w-4 h-4 animate-spin mx-auto mb-1 text-indigo-900" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Loading Academic History...</td></tr>';
            
            const uId = data.userId ? data.userId : 0;
            const fetchUrl = `verification_management.php?api_student_history=1&student_id=${encodeURIComponent(data.identifier)}&user_id=${encodeURIComponent(uId)}`;
            
            fetch(fetchUrl)
                .then(res => res.json())
                .then(resData => {
                    if (progressBanner) { progressBanner.innerHTML = resData.progress_banner || ''; }
                    
                    if (resData.cor_badges_html && !resData.cor_badges_html.includes('No Official CORs')) {
                        vaultContainer.innerHTML = resData.cor_badges_html;
                        vaultWrapper.classList.remove('hidden');
                    }

                    if (resData.has_active_term_cor) {
                        lockoutBanner.classList.remove('hidden');
                        lockoutBanner.classList.add('flex');
                        if (finalizeBtn) {
                            finalizeBtn.disabled = true;
                            finalizeBtn.classList.add('opacity-50', 'cursor-not-allowed');
                        }
                    }

                    if (resData.count > 0 && resData.html) {
                        historyTbody.innerHTML = resData.html;
                        historyWrapper.classList.remove('hidden');
                    } else {
                        historyWrapper.classList.add('hidden');
                    }

                    let isTransferee = false;
                    const admCb = document.querySelector('input[name="sub_admission_type[]"][value="Transferee"]');
                    if (admCb && admCb.checked) isTransferee = true;

                    let isOldStudent = false;
                    const oldCb = document.querySelector('input[name="base_admission_type"][value="Old"]');
                    if (oldCb && oldCb.checked) isOldStudent = true;

                    // NEW: Check for Returnee
                    let isReturnee = false;
                    const retCb = document.querySelector('input[name="sub_admission_type[]"][value="Returnee"]');
                    if (retCb && retCb.checked) isReturnee = true;

                    if (hasShiftRequest) {
                        const shifterRadio = document.getElementById('mode_shifter');
                        if (shifterRadio && !shifterRadio.checked) shifterRadio.checked = true;
                    } else if (resData.has_failing || isReturnee) { // Auto-assign to Manual Irregular
                        const manualIrregRadio = document.getElementById('mode_manual_irregular');
                        if (manualIrregRadio && !manualIrregRadio.checked) manualIrregRadio.checked = true;
                    } else if (resData.has_missing || isTransferee || (isOldStudent && resData.count === 0)) {
                        const manualRegRadio = document.getElementById('mode_manual_regular');
                        if (manualRegRadio && !manualRegRadio.checked) manualRegRadio.checked = true;
                    } else {
                        const autoRegRadio = document.getElementById('mode_regular');
                        if (autoRegRadio && !autoRegRadio.checked) autoRegRadio.checked = true;
                    }
                    
                    window.triggerCurriculumFetch();
                })
                .catch(e => {
                    console.error("History fetch error", e);
                    window.triggerCurriculumFetch();
                });
        }
    } else {
        window.triggerCurriculumFetch();
    }

    window.openModal('evaluationModal');
};

window.closeEvalModal = function() {
    window.closeModal('evaluationModal');
    window.isRegistrarModalOpen = false;
};

window.triggerCurriculumFetch = async function() {
    const radio = document.querySelector('input[name="student_status"]:checked');
    if (!radio) return;
    
    const container = document.getElementById('curriculum_container');
    const status = radio.value;
    const prog = document.getElementById('eval_program').value;
    const year = document.getElementById('eval_year').value;
    const studentId = document.getElementById('eval_student_identifier')?.value || '';
    const userId = document.getElementById('eval_user_internal_id')?.value || '0';
    const oldProg = document.getElementById('eval_current_program_hidden')?.value || '';
    
    container.innerHTML = '<div class="text-center p-6 text-indigo-400 text-[10px] font-bold uppercase tracking-widest animate-pulse"><svg class="w-5 h-5 animate-spin mx-auto mb-2 text-indigo-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>Loading Curriculum & Evaluation Routine...</div>';
    
    try {
        const url = `verification_management.php?api_curriculum=1&program=${encodeURIComponent(prog)}&status=${encodeURIComponent(status)}&year=${encodeURIComponent(year)}&student_id=${encodeURIComponent(studentId)}&user_id=${encodeURIComponent(userId)}&old_program=${encodeURIComponent(oldProg)}`;
        const res = await fetch(url);
        const data = await res.json();
        const htmlToInject = data.html ? data.html : '<div class="text-center p-4 text-rose-500 text-[9px] font-bold uppercase">No curriculum found.</div>';
        container.innerHTML = htmlToInject;
    } catch (e) {
        container.innerHTML = '<div class="text-center p-4 text-rose-500 text-[9px] font-bold uppercase">Error loading curriculum.</div>';
    }
};

window.toggleSubjectGroup = function(selector, checkStatus) {
    document.querySelectorAll(selector).forEach(cb => {
        cb.checked = checkStatus;
        if(cb.classList.contains('cart-checkbox')) {
            window.updateCart(cb);
        } else {
            window.updateSubjectCardStyle(cb);
        }
    });
};

window.updateSubjectCardStyle = function(cb) {
    const tr = cb.closest('tr');
    if (!tr) return;
    
    const badgeEnrolling = tr.querySelector('.badge-enrolling');
    const badgeCredited = tr.querySelector('.badge-credited');
    const gradeInput = tr.querySelector('.grade-input');
    const subjectCode = cb.getAttribute('data-subject-code');
    
    if (cb.checked) {
        tr.classList.add('bg-indigo-50/70');
        if (badgeEnrolling) { badgeEnrolling.classList.remove('hidden'); badgeEnrolling.classList.add('inline-block'); }
        if (badgeCredited) { badgeCredited.classList.remove('block'); badgeCredited.classList.add('hidden'); }
        if (gradeInput) { gradeInput.value = ''; gradeInput.disabled = true; } 
        
        if (subjectCode) {
            const historyRow = document.getElementById('history-row-' + subjectCode);
            if (historyRow && historyRow.classList.contains('history-failed-row')) {
                historyRow.classList.remove('bg-rose-50', 'border-l-[3px]', 'border-rose-500');
                historyRow.classList.add('bg-emerald-50', 'border-l-[3px]', 'border-emerald-500');
                
                const gradeCell = historyRow.querySelector('td:last-child');
                if (gradeCell) {
                    const failBadge = gradeCell.querySelector('.bg-rose-50');
                    if (failBadge) {
                        failBadge.classList.replace('bg-rose-50', 'bg-emerald-50');
                        failBadge.classList.replace('text-rose-700', 'text-emerald-700');
                        failBadge.classList.replace('border-rose-200', 'border-emerald-200');
                        failBadge.innerText = 'RETAKING';
                        if (!failBadge.hasAttribute('data-original-text')) {
                            failBadge.setAttribute('data-original-text', 'FAIL');
                        }
                    }
                }
            }
        }
    } else {
        tr.classList.remove('bg-indigo-50/70');
        if (badgeEnrolling) { badgeEnrolling.classList.remove('inline-block'); badgeEnrolling.classList.add('hidden'); }
        if (badgeCredited) { badgeCredited.classList.remove('hidden'); badgeCredited.classList.add('block'); }
        if (gradeInput) { gradeInput.disabled = false; }
        
        if (subjectCode) {
            const historyRow = document.getElementById('history-row-' + subjectCode);
            if (historyRow && historyRow.classList.contains('history-failed-row')) {
                historyRow.classList.add('bg-rose-50', 'border-l-[3px]', 'border-rose-500');
                historyRow.classList.remove('bg-emerald-50', 'border-l-[3px]', 'border-emerald-500');
                
                const gradeCell = historyRow.querySelector('td:last-child');
                if (gradeCell) {
                    const failBadge = historyRow.querySelector('.bg-emerald-50');
                    if (failBadge) {
                        failBadge.classList.replace('bg-emerald-50', 'bg-rose-50');
                        failBadge.classList.replace('text-emerald-700', 'text-rose-700');
                        failBadge.classList.replace('border-emerald-200', 'border-rose-200');
                        failBadge.innerText = failBadge.getAttribute('data-original-text') || 'FAIL';
                    }
                }
            }
        }
    }
};

// -------------------------------------------------------------
// IRREGULAR SHOPPING CART SYSTEM
// -------------------------------------------------------------
window.cartState = [];

window.updateCart = function(cb) {
    const subjectData = JSON.parse(cb.getAttribute('data-subject'));
    const subjectCode = cb.getAttribute('data-subject-code');
    const tr = cb.closest('tr');
    
    if (cb.checked) {
        tr.classList.add('bg-emerald-50/50');
        if (!window.cartState.find(item => item.id === subjectData.id)) {
            window.cartState.push(subjectData);
        }
        
        // Remove red failure highlight on left box
        if (subjectCode) {
            const row = document.getElementById('irregular-row-' + subjectCode);
            if (row && row.classList.contains('irregular-failed-row')) {
                row.classList.remove('bg-rose-50', 'border-rose-500');
                row.classList.add('bg-emerald-50', 'border-emerald-500');
                const gradeCell = row.querySelector('td:last-child');
                if (gradeCell) {
                    const failBadge = row.querySelector('.bg-rose-100');
                    if (failBadge) {
                        failBadge.classList.replace('bg-rose-100', 'bg-emerald-100');
                        failBadge.classList.replace('text-rose-700', 'text-emerald-700');
                        failBadge.innerText = 'RETAKING';
                    }
                }
            }
        }

    } else {
        tr.classList.remove('bg-emerald-50/50');
        window.cartState = window.cartState.filter(item => item.id !== subjectData.id);
        
        // Restore red failure highlight
        if (subjectCode) {
            const row = document.getElementById('irregular-row-' + subjectCode);
            if (row && row.classList.contains('irregular-failed-row')) {
                row.classList.add('bg-rose-50', 'border-rose-500');
                row.classList.remove('bg-emerald-50', 'border-emerald-500');
                const gradeCell = row.querySelector('td:last-child');
                if (gradeCell) {
                    const failBadge = row.querySelector('.bg-emerald-100');
                    if (failBadge) {
                        failBadge.classList.replace('bg-emerald-100', 'bg-rose-100');
                        failBadge.classList.replace('text-emerald-700', 'text-rose-700');
                        failBadge.innerText = failBadge.getAttribute('data-original-text') || 'FAIL';
                    }
                }
            }
        }
    }
    
    window.renderCart();
};

window.renderCart = function() {
    const list = document.getElementById('shopping-cart-list');
    const emptyMsg = document.getElementById('shopping-cart-empty');
    const totalEl = document.getElementById('cart-total-units');
    
    if (!list || !emptyMsg || !totalEl) return;
    
    if (window.cartState.length === 0) {
        list.classList.add('hidden');
        emptyMsg.classList.remove('hidden');
        emptyMsg.classList.add('flex');
        totalEl.innerText = '0 Units';
        list.innerHTML = '';
        return;
    }
    
    list.classList.remove('hidden');
    emptyMsg.classList.add('hidden');
    emptyMsg.classList.remove('flex');
    
    let html = '';
    let totalUnits = 0;
    
    window.cartState.forEach(item => {
        totalUnits += parseFloat(item.units) || 0;
        html += `
            <li class="bg-white p-2 rounded border border-emerald-200 shadow-sm flex items-center justify-between group animate-down">
                <div>
                    <div class="text-[10px] font-black text-[#00205b] uppercase tracking-wider">${item.code}</div>
                    <div class="text-[8px] font-semibold text-slate-500 truncate max-w-[150px] leading-tight mt-0.5" title="${item.title}">${item.title}</div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-[9px] font-black text-emerald-700 bg-emerald-50 border border-emerald-100 px-1.5 py-0.5 rounded">${item.units}U</span>
                    <button type="button" onclick="window.removeCartItem('${item.id}', '${item.code}')" class="text-rose-300 hover:text-rose-600 bg-rose-50 hover:bg-rose-100 p-1 rounded transition-colors focus:outline-none"><svg class="w-3 h-3 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
            </li>
        `;
    });
    
    list.innerHTML = html;
    totalEl.innerText = `${totalUnits} Units`;
};

window.removeCartItem = function(id, code) {
    const cb = document.querySelector(`input[type='checkbox'][value='${id}']`);
    if (cb) {
        cb.checked = false;
        window.updateCart(cb);
    } else {
        window.cartState = window.cartState.filter(item => item.id !== id);
        window.renderCart();
    }
};

// -------------------------------------------------------------
// SPA FORM INTERCEPTION ENGINE
// -------------------------------------------------------------
window.initSPAEngine = function() {
    document.querySelectorAll('.spa-form').forEach(form => {
        if (form.dataset.spaBound) return;
        form.dataset.spaBound = 'true';
        
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            if (form.classList.contains('is-submitting')) return; 
            form.classList.add('is-submitting');
            
            let submitBtn = Array.from(form.elements).find(el => el.type === 'submit' && el.matches(':focus'));
            if (!submitBtn) {
                submitBtn = form.querySelector('button[type="submit"]');
            }
            
            const originalBtnHTML = submitBtn ? submitBtn.innerHTML : 'Processing...';
            
            if (submitBtn) {
                submitBtn.innerHTML = '<svg class="w-3 h-3 animate-spin inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Executing...';
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-75', 'cursor-not-allowed');
            }
            
            Array.from(form.elements).forEach(el => {
                if (el.tagName !== 'FIELDSET') el.style.pointerEvents = 'none';
            });
            
            const formData = new FormData(form);
            formData.append('ajax_post', '1');
            if (submitBtn && submitBtn.name) formData.append(submitBtn.name, submitBtn.value || '1');

            // Inject cart state for Irregular mode
            if (window.cartState && window.cartState.length > 0) {
                const radio = document.querySelector('input[name="student_status"]:checked');
                if (radio && (radio.value === 'Manual_Irregular' || radio.value === 'Manual_Regular' || radio.value === 'Irregular')) {
                    // Wipe any existing checkboxes the form might have naturally
                    formData.delete('irregular_subjects[]');
                    window.cartState.forEach(item => {
                        formData.append('irregular_subjects[]', item.id);
                    });
                }
            }

            try {
                const res = await fetch(window.location.href, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                
                if (data.status === 'success') {
                    if(window.showToast) { window.showToast(data.message, 'success'); }
                    
                    const modal = form.closest('.modal-overlay');
                    if(modal && form.getAttribute('data-no-close') !== 'true') {
                        modal.classList.remove('modal-active');
                        setTimeout(() => { modal.classList.add('hidden'); modal.classList.remove('flex'); }, 200);
                        window.isRegistrarModalOpen = false;
                        window.cartState = []; // Reset cart on successful close
                    }
                    
                    window.triggerDynamicFetch(true);
                } else {
                    if(window.showToast) { window.showToast(data.message, 'error'); }
                }
            } catch (error) {
                if(window.showToast) { window.showToast("Network Error.", 'error'); }
            } finally {
                form.classList.remove('is-submitting');
                Array.from(form.elements).forEach(el => {
                    if (el.tagName !== 'FIELDSET') el.style.pointerEvents = 'auto';
                });
                if (submitBtn && !submitBtn.closest('.bg-amber-100') && !submitBtn.closest('.bg-rose-600')) {
                    submitBtn.innerHTML = originalBtnHTML;
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('opacity-75', 'cursor-not-allowed');
                } else if (submitBtn && submitBtn.closest('.bg-rose-600')) {
                    submitBtn.innerHTML = 'Save';
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('opacity-75', 'cursor-not-allowed');
                }
                isFormDirty = false;
            }
        });
    });
};

// -------------------------------------------------------------
// GLITCH-FREE BACKGROUND DATA POLLING
// -------------------------------------------------------------
window.triggerDynamicFetch = async function(force = false) {
    if (!force) {
        if (window.isRegistrarModalOpen) return;
        if (isFormDirty) return;
        if (document.getElementById("toggleShiftOnly") && document.getElementById("toggleShiftOnly").checked) return;
    }

    const filterProg = document.getElementById('filter_program');
    const filterYear = document.getElementById('filter_year');
    
    const params = new URLSearchParams({
        api_refresh: 1,
        f_program: filterProg ? filterProg.value : 'All',
        f_year: filterYear ? filterYear.value : 'All'
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
        
        if (!force) {
            if (lastDataString === null) {
                lastDataString = newDataString;
                return;
            }
            if (newDataString === lastDataString) return; 
        }
        
        lastDataString = newDataString;

        if (data.updates) {
            if (data.updates.unified_tbody) {
                const unifiedBody = document.getElementById('unified_tbody');
                if (unifiedBody) {
                    unifiedBody.innerHTML = data.updates.unified_tbody;
                    
                    const input = document.getElementById("liveSearch");
                    const toggleShift = document.getElementById("toggleShiftOnly");
                    
                    if ((input && input.value.trim() !== '') || (toggleShift && toggleShift.checked)) {
                        if (typeof window.filterTable === 'function') {
                            window.filterTable();
                        }
                    }
                }
            }
            
            const toggleShift = document.getElementById("toggleShiftOnly");
            if (!toggleShift || !toggleShift.checked) {
                if (data.updates.queue_count) {
                    const queueCountEl = document.getElementById('queue_count');
                    if (queueCountEl) {
                        queueCountEl.innerText = data.updates.queue_count;
                    }
                }
            }
            
            window.initSPAEngine();
        }
    } catch (err) {}
};

document.addEventListener('DOMContentLoaded', () => {
    window.initSPAEngine();
    setInterval(() => window.triggerDynamicFetch(), 5000);
});

// =========================================================
// HISTORICAL SHIFT MODAL & CURRICULUM OVERVIEW LOGIC
// =========================================================

window.activeShiftStudent = '';
window.activeShiftOldProg = '';
window.activeShiftNewProg = '';

window.openShiftModal = function(studentId, oldProg, newProg, name, currentYearLvl) {
    window.isRegistrarModalOpen = true;
    window.activeShiftStudent = studentId;
    window.activeShiftOldProg = oldProg;
    window.activeShiftNewProg = newProg;

    const idInput = document.getElementById('shift_student_id_val');
    if (idInput) idInput.value = studentId;

    const headerEl = document.getElementById('shift_student_header');
    if (headerEl) headerEl.innerText = `${name} • ${studentId} • ${oldProg}`;
    
    let semToLoad = '1st Semester';
    if (!currentYearLvl || currentYearLvl === '') currentYearLvl = '1st Year';
    const navEl = document.getElementById('shift_period_nav');
    if (navEl) navEl.value = `${currentYearLvl}|${semToLoad}`;

    const modal = document.getElementById('shiftHistoryModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => modal.classList.add('modal-active'), 10);
    }
    
    const prevProgInput = document.getElementById('shift_prev_prog_input');
    const newProgInput = document.getElementById('shift_new_prog_input');
    const acadYearInput = document.getElementById('shift_acad_year_input');

    if (prevProgInput) {
        prevProgInput.setAttribute('readonly', 'true');
        prevProgInput.classList.remove('border', 'border-slate-300', 'px-2', 'py-1', 'bg-white');
    }
    if (newProgInput) {
        newProgInput.setAttribute('readonly', 'true');
        newProgInput.classList.remove('border', 'border-indigo-300', 'px-2', 'py-1', 'bg-white');
    }
    if (acadYearInput) {
        acadYearInput.setAttribute('readonly', 'true');
        acadYearInput.classList.remove('border', 'border-slate-300', 'px-2', 'py-1', 'bg-white');
    }

    const btnEnable = document.getElementById('btn_enable_shift_edit');
    const btnSave = document.getElementById('btn_save_shift_changes');
    if (btnEnable) btnEnable.classList.remove('hidden');
    if (btnSave) btnSave.classList.add('hidden');
    
    window.loadShiftData();
};

window.closeShiftModal = function() {
    const modal = document.getElementById('shiftHistoryModal');
    if (modal) {
        modal.classList.remove('modal-active');
        setTimeout(() => { modal.classList.add('hidden'); modal.classList.remove('flex'); }, 200);
    }
    window.isRegistrarModalOpen = false;
};

window.enableShiftEdit = function() {
    const prevProgInput = document.getElementById('shift_prev_prog_input');
    const newProgInput = document.getElementById('shift_new_prog_input');
    const acadYearInput = document.getElementById('shift_acad_year_input');

    if (prevProgInput) {
        prevProgInput.removeAttribute('readonly');
        prevProgInput.classList.add('border', 'border-slate-300', 'px-2', 'py-1', 'bg-white', 'rounded');
    }
    if (newProgInput) {
        newProgInput.removeAttribute('readonly');
        newProgInput.classList.add('border', 'border-indigo-300', 'px-2', 'py-1', 'bg-white', 'rounded');
    }
    if (acadYearInput) {
        acadYearInput.removeAttribute('readonly');
        acadYearInput.classList.add('border', 'border-slate-300', 'px-2', 'py-1', 'bg-white', 'rounded');
    }

    document.querySelectorAll('.shift-eval-select').forEach(sel => {
        sel.removeAttribute('disabled');
    });

    const btnEnable = document.getElementById('btn_enable_shift_edit');
    const btnSave = document.getElementById('btn_save_shift_changes');
    if (btnEnable) btnEnable.classList.add('hidden');
    if (btnSave) btnSave.classList.remove('hidden');
    
    if(window.showToast) window.showToast("Edit Mode Enabled. Don't forget to save changes.", 'success');
};

window.loadShiftData = function() {
    const navEl = document.getElementById('shift_period_nav');
    if (!navEl) return;
    const navVal = navEl.value.split('|');
    const yl = navVal[0];
    const sem = navVal[1];
    
    const ylHidden = document.getElementById('shift_year_level_hidden');
    const semHidden = document.getElementById('shift_semester_hidden');
    if (ylHidden) ylHidden.value = yl;
    if (semHidden) semHidden.value = sem;
    
    const leftBox = document.getElementById('shift_left_box');
    const rightBox = document.getElementById('shift_right_box');

    if (leftBox) leftBox.innerHTML = '<div class="p-8 text-center"><svg class="w-6 h-6 animate-spin text-slate-400 mx-auto" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg></div>';
    if (rightBox) rightBox.innerHTML = '<div class="p-8 text-center"><svg class="w-6 h-6 animate-spin text-indigo-400 mx-auto" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg></div>';

    fetch(`verification_management.php?api_shift_history=1&student_id=${encodeURIComponent(window.activeShiftStudent)}&old_prog=${encodeURIComponent(window.activeShiftOldProg)}&new_prog=${encodeURIComponent(window.activeShiftNewProg)}&year_level=${encodeURIComponent(yl)}&semester=${encodeURIComponent(sem)}`)
    .then(res => res.json())
    .then(data => {
        const prevProg = document.getElementById('shift_prev_prog_input');
        const newProg = document.getElementById('shift_new_prog_input');
        const acadYear = document.getElementById('shift_acad_year_input');
        
        if (prevProg && data.old_prog) prevProg.value = data.old_prog;
        if (newProg && data.new_prog) newProg.value = data.new_prog;
        if (acadYear && data.acad_year) acadYear.value = data.acad_year;
        
        if (leftBox && data.old_html) leftBox.innerHTML = data.old_html;
        if (rightBox && data.new_html) rightBox.innerHTML = data.new_html;

        if (prevProg) {
            prevProg.setAttribute('readonly', 'true');
            prevProg.classList.remove('border', 'border-slate-300', 'px-2', 'py-1', 'bg-white');
        }
        if (newProg) {
            newProg.setAttribute('readonly', 'true');
            newProg.classList.remove('border', 'border-indigo-300', 'px-2', 'py-1', 'bg-white');
        }
        if (acadYear) {
            acadYear.setAttribute('readonly', 'true');
            acadYear.classList.remove('border', 'border-slate-300', 'px-2', 'py-1', 'bg-white');
        }
        
        const btnEnable = document.getElementById('btn_enable_shift_edit');
        const btnSave = document.getElementById('btn_save_shift_changes');
        if (btnEnable) btnEnable.classList.remove('hidden');
        if (btnSave) btnSave.classList.add('hidden');
    })
    .catch(err => {
        if (leftBox) leftBox.innerHTML = '<div class="p-4 text-rose-500 font-bold text-xs text-center">Failed to load</div>';
        if (rightBox) rightBox.innerHTML = '<div class="p-4 text-rose-500 font-bold text-xs text-center">Failed to load</div>';
    });
};

window.saveShiftRecord = function(e) {
    e.preventDefault();
    const form = document.getElementById('shiftRecordForm');
    if (!form) return;
    const formData = new FormData(form);
    formData.append('ajax_post', '1');

    const btn = document.getElementById('btn_save_shift_changes');
    const oldHtml = btn ? btn.innerHTML : 'Save';
    if (btn) {
        btn.innerHTML = 'Saving...';
        btn.disabled = true;
    }

    fetch('verification_management.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            if(window.showToast) window.showToast(data.message, 'success');
            const btnEnable = document.getElementById('btn_enable_shift_edit');
            const btnSave = document.getElementById('btn_save_shift_changes');
            if (btnEnable) btnEnable.classList.remove('hidden');
            if (btnSave) btnSave.classList.add('hidden');
            
            document.querySelectorAll('.shift-eval-select').forEach(sel => sel.setAttribute('disabled', 'true'));
            
            const prevProg = document.getElementById('shift_prev_prog_input');
            const newProg = document.getElementById('shift_new_prog_input');
            const acadYear = document.getElementById('shift_acad_year_input');

            if (prevProg) {
                prevProg.setAttribute('readonly', 'true');
                prevProg.classList.remove('border', 'border-slate-300', 'px-2', 'py-1', 'bg-white');
            }
            if (newProg) {
                newProg.setAttribute('readonly', 'true');
                newProg.classList.remove('border', 'border-indigo-300', 'px-2', 'py-1', 'bg-white');
            }
            if (acadYear) {
                acadYear.setAttribute('readonly', 'true');
                acadYear.classList.remove('border', 'border-slate-300', 'px-2', 'py-1', 'bg-white');
            }
        } else {
            if(window.showToast) window.showToast(data.message, 'error');
        }
    })
    .finally(() => {
        if (btn) {
            btn.innerHTML = oldHtml;
            btn.disabled = false;
        }
    });
};