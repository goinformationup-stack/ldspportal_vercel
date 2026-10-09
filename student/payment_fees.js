// =========================================================
// PAYMENT & FEES LOGIC (STRICT SINGLETON ENGINE)
// =========================================================

window.isPaymentModalOpen = false;

window.checkFormDirty = function() {
    if (document.querySelector('form[data-submitting="true"]')) return true;

    const active = document.activeElement;
    if (active && ['INPUT', 'SELECT', 'TEXTAREA'].includes(active.tagName)) return true;

    const orInput = document.getElementById('or_number_input');
    if (orInput && orInput.value.trim() !== '') return true;

    const bankSelect = document.getElementById('paid_through_select');
    if (bankSelect && bankSelect.value !== '') return true;

    const fileInput = document.getElementById('file-upload');
    if (fileInput && fileInput.files && fileInput.files.length > 0) return true;

    if (document.querySelectorAll('div[id^="float_"]:not(.hidden)').length > 0) return true;

    return false;
};

window.showToast = function(message, type = 'success') {
    // FIX: Guard against empty/blank messages to prevent duplicate empty toast bubbles
    if (!message || String(message).trim() === '') return; 

    let container = document.getElementById('toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    const isSuccess = (type === 'success');
    const isInfo = (type === 'info');

    let bgClass = 'bg-rose-50 border-rose-200 text-rose-800';
    let iconColor = 'text-rose-600';
    let iconPath = 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z';

    if (isSuccess) {
        bgClass = 'bg-emerald-50 border-emerald-200 text-emerald-800';
        iconColor = 'text-emerald-600';
        iconPath = 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z';
    } else if (isInfo) {
        bgClass = 'bg-blue-50 border-blue-200 text-blue-800';
        iconColor = 'text-blue-600';
        iconPath = 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
    }

    toast.className = `flex items-center gap-3 px-4 py-3 rounded-xl shadow-xl border ${bgClass} font-bold text-xs transition-all duration-300 translate-x-full opacity-0 pointer-events-auto mt-2`;
    toast.innerHTML = `
        <svg class="w-4 h-4 ${iconColor} shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="${iconPath}" />
        </svg>
        <span>${message}</span>
    `;

    container.appendChild(toast);
    requestAnimationFrame(() => toast.classList.remove('translate-x-full', 'opacity-0'));

    setTimeout(() => {
        toast.classList.add('translate-x-full', 'opacity-0');
        setTimeout(() => toast.remove(), 400);
    }, 3500);
};

window.pollPaymentState = async function() {
    if (window.isPaymentModalOpen || window.checkFormDirty()) return;

    try {
        const response = await fetch(window.location.href.split('?')[0] + '?api_refresh=1', {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Cache-Control': 'no-cache' }
        });

        if (!response.ok) return;
        const data = await response.json();

        const currentStatusInput = document.getElementById('current_enrollment_status');
        const currentBalanceInput = document.getElementById('current_balance');
        const currentStatus = currentStatusInput ? currentStatusInput.value : '';
        const currentBalance = currentBalanceInput ? currentBalanceInput.value : '';

        if (data.final_status === currentStatus && String(data.balance) === String(currentBalance)) {
            return; 
        }

        const root = document.getElementById('spa-content-root');
        if (root && data.new_html) {
            if (data.final_status !== currentStatus) {
                if (data.final_status === 'Pending Registrar') window.showToast("Payment Verified by Accounting!", "success");
                else if (data.final_status === 'Enrolled') window.showToast("Congratulations! You are officially enrolled.", "success");
                else if (data.final_status === 'Payment Rejected') window.showToast("Attention: Payment was rejected.", "error");
            }

            root.style.transition = 'opacity 0.2s ease-out';
            root.style.opacity = '0';
            
            setTimeout(() => {
                root.innerHTML = data.new_html;
                window.rebindAll();
                root.style.opacity = '1';
            }, 200);
        }
    } catch (e) { }
};

// =========================================================================
// HARD CLEAN FIX: BYPASS STUDENT.JS AND FORCE SINGLE EXECUTION
// =========================================================================
window.submitPaymentForm = async function(e, form) {
    e.preventDefault();
    e.stopImmediatePropagation(); // FIX: Completely blocks other potential global listeners from dual-submitting

    if (form.dataset.submitting === 'true') return false;
    form.dataset.submitting = 'true';

    // FIX: Add `e.submitter` fallback to correctly map external modal buttons bound via `form="enrollmentForm"`
    const btn = e.submitter || form.querySelector('button[type="submit"]');
    const originalHTML = btn ? btn.innerHTML : 'Submit';

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<svg class="w-4 h-4 animate-spin inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Processing...`;
    }

    Array.from(form.elements).forEach(el => {
        if (el.tagName !== 'FIELDSET') el.style.pointerEvents = 'none';
    });

    const formData = new FormData(form);
    formData.append('ajax_post', '1');
    
    if (btn && btn.name) {
        formData.append(btn.name, '1'); // Passes exact button name clicked to backend reliably now
    }

    try {
        const response = await fetch(window.location.href.split('?')[0], {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const data = await response.json();

        if (data.status === 'success') {
            const root = document.getElementById('spa-content-root');
            if (root && data.new_html) {
                root.innerHTML = data.new_html;
                window.rebindAll();
            }
            
            if (data.message && String(data.message).trim() !== '') { // FIX: Guard check
                window.showToast(data.message, "success");
            }
            
            window.isPaymentModalOpen = false;
            const modal = document.getElementById('agreementModal');
            if(modal) { modal.classList.add('hidden'); modal.classList.remove('flex'); }
        } else {
            if (data.message && String(data.message).trim() !== '') {
                window.showToast(data.message, "error");
            } else {
                window.showToast("Failed to process payment.", "error");
            }
            
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
        }
    } catch (err) {
        window.showToast("Connection failed. Please retry.", "error");
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
    } finally {
        if (document.body.contains(form)) {
            form.dataset.submitting = 'false';
            Array.from(form.elements).forEach(el => {
                if (el.tagName !== 'FIELDSET') el.style.pointerEvents = 'auto';
            });
        }
    }
    return false;
};

window.updateSelectedFees = function() {
    let total = 0; 
    let intent = [];
    document.querySelectorAll('.fee-selector:checked').forEach(cb => {
        total += parseFloat(cb.getAttribute('data-amount') || 0);
        intent.push(cb.value);
    });
    
    const amountInput = document.getElementById('amount_paid');
    const intentInput = document.getElementById('payment_intent');
    const submitBtn = document.getElementById('btn_submit_payment');
    
    if (amountInput) amountInput.value = total.toFixed(2);
    if (intentInput) intentInput.value = intent.join(' , ');
    
    if (submitBtn) {
        if (total > 0) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = `Submit Payment (₱${total.toLocaleString('en-US', {minimumFractionDigits: 2})}) <svg class="w-4 h-4 opacity-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>`;
        } else {
            submitBtn.disabled = true;
            submitBtn.innerHTML = `Select a Fee to Pay`;
        }
    }
};

window.initBankDropdown = function() {
    const select = document.getElementById('paid_through_select');
    const card = document.getElementById('selected_bank_card');
    
    if (!select || !card || select.dataset.boundBank) return;
    select.dataset.boundBank = 'true';
    
    select.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (!option.value) { 
            card.classList.add('hidden'); 
            return; 
        }
        
        const name = option.value;
        const accName = option.dataset.accname;
        const accNum = option.dataset.accnum;
        const link = option.dataset.link;
        const isLinkOnly = (accName === 'N/A' && accNum === 'N/A');
        
        let html = `<div class="flex flex-col gap-2"><h5 class="text-[10px] font-black text-[#00205b] uppercase tracking-widest mb-1 border-b border-[#00205b]/20 pb-1">Payment Instructions: ${name}</h5>`;
        
        if (!isLinkOnly) {
            html += `<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4 mt-1"><div><p class="text-[8px] uppercase font-bold text-slate-500 tracking-widest">Account Name</p><p class="text-xs font-bold text-slate-800">${accName}</p></div><div><p class="text-[8px] uppercase font-bold text-slate-500 tracking-widest">Account Number</p><p class="text-sm font-mono font-black text-[#00205b] break-all">${accNum}</p></div></div>`;
        } else {
            html += `<div class="mt-1"><p class="text-xs font-black text-[#00205b]">Direct Payment Link</p><p class="text-[9px] font-medium text-slate-500">Click the link below to pay your fee.</p></div>`;
        }
        
        if (link && link !== '') {
            html += `<div class="mt-3"><a href="${link}" target="_blank" class="inline-flex items-center justify-center w-full gap-1.5 text-[10px] uppercase tracking-widest font-black text-indigo-700 bg-white hover:bg-[#00205b] hover:text-white border border-[#00205b]/20 py-2.5 px-4 rounded-md transition-all shadow-sm focus:outline-none"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg> Open Payment Link</a></div>`;
        }
        html += `</div>`;
        card.innerHTML = html; 
        card.classList.remove('hidden');
    });
};

window.initMiscLogic = function() {
    document.querySelectorAll('.misc-plan-toggle').forEach(toggle => {
        if (toggle.dataset.boundMisc) return;
        toggle.dataset.boundMisc = 'true';
        
        toggle.addEventListener('change', function() {
            const target = this.dataset.target;
            const floatCard = document.getElementById('float_' + target);
            const amtDisplay = document.getElementById('amt_' + target);
            const cb = document.getElementById('cb_' + target);
            const cbAmt = document.getElementById('cbamt_' + target);
            const lbl = document.getElementById('lbl_' + target);
            const basenameElem = document.getElementById('basename_' + target);
            const basename = basenameElem ? basenameElem.textContent.trim() : '';

            if (this.value === 'partial') {
                if(floatCard) floatCard.classList.remove('hidden');
                if(cb) cb.checked = false; 
                window.updateSelectedFees();
            } else {
                if(floatCard) floatCard.classList.add('hidden');
                const fullAmt = parseFloat(this.dataset.full);
                if (amtDisplay) amtDisplay.textContent = '₱' + fullAmt.toLocaleString('en-US', {minimumFractionDigits: 2});
                if (cb) { cb.dataset.amount = fullAmt; cb.value = basename + ' (Full Payment)'; cb.checked = true; }
                if (cbAmt) cbAmt.textContent = '₱' + fullAmt.toLocaleString('en-US', {minimumFractionDigits: 2});
                if (lbl) lbl.textContent = 'FULL';
                window.updateSelectedFees();
            }
        });
    });

    document.querySelectorAll('.confirm-partial-btn').forEach(btn => {
        if (btn.dataset.boundBtn) return;
        btn.dataset.boundBtn = 'true';
        
        btn.addEventListener('click', function() {
            const target = this.dataset.target;
            const floatCard = document.getElementById('float_' + target);
            const toggle = document.querySelector(`.misc-plan-toggle[data-target="${target}"][value="partial"]`);
            if(!toggle) return;

            const amtDisplay = document.getElementById('amt_' + target);
            const cb = document.getElementById('cb_' + target);
            const cbAmt = document.getElementById('cbamt_' + target);
            const lbl = document.getElementById('lbl_' + target);
            const basenameElem = document.getElementById('basename_' + target);
            const basename = basenameElem ? basenameElem.textContent.trim() : '';

            const firstAmt = parseFloat(toggle.dataset.first);
            if(floatCard) floatCard.classList.add('hidden');
            if (amtDisplay) amtDisplay.textContent = '₱' + firstAmt.toLocaleString('en-US', {minimumFractionDigits: 2});
            if (cb) { cb.dataset.amount = firstAmt; cb.value = basename + ' (1st Installment)'; cb.checked = true; }
            if (cbAmt) cbAmt.textContent = '₱' + firstAmt.toLocaleString('en-US', {minimumFractionDigits: 2});
            if (lbl) lbl.textContent = '1ST INST.';
            window.updateSelectedFees();
        });
    });
};

window.rebindAll = function() {
    window.initBankDropdown();
    window.initMiscLogic();
    window.updateSelectedFees();
};

window.handleReceiptUpload = function(input, previewCardId) {
    if (!input.files || input.files.length === 0) return;
    const file = input.files[0];

    if (file.size > 5 * 1024 * 1024) {
        const toast = document.getElementById('file-size-toast');
        if (toast) {
            toast.classList.remove('hidden');
            setTimeout(() => {
                toast.classList.remove('translate-y-[-20px]', 'opacity-0');
                toast.classList.add('translate-y-0', 'opacity-100');
            }, 10);
            setTimeout(() => {
                toast.classList.remove('translate-y-0', 'opacity-100');
                toast.classList.add('translate-y-[-20px]', 'opacity-0');
                setTimeout(() => { toast.classList.add('hidden'); }, 300);
            }, 4000);
        }
        input.value = '';
        return;
    }

    const card = document.getElementById(previewCardId);
    const dropzone = document.getElementById('upload_container_receipt');
    if (card) {
        card.querySelector('.filename-text').textContent = file.name;
        card.querySelector('.filesize-text').textContent = (file.size / (1024 * 1024)).toFixed(2) + ' MB';
        card.classList.remove('hidden');
    }
    if (dropzone) dropzone.classList.add('hidden');
};

window.removeReceiptFile = function(inputId, cardId) {
    const input = document.getElementById(inputId);
    const card = document.getElementById(cardId);
    const dropzone = document.getElementById('upload_container_receipt');

    if (input) input.value = '';
    if (card) card.classList.add('hidden');
    if (dropzone) dropzone.classList.remove('hidden');
};

window.openShiftModal = function() {
    window.isPaymentModalOpen = true;
    const m = document.getElementById('shiftModal');
    if (m) { m.classList.remove('hidden'); m.classList.add('flex'); setTimeout(() => m.classList.add('modal-active'), 10); }
};
window.closeShiftModal = function() {
    window.isPaymentModalOpen = false;
    const m = document.getElementById('shiftModal');
    if (m) { m.classList.remove('modal-active'); setTimeout(() => { m.classList.add('hidden'); m.classList.remove('flex'); }, 300); }
};
window.openAgreementModal = function() {
    const form = document.getElementById('enrollmentForm');
    if (form && form.checkValidity()) {
        window.isPaymentModalOpen = true;
        const m = document.getElementById('agreementModal');
        if (m) { m.classList.remove('hidden'); m.classList.add('flex'); setTimeout(() => m.classList.add('modal-active'), 10); }
    } else if (form) {
        form.reportValidity();
    }
};
window.closeAgreementModal = function() {
    window.isPaymentModalOpen = false;
    const m = document.getElementById('agreementModal');
    if (m) { m.classList.remove('modal-active'); setTimeout(() => { m.classList.add('hidden'); m.classList.remove('flex'); }, 300); }
};
window.applyShiftSelection = function() {
    const select = document.getElementById('modal_program_select');
    const targetInput = document.getElementById('target_program');
    const textDisplay = document.getElementById('display_target_program_text');
    const warning = document.getElementById('shift_warning');
    const currentBase = document.getElementById('current_program_hidden')?.value || '';

    if (select && targetInput && textDisplay) {
        targetInput.value = select.value;
        textDisplay.textContent = select.value;
        if (select.value !== currentBase) {
            textDisplay.classList.add('text-amber-600');
            textDisplay.classList.remove('text-[#00205b]');
            if (warning) { warning.classList.remove('hidden'); warning.classList.add('flex'); }
        } else {
            textDisplay.classList.remove('text-amber-600');
            textDisplay.classList.add('text-[#00205b]');
            if (warning) { warning.classList.add('hidden'); warning.classList.remove('flex'); }
        }
    }
    window.closeShiftModal();
};

let currentZoom = 1;
let isDragging = false;
let startX, startY, translateX = 0, translateY = 0;

window.viewLocalDocument = function(url, type) {
    const modal = document.getElementById('doc-modal');
    const img = document.getElementById('doc-image');
    const iframe = document.getElementById('doc-iframe');
    
    if(!modal) return;
    window.isPaymentModalOpen = true;
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => { modal.classList.remove('opacity-0'); }, 10);
    
    currentZoom = 1; translateX = 0; translateY = 0;
    if(img) img.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentZoom})`;

    if (type.startsWith('image/')) { 
        if(img) { img.src = url; img.classList.remove('hidden'); }
        if(iframe) iframe.classList.add('hidden');
    } else {
        if(iframe) { iframe.src = url; iframe.classList.remove('hidden'); }
        if(img) img.classList.add('hidden');
    }
};

const docContainer = document.getElementById('doc-container');
if(docContainer) {
    docContainer.addEventListener('wheel', (e) => {
        const img = document.getElementById('doc-image');
        if (img && !img.classList.contains('hidden')) {
            e.preventDefault();
            currentZoom += (e.deltaY > 0) ? -0.1 : 0.1;
            if (currentZoom < 0.5) currentZoom = 0.5;
            if (currentZoom > 4) currentZoom = 4;
            img.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentZoom})`;
        }
    }, { passive: false });
}

window.startDrag = function(e) {
    e.preventDefault();
    isDragging = true;
    startX = e.clientX - translateX;
    startY = e.clientY - translateY;
    const img = document.getElementById('doc-image');
    if(img) { img.classList.add('cursor-grabbing'); img.classList.remove('cursor-grab'); }
}
window.addEventListener('mousemove', (e) => {
    if (!isDragging) return;
    translateX = e.clientX - startX;
    translateY = e.clientY - startY;
    const img = document.getElementById('doc-image');
    if(img) img.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentZoom})`;
});
window.addEventListener('mouseup', () => {
    isDragging = false;
    const img = document.getElementById('doc-image');
    if(img) { img.classList.remove('cursor-grabbing'); img.classList.add('cursor-grab'); }
});

window.closeDocModal = function() {
    window.isPaymentModalOpen = false;
    const modal = document.getElementById('doc-modal');
    if (modal) {
        modal.classList.add('opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.getElementById('doc-iframe').src = '';
            document.getElementById('doc-image').src = '';
        }, 300);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    window.rebindAll();
    setInterval(() => window.pollPaymentState(), 3500);
});