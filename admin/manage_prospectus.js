// =========================================================
// MANAGE PROSPECTUS SPECIFIC LOGIC (manage_prospectus.js)
// =========================================================

let activeSemesterIdForCredit = '';
let confirmTargetUrl = '';
let confirmTargetForm = null;

// Handle auto-opening the Builder Modal after creating a new year
document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('open_builder') === '1') {
        setTimeout(() => {
            window.openReferenceModal();
        }, 300);
    }
});

// Use global modals/toasts if available, otherwise define fallbacks
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

// Standard Form Handler (For Edit, Archive, etc.)
window.handleAjaxSubmit = async function(e, modalId, successMessage) {
    e.preventDefault();
    const form = e.target;
    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    
    btn.innerHTML = '<svg class="animate-spin h-4 w-4 mr-2 inline" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> SAVING...';
    btn.disabled = true;

    try {
        const formData = new FormData(form);
        formData.append('ajax_request', '1');
        
        await fetch(form.action || window.location.href, { method: 'POST', body: formData });
        
        if (modalId === 'manualEntryCard') {
            window.toggleManualEntryForm();
            form.reset();
        } else if (modalId) {
            window.closeModal(modalId);
        }
        
        if (typeof window.showToast === 'function') window.showToast(successMessage || 'Action successful.', 'success');
        
        // Refresh the UI without a hard reload
        if(form.name !== 'set_default_year') {
            window.switchTab(window.PROSPECTUS_INIT_DATA.currentTab, true);
            // If the Book Workspace is open, update its right pane too!
            if(document.getElementById('referenceCurriculumModal') && !document.getElementById('referenceCurriculumModal').classList.contains('hidden')) {
                window.loadLiveDraft();
            }
        } else {
            setTimeout(() => window.location.reload(), 1000); // Reload to update badges for Publish
        }
        
    } catch (error) {
        if (typeof window.showToast === 'function') window.showToast('Failed to process request.', 'error');
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
};

// SPA TAB SWITCHING
window.switchTab = async function(status, forceRefresh = false) {
    if (window.PROSPECTUS_INIT_DATA.currentTab === status && !forceRefresh) return;
    window.PROSPECTUS_INIT_DATA.currentTab = status;

    const newUrl = new URL(window.location);
    newUrl.searchParams.set('status', status);
    window.history.pushState({}, '', newUrl);

    const btnActive = document.getElementById('tab_btn_active');
    const btnArchived = document.getElementById('tab_btn_archived');
    
    if (status === 'active') {
        if(btnActive) btnActive.className = "px-3 md:px-4 py-1.5 rounded-md text-[10px] font-bold uppercase tracking-widest transition-colors cursor-pointer bg-[#00205b] text-white shadow-sm";
        if(btnArchived) btnArchived.className = "px-3 md:px-4 py-1.5 rounded-md text-[10px] font-bold uppercase tracking-widest transition-colors flex items-center gap-1.5 cursor-pointer bg-white border border-slate-200 text-slate-600 hover:bg-slate-50";
    } else {
        if(btnArchived) btnArchived.className = "px-3 md:px-4 py-1.5 rounded-md text-[10px] font-bold uppercase tracking-widest transition-colors flex items-center gap-1.5 cursor-pointer bg-slate-700 text-white shadow-sm";
        if(btnActive) btnActive.className = "px-3 md:px-4 py-1.5 rounded-md text-[10px] font-bold uppercase tracking-widest transition-colors cursor-pointer bg-white border border-slate-200 text-slate-600 hover:bg-slate-50";
    }

    const container = document.getElementById('documentCanvasContainer');
    if(container) container.style.opacity = '0.4';

    try {
        const response = await fetch(`manage_prospectus.php?api_refresh=1&program_id=${window.PROSPECTUS_INIT_DATA.activeProgramId}&year=${window.PROSPECTUS_INIT_DATA.activeYear}&status=${status}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        
        const currContainer = document.getElementById('curriculum_container');
        if(currContainer) currContainer.innerHTML = data.curriculum_html;
        
        const printUnits = document.getElementById('print_total_units');
        if(printUnits) printUnits.innerText = data.total_units;
        
        const headerUnits = document.getElementById('header_total_units');
        if(headerUnits) headerUnits.innerText = data.total_units;
        
    } catch (err) {
        if (typeof window.showToast === 'function') window.showToast("Connection error while switching views.", 'error');
    }
    if(container) container.style.opacity = '1';
};

// SEMESTER ACTION BAR & CHECKBOXES
window.updateSemesterActions = function(semesterId, sourceCb = null) {
    const table = document.getElementById('table_' + semesterId);
    if (!table) return;

    if (sourceCb) {
        const row = sourceCb.closest('tr');
        if(sourceCb.checked) {
            row.classList.add('bg-indigo-50/40', 'border-l-indigo-500');
            row.classList.remove('border-l-transparent');
        } else {
            row.classList.remove('bg-indigo-50/40', 'border-l-indigo-500');
            row.classList.add('border-l-transparent');
        }
    }
    
    const checkedCount = table.querySelectorAll('.subject-cb:checked').length;
    const actionDiv = document.getElementById('actions_' + semesterId);
    
    if (actionDiv) {
        if (checkedCount > 0) {
            actionDiv.classList.remove('hidden');
            actionDiv.classList.add('flex');
        } else {
            actionDiv.classList.add('hidden');
            actionDiv.classList.remove('flex');
        }
    }
};

window.promptSemesterBulkAction = function(semesterId, actionType) {
    const table = document.getElementById('table_' + semesterId);
    const checked = table.querySelectorAll('.subject-cb:checked');
    if(checked.length === 0) return;

    const form = document.getElementById('semesterActionForm');
    form.innerHTML = `<input type="hidden" name="semester_bulk_action" value="${actionType}">
                      <input type="hidden" name="current_status" value="${window.PROSPECTUS_INIT_DATA.currentTab}">`;
    checked.forEach(cb => form.innerHTML += `<input type="hidden" name="selected_subjects[]" value="${cb.value}">`);

    const verb = actionType === 'archive' ? 'archive' : 'restore';
    window.showCustomConfirm(`Are you sure you want to ${verb} the ${checked.length} selected subject(s)?`, actionType, form);
};

window.promptEditCredit = function(semesterId) {
    activeSemesterIdForCredit = semesterId;
    window.openModal('editCreditModal');
};

window.submitEditCredit = async function() {
    const table = document.getElementById('table_' + activeSemesterIdForCredit);
    const checked = table.querySelectorAll('.subject-cb:checked');
    if (checked.length === 0) return;

    const btn = document.getElementById('btnApplyCredit');
    const origText = btn.innerText;
    btn.innerHTML = "Saving..."; btn.disabled = true;

    const form = document.getElementById('semesterActionForm');
    const newUnits = document.getElementById('bulk_new_units').value;
    
    form.innerHTML = `<input type="hidden" name="bulk_edit_credit" value="1"><input type="hidden" name="new_units" value="${newUnits}">`;
    checked.forEach(cb => form.innerHTML += `<input type="hidden" name="selected_subjects[]" value="${cb.value}">`);

    const formData = new FormData(form);
    formData.append('ajax_request', '1');

    try {
        await fetch(window.location.href, { method: 'POST', body: formData });
        window.closeModal('editCreditModal');
        if (typeof window.showToast === 'function') window.showToast('Credit units updated successfully.', 'success');
        window.switchTab(window.PROSPECTUS_INIT_DATA.currentTab, true); 
        if(!document.getElementById('referenceCurriculumModal').classList.contains('hidden')) window.loadLiveDraft();
    } catch (error) {
        if (typeof window.showToast === 'function') window.showToast('Error updating credit units.', 'error');
    } finally {
        btn.innerHTML = origText; btn.disabled = false;
    }
};

window.showCustomConfirm = function(message, actionType, target) {
    const modal = document.getElementById('customConfirmModal');
    const card = document.getElementById('customConfirmCard');
    const btnProceed = document.getElementById('btnConfirmProceed');
    const iconContainer = document.getElementById('confirmIconContainer');
    
    document.getElementById('confirmButtons').classList.remove('hidden');
    document.getElementById('confirmLoading').classList.add('hidden');
    document.getElementById('confirmLoading').classList.remove('flex');
    document.getElementById('confirmMessage').innerText = message;
    
    if (typeof target === 'string') { confirmTargetUrl = target; confirmTargetForm = null; } 
    else { confirmTargetUrl = ''; confirmTargetForm = target; }
    
    if (actionType === 'delete' || actionType === 'archive') {
        btnProceed.className = 'px-5 py-2.5 rounded-xl text-[10px] font-bold uppercase tracking-widest text-white shadow-md transition-colors bg-rose-600 hover:bg-rose-700';
        btnProceed.innerText = actionType === 'delete' ? 'Yes, Delete' : 'Yes, Archive';
        iconContainer.className = 'mx-auto w-12 h-12 mb-4 rounded-full flex items-center justify-center bg-rose-100 text-rose-600';
        iconContainer.innerHTML = '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>';
    } else {
        btnProceed.className = 'px-5 py-2.5 rounded-xl text-[10px] font-bold uppercase tracking-widest text-white shadow-md transition-colors bg-emerald-600 hover:bg-emerald-700';
        btnProceed.innerText = 'Yes, Proceed';
        iconContainer.className = 'mx-auto w-12 h-12 mb-4 rounded-full flex items-center justify-center bg-emerald-100 text-emerald-600';
        iconContainer.innerHTML = '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg>';
    }
    
    modal.classList.remove('hidden'); modal.classList.add('flex');
    setTimeout(() => { modal.classList.remove('opacity-0'); card.classList.remove('scale-90'); card.classList.add('scale-100'); }, 10);
};

window.closeCustomConfirm = function() {
    const modal = document.getElementById('customConfirmModal');
    const card = document.getElementById('customConfirmCard');
    modal.classList.add('opacity-0'); card.classList.remove('scale-100'); card.classList.add('scale-90');
    setTimeout(() => { modal.classList.remove('flex'); modal.classList.add('hidden'); }, 300);
};

document.addEventListener('DOMContentLoaded', () => {
    const btnConfirmCancel = document.getElementById('btnConfirmCancel');
    const btnConfirmProceed = document.getElementById('btnConfirmProceed');

    if (btnConfirmCancel) btnConfirmCancel.addEventListener('click', window.closeCustomConfirm);
    
    if (btnConfirmProceed) {
        btnConfirmProceed.addEventListener('click', async function() {
            document.getElementById('confirmButtons').classList.add('hidden');
            document.getElementById('confirmLoading').classList.remove('hidden');
            document.getElementById('confirmLoading').classList.add('flex');
            
            try {
                if (confirmTargetUrl) {
                    const url = new URL(confirmTargetUrl, window.location.origin);
                    url.searchParams.append('ajax_request', '1');
                    await fetch(url.toString(), { method: 'GET' });
                } else if (confirmTargetForm) {
                    const formData = new FormData(confirmTargetForm);
                    formData.append('ajax_request', '1');
                    await fetch(window.location.href, { method: 'POST', body: formData });
                }
                if (typeof window.showToast === 'function') window.showToast('Action completed successfully.', 'success');
                window.closeCustomConfirm();
                window.switchTab(window.PROSPECTUS_INIT_DATA.currentTab, true);
                if(!document.getElementById('referenceCurriculumModal').classList.contains('hidden')) window.loadLiveDraft();
            } catch (err) {
                if (typeof window.showToast === 'function') window.showToast('Action failed.', 'error');
                window.closeCustomConfirm();
            }
        });
    }
});

window.toggleManualEntryForm = function() {
    const formCard = document.getElementById('manualEntryCard');
    if (!formCard) return;
    if (formCard.classList.contains('hidden')) { formCard.classList.remove('hidden'); } 
    else { formCard.classList.add('hidden'); }
};

window.openEditSubjectModal = function(btnElement) {
    document.getElementById('edit_sub_id').value = btnElement.getAttribute('data-id');
    document.getElementById('edit_sub_code').value = btnElement.getAttribute('data-code');
    document.getElementById('edit_sub_title').value = btnElement.getAttribute('data-title');
    document.getElementById('edit_sub_units').value = btnElement.getAttribute('data-units');
    document.getElementById('edit_sub_prereq').value = btnElement.getAttribute('data-prereq');
    
    const instructorInput = document.getElementById('edit_sub_instructor');
    if (instructorInput) { instructorInput.value = btnElement.getAttribute('data-instructor'); }
    
    window.openModal('editSubjectModal');
};

// ---------------------------------------------------------
// NEW: MASSIVE BOOK VIEW WORKSPACE LOGIC
// ---------------------------------------------------------

window.openReferenceModal = function() {
    // Left Page: Reset
    document.getElementById('import_program_selector').value = '';
    document.getElementById('import_year_selector').innerHTML = '<option value="">-- Select Program First --</option>';
    document.getElementById('import_year_selector').disabled = true;
    document.getElementById('past_curriculum_container').innerHTML = '<div class="text-center py-10 md:py-24 text-slate-400 text-[10px] md:text-xs font-bold uppercase tracking-widest border border-dashed border-slate-300 rounded-xl bg-slate-50 mx-4 mt-4">Select a program and year above to view subjects.</div>';
    
    // Right Page: Load initial live draft
    window.loadLiveDraft();
    
    window.openModal('referenceCurriculumModal');
};

window.loadImportYears = async function() {
    const programId = document.getElementById('import_program_selector').value;
    const yearSelector = document.getElementById('import_year_selector');
    const container = document.getElementById('past_curriculum_container');
    
    yearSelector.innerHTML = '<option value="">-- Loading... --</option>';
    yearSelector.disabled = true;
    container.innerHTML = '<div class="text-center py-10 md:py-24 text-slate-400 text-[10px] md:text-xs font-bold uppercase tracking-widest border border-dashed border-slate-300 rounded-xl bg-slate-50 mx-4 mt-4">Select a source year to view subjects.</div>';

    if (!programId) {
        yearSelector.innerHTML = '<option value="">-- Select Program First --</option>';
        return;
    }

    try {
        const response = await fetch(`manage_prospectus.php?api_get_years=1&program_id=${programId}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        
        yearSelector.innerHTML = '<option value="">-- Choose a year --</option>';
        if (data.years && data.years.length > 0) {
            data.years.forEach(y => {
                yearSelector.innerHTML += `<option value="${y}">S.Y. ${y}</option>`;
            });
            yearSelector.disabled = false;
        } else {
            yearSelector.innerHTML = '<option value="">No years found for this program</option>';
        }
    } catch (e) {
        yearSelector.innerHTML = '<option value="">Error loading years</option>';
    }
};

window.loadPastCurriculum = async function() {
    const programId = document.getElementById('import_program_selector').value;
    const sourceYear = document.getElementById('import_year_selector').value;
    const container = document.getElementById('past_curriculum_container');
    
    if(!programId || !sourceYear) {
        container.innerHTML = '<div class="text-center py-10 md:py-24 text-slate-400 text-[10px] md:text-xs font-bold uppercase tracking-widest border border-dashed border-slate-300 rounded-xl bg-slate-50 mx-4 mt-4">Select a program and year above to view subjects.</div>';
        return;
    }

    container.innerHTML = '<div class="flex flex-col items-center justify-center py-24 text-slate-500"><svg class="animate-spin h-8 w-8 text-indigo-500 mb-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg><span class="text-xs font-bold uppercase tracking-widest">Retrieving Data...</span></div>';
    
    try {
        const response = await fetch(`manage_prospectus.php?api_get_past=1&import_program_id=${programId}&year=${sourceYear}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        container.innerHTML = data.html;
    } catch(e) {
        container.innerHTML = '<div class="text-center text-rose-500 py-8 mx-4 mt-4 font-bold border border-rose-200 bg-rose-50 rounded-xl">Error loading curriculum data.</div>';
    }
};

window.toggleAllPastSubjects = function(masterCb) {
    const checkboxes = document.querySelectorAll('.past-subject-cb');
    checkboxes.forEach(cb => cb.checked = masterCb.checked);
};

// Handle the Left Page Import button without closing the modal
window.submitImport = async function(e) {
    e.preventDefault();
    const form = e.target;
    
    const checked = document.querySelectorAll('.past-subject-cb:checked');
    if(checked.length === 0) {
        if (typeof window.showToast === 'function') window.showToast('Please select at least one subject to import.', 'error');
        return;
    }
    
    const hiddenDiv = document.getElementById('hiddenImportInputs');
    hiddenDiv.innerHTML = '';
    checked.forEach(cb => {
        hiddenDiv.innerHTML += `<input type="hidden" name="selected_import_subjects[]" value="${cb.value}">`;
    });

    const btn = document.getElementById('importSubmitBtn');
    const origText = btn.innerHTML;
    btn.innerHTML = 'Importing...'; btn.disabled = true;

    try {
        const formData = new FormData(form);
        formData.append('ajax_request', '1');
        await fetch(window.location.href, { method: 'POST', body: formData });
        
        if (typeof window.showToast === 'function') window.showToast('Subjects imported to draft!', 'success');
        
        // Reset checkboxes
        checked.forEach(cb => cb.checked = false);
        const master = document.getElementById('selectAllPast');
        if(master) master.checked = false;

        // Refresh Right Page & Background Page
        window.loadLiveDraft();
        window.switchTab(window.PROSPECTUS_INIT_DATA.currentTab, true);
    } catch (err) {
        if (typeof window.showToast === 'function') window.showToast('Failed to import.', 'error');
    } finally {
        btn.innerHTML = origText; btn.disabled = false;
    }
};

// Handle Right Page Quick-Add Form without closing modal
window.submitModalAddSubject = async function(e) {
    e.preventDefault();
    const form = e.target;
    const btn = form.querySelector('button[type="submit"]');
    const origText = btn.innerHTML;
    btn.innerHTML = '...'; btn.disabled = true;

    try {
        const formData = new FormData(form);
        formData.append('ajax_request', '1');
        await fetch(window.location.href, { method: 'POST', body: formData });
        
        if (typeof window.showToast === 'function') window.showToast('Subject added to draft!', 'success');
        
        // Clear text inputs but keep selectors
        form.querySelector('input[name="course_code"]').value = '';
        form.querySelector('input[name="descriptive_title"]').value = '';
        form.querySelector('input[name="prerequisite"]').value = '';
        form.querySelector('input[name="course_code"]').focus();

        // Refresh Right Page & Background Page
        window.loadLiveDraft();
        window.switchTab(window.PROSPECTUS_INIT_DATA.currentTab, true);
    } catch(err) {
        if (typeof window.showToast === 'function') window.showToast('Failed to add subject', 'error');
    } finally {
        btn.innerHTML = origText; btn.disabled = false;
    }
};

// Refresh the Right Page UI dynamically
window.loadLiveDraft = async function() {
    const container = document.getElementById('live_draft_container');
    container.innerHTML = '<div class="flex flex-col items-center justify-center py-20 text-slate-400"><svg class="animate-spin h-6 w-6 mb-2 text-indigo-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg><span class="text-[10px] font-bold tracking-widest uppercase">Syncing Draft...</span></div>';
    
    try {
        const response = await fetch(`manage_prospectus.php?api_refresh=1&program_id=${window.PROSPECTUS_INIT_DATA.activeProgramId}&year=${window.PROSPECTUS_INIT_DATA.activeYear}&status=active`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        
        // The API returns standard HTML grid elements. It will flow beautifully in the right pane.
        container.innerHTML = data.curriculum_html;
    } catch(err) {
        container.innerHTML = '<div class="text-center text-rose-500 py-8 font-bold">Error loading live draft.</div>';
    }
};