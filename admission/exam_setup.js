// =========================================================
// EXAM SETUP & BUILDER LOGIC (SPA CONTROLLER)
// =========================================================

let questionIndex = 0;
let isModalOpen = false;

// Format seconds into exact Human-Readable string (e.g., "20 secs", "2 mins 30 secs")
window.formatSeconds = function(totalSec) {
    if (totalSec <= 0) return "0 secs";
    const hrs = Math.floor(totalSec / 3600);
    const mins = Math.floor((totalSec % 3600) / 60);
    const secs = totalSec % 60;
    
    let parts = [];
    if (hrs > 0) parts.push(hrs + (hrs === 1 ? " hr" : " hrs"));
    if (mins > 0) parts.push(mins + (mins === 1 ? " min" : " mins"));
    if (secs > 0) parts.push(secs + (secs === 1 ? " sec" : " secs"));
    
    return parts.join(" ");
};

// Calculate and update the exact total duration live from the MM:SS inputs
window.updateLiveDuration = function() {
    let totalSec = 0;
    const cards = document.querySelectorAll('.step-card');
    
    cards.forEach(card => {
        const minInput = card.querySelector('input[name*="[time_m]"]');
        const secInput = card.querySelector('input[name*="[time_s]"]');
        if (minInput && secInput) {
            const m = parseInt(minInput.value) || 0;
            const s = parseInt(secInput.value) || 0;
            totalSec += (m * 60) + s;
        }
    });
    
    const badge = document.getElementById('live_total_duration');
    if (badge) {
        badge.innerText = window.formatSeconds(totalSec);
    }
};

window.addNewQuestion = function() {
    const container = document.getElementById('questions-container');
    if (!container) return;

    const currentSteps = container.querySelectorAll('.step-card').length;
    const newNum = currentSteps + 1;
    
    const newCard = document.createElement('div');
    newCard.className = "bg-white/95 backdrop-blur-md border border-slate-200 rounded-xl shadow-lg p-5 md:p-7 border-t-[3px] border-t-[#c5a02c] step-card relative overflow-hidden transition-all duration-300";
    
    let optionsHtml = '';
    ['a','b','c','d'].forEach(opt => {
        optionsHtml += `
            <div class="p-3 bg-slate-50/80 rounded-xl border border-slate-200 shadow-sm transition-all hover:bg-white hover:border-[#00205b]/30 group flex flex-col justify-between">
                <label class="flex items-center gap-2 text-[#00205b] block text-[9px] font-black uppercase tracking-widest mb-2 drop-shadow-sm">
                    <span class="bg-slate-200 text-slate-700 px-2 py-0.5 rounded shadow-sm border border-slate-300 group-hover:bg-[#00205b] group-hover:text-white group-hover:border-[#00205b] transition-colors">${opt.toUpperCase()}</span> Option
                </label>
                <input type="text" name="q[${questionIndex}][${opt}]" required class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 mb-2 text-xs font-semibold text-slate-700 shadow-inner focus:outline-none focus:border-[#00205b] focus:ring-1 focus:ring-[#00205b]/20 transition-all" placeholder="Enter answer text...">
                
                <div class="relative overflow-hidden">
                    <input type="file" name="q_files[${questionIndex}][img_${opt}]" class="w-full text-[10px] text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-[9px] file:font-black file:uppercase file:tracking-widest file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer bg-white border border-slate-200 rounded-lg shadow-sm">
                </div>
            </div>
        `;
    });

    let correctKeyHtml = '';
    ['A','B','C','D'].forEach(opt => {
        correctKeyHtml += `
            <label class="cursor-pointer relative group flex-1">
                <input type="radio" name="q[${questionIndex}][correct]" value="${opt}" required class="hidden peer">
                <div class="w-full h-10 flex items-center justify-center rounded-lg border-2 border-slate-200 bg-white text-slate-400 font-black text-sm transition-all duration-200 peer-checked:bg-emerald-500 peer-checked:border-emerald-600 peer-checked:text-white peer-checked:shadow-[0_4px_10px_rgba(16,185,129,0.4)] shadow-sm hover:border-emerald-300">${opt}</div>
            </label>
        `;
    });

    newCard.innerHTML = `
        <div class="absolute right-0 top-0 w-32 h-32 bg-[#c5a02c]/5 rounded-bl-full pointer-events-none"></div>
        
        <div class="flex flex-col sm:flex-row justify-between sm:items-center mb-5 border-b border-slate-200/60 pb-3 gap-3 relative z-10">
            <span class="step-header text-[10px] font-black text-[#00205b] uppercase tracking-[0.2em] bg-[#c5a02c]/10 px-3 py-1.5 rounded-lg border border-[#c5a02c]/30 shadow-sm flex items-center gap-2 w-max">
                <span class="w-2 h-2 rounded-full bg-[#c5a02c] shadow-[0_0_5px_rgba(197,160,44,0.8)]"></span> Question #${newNum}
            </span>
            <button type="button" onclick="window.removeExamQuestion(this)" class="text-rose-500 hover:text-white bg-white hover:bg-rose-500 px-3 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest transition-all flex items-center justify-center gap-1.5 border border-rose-200 shadow-sm w-full sm:w-auto">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg> Remove Block
            </button>
        </div>
        
        <div class="flex flex-col lg:flex-row gap-5 mb-5 relative z-10">
            <div class="flex-1">
                <label class="block text-[10px] font-black text-slate-600 uppercase tracking-widest mb-1.5">Question Text & Media</label>
                <textarea name="q[${questionIndex}][text]" required class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-semibold text-slate-800 shadow-inner h-24 resize-none mb-3 focus:outline-none focus:border-[#00205b] focus:ring-1 focus:ring-[#00205b]/20 transition-all" placeholder="Type the question scenario here..."></textarea>
                
                <div class="bg-slate-50 p-2 rounded-lg border border-slate-200">
                    <p class="text-[8px] font-bold text-slate-400 uppercase tracking-widest mb-1 ml-1">Attach Context Image (Optional)</p>
                    <input type="file" name="q_files[${questionIndex}][q_img]" class="w-full text-[10px] text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-[9px] file:font-black file:uppercase file:tracking-widest file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer bg-white border border-slate-200 rounded-lg shadow-sm">
                </div>
            </div>
            
            <div class="w-full lg:w-48 flex flex-col justify-start shrink-0">
                <div class="bg-[#c5a02c]/5 p-4 rounded-xl border border-[#c5a02c]/30 shadow-sm text-center h-full flex flex-col justify-center">
                    <label class="block text-[10px] font-black text-[#c5a02c] uppercase tracking-widest mb-2 flex items-center justify-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg> Timer
                    </label>
                    <p class="text-[8px] text-slate-500 font-bold uppercase tracking-widest mb-2">Set MM:SS</p>
                    
                    <div class="flex items-center justify-center gap-1 bg-white border-2 border-[#c5a02c]/40 rounded-lg px-2 py-1 shadow-inner focus-within:border-[#c5a02c] transition-colors">
                        <div class="flex flex-col items-center w-12">
                            <input type="number" name="q[${questionIndex}][time_m]" min="0" max="60" value="01" oninput="window.updateLiveDuration()" onblur="this.value = this.value.padStart(2, '0')" class="w-full text-center text-xl font-black text-[#c5a02c] focus:outline-none bg-transparent">
                            <span class="text-[7px] text-slate-400 uppercase font-black -mt-1">MIN</span>
                        </div>
                        <span class="text-xl font-black text-[#c5a02c] mb-2">:</span>
                        <div class="flex flex-col items-center w-12">
                            <input type="number" name="q[${questionIndex}][time_s]" min="0" max="59" value="00" oninput="window.updateLiveDuration()" onblur="this.value = this.value.padStart(2, '0')" class="w-full text-center text-xl font-black text-[#c5a02c] focus:outline-none bg-transparent">
                            <span class="text-[7px] text-slate-400 uppercase font-black -mt-1">SEC</span>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 relative z-10">
            ${optionsHtml}
        </div>

        <div class="mt-5 pt-5 border-t border-slate-200/60 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-emerald-50/60 p-4 rounded-xl border border-emerald-200 shadow-inner relative z-10">
            <div class="flex-1">
                <label class="text-emerald-800 font-black flex items-center gap-2 text-[11px] uppercase tracking-widest mb-1">
                    <div class="p-1.5 rounded-md bg-emerald-100 shadow-sm border border-emerald-200"><svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg></div>
                    Correct Answer Key
                </label>
                <p class="text-[8px] text-emerald-600/80 font-bold uppercase tracking-widest">Select the correct option for auto-grading</p>
            </div>
            <div class="flex gap-2 w-full md:w-1/2">
                ${correctKeyHtml}
            </div>
        </div>
    `;
    
    container.appendChild(newCard);
    questionIndex++;
    window.updateLiveDuration();
};

window.removeExamQuestion = function(btn) {
    const container = document.getElementById('questions-container');
    if (!container) return;
    
    const steps = container.querySelectorAll('.step-card');
    if (steps.length <= 1) {
        if (typeof window.showToast === 'function') {
            window.showToast('You must have at least one question in the exam builder.', 'error');
        } else {
            alert('You must have at least one question in the exam builder.');
        }
        return;
    }
    
    const card = btn.closest('.step-card');
    card.remove();
    
    const remainingSteps = container.querySelectorAll('.step-card');
    remainingSteps.forEach((card, index) => {
        const newIdx = index + 1;
        const headerTextNode = card.querySelector('.step-header');
        if (headerTextNode) {
            headerTextNode.innerHTML = `<span class="w-2 h-2 rounded-full bg-[#c5a02c] shadow-[0_0_5px_rgba(197,160,44,0.8)]"></span> Question #${newIdx}`;
        }
    });
    
    window.updateLiveDuration();
};

// Toggle Single Page Views
window.toggleView = function(viewName) {
    const dash = document.getElementById('dashboard_view');
    const builder = document.getElementById('builder_view');
    
    if (viewName === 'builder') {
        dash.classList.replace('block', 'hidden');
        builder.classList.replace('hidden', 'block');
    } else {
        builder.classList.replace('block', 'hidden');
        dash.classList.replace('hidden', 'block');
    }
};

// Modal Logic
window.openModal = function(modalId) {
    isModalOpen = true;
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
        isModalOpen = false; 
    }, 300);
};

window.openEditModal = function(id, title, activation, deadline, passing) {
    const editExamId = document.getElementById('edit_exam_id');
    const editExamTitle = document.getElementById('edit_exam_title');
    const editActivation = document.getElementById('edit_activation_time');
    const editDeadline = document.getElementById('edit_deadline_time');
    const editPassing = document.getElementById('edit_passing_score');

    if (editExamId) editExamId.value = id;
    if (editExamTitle) editExamTitle.value = title;
    if (editActivation) editActivation.value = activation;
    if (editDeadline) editDeadline.value = deadline;
    if (editPassing) editPassing.value = passing;
    
    window.openModal('editExamModal');
};

// Toast Alerts
window.showToast = function(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = `flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg border text-sm font-bold text-white transition-all transform duration-300 translate-x-full opacity-0 ${type === 'success' ? 'bg-gradient-to-r from-emerald-500 to-emerald-600 border-emerald-400' : 'bg-gradient-to-r from-rose-500 to-rose-600 border-rose-400'}`;
    
    const icon = type === 'success' 
        ? '<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
        : '<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
        
    toast.innerHTML = `${icon} <span>${message}</span>`;
    container.appendChild(toast);
    
    requestAnimationFrame(() => {
        toast.classList.remove('translate-x-full', 'opacity-0');
        toast.classList.add('translate-x-0', 'opacity-100');
    });
    
    setTimeout(() => {
        toast.classList.remove('translate-x-0', 'opacity-100');
        toast.classList.add('translate-x-full', 'opacity-0');
        setTimeout(() => toast.remove(), 300);
    }, 4000);
};