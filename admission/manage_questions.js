// =========================================================
// MANAGE QUESTIONS SPECIFIC LOGIC (SPA HANDLER)
// =========================================================

window.openEditModal = function(id, title, activation, deadline, duration_mins, passing) {
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
    
    if (typeof window.openModal === 'function') {
        window.openModal('editExamModal');
    }
};

window.openPreviewModal = function() {
    if (typeof window.openModal === 'function') {
        window.openModal('previewExamModal');
    }
};

// =========================================================
// INTERACTIVE STUDENT EXPERIENCE VIEW LOGIC (FULL-SCREEN)
// =========================================================
window.openExperienceView = function() {
    const overlay = document.getElementById('experienceViewOverlay');
    if (overlay) {
        // 1. TRIGGER NATIVE BROWSER FULL-SCREEN
        let elem = document.documentElement;
        if (elem.requestFullscreen) {
            elem.requestFullscreen();
        } else if (elem.webkitRequestFullscreen) { /* Safari */
            elem.webkitRequestFullscreen();
        } else if (elem.msRequestFullscreen) { /* IE11 */
            elem.msRequestFullscreen();
        }

        // 2. SHOW OVERLAY
        overlay.classList.remove('hidden');
        
        // 3. RESET SIMULATED EXAM STATE TO QUESTION 1
        document.querySelectorAll('.question-block-exp').forEach(block => {
            block.classList.add('hidden');
        });
        
        const firstBlock = document.getElementById('q_block_exp_1');
        if (firstBlock) firstBlock.classList.remove('hidden');
        
        // 4. CLEAR SELECTED DUMMY ANSWERS & HIDE NEXT BUTTONS
        document.querySelectorAll('input[name^="exp_answer_"]').forEach(r => {
            r.checked = false;
        });
        document.querySelectorAll('[id^="exp_next_btn_"]').forEach(btn => {
            btn.classList.add('hidden');
        });
    }
};

window.closeExperienceView = function() {
    const overlay = document.getElementById('experienceViewOverlay');
    if (overlay) {
        overlay.classList.add('hidden');
        
        // EXIT NATIVE BROWSER FULL-SCREEN
        if (document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement) {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            } else if (document.webkitExitFullscreen) { /* Safari */
                document.webkitExitFullscreen();
            } else if (document.msExitFullscreen) { /* IE11 */
                document.msExitFullscreen();
            }
        }
    }
};

window.showExpNextButton = function(num) {
    const btn = document.getElementById('exp_next_btn_' + num);
    if (btn) btn.classList.remove('hidden');
};

window.nextExpQuestion = function(num) {
    const current = document.getElementById('q_block_exp_' + num);
    const next = document.getElementById('q_block_exp_' + (num + 1));
    if (current && next) {
        current.classList.add('hidden');
        next.classList.remove('hidden');
    }
};

document.addEventListener('DOMContentLoaded', () => {
    // Intercept .ajax-form submissions specific to the Manage Questions page
    document.querySelectorAll('.ajax-form').forEach(form => {
        form.addEventListener('submit', async function(e) {
            if (e.defaultPrevented) return; // Respect standard confirm dialogs
            e.preventDefault();
            
            const submitBtn = Array.from(e.target.elements).find(el => el.type === 'submit' && el.matches(':focus')) || e.target.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn ? submitBtn.innerHTML : 'Processing...';
            
            if (submitBtn) {
                submitBtn.innerHTML = '<svg class="w-3 h-3 animate-spin inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Wait...';
                submitBtn.disabled = true;
            }
            
            const formData = new FormData(this);
            formData.append('ajax_request', '1'); // Target the ajaxResponse trigger
            if (submitBtn && submitBtn.name) formData.append(submitBtn.name, '1');
            
            try {
                const res = await fetch(window.location.href, { method: 'POST', body: formData });
                const data = await res.json();
                
                if (data.status === 'success') {
                    if (typeof window.showToast === 'function') window.showToast(data.message, 'success');
                    
                    if (form.closest('#editExamModal')) {
                        window.closeModal('editExamModal');
                    }
                    
                    setTimeout(() => window.location.reload(), 1000); // Reload immediately to show updated questions/order
                } else {
                    if (typeof window.showToast === 'function') window.showToast(data.message, 'error');
                    if (submitBtn) {
                        submitBtn.innerHTML = originalBtnText;
                        submitBtn.disabled = false;
                    }
                }
            } catch (error) {
                if (typeof window.showToast === 'function') window.showToast("Network Error: Could not save data.", 'error');
                if (submitBtn) {
                    submitBtn.innerHTML = originalBtnText;
                    submitBtn.disabled = false;
                }
            }
        });
    });
});