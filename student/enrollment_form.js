// =========================================================
// MASTER PROFILE (enrollment_form.js)
// =========================================================

// =========================================================
// DOCUMENT OVERLAY (MESSENGER-STYLE ZOOM / PAN) LOGIC
// =========================================================
let currentZoom = 1;
let isDragging = false;
let startX, startY, translateX = 0, translateY = 0;

window.openDocModal = function(url, type) {
    const modal = document.getElementById('doc-modal');
    const img = document.getElementById('doc-image');
    const iframe = document.getElementById('doc-iframe');
    
    if(!modal) return;
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    
    setTimeout(() => { modal.classList.remove('opacity-0'); }, 10);
    
    currentZoom = 1; translateX = 0; translateY = 0;
    window.updateTransform();

    if (type.startsWith('image/')) { 
        if(img) {
            img.src = url;
            img.classList.remove('hidden');
        }
        if(iframe) iframe.classList.add('hidden');
    } else {
        if(iframe) {
            iframe.src = url;
            iframe.classList.remove('hidden');
        }
        if(img) img.classList.add('hidden');
    }
};

window.zoomDoc = function(step) {
    currentZoom += step;
    if (currentZoom < 0.5) currentZoom = 0.5;
    if (currentZoom > 4) currentZoom = 4;
    window.updateTransform();
}

window.updateTransform = function() {
    const img = document.getElementById('doc-image');
    if(img) img.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentZoom})`;
}

const docContainer = document.getElementById('doc-container');
if(docContainer) {
    docContainer.addEventListener('wheel', (e) => {
        const img = document.getElementById('doc-image');
        if (img) {
            if (!img.classList.contains('hidden')) {
                e.preventDefault();
                if (e.deltaY > 0) {
                    window.zoomDoc(-0.1);
                } else {
                    window.zoomDoc(0.1);
                }
            }
        }
    }, { passive: false });
}

window.startDrag = function(e) {
    e.preventDefault();
    isDragging = true;
    startX = e.clientX - translateX;
    startY = e.clientY - translateY;
    const img = document.getElementById('doc-image');
    if(img) {
        img.classList.add('cursor-grabbing');
        img.classList.remove('cursor-grab');
    }
}

window.addEventListener('mousemove', (e) => {
    if (!isDragging) return;
    translateX = e.clientX - startX;
    translateY = e.clientY - startY;
    window.updateTransform();
});

window.addEventListener('mouseup', () => {
    isDragging = false;
    const img = document.getElementById('doc-image');
    if(img) {
        img.classList.remove('cursor-grabbing');
        img.classList.add('cursor-grab');
    }
});