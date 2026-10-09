window.openModal = function(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex'); 
    
    // Force a browser reflow so the transition animations trigger
    void modal.offsetWidth; 
    modal.classList.add('modal-active');
};

window.closeModal = function(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    modal.classList.remove('modal-active');
    
    // Wait for the CSS transition (opacity/transform) to finish before hiding
    setTimeout(() => {
        modal.classList.remove('flex'); 
        modal.classList.add('hidden');
        
        // Specific cleanup for the document viewer modal (if it exists on the page)
        if(modalId === 'documentModal') {
            const iframe = document.getElementById('docIframe');
            const img = document.getElementById('docImage');
            if (iframe) iframe.src = '';
            if (img) img.src = '';
        }
    }, 400); 
};

// -------------------------------------------------------------
// GLOBAL DOCUMENT VIEWER (Used by Admissions & Accounts)
// -------------------------------------------------------------
window.viewDocument = function(url) {
    // Only attempt if the modal exists on the current page
    if (!document.getElementById('documentModal')) return;

    openModal('documentModal');
    
    document.getElementById('docLoader').classList.remove('hidden');
    document.getElementById('docIframe').classList.add('hidden');
    document.getElementById('docImage').classList.add('hidden');
    document.getElementById('docError').classList.add('hidden');
    
    const downloadBtn = document.getElementById('docDownloadBtn');
    const fallbackLink = document.getElementById('docFallbackLink');
    if(downloadBtn) downloadBtn.href = url;
    if(fallbackLink) fallbackLink.href = url;
    
    const extension = url.split('.').pop().toLowerCase();
    const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    const docExts = ['pdf', 'txt'];
    
    if (imageExts.includes(extension)) {
        const img = document.getElementById('docImage');
        if(img) img.src = url;
    } else if (docExts.includes(extension)) {
        const iframe = document.getElementById('docIframe');
        if(iframe) iframe.src = url;
    } else {
        document.getElementById('docLoader').classList.add('hidden');
        const errBox = document.getElementById('docError');
        if(errBox) {
            errBox.classList.remove('hidden');
            errBox.classList.add('flex');
        }
    }
};

// -------------------------------------------------------------
// GLOBAL TOAST NOTIFICATION SYSTEM
// -------------------------------------------------------------
window.showToast = function(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return; // Fail silently if the page doesn't have a toast container
    
    const toast = document.createElement('div');
    const isSuccess = type === 'success';
    
    // Tailwind classes for success (emerald) or error (rose)
    const bgColor = isSuccess ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800';
    const iconPath = isSuccess ? 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
    const iconColor = isSuccess ? 'text-emerald-500' : 'text-rose-500';
    
    toast.className = `flex items-center gap-2 px-3 py-2 rounded-lg shadow-lg border ${bgColor} toast-enter z-[9999]`;
    toast.innerHTML = `
        <svg class="w-4 h-4 ${iconColor}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${iconPath}" />
        </svg>
        <span class="text-[11px] font-semibold tracking-wide">${message}</span>
    `;
    
    container.appendChild(toast);
    
    // Remove the toast after 3 seconds
    setTimeout(() => {
        toast.classList.replace('toast-enter', 'toast-exit');
        setTimeout(() => toast.remove(), 400); // Wait for exit animation
    }, 3000);
};