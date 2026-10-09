// =========================================================
// CURRICULUM READ-ONLY SPECIFIC LOGIC
// =========================================================

window.downloadAsPDF = function(programName, year) {
    const element = document.getElementById('documentCanvas');
    element.classList.add('pdf-generating');

    const opt = {
        margin: 0,
        filename: `Curriculum_${programName}_${year}.pdf`,
        image: { type: 'jpeg', quality: 1.0 },
        html2canvas: { scale: 2, useCORS: true, letterRendering: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    html2pdf().set(opt).from(element).save().then(() => {
        element.classList.remove('pdf-generating');
    });
};