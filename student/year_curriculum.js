// =========================================================
// YEAR CURRICULUM LOGIC (year_curriculum.js)
// =========================================================

let currentScale = 1.0;

window.zoomCurriculum = function(step) {
    currentScale += step;
    if (currentScale < 0.5) currentScale = 0.5;
    if (currentScale > 1.5) currentScale = 1.5;
    
    const canvas = document.getElementById('documentCanvas');
    const display = document.getElementById('zoomLevelDisplay');
    
    if (canvas) canvas.style.transform = `scale(${currentScale})`;
    if (display) display.innerText = Math.round(currentScale * 100) + '%';
};

window.triggerPrint = function(type = 'curriculum') {
    setTimeout(() => window.print(), 100);
};

window.downloadAsPDF = function(programName, year) {
    const element = document.getElementById('documentCanvas');
    if (!element) return;
    
    element.classList.add('pdf-generating');

    const opt = {
        margin:       0,
        filename:     `Curriculum_${programName.replace(/[^a-zA-Z0-9]/g, '_')}_${year}.pdf`,
        image:        { type: 'jpeg', quality: 1.0 },
        html2canvas:  { scale: 2, useCORS: true, letterRendering: true },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    html2pdf().set(opt).from(element).save().then(() => {
        element.classList.remove('pdf-generating');
    });
};