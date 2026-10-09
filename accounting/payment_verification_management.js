// =========================================================
// VERIFICATION QUEUE LOGIC (SMART ROUTING ARCHITECTURE)
// =========================================================

let lastDataString = null; 
window.isRegistrarModalOpen = false;
let isFormDirty = false;

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
    if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) { isImage = true; }

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

    // Call the central modal engine to properly handle the opacity animations
    window.openModal('doc-modal');
};

window.closeDocModal = function() { 
    window.closeModal('doc-modal');
    setTimeout(() => {
        const iframe = document.getElementById('docIframe');
        const img = document.getElementById('docImage');
        if (iframe) iframe.src = ''; 
        if (img) img.src = ''; 
    }, 300); // Clear sources after animation ends
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

            try {
                const res = await fetch(window.location.href, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                
                if (data.status === 'success') {
                    if(window.showToast) { window.showToast(data.message, 'success'); }
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
                if (submitBtn && !submitBtn.closest('.bg-amber-100')) {
                    submitBtn.innerHTML = originalBtnHTML;
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('opacity-75', 'cursor-not-allowed');
                }
                isFormDirty = false;

                // Unlock confirm modal and close it after request finishes
                if (window.currentPaymentForm === form) {
                    window.closeConfirmModal();
                }
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
// ACTION & CONFIRMATION MODAL HANDLERS
// =========================================================

window.currentPaymentForm = null;
window.currentPaymentAction = null;
window.currentPaymentBtnHtml = null;

// 1. CONFIRM PAYMENT ACTION (Online/Existing Queue)
window.confirmPaymentAction = function(action, form) {
    window.currentPaymentForm = form;
    window.currentPaymentAction = action;

    const modal = document.getElementById('actionConfirmModal');
    const title = document.getElementById('confirmActionTitle');
    const desc = document.getElementById('confirmActionDesc');
    const reasonDiv = document.getElementById('confirmReasonDiv');
    const reasonInput = document.getElementById('confirmReasonInput');
    const btn = document.getElementById('btnConfirmAction');

    const studentName = form.getAttribute('data-name');
    const amount = form.getAttribute('data-amount');
    const orNumber = form.getAttribute('data-or');

    if (action === 'approve') {
        title.innerText = 'Approve Payment';
        desc.innerHTML = `Are you sure you want to approve the payment of <strong>${amount}</strong> for <strong>${studentName}</strong> (O.R. ${orNumber})?`;
        reasonDiv.classList.add('hidden');
        reasonInput.removeAttribute('required');
        btn.className = 'flex-1 py-2 px-4 rounded-xl text-[10px] font-bold text-white uppercase tracking-widest shadow-md transition-colors cursor-pointer bg-emerald-500 hover:bg-emerald-600';
        btn.innerText = 'Approve';
    } else {
        title.innerText = 'Reject Payment';
        desc.innerHTML = `Are you sure you want to reject/hold the payment for <strong>${studentName}</strong>? Please provide a reason below.`;
        reasonDiv.classList.remove('hidden');
        reasonInput.setAttribute('required', 'true');
        reasonInput.value = '';
        btn.className = 'flex-1 py-2 px-4 rounded-xl text-[10px] font-bold text-white uppercase tracking-widest shadow-md transition-colors cursor-pointer bg-rose-500 hover:bg-rose-600';
        btn.innerText = 'Reject';
    }

    window.openModal('actionConfirmModal');
};

// 2. CLOSE CONFIRM MODAL
window.closeConfirmModal = function() {
    window.closeModal('actionConfirmModal');
    window.currentPaymentForm = null;
    window.currentPaymentAction = null;
    
    // Unlock modal buttons
    const btn = document.getElementById('btnConfirmAction');
    if (btn && window.currentPaymentBtnHtml) {
        btn.innerHTML = window.currentPaymentBtnHtml;
        btn.disabled = false;
        btn.classList.remove('opacity-75', 'cursor-not-allowed');
        const cancelBtn = btn.previousElementSibling;
        if(cancelBtn) {
            cancelBtn.disabled = false;
            cancelBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        }
    }
    window.currentPaymentBtnHtml = null;
};

// 3. EXECUTE PAYMENT ACTION (Triggered inside Modal)
window.executePaymentAction = function() {
    if (!window.currentPaymentForm) return;

    // Clean up any old hidden inputs
    const oldInputs = window.currentPaymentForm.querySelectorAll('.temp-action-input');
    oldInputs.forEach(el => el.remove());

    if (window.currentPaymentAction === 'reject') {
        const reasonInput = document.getElementById('confirmReasonInput');
        if (!reasonInput.value.trim()) {
            if(window.showToast) window.showToast("Please provide a reason for rejection.", "error");
            reasonInput.focus();
            return;
        }
        
        let hiddenReason = document.createElement('input');
        hiddenReason.type = 'hidden';
        hiddenReason.name = 'reject_reason';
        hiddenReason.value = reasonInput.value;
        hiddenReason.className = 'temp-action-input';
        window.currentPaymentForm.appendChild(hiddenReason);
        
        let actionBtn = document.createElement('input');
        actionBtn.type = 'hidden';
        actionBtn.name = 'reject_payment';
        actionBtn.value = '1';
        actionBtn.className = 'temp-action-input';
        window.currentPaymentForm.appendChild(actionBtn);
    } else {
        let actionBtn = document.createElement('input');
        actionBtn.type = 'hidden';
        actionBtn.name = 'approve_payment';
        actionBtn.value = '1';
        actionBtn.className = 'temp-action-input';
        window.currentPaymentForm.appendChild(actionBtn);
    }

    // Lock Modal UI to prevent double click
    const btn = document.getElementById('btnConfirmAction');
    const cancelBtn = btn.previousElementSibling;
    window.currentPaymentBtnHtml = btn.innerHTML;
    
    btn.disabled = true;
    btn.classList.add('opacity-75', 'cursor-not-allowed');
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin inline-block mr-1" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Processing...';
    if(cancelBtn) {
        cancelBtn.disabled = true;
        cancelBtn.classList.add('opacity-50', 'cursor-not-allowed');
    }

    // Programmatically trigger the SPA form submission listener
    const submitEvent = new Event('submit', { cancelable: true, bubbles: true });
    window.currentPaymentForm.dispatchEvent(submitEvent);
};

// 4. OPEN NEW APPLICANT MODAL
window.openProcessNewModal = function(id, name, displayId) {
    document.getElementById('proc_adm_id').value = id;
    document.getElementById('proc_name').innerText = name;
    document.getElementById('proc_id').innerText = displayId;
    
    // Reset form elements
    const form = document.querySelector('#processNewModal form');
    if (form) form.reset();
    
    document.getElementById('new_amount_paid').value = '';
    document.getElementById('or_warning').classList.add('hidden');
    document.getElementById('or_counter').innerText = '0/18';
    
    // Disable submit button by default
    const btn = document.getElementById('process_submit_btn');
    if (btn) {
        btn.disabled = true;
        btn.classList.add('opacity-50', 'cursor-not-allowed');
    }

    // Reset Radio styling
    document.querySelectorAll('.status-radio').forEach(r => {
        r.disabled = true;
        r.checked = false;
    });
    
    const fullLbl = document.getElementById('lbl_pay_full');
    const partLbl = document.getElementById('lbl_pay_partial');
    if(fullLbl) fullLbl.classList.add('opacity-50', 'pointer-events-none');
    if(partLbl) partLbl.classList.add('opacity-50', 'pointer-events-none');
    
    const helper = document.getElementById('payment_status_helper');
    if(helper) helper.classList.remove('hidden');

    window.openModal('processNewModal');
};

// 5. CLOSE NEW APPLICANT MODAL
window.closeProcessNewModal = function() {
    window.closeModal('processNewModal');
};

// 6. PROCESS NEW APPLICANT AJAX HANDLER
window.handleFormSubmit = async function(e, form) {
    e.preventDefault();
    
    const submitBtn = document.getElementById('process_submit_btn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<svg class="w-4 h-4 animate-spin inline-block mr-1" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Verifying...';
    submitBtn.disabled = true;
    
    const formData = new FormData(form);
    formData.append('ajax_post', '1');
    
    try {
        const res = await fetch(window.location.href, { 
            method: 'POST', 
            body: formData, 
            headers: { 'X-Requested-With': 'XMLHttpRequest' } 
        });
        const data = await res.json();
        
        if (data.status === 'success') {
            if(window.showToast) window.showToast(data.message, 'success');
            window.closeProcessNewModal();
            window.triggerDynamicFetch(true);
        } else {
            if(window.showToast) window.showToast(data.message, 'error');
        }
    } catch (error) {
        if(window.showToast) window.showToast("Network Error while processing.", 'error');
    } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
};