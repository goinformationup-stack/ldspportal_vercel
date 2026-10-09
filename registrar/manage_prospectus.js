// =========================================================
// MANAGE PROSPECTUS SPECIFIC LOGIC
// =========================================================

let lastDataString = '';

window.toggleAccordion = function(contentId, iconId) {
    const content = document.getElementById(contentId);
    const icon = document.getElementById(iconId);
    if (!content) return;
    
    if (content.classList.contains('hidden')) { 
        content.classList.remove('hidden'); 
        if (icon) icon.style.transform = 'rotate(180deg)'; 
    } else { 
        content.classList.add('hidden'); 
        if (icon) icon.style.transform = 'rotate(0deg)'; 
    }
};

window.downloadAsPDF = function(programName, year) {
    const element = document.getElementById('documentCanvas');
    element.classList.add('pdf-generating');

    const opt = {
        margin: 0,
        filename: `Prospectus_${programName}_${year}.pdf`,
        image: { type: 'jpeg', quality: 1.0 },
        html2canvas: { 
            scale: 2,
            useCORS: true,
            letterRendering: true
        },
        jsPDF: { 
            unit: 'mm', 
            format: 'a4', 
            orientation: 'portrait' 
        }
    };

    html2pdf().set(opt).from(element).save().then(() => {
        element.classList.remove('pdf-generating');
    });
};

window.triggerDynamicFetch = async function() {
    // Prevent syncing while modal is open
    if (window.isRegistrarModalOpen) return;

    const f_program_id = document.getElementById('f_program_id')?.value || '';
    const year_selector = document.getElementById('year_selector')?.value || '';
    const status = document.querySelector('input[name="status"]')?.value || 'active';

    try {
        const url = `manage_prospectus.php?api_refresh=1&program_id=${encodeURIComponent(f_program_id)}&year=${encodeURIComponent(year_selector)}&status=${encodeURIComponent(status)}`;
        const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        
        if (!response.ok) return;
        
        const data = await response.json();
        const newDataString = JSON.stringify(data);
        if (newDataString === lastDataString) return; 
        lastDataString = newDataString;

        // Update DOM components safely
        const currContainer = document.getElementById('curriculum_container');
        const pdfContainer = document.getElementById('pdf_actions_container');
        const printUnits = document.getElementById('print_total_units');

        if (currContainer && data.curriculum_html) currContainer.innerHTML = data.curriculum_html;
        if (pdfContainer && data.pdf_html) pdfContainer.innerHTML = data.pdf_html;
        if (printUnits && data.total_units !== undefined) printUnits.innerText = data.total_units;
        
    } catch (err) {
        console.error("Auto-sync error:", err);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('curriculum_container')) {
        setInterval(() => window.triggerDynamicFetch(), 5000);
    }
});