// =========================================================
// STUDENT GLOBAL ENGINE (student.js)
// =========================================================

window.isFormDirty = false;
window.currentPollState = { status: "", balance: -1, has_active_request: null };
let pollInterval = null;

// -------------------------------------------------------------
// 0. GLOBAL OVERRIDES FOR LOCALHOST ALERTS (Unified Architecture)
// -------------------------------------------------------------
window.alert = function(msg) {
    window.showToast(msg, 'error');
};

window.confirm = function(msg) {
    console.warn("Native confirm blocked. Please use window.customConfirm instead.");
    return false;
};

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

// -------------------------------------------------------------
// 1. MOBILE SIDEBAR TOGGLE LOGIC
// -------------------------------------------------------------
window.toggleMenu = function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    if (sidebar) {
        sidebar.classList.toggle('-translate-x-full');
    }
    
    if (overlay) {
        if (overlay.classList.contains('hidden')) {
            overlay.classList.remove('hidden');
            setTimeout(() => {
                overlay.classList.remove('opacity-0');
            }, 10);
        } else {
            overlay.classList.add('opacity-0');
            setTimeout(() => {
                overlay.classList.add('hidden');
            }, 300);
        }
    }
};

// -------------------------------------------------------------
// 2. TOAST NOTIFICATIONS
// -------------------------------------------------------------
window.showToast = function(message, type = 'success') {
    // Fallbacks for different naming conventions across modules
    const container = document.getElementById('toast-container') || document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    const isError = type === 'error';
    const bgColor = isError ? 'bg-rose-50 border-rose-200 text-rose-800' : 'bg-emerald-50 border-emerald-200 text-emerald-800';
    const iconColor = isError ? 'text-rose-500' : 'text-emerald-500';
    const iconPath = isError ? 'M6 18L18 6M6 6l12 12' : 'M5 13l4 4L19 7';

    toast.className = `fixed top-6 right-6 p-4 rounded-md shadow-2xl z-[9999] font-bold text-sm ${bgColor} transform transition-all duration-300 translate-x-full opacity-0 flex items-center gap-3`;
    toast.innerHTML = `<svg class="w-5 h-5 shrink-0 ${iconColor}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${iconPath}"/></svg> <span>${message}</span>`;
    
    container.appendChild(toast);
    requestAnimationFrame(() => toast.classList.remove('translate-x-full', 'opacity-0'));
    setTimeout(() => {
        toast.classList.add('translate-x-full', 'opacity-0');
        setTimeout(() => toast.remove(), 300);
    }, 4000);
};

// -------------------------------------------------------------
// 3. DOM REFRESHER & COMPONENT RE-BINDING
// -------------------------------------------------------------
window.updateDOM = function(newHtml) {
    const root = document.getElementById('spa-content-root');
    if(root) {
        root.outerHTML = newHtml;
        window.initSPAEngine(); 
        window.bindDirtyStates();
        
        // Re-trigger module-specific initializers if they exist on the current page
        if(typeof window.initLocationAPI === 'function') window.initLocationAPI();
        if(typeof window.initSchoolSearch === 'function') window.initSchoolSearch();
        if(typeof window.checkRequirements === 'function') window.checkRequirements();
        if(typeof window.initBankDropdown === 'function') window.initBankDropdown();
        if(typeof window.initMiscLogic === 'function') window.initMiscLogic();
        if(typeof window.updateSelectedFees === 'function') window.updateSelectedFees();
    }
};

// -------------------------------------------------------------
// 4. SPA FORM SUBMISSION ENGINE (NO-REFRESH FORMS)
// -------------------------------------------------------------
window.initSPAEngine = function() {
    document.querySelectorAll('form').forEach(form => {
        if (form.dataset.spaBound || form.dataset.ignoreAjax === "true") return;
        form.dataset.spaBound = 'true';

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            window.isFormDirty = false;

            const submitBtn = form.querySelector('button[type="submit"]:focus') || form.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn ? submitBtn.innerHTML : 'Submit';
            
            if (submitBtn) {
                submitBtn.innerHTML = '<svg class="w-4 h-4 animate-spin inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Processing...';
                submitBtn.disabled = true;
            }

            const formData = new FormData(form);
            formData.append('ajax_post', '1');
            if (e.submitter && e.submitter.name) formData.append(e.submitter.name, e.submitter.value || '1');
            
            try {
                const response = await fetch(window.location.href, { method: 'POST', body: formData });
                const data = await response.json();
                
                window.showToast(data.message, data.status);
                if (data.new_html) window.updateDOM(data.new_html);
                
            } catch (err) {
                console.error('Submission Error:', err);
                window.showToast('A network error occurred. Please try again.', 'error');
                if (submitBtn) { submitBtn.innerHTML = originalBtnText; submitBtn.disabled = false; }
            }
        });
    });
};

// -------------------------------------------------------------
// 5. LIVE BACKGROUND POLLING
// -------------------------------------------------------------
window.startRealtimePolling = function() {
    if (pollInterval) clearInterval(pollInterval);
    
    pollInterval = setInterval(async () => {
        if (window.isFormDirty) return; // Pause sync if user is typing
        try {
            const res = await fetch(window.location.pathname + '?live_poll=1');
            if (!res.ok) return;
            const data = await res.json();
            
            if (data.status !== window.currentPollState.status || 
                data.remaining_balance !== window.currentPollState.balance || 
                data.has_active_request !== window.currentPollState.has_active_request) {
                
                window.currentPollState.status = data.status;
                window.currentPollState.balance = data.remaining_balance;
                window.currentPollState.has_active_request = data.has_active_request;
                
                if (data.new_html) window.updateDOM(data.new_html);
            }
        } catch (e) {}
    }, 5000); 
};

// -------------------------------------------------------------
// 6. UTILITIES (Dirty State & Scroll)
// -------------------------------------------------------------
window.bindDirtyStates = function() {
    document.querySelectorAll('input, select, textarea').forEach(input => {
        if (input.dataset.dirtyBound) return;
        input.dataset.dirtyBound = 'true';
        input.addEventListener('focus', () => window.isFormDirty = true);
        input.addEventListener('input', () => window.isFormDirty = true);
        input.addEventListener('change', () => window.isFormDirty = true);
        input.addEventListener('blur', () => setTimeout(() => window.isFormDirty = false, 300));
    });
};

window.scrollToTop = function() { 
    const mainScrollArea = document.getElementById('mainScrollArea');
    if(mainScrollArea) mainScrollArea.scrollTo({ top: 0, behavior: 'smooth' }); 
};

// -------------------------------------------------------------
// 7. INITIALIZATION (DOM READY)
// -------------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    window.initSPAEngine();
    window.bindDirtyStates();
    window.startRealtimePolling();
    
    // BIND SIDEBAR TOGGLE BUTTONS
    const mobileBtn = document.getElementById('mobileMenuBtn');
    const closeBtn = document.getElementById('closeMenuBtn');
    const overlay = document.getElementById('sidebarOverlay');
    
    if (mobileBtn) mobileBtn.addEventListener('click', window.toggleMenu);
    if (closeBtn) closeBtn.addEventListener('click', window.toggleMenu);
    if (overlay) overlay.addEventListener('click', window.toggleMenu);
    
    // FLOATING BACK TO TOP BUTTON LOGIC
    const mainScrollArea = document.getElementById('mainScrollArea');
    const floatingBtn = document.getElementById('floatingBackToTop');
    if(mainScrollArea && floatingBtn) {
        mainScrollArea.addEventListener('scroll', () => {
            if (mainScrollArea.scrollTop > 400) {
                floatingBtn.classList.remove('opacity-0', 'pointer-events-none', 'translate-y-4');
                floatingBtn.classList.add('opacity-100', 'pointer-events-auto', 'translate-y-0');
            } else {
                floatingBtn.classList.add('opacity-0', 'pointer-events-none', 'translate-y-4');
                floatingBtn.classList.remove('opacity-100', 'pointer-events-auto', 'translate-y-0');
            }
        });
    }
});