// =========================================================
// PORTAL SETTINGS SPECIFIC LOGIC
// =========================================================

window.isFormDirty = false;

document.addEventListener('focusin', (e) => {
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) { window.isFormDirty = true; }
});
document.addEventListener('focusout', (e) => {
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) {
        setTimeout(() => { if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) { window.isFormDirty = false; } }, 150);
    }
});

// Setup event listeners on DOM load
document.addEventListener('DOMContentLoaded', () => {
    const addTypeInput = document.getElementById('new_admission_type');
    if (addTypeInput) {
        addTypeInput.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                window.addAdmissionType();
            }
        });
    }
});


// --- DYNAMIC ADMISSION TYPES LOGIC ---
window.addAdmissionType = function() {
    window.isFormDirty = true;
    const input = document.getElementById('new_admission_type');
    if (!input) return;

    // Strip commas to prevent issues with array implode/explode via SQL
    let val = input.value.trim().replace(/,/g, ''); 
    if (!val) return;

    // Prevent duplicate entries
    const existingInputs = document.querySelectorAll('input[name="available_types[]"]');
    for (let i = 0; i < existingInputs.length; i++) {
        if (existingInputs[i].value.toLowerCase() === val.toLowerCase()) {
            if (typeof window.showToast === 'function') {
                window.showToast('This admission type already exists.', 'error');
            } else {
                alert('This admission type already exists.');
            }
            return;
        }
    }

    const container = document.getElementById('admission-types-container');
    const item = document.createElement('div');
    item.className = 'admission-type-item flex items-stretch relative group';
    
    // Safely encode characters for HTML injecting
    const safeVal = val.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");

    item.innerHTML = `
        <input type="hidden" name="available_types[]" value="${safeVal}">
        <label class="cursor-pointer relative flex">
            <input type="checkbox" name="accepted_types[]" value="${safeVal}" checked class="peer sr-only">
            <div class="px-3 py-1.5 rounded-l-lg border border-slate-200 border-r-0 bg-white text-slate-500 font-bold text-[10px] uppercase tracking-wide transition-all duration-200 peer-checked:border-emerald-500 peer-checked:bg-emerald-50 peer-checked:text-emerald-700 shadow-sm hover:border-emerald-300 flex items-center gap-1.5">
                <svg class="w-3 h-3 opacity-0 peer-checked:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                <span>${safeVal}</span>
            </div>
        </label>
        <button type="button" onclick="removeAdmissionType(this)" class="px-2 py-1.5 bg-white border border-slate-200 rounded-r-lg text-rose-400 hover:text-rose-600 hover:bg-rose-50 transition-colors shadow-sm focus:outline-none flex items-center justify-center" title="Delete Type">
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    `;
    
    container.appendChild(item);
    input.value = ''; // clear input field
};

window.removeAdmissionType = function(btn) {
    window.isFormDirty = true;
    btn.closest('.admission-type-item').remove();
};


// --- STEPPER LOGIC FOR ACADEMIC TERM ---
window.stepTerm = function(type, direction) {
    window.isFormDirty = true;
    
    let yearInput, semInput, displayYear, displaySem;
    const terms = ['1st Semester', '2nd Semester', 'Summer'];
    
    // Target the specific section being updated
    if (type === 'general') {
        yearInput = document.getElementById('hidden_general_year');
        semInput = document.getElementById('hidden_general_sem');
        displayYear = document.getElementById('display_general_year');
        displaySem = document.getElementById('display_general_sem');
    } else if (type === 'enrollment') {
        yearInput = document.getElementById('hidden_enrollment_year');
        semInput = document.getElementById('hidden_enrollment_sem');
        displayYear = document.getElementById('display_enrollment_year');
        displaySem = document.getElementById('display_enrollment_sem');
    }
    
    if (!yearInput || !semInput) return;
    
    // Read current state
    let currentYearStr = yearInput.value || "2024-2025";
    let currentSem = semInput.value || "1st Semester";
    
    // Parse starting year (e.g., "2025" from "2025-2026")
    let startYear = parseInt(currentYearStr.split('-')[0]);
    if (isNaN(startYear)) startYear = new Date().getFullYear();
    
    // Find where we currently are in the sequence
    let currentSemIndex = terms.indexOf(currentSem);
    if (currentSemIndex === -1) currentSemIndex = 0;
    
    // Calculate Next or Previous
    if (direction === 1) { // Next
        currentSemIndex++;
        if (currentSemIndex > 2) { // Passed Summer, jump to next year's 1st Sem
            currentSemIndex = 0;
            startYear++;
        }
    } else { // Prev
        currentSemIndex--;
        if (currentSemIndex < 0) { // Passed 1st Sem, jump back to previous year's Summer
            currentSemIndex = 2;
            startYear--;
        }
    }
    
    // Reconstruct the strings
    let nextYearStr = `${startYear}-${startYear + 1}`;
    let nextSemStr = terms[currentSemIndex];
    
    // Update the DOM
    yearInput.value = nextYearStr;
    semInput.value = nextSemStr;
    displayYear.innerText = nextYearStr;
    displaySem.innerText = nextSemStr;
};


// --- STEPPER LOGIC FOR PROCESS STEPS ---
window.addStep = function() {
    window.isFormDirty = true;
    const container = document.getElementById('steps-container');
    if (!container) return;

    const currentSteps = container.querySelectorAll('.step-card').length;
    const newNum = currentSteps + 1;
    
    const newCard = document.createElement('div');
    newCard.className = 'step-card bg-white/60 p-3.5 relative group transition-all duration-300 hover:shadow-md z-20 border border-slate-200 rounded-xl';
    newCard.innerHTML = `
        <div class="flex justify-between items-center mb-3 border-b border-slate-200/60 pb-2">
            <h4 class="font-black text-[#00205b] text-[10px] uppercase tracking-widest step-header drop-shadow-sm flex items-center gap-1.5">
                <div class="w-4 h-4 rounded bg-[#00205b]/10 flex items-center justify-center text-[#00205b] text-[9px]">${newNum}</div>
                Step ${newNum}
            </h4>
            <div class="flex gap-1.5 opacity-80 group-hover:opacity-100 transition-opacity">
                <button type="button" onclick="window.removeSpecificStep(this)" class="text-rose-500 hover:text-white bg-rose-50 hover:bg-rose-500 p-1 rounded border border-rose-200 hover:border-rose-600 shadow-sm transition-colors cursor-pointer" title="Remove Step"><svg class="w-3 h-3 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button>
            </div>
        </div>
        <div class="space-y-2.5">
            <div><label class="block text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1 drop-shadow-sm">Title</label><input type="text" name="step_title[]" required class="w-full bg-white border border-slate-300 rounded-lg px-2 py-1.5 text-xs font-bold text-[#00205b] focus:outline-none focus:border-[#c5a02c]"></div>
            <div><label class="block text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1 drop-shadow-sm">Description</label><textarea name="step_desc[]" rows="2" class="w-full bg-white border border-slate-300 rounded-lg px-2 py-1.5 text-[11px] leading-relaxed text-slate-600 font-medium focus:outline-none focus:border-[#c5a02c]"></textarea></div>
        </div>
    `;
    container.appendChild(newCard);
};

window.removeSpecificStep = function(btn) {
    window.isFormDirty = true;
    const container = document.getElementById('steps-container');
    const steps = container.querySelectorAll('.step-card');
    if (steps.length <= 1) { 
        if(typeof window.showToast === 'function') window.showToast('You must have at least one step in the process overview.', 'error'); 
        return; 
    }
    
    btn.closest('.step-card').remove();
    
    // Renumber remaining steps to keep it clean
    container.querySelectorAll('.step-card').forEach((card, index) => {
        const newIdx = index + 1;
        card.querySelector('.step-header').innerHTML = `<div class="w-4 h-4 rounded bg-[#00205b]/10 flex items-center justify-center text-[#00205b] text-[9px]">${newIdx}</div> Step ${newIdx}`;
    });
};