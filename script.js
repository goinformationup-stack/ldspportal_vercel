// =========================================================
// 1. GLOBAL SIDEBAR LOGIC
// =========================================================
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
const mobileMenuBtn = document.getElementById('mobileMenuBtn');
const closeMenuBtn = document.getElementById('closeMenuBtn');

function toggleMenu() { 
    if (sidebar) sidebar.classList.toggle('-translate-x-full'); 
    if (overlay) overlay.classList.toggle('hidden'); 
}

if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', toggleMenu);
if (closeMenuBtn) closeMenuBtn.addEventListener('click', toggleMenu);
if (overlay) overlay.addEventListener('click', toggleMenu);


// =========================================================
// 2. GLOBAL UTILITIES (Toasts, Modals, Live Search)
// =========================================================
function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    if (!container) return; 

    const toast = document.createElement('div');
    const isSuccess = type === 'success';
    const bgColor = isSuccess ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800';
    const iconPath = isSuccess ? 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
    
    toast.className = `fixed bottom-6 right-6 flex items-center gap-2 px-4 py-3 rounded-lg shadow-xl border ${bgColor} font-bold text-sm z-[9999] transition-all duration-300 translate-x-full opacity-0`;
    toast.innerHTML = `<svg class="w-5 h-5 ${isSuccess?'text-emerald-500':'text-rose-500'}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${iconPath}" /></svg><span class="tracking-wide">${message}</span>`;
    
    container.appendChild(toast);
    requestAnimationFrame(() => { toast.classList.remove('translate-x-full', 'opacity-0'); });
    setTimeout(() => {
        toast.classList.add('translate-x-full', 'opacity-0');
        setTimeout(() => toast.remove(), 300);
    }, 3500);
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    modal.classList.remove('hidden'); 
    modal.classList.add('flex'); 
    void modal.offsetWidth; 
    modal.classList.add('modal-active');
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    modal.classList.remove('modal-active');
    setTimeout(() => { modal.classList.add('hidden'); modal.classList.remove('flex'); }, 300); 
}

function filterTable() {
    let input = document.getElementById("liveSearch");
    if (!input) return;
    
    let filter = input.value.toUpperCase();
    let activeTable = document.querySelector('.queue-table.block tbody') || document.getElementById("results_tbody");
    
    if (activeTable) {
        let tr = activeTable.getElementsByTagName("tr");
        for (let i = 0; i < tr.length; i++) {
            let tdName = tr[i].querySelector('.searchable-name');
            let tdId = tr[i].querySelector('.searchable-id');
            if (tdName || tdId) { 
                let txtValue = (tdName ? tdName.textContent || tdName.innerText : "") + " " + (tdId ? tdId.textContent || tdId.innerText : "");
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    tr[i].style.display = "";
                } else {
                    tr[i].style.display = "none";
                }
            }
        }
    }
}


// =========================================================
// 3. ADMISSION: APPLICATION MANAGEMENT LOGIC
// =========================================================
function switchQueue(queueType) {
    document.querySelectorAll('.queue-table').forEach(el => { el.classList.remove('block'); el.classList.add('hidden'); });
    
    const targetQueue = document.getElementById('queue-' + queueType);
    if (targetQueue) { targetQueue.classList.remove('hidden'); targetQueue.classList.add('block'); }
    
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('bg-[#00205b]', 'text-white', 'shadow-md');
        btn.classList.add('bg-white/80', 'border-slate-200', 'text-slate-500', 'hover:bg-slate-100');
    });
    
    const activeBtn = document.getElementById('q-btn-' + queueType);
    if (activeBtn) {
        activeBtn.classList.add('bg-[#00205b]', 'text-white', 'shadow-md');
        activeBtn.classList.remove('bg-white/80', 'border-slate-200', 'text-slate-500', 'hover:bg-slate-100');
    }
    filterTable(); 
}

function viewDocument(url) {
    openModal('documentModal');
    document.getElementById('docLoader').classList.remove('hidden');
    document.getElementById('docIframe').classList.add('hidden');
    document.getElementById('docImage').classList.add('hidden');
    document.getElementById('docError').classList.add('hidden');
    document.getElementById('docError').classList.remove('flex');
    document.getElementById('docDownloadBtn').href = url;
    document.getElementById('docFallbackLink').href = url;
    
    const cleanUrl = url.split('?')[0];
    const ext = cleanUrl.split('.').pop().toLowerCase();
    
    if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) { 
        document.getElementById('docImage').src = url; 
        document.getElementById('docImage').classList.remove('hidden'); 
    } else if (['pdf', 'txt'].includes(ext)) { 
        document.getElementById('docIframe').src = url; 
        document.getElementById('docIframe').classList.remove('hidden'); 
    } else {
        document.getElementById('docLoader').classList.add('hidden');
        document.getElementById('docError').classList.remove('hidden'); 
        document.getElementById('docError').classList.add('flex');
    }
}

function closeDocModal() { 
    closeModal('documentModal'); 
    document.getElementById('docIframe').src = ''; 
    document.getElementById('docImage').src = ''; 
}

function copyMsg() {
    document.getElementById("copy-text").select(); 
    document.execCommand("copy");
    showToast("Message copied to clipboard!", 'success');
}

function closeMsgModal() { closeModal('msg-modal'); }

function openProfileModal(button) {
    const data = JSON.parse(button.getAttribute('data-profile'));
    const first = data.first_name || ''; 
    const middle = data.middle_name ? ' ' + data.middle_name : ''; 
    const last = data.last_name ? data.last_name + ', ' : '';
    
    const setText = (id, text) => { const el = document.getElementById(id); if(el) el.innerText = text; };

    setText('mod-applicant-name', (last + first + middle).trim() || 'N/A');
    setText('mod-adm-no', data.admission_number || 'N/A');
    setText('mod-dob', data.dob || 'N/A');
    setText('mod-gender', data.gender || 'N/A');
    setText('mod-pob', data.pob || data.place_of_birth || 'N/A');
    setText('mod-email', data.email || 'N/A');
    setText('mod-phone', data.phone || 'N/A');
    setText('mod-address', data.address || 'N/A');
    setText('mod-program', data.program || data.program_choice || 'N/A');
    setText('mod-type', data.student_type || 'N/A');
    setText('mod-year-level', data.year_level || 'N/A');
    setText('mod-school', data.school_last_attended || data.last_school || 'N/A');
    setText('mod-school-year', data.school_year_attended || 'N/A');
    setText('mod-father-name', data.father_name || 'N/A');
    setText('mod-father-occ', data.father_occupation || 'N/A');
    setText('mod-father-con', data.father_contact || 'N/A');
    setText('mod-mother-name', data.mother_name || 'N/A');
    setText('mod-mother-occ', data.mother_occupation || 'N/A');
    setText('mod-mother-con', data.mother_contact || 'N/A');
    setText('mod-em-name', data.emergency_contact_name || 'N/A');
    setText('mod-em-con', data.emergency_contact_number || 'N/A');
    setText('mod-influence', data.influence_source || 'N/A');
    
    openModal('profileModal');
}

function closeProfileModal() { closeModal('profileModal'); }

function denyWithReason(id) {
    const r = prompt("Enter specific reason for denial (e.g., Form 138 is unreadable):");
    if (r != null && r.trim() != "") {
        document.getElementById('reasonInput_' + id).value = r;
        document.getElementById('denyForm_' + id).submit();
    } else if (r != null) {
        showToast("A reason must be provided to deny an application.", 'error');
    }
}


// =========================================================
// 4. ADMISSION: PORTAL SETTINGS LOGIC
// =========================================================
function addStep() {
    const container = document.getElementById('steps-container');
    if (!container) return;

    const currentSteps = container.querySelectorAll('.step-card').length;
    const newNum = currentSteps + 1;
    
    const newCard = document.createElement('div');
    newCard.className = 'step-card glass-section p-3.5 relative group transition-all duration-300 hover:shadow-md z-20 fade-in-up';
    newCard.innerHTML = `
        <div class="flex justify-between items-center mb-3 border-b border-slate-200/60 pb-2">
            <h4 class="font-black text-[#00205b] text-[10px] uppercase tracking-widest step-header drop-shadow-sm flex items-center gap-1.5">
                <div class="w-4 h-4 rounded bg-[#00205b]/10 flex items-center justify-center text-[#00205b] text-[9px]">${newNum}</div>
                Step ${newNum}
            </h4>
            <div class="flex gap-1.5 opacity-80 group-hover:opacity-100 transition-opacity">
                <button type="button" onclick="this.closest('.step-card').querySelector('input').focus()" class="text-blue-500 hover:text-white bg-blue-50 hover:bg-blue-500 p-1 rounded border border-blue-200 hover:border-blue-600 shadow-sm transition-colors" title="Edit Step"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg></button>
                <button type="button" onclick="removeSpecificStep(this)" class="text-rose-500 hover:text-white bg-rose-50 hover:bg-rose-500 p-1 rounded border border-rose-200 hover:border-rose-600 shadow-sm transition-colors" title="Remove Step"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button>
            </div>
        </div>
        <div class="space-y-2.5">
            <div><label class="block text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1 drop-shadow-sm">Title</label><input type="text" name="step_title[]" required class="input-glossy-smooth !py-1.5 !px-2 text-xs font-bold text-[#00205b]"></div>
            <div><label class="block text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1 drop-shadow-sm">Description</label><textarea name="step_desc[]" rows="2" class="input-glossy-smooth !py-1.5 !px-2 text-[11px] leading-relaxed text-slate-600 font-medium"></textarea></div>
        </div>
    `;
    container.appendChild(newCard);
}

function removeSpecificStep(btn) {
    const container = document.getElementById('steps-container');
    const steps = container.querySelectorAll('.step-card');
    if (steps.length <= 1) { showToast('You must have at least one step in the process overview.', 'error'); return; }
    
    btn.closest('.step-card').remove();
    
    // Renumber remaining steps to keep it clean
    container.querySelectorAll('.step-card').forEach((card, index) => {
        const newIdx = index + 1;
        card.querySelector('.step-header').innerHTML = `<div class="w-4 h-4 rounded bg-[#00205b]/10 flex items-center justify-center text-[#00205b] text-[9px]">${newIdx}</div> Step ${newIdx}`;
    });
}


// =========================================================
// 5. ADMISSION: EXAM BUILDER LOGIC
// =========================================================
let questionIndex = 0;

function addNewQuestion() {
    const container = document.getElementById('questions-container');
    if (!container) return; // Guard clause so it doesn't break other pages

    const currentSteps = container.querySelectorAll('.step-card').length;
    const newNum = currentSteps + 1;
    
    const newCard = document.createElement('div');
    newCard.className = "glossy-panel p-4 md:p-5 shadow-sm border-t-[3px] !border-t-[#c5a02c] animate-up step-card";
    newCard.innerHTML = `
        <div class="flex justify-between items-center mb-3 border-b border-slate-200/60 pb-2.5 relative z-10">
            <span class="step-header text-[9px] font-black text-[#00205b] uppercase tracking-widest bg-[#c5a02c]/10 px-2 py-1 rounded border border-[#c5a02c]/30 shadow-sm flex items-center gap-1.5 drop-shadow-sm">
                <span class="w-1.5 h-1.5 rounded-full bg-[#c5a02c]"></span>
                Question #${newNum}
            </span>
            <button type="button" onclick="removeExamQuestion(this)" class="text-rose-500 hover:text-rose-700 bg-white hover:bg-rose-50 px-2 py-1 rounded text-[8px] font-black uppercase tracking-widest transition-all flex items-center gap-1 border border-rose-200 shadow-sm hover:shadow-md">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                Remove
            </button>
        </div>
        
        <div class="flex flex-col md:flex-row gap-4 mb-3 border-b border-slate-200/60 pb-3 relative z-10">
            <div class="flex-1">
                <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">Question Text & Media</label>
                <textarea name="q[${questionIndex}][text]" required class="input-glossy-smooth w-full h-14 mb-2 resize-none text-xs shadow-inner font-medium !py-1.5" placeholder="Enter question here..."></textarea>
                <input type="file" name="q_files[${questionIndex}][q_img]" class="file-input bg-white shadow-sm !py-1 !px-2 text-[9px]">
            </div>
            <div class="w-full md:w-32 flex flex-col justify-start">
                <label class="block text-[9px] font-bold text-[#c5a02c] uppercase tracking-widest mb-1 drop-shadow-sm">Time (Sec)</label>
                <input type="number" name="q[${questionIndex}][time_limit]" required min="10" value="60" class="input-glossy-smooth w-full h-10 text-center font-black text-lg !text-[#c5a02c] !border-[#c5a02c]/40 !bg-[#c5a02c]/5 focus:!border-[#c5a02c]/70 focus:!bg-white shadow-inner !py-1">
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 relative z-10">
            ${['a','b','c','d'].map(opt => `
                <div class="p-3 bg-white/60 backdrop-blur-sm rounded-lg border border-slate-200 shadow-sm transition-all hover:bg-white group">
                    <label class="flex items-center gap-1.5 text-[#00205b] block text-[9px] font-black uppercase tracking-widest mb-1.5 drop-shadow-sm">
                        <span class="bg-slate-200 text-slate-700 px-1.5 py-0.5 rounded text-[8px] shadow-sm border border-slate-300 group-hover:bg-[#00205b] group-hover:text-white group-hover:border-[#00205b] transition-colors">${opt.toUpperCase()}</span> Option
                    </label>
                    <input type="text" name="q[${questionIndex}][${opt}]" required class="input-glossy-smooth w-full mb-1.5 text-xs font-medium !py-1 !px-2" placeholder="Answer text">
                    <input type="file" name="q_files[${questionIndex}][img_${opt}]" class="file-input bg-slate-50 group-hover:bg-white transition-colors !py-0.5 !px-1.5 text-[8px]">
                </div>
            `).join('')}
        </div>

        <div class="mt-3 pt-3 border-t border-slate-200/60 flex flex-col md:flex-row md:items-center justify-between gap-3 bg-emerald-50/50 backdrop-blur-md p-3 rounded-lg border border-emerald-200/60 shadow-[inset_0_1px_4px_rgba(0,0,0,0.02)] relative z-10">
            <label class="mb-0 text-emerald-700 font-black flex items-center gap-1.5 text-[9px] uppercase tracking-widest drop-shadow-sm">
                <div class="p-1 rounded bg-emerald-100"><svg class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg></div>
                Correct Answer Key
            </label>
            <div class="flex gap-2 w-full md:w-auto">
                ${['A','B','C','D'].map(opt => `
                    <label class="cursor-pointer relative group flex-1 md:flex-none">
                        <input type="radio" name="q[${questionIndex}][correct]" value="${opt}" required class="hidden peer">
                        <div class="w-full md:w-10 h-7 flex items-center justify-center rounded border border-slate-300 bg-white text-slate-400 font-black text-[10px] transition-all duration-300 peer-checked:bg-gradient-to-br peer-checked:from-emerald-500 peer-checked:to-emerald-600 peer-checked:border-emerald-600 peer-checked:text-white peer-checked:shadow-sm shadow-sm group-hover:border-emerald-300">${opt}</div>
                    </label>
                `).join('')}
            </div>
        </div>
    `;
    container.appendChild(newCard);
    questionIndex++;
}

function removeExamQuestion(btn) {
    const container = document.getElementById('questions-container');
    if (!container) return;
    
    const steps = container.querySelectorAll('.step-card');
    
    if (steps.length <= 1) {
        showToast('You must have at least one question in the exam builder.', 'error');
        return;
    }
    
    btn.closest('.step-card').remove();
    
    // Re-index remaining cards so they stay visually ordered
    const remainingSteps = container.querySelectorAll('.step-card');
    remainingSteps.forEach((card, index) => {
        const newIdx = index + 1;
        const headerTextNode = card.querySelector('.step-header');
        if (headerTextNode) {
            headerTextNode.innerHTML = `
                <span class="w-1.5 h-1.5 rounded-full bg-[#c5a02c]"></span>
                Question #${newIdx}
            `;
        }
    });
}

function openEditModal(id, title, activation, deadline, duration_mins, passing) {
    const editExamId = document.getElementById('edit_exam_id');
    const editExamTitle = document.getElementById('edit_exam_title');
    const editActivation = document.getElementById('edit_activation_time');
    const editDeadline = document.getElementById('edit_deadline_time');
    const editDuration = document.getElementById('edit_duration_hours');
    const editPassing = document.getElementById('edit_passing_score');

    if (editExamId) editExamId.value = id;
    if (editExamTitle) editExamTitle.value = title;
    if (editActivation) editActivation.value = activation;
    if (editDeadline) editDeadline.value = deadline;
    if (editDuration) editDuration.value = (duration_mins / 60).toFixed(1);
    if (editPassing) editPassing.value = passing;
    
    openModal('editExamModal');
}

function closeEditModal() { closeModal('editExamModal'); }

// Auto-init for Exam Builder
document.addEventListener("DOMContentLoaded", () => {
    const container = document.getElementById('questions-container');
    if (container && container.children.length === 0) {
        addNewQuestion();
    }
});


// =========================================================
// 6. ACCOUNTING: FINANCIAL LEDGER LOGIC
// =========================================================
let ledgerLastDataString = '';
let isLedgerModalOpen = false;

function resetFilters() {
    const filterProg = document.getElementById('filter_program');
    const filterMonth = document.getElementById('filter_month');
    
    if (filterProg) filterProg.value = 'All';
    if (filterMonth) filterMonth.value = 'All';
    
    triggerDynamicFetch();
}

async function triggerDynamicFetch() {
    const ledgerBody = document.getElementById('ledger_tbody');
    if (!ledgerBody || isLedgerModalOpen) return; 

    const filterProg = document.getElementById('filter_program');
    const filterMonth = document.getElementById('filter_month');
    
    const params = new URLSearchParams({
        api_refresh: 1,
        f_program: filterProg ? filterProg.value : 'All',
        f_month: filterMonth ? filterMonth.value : 'All'
    });

    const cleanUrl = window.location.pathname + '?' + params.toString().replace('api_refresh=1&', '');
    window.history.replaceState({}, '', cleanUrl);

    try {
        const response = await fetch(`${window.location.pathname}?${params.toString()}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) return;
        
        const data = await response.json();
        const newDataString = JSON.stringify(data);
        
        if (newDataString === ledgerLastDataString) return; 
        ledgerLastDataString = newDataString;

        if (data.ledger_html) ledgerBody.innerHTML = data.ledger_html;

        const tbodyRows = ledgerBody.querySelectorAll('tr').length;
        const footerText = ledgerBody.innerText.includes('No cleared payments') ? 0 : tbodyRows;
        
        const footerCountEl = document.getElementById('footer_count');
        const ledgerCountEl = document.getElementById('ledger_count');
        if (footerCountEl) footerCountEl.innerText = `Viewing ${footerText} item(s)`;
        if (ledgerCountEl) ledgerCountEl.innerText = `${data.ledger_count} CLEARED PAYMENTS`;
        
    } catch (err) { console.error("Ledger sync error:", err); }
}

if (document.getElementById('ledger_tbody')) { setInterval(triggerDynamicFetch, 5000); }

function openReceiptModal(fileUrl) {
    isLedgerModalOpen = true;
    const inner = document.getElementById('receiptModalInner');
    const viewer = document.getElementById('receiptViewer');
    const downloadBtn = document.getElementById('receiptDownloadBtn');

    if (!inner || !viewer || !downloadBtn) return;
    viewer.src = fileUrl; 
    downloadBtn.href = fileUrl;
    
    openModal('receiptModal');
    setTimeout(() => { inner.classList.remove('scale-95', 'opacity-0'); }, 10);
}

function closeReceiptModal() {
    const inner = document.getElementById('receiptModalInner');
    const viewer = document.getElementById('receiptViewer');

    if (!inner || !viewer) return;
    inner.classList.add('scale-95', 'opacity-0');
    setTimeout(() => { 
        closeModal('receiptModal'); 
        viewer.src = ''; 
        isLedgerModalOpen = false; 
    }, 300);
}

function openStudentModal(buttonElement) {
    isLedgerModalOpen = true;
    const data = JSON.parse(buttonElement.getAttribute('data-profile'));
    const parseVal = (val) => val && val.trim() !== '' ? val : 'Not Provided';
    const builtName = ((data.last_name ? data.last_name + ', ' : '') + (data.first_name || '') + (data.middle_name ? ' ' + data.middle_name : '')).trim() || 'No Name Provided';

    const setText = (id, text) => { const el = document.getElementById(id); if(el) el.innerText = text; };

    setText('m_student_type', parseVal(data.student_status));
    setText('m_modality', parseVal(data.learning_mode));
    setText('m_semester', parseVal(data.semester));
    
    const dateVal = data.transaction_date || data.approved_at || data.created_at;
    setText('m_date', dateVal ? new Date(dateVal).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : 'N/A');
    
    setText('m_detail_name', builtName);
    setText('m_email', parseVal(data.user_email));
    setText('m_phone', parseVal(data.phone));
    setText('m_gender', parseVal(data.u_gender));
    setText('m_dob', parseVal(data.dob));
    setText('m_pob', parseVal(data.pob));
    setText('m_address', parseVal(data.address));
    setText('m_school', parseVal(data.school_last_attended));
    setText('m_school_year', parseVal(data.school_year_attended));
    setText('m_father_name', parseVal(data.father_name));
    setText('m_father_details', `${parseVal(data.father_occupation)} • ${parseVal(data.father_contact)}`);
    setText('m_mother_name', parseVal(data.mother_name));
    setText('m_mother_details', `${parseVal(data.mother_occupation)} • ${parseVal(data.mother_contact)}`);
    setText('m_emergency', `${parseVal(data.emergency_contact_name)} — ${parseVal(data.emergency_contact_number)}`);
    
    const docContainer = document.getElementById('m_documents_container');
    if (docContainer) {
        if (data.payment_proof) {
            docContainer.innerHTML = `<button type="button" onclick="openReceiptModal('../${data.payment_proof}')" class="btn-glossy-secondary w-full sm:w-auto text-[10px] uppercase tracking-widest flex items-center justify-center gap-2"><svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg> View Attached Payment Receipt</button>`;
        } else {
            docContainer.innerHTML = `<span class="text-xs text-slate-500 italic font-medium">No documents uploaded.</span>`;
        }
    }
    
    const inner = document.getElementById('profileModalInner');
    if (inner) {
        openModal('studentProfileModal');
        setTimeout(() => { inner.classList.remove('scale-95', 'opacity-0'); }, 10);
    }
}

function closeStudentModal() {
    const inner = document.getElementById('profileModalInner');
    if (!inner) return;
    inner.classList.add('scale-95', 'opacity-0');
    setTimeout(() => { 
        closeModal('studentProfileModal'); 
        isLedgerModalOpen = false; 
    }, 300);
}


// =========================================================
// 7. ACCOUNTING: VERIFICATION REJECT PROMPT
// =========================================================
function promptReject(btn) {
    const reason = prompt("Please provide a note for denying or placing this payment on hold:");
    if (reason && reason.trim() !== "") {
        btn.closest('form').querySelector('.reject-reason').value = reason;
        return true;
    }
    if (reason !== null) {
        showToast("A reason is required to deny or place payment on hold.", "error");
    }
    return false;
}

// =========================================================
// 8. REGISTRAR: FEE MANAGEMENT LOGIC
// =========================================================
function openEditFeeModal(btn) {
    document.getElementById('edit_fee_id').value = btn.getAttribute('data-id');
    document.getElementById('edit_fee_name').value = btn.getAttribute('data-name');
    document.getElementById('edit_fee_amount').value = btn.getAttribute('data-amount');
    document.getElementById('edit_fee_rule').value = btn.getAttribute('data-rule');
    document.getElementById('edit_fee_program').value = btn.getAttribute('data-program');
    document.getElementById('edit_fee_year').value = btn.getAttribute('data-year');
    
    document.getElementById('edit_first_payment').value = btn.getAttribute('data-first');
    document.getElementById('edit_second_payment').value = btn.getAttribute('data-second');

    toggleEditInstallments();
    openModal('editFeeModal');
}

function openSubjectFeeModal(btn) {
    document.getElementById('sf_code_input').value = btn.getAttribute('data-code');
    document.getElementById('sf_code_display').innerText = btn.getAttribute('data-code');
    document.getElementById('sf_title_display').innerText = btn.getAttribute('data-title');
    document.getElementById('sf_fee_input').value = btn.getAttribute('data-fee');
    openModal('subjectFeeModal');
}

function toggleAddInstallments() {
    const rule = document.getElementById('add_payment_rule').value;
    const instDiv = document.getElementById('add_fee_installments');
    if (rule === 'Installments Allowed') {
        instDiv.classList.remove('hidden'); 
        instDiv.classList.add('grid'); 
        calcAddSecondPayment();
    } else {
        instDiv.classList.add('hidden'); 
        instDiv.classList.remove('grid');
    }
}

function calcAddSecondPayment() {
    const amt = parseFloat(document.getElementById('add_fee_amount').value) || 0;
    let first = parseFloat(document.getElementById('add_first_payment').value) || 0;
    if (first > amt) { first = amt; document.getElementById('add_first_payment').value = first; }
    document.getElementById('add_second_payment').value = (amt - first).toFixed(2);
}

function toggleEditInstallments() {
    const rule = document.getElementById('edit_fee_rule').value;
    const instDiv = document.getElementById('edit_fee_installments');
    if (rule === 'Installments Allowed') {
        instDiv.classList.remove('hidden'); 
        instDiv.classList.add('grid'); 
        calcEditSecondPayment();
    } else {
        instDiv.classList.add('hidden'); 
        instDiv.classList.remove('grid');
    }
}

function calcEditSecondPayment() {
    const amt = parseFloat(document.getElementById('edit_fee_amount').value) || 0;
    let first = parseFloat(document.getElementById('edit_first_payment').value) || 0;
    if (first > amt) { first = amt; document.getElementById('edit_first_payment').value = first; }
    document.getElementById('edit_second_payment').value = (amt - first).toFixed(2);
}

function filterGeneralFees() {
    let input = document.getElementById("generalFeeSearch").value.toUpperCase();
    let tr = document.getElementById("generalFeesTable").getElementsByTagName("tr");
    for (let i = 1; i < tr.length; i++) {
        let nameCell = tr[i].getElementsByTagName("td")[1];
        let progCell = tr[i].getElementsByTagName("td")[4];
        if (nameCell || progCell) {
            tr[i].style.display = ((nameCell.innerText + " " + progCell.innerText).toUpperCase().indexOf(input) > -1) ? "" : "none";
        }
    }
}

function filterSubjects() {
    let input = document.getElementById("subjectSearch").value.toUpperCase();
    let tr = document.getElementsByClassName("subject-row");
    for (let i = 0; i < tr.length; i++) {
        let codeCell = tr[i].getElementsByTagName("td")[0];
        let titleCell = tr[i].getElementsByTagName("td")[1];
        if (codeCell || titleCell) {
            tr[i].style.display = ((codeCell.innerText + " " + titleCell.innerText).toUpperCase().indexOf(input) > -1) ? "" : "none";
        }
    }
}


// =========================================================
// 9. STUDENT PORTAL SPECIFIC LOGIC & SCROLL LOGIC
// =========================================================
let isFormDirty = false;
let currentPollState = {
    status: "",
    balance: 0,
    has_active_request: false
};
let pollInterval = null;

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('spa-content-root')) {
        initSPAEngine();
        if (typeof initLocationAPI === 'function') initLocationAPI();
        if (typeof initSchoolSearch === 'function') initSchoolSearch();
        startRealtimePolling();
    }
    
    const mainScrollArea = document.getElementById('mainScrollArea');
    const floatingBtn = document.getElementById('floatingBackToTop');
    if (mainScrollArea && floatingBtn) {
        mainScrollArea.addEventListener('scroll', () => {
            if (mainScrollArea.scrollTop > 400) {
                floatingBtn.classList.remove('opacity-0', 'pointer-events-none', 'translate-y-4');
                floatingBtn.classList.add('opacity-100', 'translate-y-0');
            } else {
                floatingBtn.classList.add('opacity-0', 'pointer-events-none', 'translate-y-4');
                floatingBtn.classList.remove('opacity-100', 'translate-y-0');
            }
        });
    }

    // Auto-init the gesture zooming specifically for the curriculum page
    if (document.getElementById('documentCanvas')) {
        initCurriculumGestures();
    }
});

function initSPAEngine() {
    document.querySelectorAll('input, select, textarea').forEach(input => {
        if (input.dataset.dirtyBound) return;
        input.dataset.dirtyBound = 'true';

        input.addEventListener('focus', () => isFormDirty = true);
        input.addEventListener('input', () => isFormDirty = true);
        input.addEventListener('change', () => isFormDirty = true);
        input.addEventListener('blur', () => {
            setTimeout(() => isFormDirty = false, 300);
        });
    });

    document.querySelectorAll('form').forEach(form => {
        if (form.dataset.spaBound) return;
        form.dataset.spaBound = 'true';

        form.addEventListener('submit', async (e) => {
            if (!form.hasAttribute('data-spa-post') && form.querySelector('input[type="file"]')) {
                return;
            }
            
            e.preventDefault();
            isFormDirty = false; 

            const submitBtn = form.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn ? submitBtn.innerHTML : 'Submit';
            
            if (submitBtn) {
                submitBtn.innerHTML = '<svg class="w-4 h-4 animate-spin inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Processing...';
                submitBtn.disabled = true;
            }

            const formData = new FormData(form);
            formData.append('ajax_post', '1');
            
            if (e.submitter && e.submitter.name) {
                formData.append(e.submitter.name, e.submitter.value || '1');
            }

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                showToast(data.message, data.status);

                if (data.new_html) {
                    updateDOM(data.new_html);
                }
            } catch (err) {
                console.error('Submission Error:', err);
                showToast('A network error occurred. Please try again.', 'error');
                if (submitBtn) {
                    submitBtn.innerHTML = originalBtnText;
                    submitBtn.disabled = false;
                }
            }
        });
    });
    
    const appYear = document.getElementById('app_year');
    if(appYear) {
        appYear.addEventListener('change', checkRequirements);
        checkRequirements();
    }

    if (typeof window.initMiscLogic === 'function') window.initMiscLogic();
    if (typeof window.initBankDropdown === 'function') window.initBankDropdown();
}

function updateDOM(newHtml) {
    const root = document.getElementById('spa-content-root');
    if(root) {
        root.outerHTML = newHtml; 
        initSPAEngine(); 
        if (typeof initLocationAPI === 'function') initLocationAPI();
        if (typeof initSchoolSearch === 'function') initSchoolSearch();
        
        if (document.getElementById('documentCanvas')) {
            initCurriculumGestures();
        }
    }
}

function startRealtimePolling() {
    if (pollInterval) clearInterval(pollInterval);
    
    pollInterval = setInterval(async () => {
        if (isFormDirty) return;
        try {
            const res = await fetch(window.location.pathname + '?live_poll=1');
            const data = await res.json();
            
            if (data.status !== currentPollState.status || 
                data.remaining_balance !== currentPollState.balance || 
                data.has_active_request !== currentPollState.has_active_request) {
                
                currentPollState.status = data.status;
                currentPollState.balance = data.remaining_balance;
                currentPollState.has_active_request = data.has_active_request;
                
                if (data.new_html) updateDOM(data.new_html);
            }
        } catch (e) { console.error('Background poll error:', e); }
    }, 5000); 
}

window.initBankDropdown = function() {
    const select = document.getElementById('paid_through_select');
    const card = document.getElementById('selected_bank_card');
    if(!select || !card) return;
    
    if (select.dataset.boundBank) return;
    select.dataset.boundBank = 'true';
    
    select.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if(!option.value) {
            card.classList.add('hidden');
            return;
        }
        
        const name = option.value;
        const accName = option.dataset.accname;
        const accNum = option.dataset.accnum;
        const link = option.dataset.link;
        const isLinkOnly = (accName === 'N/A' && accNum === 'N/A');
        
        let html = `<div class="flex flex-col gap-2"><h5 class="text-[10px] font-black text-[#00205b] uppercase tracking-widest mb-1 border-b border-blue-200/60 pb-1">Payment Instructions: ${name}</h5>`;
        
        if(!isLinkOnly) {
            html += `
                <div class="grid grid-cols-2 gap-4 mt-1">
                    <div><p class="text-[8px] uppercase font-bold text-slate-500 tracking-widest">Account Name</p><p class="text-xs font-bold text-slate-800">${accName}</p></div>
                    <div><p class="text-[8px] uppercase font-bold text-slate-500 tracking-widest">Account Number</p><p class="text-sm font-mono font-black text-[#c5a02c]">${accNum}</p></div>
                </div>
            `;
        } else {
            html += `<div class="mt-1"><p class="text-xs font-bold text-[#c5a02c]">Direct Payment Link Only</p><p class="text-[9px] font-medium text-slate-500">Please click the link below to process your payment.</p></div>`;
        }
        
        if(link && link !== '') {
            html += `<div class="mt-3"><a href="${link}" target="_blank" class="inline-flex items-center justify-center w-full gap-1.5 text-[10px] uppercase tracking-widest font-black text-indigo-700 bg-indigo-100 hover:bg-indigo-600 hover:text-white border border-indigo-200 py-2.5 px-4 rounded-lg transition-all shadow-sm hover:shadow-md">Open Payment Link</a></div>`;
        }
        
        html += `</div>`;
        card.innerHTML = html;
        card.classList.remove('hidden');
    });
};

window.initMiscLogic = function() {
    document.querySelectorAll('.misc-plan-toggle').forEach(toggle => {
        toggle.addEventListener('change', function(e) {
            const target = this.dataset.target;
            const floatCard = document.getElementById('float_' + target);
            const amtDisplay = document.getElementById('amt_' + target);
            const cb = document.getElementById('cb_' + target);
            const cbAmt = document.getElementById('cbamt_' + target);
            const lbl = document.getElementById('lbl_' + target);
            const basename = document.getElementById('basename_' + target).innerText;

            if (this.value === 'partial') {
                floatCard.classList.remove('hidden');
                cb.checked = false;
                updateSelectedFees();
            } else {
                floatCard.classList.add('hidden');
                const fullAmt = parseFloat(this.dataset.full);
                amtDisplay.innerText = '₱' + fullAmt.toLocaleString('en-US', {minimumFractionDigits: 2});
                cb.dataset.amount = fullAmt;
                cbAmt.innerText = '₱' + fullAmt.toLocaleString('en-US', {minimumFractionDigits: 2});
                cb.value = basename + ' (Full Payment)';
                lbl.innerText = 'FULL PAYMENT';
                cb.checked = true;
                updateSelectedFees();
            }
        });
    });

    document.querySelectorAll('.confirm-partial-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const target = this.dataset.target;
            const floatCard = document.getElementById('float_' + target);
            const toggle = document.querySelector(`.misc-plan-toggle[data-target="${target}"][value="partial"]`);
            const amtDisplay = document.getElementById('amt_' + target);
            const cb = document.getElementById('cb_' + target);
            const cbAmt = document.getElementById('cbamt_' + target);
            const lbl = document.getElementById('lbl_' + target);
            const basename = document.getElementById('basename_' + target).innerText;

            const firstAmt = parseFloat(toggle.dataset.first);
            floatCard.classList.add('hidden');
            amtDisplay.innerText = '₱' + firstAmt.toLocaleString('en-US', {minimumFractionDigits: 2});
            cb.dataset.amount = firstAmt;
            cbAmt.innerText = '₱' + firstAmt.toLocaleString('en-US', {minimumFractionDigits: 2});
            cb.value = basename + ' (1st Installment)';
            lbl.innerText = '1ST INSTALLMENT';
            cb.checked = true;
            updateSelectedFees();
        });
    });
};

function checkRequirements() {
    const appYear = document.getElementById('app_year');
    const reqContainer = document.getElementById('extra-requirements');
    const clearanceInput = document.querySelector('input[name="clearance_file"]');
    const classcardInput = document.querySelector('input[name="classcard_file"]');

    if(!appYear || !reqContainer) return;
    
    if (appYear.value !== "" && appYear.value !== '1st Year') {
        reqContainer.classList.remove('hidden');
        if(clearanceInput) clearanceInput.required = true;
        if(classcardInput) classcardInput.required = true;
    } else {
        reqContainer.classList.add('hidden');
        if(clearanceInput) clearanceInput.required = false;
        if(classcardInput) classcardInput.required = false;
    }
}

window.updateDob = function() {
    const m = document.getElementById('dob_month').value;
    const d = document.getElementById('dob_day').value;
    const y = document.getElementById('dob_year').value;
    const dobInput = document.getElementById('actual_dob');
    
    if (m && d && y && dobInput) {
        dobInput.value = `${y}-${m}-${d}`;
    } else if (dobInput) {
        dobInput.value = '';
    }
};

window.updateSelectedFees = function() {
    let total = 0;
    let intent = [];
    
    document.querySelectorAll('.fee-selector:checked').forEach(cb => {
        total += parseFloat(cb.getAttribute('data-amount'));
        intent.push(cb.value);
    });
    
    const amountInput = document.getElementById('amount_paid');
    const intentInput = document.getElementById('payment_intent');
    const submitBtn = document.getElementById('btn_submit_payment');
    
    if(amountInput) amountInput.value = total.toFixed(2);
    if(intentInput) intentInput.value = intent.join(', ');
    
    if(submitBtn) {
        submitBtn.disabled = (total <= 0);
    }
};

window.triggerPrint = function(type = 'default', id = null) {
    const styleElement = document.getElementById('dynamic_print_style');
    if(!styleElement) return;
    
    if (type === 'cor') {
        styleElement.innerHTML = `@media print { 
            @page { size: A4 portrait; margin: 0; } 
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } 
            .cor-record-wrapper { display: none !important; }
            #cor_wrapper_${id} { display: block !important; }
            #section-curriculum, #section-overview, #section-payment, #section-announcements, #section-subject_fees, #section-enrollment, #section-grades { display: none !important; }
        }`;
    } 
    setTimeout(() => window.print(), 100);
};

window.downloadCOR = function(id) {
    const element = document.getElementById('cor_document_' + id);
    if(!element) return;
    
    const fnName = 'Digital_COR_Record_' + id + '.pdf';
    const opt = {
        margin:       0,
        filename:     fnName,
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2 },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    if(typeof html2pdf !== 'undefined') {
        html2pdf().set(opt).from(element).save();
    }
};


// =========================================================
// 11. REUSABLE FORM UTILITIES (SCHOOL & LOCATION APIs)
// =========================================================

function initLocationAPI() {
    const regionSel = document.getElementById('addr_region');
    const provSel = document.getElementById('addr_province');
    const citySel = document.getElementById('addr_city');
    const brgySel = document.getElementById('addr_brgy');
    const actualAddr = document.getElementById('actual_address');

    if (regionSel && !regionSel.dataset.bound) {
        regionSel.dataset.bound = 'true';

        async function fetchLocation(url) {
            try {
                const res = await fetch(url);
                return await res.json();
            } catch(e) { return []; }
        }

        async function loadRegions() {
            const regions = await fetchLocation('https://psgc.gitlab.io/api/regions/');
            regions.sort((a,b) => a.name.localeCompare(b.name)).forEach(r => {
                regionSel.add(new Option(r.name, r.code));
            });
        }

        regionSel.addEventListener('change', async () => {
            provSel.innerHTML = '<option value="" disabled selected>Province</option>';
            citySel.innerHTML = '<option value="" disabled selected>City/Municipality</option>';
            brgySel.innerHTML = '<option value="" disabled selected>Barangay</option>';
            provSel.disabled = false; citySel.disabled = true; brgySel.disabled = true;

            const provinces = await fetchLocation(`https://psgc.gitlab.io/api/regions/${regionSel.value}/provinces/`);
            if (provinces.length > 0) {
                provinces.sort((a,b) => a.name.localeCompare(b.name)).forEach(p => provSel.add(new Option(p.name, p.code)));
            } else {
                provSel.add(new Option('- Metro Manila (NCR) -', 'NCR'));
                provSel.value = 'NCR';
                provSel.dispatchEvent(new Event('change'));
            }
            updateAddress();
        });

        provSel.addEventListener('change', async () => {
            citySel.innerHTML = '<option value="" disabled selected>City/Municipality</option>';
            brgySel.innerHTML = '<option value="" disabled selected>Barangay</option>';
            citySel.disabled = false; brgySel.disabled = true;

            let cities = [];
            if (provSel.value === 'NCR') {
                cities = await fetchLocation(`https://psgc.gitlab.io/api/regions/${regionSel.value}/cities-municipalities/`);
            } else {
                cities = await fetchLocation(`https://psgc.gitlab.io/api/provinces/${provSel.value}/cities-municipalities/`);
            }
            cities.sort((a,b) => a.name.localeCompare(b.name)).forEach(c => citySel.add(new Option(c.name, c.code)));
            updateAddress();
        });

        citySel.addEventListener('change', async () => {
            brgySel.innerHTML = '<option value="" disabled selected>Barangay</option>';
            brgySel.disabled = false;
            
            const brgys = await fetchLocation(`https://psgc.gitlab.io/api/cities-municipalities/${citySel.value}/barangays/`);
            brgys.sort((a,b) => a.name.localeCompare(b.name)).forEach(b => brgySel.add(new Option(b.name, b.code)));
            updateAddress();
        });

        brgySel.addEventListener('change', updateAddress);

        function updateAddress() {
            const parts = [];
            if (brgySel.selectedIndex > 0) parts.push(brgySel.options[brgySel.selectedIndex].text);
            if (citySel.selectedIndex > 0) parts.push(citySel.options[citySel.selectedIndex].text);
            if (provSel.selectedIndex > 0 && provSel.value !== 'NCR') parts.push(provSel.options[provSel.selectedIndex].text);
            if (regionSel.selectedIndex > 0) parts.push(regionSel.options[regionSel.selectedIndex].text);
            
            if (parts.length > 0) {
                if (actualAddr) actualAddr.value = parts.join(', ');
                isFormDirty = true;
            }
        }

        loadRegions();
    }
}

function initSchoolSearch() {
    const schoolInput = document.getElementById('live-school-input');
    const dropdown = document.getElementById('custom-school-dropdown');
    const loader = document.getElementById('school-loader');
    
    if (!schoolInput || schoolInput.dataset.searchBound) return;
    schoolInput.dataset.searchBound = 'true';

    let nationalSchoolRegistry = [];
    if(loader) loader.classList.remove('hidden');

    if (typeof window.LDSP_SCHOOLS !== 'undefined') {
        window.LDSP_SCHOOLS.forEach(school => { nationalSchoolRegistry.push(school); });
    }

    if (typeof Papa !== 'undefined') {
        let csvUrl = window.LDSP_CONFIG ? window.LDSP_CONFIG.csvPath : "../schools_masterlist.csv";
        Papa.parse(csvUrl, {
            download: true,
            header: true,
            skipEmptyLines: true,
            complete: function(results) {
                const csvSchools = results.data.map(row => row.school_name || row[0]).filter(name => name);
                nationalSchoolRegistry = [...new Set([...nationalSchoolRegistry, ...csvSchools])];
                if(loader) loader.classList.add('hidden');
            },
            error: function(err) {
                console.error("Could not find CSV data.", err);
                if(loader) loader.classList.add('hidden');
            }
        });
    } else {
        if(loader) loader.classList.add('hidden');
    }

    window.selectSchool = function(fullSchoolName) {
        if(schoolInput) schoolInput.value = fullSchoolName;
        if(dropdown) dropdown.classList.add('hidden');
        isFormDirty = true;
    };

    window.forceRecordSchool = function(newSchoolName) {
        if(schoolInput) schoolInput.value = newSchoolName;
        if(dropdown) dropdown.classList.add('hidden');
        isFormDirty = true;
    };

    document.addEventListener('click', function(e) {
        const container = document.getElementById('school-search-container');
        if (container && !container.contains(e.target)) {
            if (dropdown) dropdown.classList.add('hidden');
        }
    });

    schoolInput.addEventListener('input', function(e) {
        const query = e.target.value.trim().toLowerCase();
        if (query.length < 2) {
            if(dropdown) dropdown.classList.add('hidden');
            return;
        }

        let optionsHtml = `
            <li class="px-5 py-3 bg-slate-50 hover:bg-slate-100 cursor-pointer border-b border-slate-200 transition-colors" onclick="forceRecordSchool('${e.target.value.replace(/'/g, "\\'")}')">
                <div class="text-[var(--ldsp-blue)] font-black text-[9px] uppercase tracking-widest mb-1">Unlisted Institution?</div>
                <div class="text-slate-600 text-xs font-semibold">Record exactly as typed: <span class="font-black text-slate-800 bg-white px-2 py-0.5 border border-slate-300 shadow-sm rounded-md ml-1">"${e.target.value}"</span></div>
            </li>
        `;

        let matchCount = 0;
        for (let i = 0; i < nationalSchoolRegistry.length; i++) {
            if (nationalSchoolRegistry[i].toLowerCase().includes(query)) {
                optionsHtml += `
                    <li class="px-5 py-3 hover:bg-slate-50 cursor-pointer border-b border-slate-200/60 last:border-0 transition-colors flex justify-between items-center gap-4 group" onclick="selectSchool('${nationalSchoolRegistry[i].replace(/'/g, "\\'")}')">
                        <div class="text-slate-800 font-bold text-sm truncate drop-shadow-sm">${nationalSchoolRegistry[i]}</div>
                        <span class="text-[9px] px-2 py-1 uppercase font-black tracking-widest border bg-slate-50 text-slate-400 border-slate-200 rounded group-hover:bg-[#00205b] group-hover:text-white group-hover:border-[#00205b] transition-colors shadow-sm">Directory</span>
                    </li>
                `;
                matchCount++;
                if (matchCount >= 25) break; 
            }
        }

        if(dropdown) {
            dropdown.innerHTML = optionsHtml;
            dropdown.classList.remove('hidden');
        }
    });

    schoolInput.addEventListener('focus', function() {
        if (dropdown && dropdown.innerHTML.trim() !== '') {
            dropdown.classList.remove('hidden');
        }
    });
}