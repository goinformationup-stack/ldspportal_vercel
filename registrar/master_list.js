// =========================================================
// MASTER LIST SPECIFIC LOGIC (ZERO-REFRESH SPA - FULLY CONNECTED)
// =========================================================

let currentMasterlistStudents = [];
let lastDataString = null; // Set to null initially to prevent load flicker

// -------------------------------------------------------------
// MODAL & TOAST FALLBACK GUARDS (Prevents missing-function errors)
// -------------------------------------------------------------
if (typeof window.openModal !== 'function') {
    window.openModal = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => modal.classList.add('modal-active'), 10);
            window.isRegistrarModalOpen = true;
        }
    };
}

if (typeof window.closeModal !== 'function') {
    window.closeModal = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('modal-active');
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                window.isRegistrarModalOpen = false;
            }, 200);
        }
    };
}

if (typeof window.showToast !== 'function') {
    window.showToast = function(msg, type = 'info') {
        const container = document.getElementById('toastContainer');
        if (!container) return;
        const toast = document.createElement('div');
        const bg = type === 'success' ? 'bg-emerald-600' : (type === 'error' ? 'bg-rose-600' : 'bg-slate-800');
        toast.className = `${bg} text-white text-xs px-3.5 py-2 rounded-lg shadow-lg transition-all duration-300 transform translate-y-2 opacity-0 pointer-events-auto flex items-center gap-2`;
        toast.innerText = msg;
        container.appendChild(toast);
        requestAnimationFrame(() => toast.classList.remove('translate-y-2', 'opacity-0'));
        setTimeout(() => {
            toast.classList.add('opacity-0', 'translate-y-2');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    };
}

// SPA Engine Variables
window.isRegistrarModalOpen = false;
let isFormDirty = false;

// 1. Silent DOM Tracker (Pauses background refresh while typing)
document.addEventListener('focusin', (e) => {
    const t = e.target.tagName;
    if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT') {
        isFormDirty = true;
    }
});
document.addEventListener('focusout', (e) => {
    const t = e.target.tagName;
    if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT') {
        setTimeout(() => { 
            const activeElement = document.activeElement;
            if (!activeElement) {
                isFormDirty = false;
                return;
            }
            const activeTag = activeElement.tagName;
            if (activeTag !== 'INPUT' && activeTag !== 'TEXTAREA' && activeTag !== 'SELECT') {
                isFormDirty = false; 
            }
        }, 150);
    }
});

// -------------------------------------------------------------
// FILTER CONTROLS & ARCHIVE MODAL
// -------------------------------------------------------------
window.handleYearFilterChange = function(select) {
    if (select.value === 'open_all_years_modal') {
        const modal = document.getElementById('allYearsModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => modal.classList.add('modal-active'), 10);
            window.isRegistrarModalOpen = true;
        }
        
        let lastVal = select.getAttribute('data-last-value');
        select.value = lastVal ? lastVal : 'All';
        return;
    }
    
    select.setAttribute('data-last-value', select.value);
    window.triggerDynamicFetch(true);
};

window.closeAllYearsModal = function() {
    const modal = document.getElementById('allYearsModal');
    if (modal) {
        modal.classList.remove('modal-active');
        setTimeout(() => { 
            modal.classList.add('hidden'); 
            modal.classList.remove('flex'); 
            window.isRegistrarModalOpen = false;
        }, 200);
    }
};

window.selectYearFilter = function(year) {
    const select = document.getElementById('f_year');
    if (select) {
        let optionExists = false;
        for (let i = 0; i < select.options.length; i++) {
            if (select.options[i].value === year) {
                optionExists = true;
                break;
            }
        }
        
        if (!optionExists && year !== 'open_all_years_modal') {
            const newOpt = document.createElement('option');
            newOpt.value = year;
            newOpt.text = year;
            const viewAllOpt = select.querySelector('option[value="open_all_years_modal"]');
            if (viewAllOpt) {
                select.insertBefore(newOpt, viewAllOpt);
            } else {
                select.appendChild(newOpt);
            }
        }
        select.value = year;
        select.setAttribute('data-last-value', year);
    }
    
    document.querySelectorAll('.year-btn').forEach(btn => {
        btn.classList.remove('border-[#00205b]', 'bg-blue-50', 'text-[#00205b]');
        btn.classList.add('border-slate-200', 'bg-white', 'text-slate-600');
    });
    
    const safeYearId = year.replace('-', '_');
    const activeBtn = document.getElementById('btn_year_' + safeYearId);
    if (activeBtn) {
        activeBtn.classList.remove('border-slate-200', 'bg-white', 'text-slate-600');
        activeBtn.classList.add('border-[#00205b]', 'bg-blue-50', 'text-[#00205b]');
    }
    
    window.closeAllYearsModal();
    window.triggerDynamicFetch(true);
};

window.resetMasterFilters = function() {
    const prog = document.getElementById('f_program');
    if (prog) prog.value = 'All';
    const yl = document.getElementById('f_year_lvl');
    if (yl) yl.value = 'All';
    const sec = document.getElementById('f_section');
    if (sec && sec.options.length > 0) sec.value = '';
    const srch = document.getElementById('rosterSearch');
    if (srch) srch.value = '';
    window.triggerDynamicFetch(true);
};

// Live Quick Search (Connected to Cards & Roster Rows)
window.filterRosterCards = function() {
    const query = (document.getElementById('rosterSearch')?.value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('.excel-table tbody tr, .student-row');

    let visibleStudents = 0;
    let visibleMales = 0;
    let visibleFemales = 0;
    let visibleReg = 0;
    let visibleIrreg = 0;

    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        if (!query || text.includes(query)) {
            row.style.display = '';
            visibleStudents++;
            
            // Live gender count
            const rowText = row.innerText;
            if (/\bMale\b|\bM\b/i.test(rowText)) visibleMales++;
            else if (/\bFemale\b|\bF\b/i.test(rowText)) visibleFemales++;
            
            // Live classification count
            if (/irreg/i.test(rowText)) visibleIrreg++;
            else visibleReg++;
        } else {
            row.style.display = 'none';
        }
    });

    // Update Top Metric Cards live if a search query is active
    if (query) {
        const totalEl = document.getElementById('stat_total_enrolled');
        if (totalEl) totalEl.innerText = visibleStudents;

        const maleEl = document.getElementById('stat_male_count');
        if (maleEl) maleEl.innerText = visibleMales;

        const femaleEl = document.getElementById('stat_female_count');
        if (femaleEl) femaleEl.innerText = visibleFemales;

        const regEl = document.getElementById('stat_reg_count');
        if (regEl) regEl.innerText = `Reg: ${visibleReg}`;

        const irregEl = document.getElementById('stat_irreg_count');
        if (irregEl) irregEl.innerText = `Irreg: ${visibleIrreg}`;
    }
};

// 2. SPA Form Interception Engine
window.initSPAEngine = function() {
    document.querySelectorAll('.spa-form').forEach(form => {
        if (form.dataset.spaBound) return;
        form.dataset.spaBound = 'true';
        
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            let submitBtn = Array.from(form.elements).find(el => el.type === 'submit' && el.matches(':focus'));
            if (!submitBtn) {
                submitBtn = form.querySelector('button[type="submit"]');
            }
            
            const originalText = submitBtn ? submitBtn.innerHTML : 'Save';
            
            if (submitBtn) {
                submitBtn.innerHTML = '<svg class="w-3 h-3 animate-spin inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
                submitBtn.disabled = true;
            }
            
            const formData = new FormData(form);
            formData.append('ajax_post', '1');
            if (submitBtn) {
                if (submitBtn.name) {
                    const btnValue = submitBtn.value ? submitBtn.value : '1';
                    formData.append(submitBtn.name, btnValue);
                }
            }

            try {
                const res = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();
                
                if (data.status === 'success') {
                    if (window.showToast) window.showToast(data.message, 'success');
                    
                    const modal = form.closest('.modal-overlay');
                    
                    // NEW CHECK: If data-no-close is true, DO NOT close the modal, instead reload the grid inside it
                    if(modal && form.getAttribute('data-no-close') !== 'true') {
                        modal.classList.remove('modal-active');
                        setTimeout(() => { modal.classList.add('hidden'); modal.classList.remove('flex'); }, 200);
                        window.isRegistrarModalOpen = false;
                    } else if (form.getAttribute('data-no-close') === 'true') {
                        // Soft reload the tab to update the grades without closing the modal
                        const isCurrent = document.getElementById('tab_current_term')?.className.includes('text-white');
                        window.loadAcademicTab(isCurrent ? 'current' : 'history');
                    }
                    
                    window.triggerDynamicFetch(true); 
                } else {
                    if (window.showToast) window.showToast(data.message, 'error');
                }
            } catch (err) {
                if (window.showToast) window.showToast("Network Error", 'error');
            } finally {
                if (submitBtn) {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
                isFormDirty = false;
            }
        });
    });
};

// 3. Glitch-Free Background Data Polling (CONNECTS ALL FILTERS & CARDS)
window.triggerDynamicFetch = async function(force = false) {
    if (!force) {
        if (window.isRegistrarModalOpen) return;
        if (isFormDirty) return;
    }
    
    const f_semester_el = document.getElementById('f_semester');
    const f_year_el     = document.getElementById('f_year');
    const f_program_el  = document.getElementById('f_program');
    const f_year_lvl_el = document.getElementById('f_year_lvl');
    const f_section_el  = document.getElementById('f_section');
    
    const f_semester = f_semester_el ? f_semester_el.value : '1st Semester';
    const f_year     = f_year_el ? f_year_el.value : '';
    const f_program  = f_program_el ? f_program_el.value : 'All';
    const f_year_lvl = f_year_lvl_el ? f_year_lvl_el.value : 'All';
    const f_section  = f_section_el ? f_section_el.value : '';

    // Synchronize all 5 filters into query parameters
    const params = new URLSearchParams({
        api_refresh: 1,
        f_semester: f_semester,
        f_year: f_year,
        f_program: f_program,
        f_year_lvl: f_year_lvl,
        f_section: f_section
    });
    
    const cleanUrl = window.location.pathname + '?' + params.toString().replace('api_refresh=1&', '').replace('&api_refresh=1', '');
    window.history.replaceState({}, '', cleanUrl);

    // Update Console Subtitle
    const consoleLabel = document.getElementById('active_console_label');
    if (consoleLabel) {
        let secText = (f_section && f_section !== '') ? ` &bull; Section ${f_section}` : '';
        consoleLabel.innerHTML = `A.Y. ${f_year} &bull; ${f_semester}${secText}`;
    }

    try {
        const response = await fetch(`${window.location.pathname}?${params.toString()}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) return;
        
        const data = await response.json();
        const newDataString = JSON.stringify(data);
        
        if (!force) {
            if (lastDataString === null) {
                lastDataString = newDataString;
                return;
            }
            if (newDataString === lastDataString) return; 
        }
        
        lastDataString = newDataString;

        // --- UPDATE SECTION DROPDOWN DYNAMICALLY FROM DATABASE ---
        if (f_section_el && data.available_sections !== undefined) {
            const currentVal = f_section_el.value;
            f_section_el.innerHTML = '';
            
            if (!data.available_sections || data.available_sections.length === 0) {
                const opt = document.createElement('option');
                opt.value = '';
                opt.text = 'No Sections Available';
                f_section_el.appendChild(opt);
                if (currentVal !== '') {
                    f_section_el.value = '';
                }
            } else {
                const defaultOpt = document.createElement('option');
                defaultOpt.value = '';
                defaultOpt.text = 'Please select a section';
                f_section_el.appendChild(defaultOpt);

                data.available_sections.forEach(secName => {
                    const opt = document.createElement('option');
                    opt.value = secName;
                    opt.text = `Section ${secName}`;
                    if (secName === currentVal) {
                        opt.selected = true;
                    }
                    f_section_el.appendChild(opt);
                });
                
                if (currentVal === 'All' || (currentVal !== '' && !data.available_sections.includes(currentVal))) {
                    f_section_el.value = '';
                }
            }
        }

        // --- 1. CONNECT & UPDATE TOP 4 METRIC CARDS ---
        const totalEl = document.getElementById('stat_total_enrolled');
        if (totalEl && (data.total_enrolled !== undefined || data.roster_total !== undefined)) {
            totalEl.innerText = Number(data.total_enrolled ?? data.roster_total ?? 0).toLocaleString();
        }

        const secEl = document.getElementById('stat_active_sections');
        if (secEl && data.active_sections !== undefined) {
            secEl.innerText = Number(data.active_sections).toLocaleString();
        }

        const maleEl = document.getElementById('stat_male_count');
        if (maleEl && data.male_count !== undefined) {
            maleEl.innerText = data.male_count;
        }

        const femaleEl = document.getElementById('stat_female_count');
        if (femaleEl && data.female_count !== undefined) {
            femaleEl.innerText = data.female_count;
        }

        const ratioEl = document.getElementById('stat_gender_ratio');
        if (ratioEl && data.gender_ratio !== undefined) {
            ratioEl.innerText = `Ratio: ${data.gender_ratio}`;
        }

        const regEl = document.getElementById('stat_reg_count');
        if (regEl && data.reg_count !== undefined) {
            regEl.innerText = `Reg: ${data.reg_count}`;
        }

        const irregEl = document.getElementById('stat_irreg_count');
        if (irregEl && data.irreg_count !== undefined) {
            irregEl.innerText = `Irreg: ${data.irreg_count}`;
        }

        const rosterContainer = document.getElementById('master_roster_container');
        const rosterTotalBadge = document.getElementById('roster_total_badge');
        const rosterBalBadge = document.getElementById('roster_balance_badge');
        
        if (rosterContainer && data.roster_html) {
            
            // --- SAVE SCROLL LOCATIONS ---
            const mainScrollArea = document.getElementById('mainScrollArea');
            const mainScroll = mainScrollArea ? mainScrollArea.scrollTop : 0;
            
            const activePanesScroll = [];
            document.querySelectorAll('.sec-pane').forEach(pane => {
                const progPane = pane.closest('.prog-pane');
                const yearPane = pane.closest('.year-pane');
                activePanesScroll.push({
                    prog: progPane ? progPane.getAttribute('data-prog') : '',
                    year: yearPane ? yearPane.getAttribute('data-year') : '',
                    sec: pane.getAttribute('data-sec'),
                    top: pane.scrollTop,
                    left: pane.scrollLeft
                });
            });

            // --- INJECT NEW DATA ---
            rosterContainer.innerHTML = data.roster_html;

            // --- RESTORE ACTIVE TABS ---
            if (window.activeProgTab) {
                const btn = document.querySelector(`.prog-tab[data-prog="${window.activeProgTab}"]`);
                if (btn) window.switchProgTab(btn);
            }
            
            const yearTabEntries = Object.entries(window.activeYearTabs);
            for (let i = 0; i < yearTabEntries.length; i++) {
                const prog = yearTabEntries[i][0];
                const year = yearTabEntries[i][1];
                const pane = document.querySelector(`.prog-pane[data-prog="${prog}"]`);
                if (pane) {
                    const btn = pane.querySelector(`.year-tab[data-year="${year}"]`);
                    if (btn) window.switchYearTab(btn);
                }
            }
            
            const secTabEntries = Object.entries(window.activeSectionTabs);
            for (let i = 0; i < secTabEntries.length; i++) {
                const prog = secTabEntries[i][0];
                const yearsObj = secTabEntries[i][1];
                
                const yearEntries = Object.entries(yearsObj);
                for (let j = 0; j < yearEntries.length; j++) {
                    const year = yearEntries[j][0];
                    const sec = yearEntries[j][1];
                    const yearPane = document.querySelector(`.prog-pane[data-prog="${prog}"] .year-pane[data-year="${year}"]`);
                    if (yearPane) {
                        const secBtn = yearPane.querySelector(`.sec-tab[data-sec="${sec}"]`);
                        if(secBtn) window.switchSectionTab(secBtn);
                    }
                }
            }
            
            // --- RESTORE EXACT SCROLL POSITIONS ---
            if (mainScrollArea) {
                mainScrollArea.scrollTop = mainScroll;
            }
            
            activePanesScroll.forEach(pos => {
                if (pos.top > 0 || pos.left > 0) {
                    const pane = document.querySelector(`.prog-pane[data-prog="${pos.prog}"] .year-pane[data-year="${pos.year}"] .sec-pane[data-sec="${pos.sec}"]`);
                    if (pane) {
                        pane.scrollTop = pos.top;
                        pane.scrollLeft = pos.left;
                    }
                }
            });

            window.initSPAEngine();
            window.filterRosterCards();
        }
        
        // Update Console Badges
        const totalCount = data.total_enrolled ?? data.roster_total;
        if (rosterTotalBadge && totalCount !== undefined) {
            rosterTotalBadge.innerText = `${totalCount} Registered`;
        }
        const balCount = data.total_balance ?? data.roster_balance;
        if (rosterBalBadge && balCount !== undefined) {
            rosterBalBadge.innerHTML = `<svg class="w-3.5 h-3.5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg><span>${balCount} Balances</span>`;
            if (balCount > 0) {
                rosterBalBadge.classList.remove('hidden');
            } else {
                rosterBalBadge.classList.add('hidden');
            }
        }
    } catch (err) { 
        console.error("Auto-sync error:", err); 
    }
};

// -------------------------------------------------------------
// WORKBOOK TAB SWITCHING UI (3 LEVELS)
// -------------------------------------------------------------
window.activeProgTab = null;
window.activeYearTabs = {};
window.activeSectionTabs = {};

window.switchProgTab = function(btn) {
    const prog = btn.getAttribute('data-prog');
    window.activeProgTab = prog;

    document.querySelectorAll('.prog-tab').forEach(t => {
        t.className = "prog-tab px-4 py-2 text-xs uppercase tracking-wider whitespace-nowrap rounded-lg transition-all flex items-center gap-2 bg-transparent text-slate-600 hover:bg-slate-200 font-medium border border-transparent";
    });
    btn.className = "prog-tab px-4 py-2 text-xs uppercase tracking-wider whitespace-nowrap rounded-lg transition-all flex items-center gap-2 bg-white text-emerald-800 font-bold shadow-sm border border-slate-200";

    document.querySelectorAll('.prog-pane').forEach(p => {
        p.classList.add('hidden');
        p.classList.remove('block');
    });

    const targetPane = document.querySelector(`.prog-pane[data-prog="${prog}"]`);
    if(targetPane) {
        targetPane.classList.remove('hidden');
        targetPane.classList.add('block');
        
        if(!window.activeYearTabs[prog]) {
            const firstYearBtn = targetPane.querySelector('.year-tab');
            if(firstYearBtn) window.switchYearTab(firstYearBtn);
        }
    }
};

window.switchYearTab = function(btn) {
    const year = btn.getAttribute('data-year');
    const pane = btn.closest('.prog-pane');
    if(!pane) return;
    
    const prog = pane.getAttribute('data-prog');
    window.activeYearTabs[prog] = year;

    pane.querySelectorAll('.year-tab').forEach(t => {
        t.className = "year-tab px-3.5 py-1.5 text-xs rounded-lg transition-all flex items-center gap-1.5 bg-white text-slate-700 border border-slate-200 hover:bg-slate-100 font-semibold";
    });
    btn.className = "year-tab px-3.5 py-1.5 text-xs rounded-lg transition-all flex items-center gap-1.5 bg-emerald-700 text-white shadow-sm font-bold";

    pane.querySelectorAll('.year-pane').forEach(p => {
        p.classList.add('hidden');
        p.classList.remove('block');
    });

    const targetYearPane = pane.querySelector(`.year-pane[data-year="${year}"]`);
    if(targetYearPane) {
        targetYearPane.classList.remove('hidden');
        targetYearPane.classList.add('block');
        
        if(!window.activeSectionTabs[prog]) window.activeSectionTabs[prog] = {};
        if(!window.activeSectionTabs[prog][year]) {
            const firstSecBtn = targetYearPane.querySelector('.sec-tab');
            if(firstSecBtn) window.switchSectionTab(firstSecBtn);
        }
    }
};

window.switchSectionTab = function(btn) {
    const sec = btn.getAttribute('data-sec');
    const yearPane = btn.closest('.year-pane');
    const progPane = btn.closest('.prog-pane');
    if(!yearPane || !progPane) return;

    const prog = progPane.getAttribute('data-prog');
    const year = yearPane.getAttribute('data-year');
    
    if(!window.activeSectionTabs[prog]) window.activeSectionTabs[prog] = {};
    window.activeSectionTabs[prog][year] = sec;

    yearPane.querySelectorAll('.sec-tab').forEach(t => {
        t.className = "sec-tab px-3 py-1.5 text-xs rounded-lg transition-all flex items-center bg-slate-100 text-slate-700 hover:bg-slate-200 font-semibold border border-slate-200";
    });
    btn.className = "sec-tab px-3 py-1.5 text-xs rounded-lg transition-all flex items-center bg-slate-900 text-white shadow-sm font-bold";

    yearPane.querySelectorAll('.sec-pane').forEach(p => {
        p.classList.add('hidden');
        p.classList.remove('block');
    });

    const targetSecPane = yearPane.querySelector(`.sec-pane[data-sec="${sec}"]`);
    if(targetSecPane) {
        targetSecPane.classList.remove('hidden');
        targetSecPane.classList.add('block');
    }
};

// -------------------------------------------------------------
// GRADES SPREADSHEET GRID API (Now Auto-Calculates Weighted GWA)
// -------------------------------------------------------------
window.openGradesGrid = async function(btn) {
    window.isRegistrarModalOpen = true; 
    
    const prog = btn.getAttribute('data-prog');
    const year = btn.getAttribute('data-year');
    const sec = btn.getAttribute('data-sec');
    const sem = btn.getAttribute('data-sem');
    const sy = btn.getAttribute('data-sy');
    
    let students = [];
    try { students = JSON.parse(btn.getAttribute('data-students')); } catch(e) {}

    document.getElementById('gradesgrid_subtitle').innerText = `${prog} | ${year} | Section ${sec}`;
    document.getElementById('gradesGridThead').innerHTML = '';
    document.getElementById('gradesGridTbody').innerHTML = '';
    document.getElementById('gradesLoader').classList.remove('hidden');

    window.openModal('sectionGradesModal');

    try {
        const res = await fetch(`master_list.php?fetch_section_grades=1&program=${encodeURIComponent(prog)}&year_level=${encodeURIComponent(year)}&section=${encodeURIComponent(sec)}&semester=${encodeURIComponent(sem)}&school_year=${encodeURIComponent(sy)}`);
        const data = await res.json();
        
        if (data.status === 'success') {
            renderGradesGrid(data.subjects, students, data.grades);
        } else {
            if(window.showToast) window.showToast("Failed to load grades grid.", "error");
        }
    } catch (e) {
        console.error(e);
        if(window.showToast) window.showToast("Error connecting to server.", "error");
    } finally {
        document.getElementById('gradesLoader').classList.add('hidden');
    }
};

function renderGradesGrid(subjects, students, grades) {
    const thead = document.getElementById('gradesGridThead');
    const tbody = document.getElementById('gradesGridTbody');

    let trHead = '<tr>';
    trHead += '<th class="px-4 py-3 border-r border-slate-200 sticky left-0 bg-slate-100 z-40 shadow-[2px_0_5px_rgba(0,0,0,0.05)] w-10">#</th>';
    trHead += '<th class="px-4 py-3 border-r border-slate-200 sticky left-[40px] bg-slate-100 z-40 shadow-[2px_0_5px_rgba(0,0,0,0.05)] min-w-[220px]">Student Name</th>';
    
    subjects.forEach(sub => {
        trHead += `<th class="px-4 py-3 border-r border-slate-200 text-center" title="${sub.descriptive_title}">${sub.course_code}</th>`;
    });
    trHead += '<th class="px-4 py-3 border-r border-slate-200 text-center text-[#00205b]">GWA</th>';
    trHead += '</tr>';
    thead.innerHTML = trHead;

    if (students.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${subjects.length + 3}" class="px-4 py-8 text-center text-[10px] text-slate-400 font-bold uppercase tracking-widest">No students found in this section.</td></tr>`;
        return;
    }

    let html = '';
    
    const sortedStudents = [...students].sort((a,b) => {
        const nameA = a.name ? a.name.toLowerCase() : '';
        const nameB = b.name ? b.name.toLowerCase() : '';
        if (nameA < nameB) return -1;
        if (nameA > nameB) return 1;
        return 0;
    });

    sortedStudents.forEach((st, idx) => {
        const bal = st.balance ? st.balance : 0;
        const has_failed = st.has_failed ? parseInt(st.has_failed) > 0 : false;
        
        let rowClass = "hover:bg-slate-50 transition-colors group";
        if (bal > 0) rowClass = "bg-amber-50/50 hover:bg-amber-50 transition-colors group";
        if (has_failed) rowClass = "bg-rose-50/50 hover:bg-rose-50 transition-colors group";

        let stickyBg = "bg-white group-hover:bg-slate-50";
        if (bal > 0) stickyBg = "bg-amber-50 group-hover:bg-amber-100";
        if (has_failed) stickyBg = "bg-rose-50 group-hover:bg-rose-100";

        const failedBadge = has_failed ? ' <span class="px-1.5 py-0.5 rounded bg-rose-100 text-rose-700 text-[9px] font-black uppercase tracking-wider ml-1 border border-rose-200">FAILED</span>' : '';

        html += `<tr class="${rowClass}">`;
        html += `<td class="px-4 py-2 border-r border-slate-200 text-slate-400 font-bold sticky left-0 ${stickyBg} z-30 shadow-[2px_0_5px_rgba(0,0,0,0.02)]">${idx + 1}</td>`;
        html += `<td class="px-4 py-2 border-r border-slate-200 sticky left-[40px] ${stickyBg} z-30 shadow-[2px_0_5px_rgba(0,0,0,0.02)]">
                    <div class="font-black text-[#00205b]">${st.name}${failedBadge}</div>
                    <div class="text-[9px] font-mono text-[#c5a02c]">${st.id}</div>
                 </td>`;
        
        let totalGradeUnits = 0;
        let totalUnits = 0;
        const stGrades = grades[st.user_id] ? grades[st.user_id] : {};

        subjects.forEach(sub => {
            const g = stGrades[sub.course_code];
            let displayGrade = '-';
            let gradeClass = 'text-slate-400';
            
            if (g) {
                displayGrade = g;
                const numGrade = parseFloat(g);
                const numUnits = parseFloat(sub.units) || 0;

                if (!isNaN(numGrade)) {
                    if (numUnits > 0) {
                        totalGradeUnits += (numGrade * numUnits);
                        totalUnits += numUnits;
                    }

                    if (numGrade <= 3.0) {
                        gradeClass = 'text-emerald-600 font-black';
                    } else {
                        gradeClass = 'text-rose-600 font-black';
                    }
                } else {
                    gradeClass = 'text-[#00205b] font-black'; 
                }
            }
            
            html += `<td class="px-4 py-2 border-r border-slate-200 text-center text-xs ${gradeClass}">${displayGrade}</td>`;
        });

        let gwa = '-';
        let gwaColor = 'text-[#00205b]';
        if (totalUnits > 0) {
            const computed = totalGradeUnits / totalUnits;
            gwa = computed.toFixed(2);
            gwaColor = (computed > 3.00) ? 'text-rose-600' : 'text-[#00205b]';
        }
        
        html += `<td class="px-4 py-2 border-r border-slate-200 text-center font-black ${gwaColor} bg-slate-50">${gwa}</td>`;
        html += `</tr>`;
    });
    tbody.innerHTML = html;
}

window.downloadGradesCSV = function() {
    const subtitleEl = document.getElementById('gradesgrid_subtitle');
    let subtitle = 'grades';
    if (subtitleEl) {
        subtitle = subtitleEl.innerText.replace(/[^a-z0-9]/gi, '_').toLowerCase();
    }
    
    let csv = [];
    document.querySelectorAll('#gradesGridTable tr').forEach(row => {
        let rowData = [];
        row.querySelectorAll('th, td').forEach(cell => {
            let text = cell.innerText.replace(/\n/g, ' - ').trim();
            rowData.push('"' + text.replace(/"/g, '""') + '"');
        });
        csv.push(rowData.join(','));
    });
    const link = document.createElement('a'); 
    link.download = `section_grades_${subtitle}.csv`; 
    link.href = window.URL.createObjectURL(new Blob([csv.join('\n')], {type: 'text/csv'}));
    document.body.appendChild(link); 
    link.click(); 
    document.body.removeChild(link);
};

// -------------------------------------------------------------
// ASYNC DB FETCH: STUDENT PROFILE & ACADEMIC RECORDS 
// -------------------------------------------------------------
window.currentProfileData = null;

window.openStudentModal = async function(btn) {
    window.isRegistrarModalOpen = true;
    try {
        const student = JSON.parse(btn.getAttribute('data-profile'));
        window.currentProfileData = student;
        
        const mDetailName = document.getElementById('m_detail_name');
        if (mDetailName) mDetailName.innerText = student.name ? student.name : 'Unknown Student';
        
        const mStudentId = document.getElementById('m_studentid');
        if (mStudentId) {
            const idVal = student.id ? student.id : 'Pending';
            mStudentId.innerHTML = `ID: <span class="text-[#c5a02c]">${idVal}</span>`;
        }
        
        const mStudentType = document.getElementById('m_student_type');
        if (mStudentType) {
            mStudentType.innerText = student.status ? student.status : 'Regular';
        }
        
        if (student.name) {
            const mInitial = document.getElementById('m_initial');
            if (mInitial) mInitial.innerText = student.name.charAt(0).toUpperCase();
        }

        const mEmail = document.getElementById('m_email');
        if (mEmail) mEmail.innerText = student.email ? student.email : 'N/A';
        
        const mGender = document.getElementById('m_gender');
        if (mGender) mGender.innerText = student.gender ? student.gender : 'N/A';
        
        const mDob = document.getElementById('m_dob');
        if (mDob) mDob.innerText = student.dob ? student.dob : 'N/A';
        
        const mAddress = document.getElementById('m_address');
        if (mAddress) mAddress.innerText = student.address ? student.address : 'N/A';
        
        const mModality = document.getElementById('m_modality');
        if (mModality) mModality.innerText = student.learning_mode ? student.learning_mode : 'Face-to-Face';
        
        const mSemester = document.getElementById('m_semester');
        if (mSemester) mSemester.innerText = student.semester ? student.semester : 'N/A';
        
        const mSchool = document.getElementById('m_school');
        if (mSchool) mSchool.innerText = student.school_last_attended ? student.school_last_attended : 'Not Provided';
        
        const mSchoolYear = document.getElementById('m_school_year');
        if (mSchoolYear) mSchoolYear.innerText = student.school_year_attended ? student.school_year_attended : 'Not Provided';

        const progLabel = document.getElementById('m_prog_year_sec');
        if(progLabel) {
            const pText = student.program ? student.program : 'UNASSIGNED';
            const yText = student.year_level ? student.year_level : 'N/A';
            const sText = student.assigned_section ? student.assigned_section : 'N/A';
            progLabel.innerText = `${pText} / ${yText} / Sec ${sText}`;
        }

        window.openModal('studentProfileModal');
        window.loadAcademicTab('current');

    } catch(e) {
        console.error("Profile Modal Error:", e);
        if(window.showToast) window.showToast("Failed to parse profile data.", "error");
    }
};

window.closeStudentModal = function() {
    window.closeModal('studentProfileModal');
};

window.loadAcademicTab = async function(tabType) {
    const student = window.currentProfileData;
    if (!student) return;

    const btnCurrent = document.getElementById('tab_current_term');
    const btnHistory = document.getElementById('tab_full_history');
    const tbody = document.getElementById('m_subjects_table');

    // UI Tab Toggle
    if (tabType === 'current') {
        if(btnCurrent) btnCurrent.className = "px-3 py-1.5 bg-[#107c41] text-white text-[9px] font-black uppercase tracking-widest rounded border border-[#107c41] shadow-sm transition-colors cursor-pointer";
        if(btnHistory) btnHistory.className = "px-3 py-1.5 bg-transparent text-slate-600 hover:text-[#107c41] text-[9px] font-black uppercase tracking-widest rounded border border-transparent transition-colors cursor-pointer";
    } else {
        if(btnHistory) btnHistory.className = "px-3 py-1.5 bg-[#107c41] text-white text-[9px] font-black uppercase tracking-widest rounded border border-[#107c41] shadow-sm transition-colors cursor-pointer";
        if(btnCurrent) btnCurrent.className = "px-3 py-1.5 bg-transparent text-slate-600 hover:text-[#107c41] text-[9px] font-black uppercase tracking-widest rounded border border-transparent transition-colors cursor-pointer";
    }

    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="7" class="px-4 py-8 text-center text-[10px] text-slate-500 font-bold uppercase tracking-widest"><svg class="animate-spin h-6 w-6 mx-auto mb-2 text-[#107c41]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Accessing Live Academic Database...</td></tr>';
    }

    let endpoint = '';
    const stIdSafe = encodeURIComponent(student.id ? student.id : '');
    const stProgSafe = encodeURIComponent(student.program ? student.program : '');
    const stYearSafe = encodeURIComponent(student.year_level ? student.year_level : '');
    const stSemSafe = encodeURIComponent(student.semester ? student.semester : '');
    const stUidSafe = encodeURIComponent(student.user_id ? student.user_id : 0);

    if (tabType === 'current') {
        endpoint = `master_list.php?fetch_academics=1&student_id=${stIdSafe}&program=${stProgSafe}&year_level=${stYearSafe}&semester=${stSemSafe}`;
    } else {
        endpoint = `master_list.php?fetch_full_academics=1&student_id=${stIdSafe}&user_internal_id=${stUidSafe}`;
    }

    try {
        const response = await fetch(endpoint);
        if (!response.ok) throw new Error("HTTP error");
        const data = await response.json();

        if (data.status === 'success') {
            let records = [];
            if (tabType === 'current') {
                records = data.subjects ? data.subjects : [];
            } else {
                records = data.history ? data.history : [];
            }
            
            if (tbody) tbody.innerHTML = '';

            if (records && records.length > 0) {
                let currentGroup = '';
                
                // Variables for the GWA Computation
                let sumGradeUnits = 0;
                let sumUnits = 0;

                records.forEach(sub => {
                    const group = `${sub.year_level ? sub.year_level : 'N/A'} - ${sub.semester ? sub.semester : 'N/A'}`;
                    
                    if (tabType === 'history') {
                        if (group !== currentGroup) {
                            currentGroup = group;
                            if (tbody) tbody.innerHTML += `<tr><td colspan="7" class="px-4 py-2 bg-slate-200/60 text-[#00205b] font-black text-[10px] uppercase tracking-widest border-y border-slate-300 shadow-inner">${group}</td></tr>`;
                        }
                    }

                    let teacherDisplay = `<span class="text-[9px] font-bold bg-rose-50 text-rose-500 border border-rose-200 px-1.5 py-0.5 rounded uppercase shadow-sm">Unassigned</span>`;
                    if (sub.teacher_name && sub.teacher_name.trim().length > 1) {
                        teacherDisplay = `Prof. ${sub.teacher_name}`;
                    }

                    const sCode = sub.subject_code ? sub.subject_code : 'N/A';
                    const sDesc = sub.subject_description ? sub.subject_description : 'N/A';
                    const sYear = sub.year_level ? sub.year_level : '-';
                    const sSem = sub.semester ? sub.semester : '-';
                    const sUnits = sub.units ? parseFloat(sub.units).toFixed(2) : '-';

                    // --- NEW MISSING GRADE LOGIC WITH SELECT OPTIONS & AUTO-COMPUTATION ---
                    const hasNoGrade = !sub.grade || sub.grade.trim() === '' || sub.grade === '-';
                    const currentGrade = hasNoGrade ? '' : sub.grade.trim();
                    let sGradeDisplay = '';
                    let rowBg = 'hover:bg-slate-50';

                    let gColor = 'text-slate-400';
                    let selectBorderColor = 'border-slate-300';
                    
                    if (!hasNoGrade) {
                        const numGrade = parseFloat(currentGrade);
                        const numUnits = parseFloat(sub.units) || 0;
                        if (!isNaN(numGrade)) {
                            if (numUnits > 0) {
                                sumGradeUnits += (numGrade * numUnits);
                                sumUnits += numUnits;
                            }
                            if (numGrade <= 3.0) {
                                gColor = 'text-[#107c41]';
                                selectBorderColor = 'border-[#107c41]';
                            } else {
                                gColor = 'text-rose-600';
                                selectBorderColor = 'border-rose-300';
                                rowBg = 'bg-rose-50/40 hover:bg-rose-50';
                            }
                        } else {
                            gColor = 'text-[#00205b]'; 
                        }
                    } else {
                        rowBg = 'bg-rose-50 hover:bg-rose-100';
                        gColor = 'text-rose-700';
                        selectBorderColor = 'border-rose-300';
                    }

                    const gradeOpts = ['1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00', '5.00'];
                    let optionsHtml = `<option value="" disabled ${hasNoGrade ? 'selected' : ''}>Grade</option>`;
                    gradeOpts.forEach(g => {
                        optionsHtml += `<option value="${g}" ${currentGrade === g ? 'selected' : ''}>${g}</option>`;
                    });
                    
                    if (!hasNoGrade) {
                        optionsHtml += `<option value="CLEAR" class="text-rose-600 font-black">Clear Grade</option>`;
                    }

                    sGradeDisplay = `
                        <form method="POST" class="spa-form flex items-center gap-1 m-0 p-0 justify-center" data-no-close="true">
                            <input type="hidden" name="update_missing_grade" value="1">
                            <input type="hidden" name="student_id" value="${student.id || ''}">
                            <input type="hidden" name="subject_code" value="${sCode}">
                            <input type="hidden" name="subject_desc" value="${sDesc}">
                            <input type="hidden" name="units" value="${sub.units || 0}">
                            <input type="hidden" name="semester" value="${sSem}">
                            <select name="new_grade" required class="w-[70px] h-6 pl-1 text-[10px] font-bold border ${selectBorderColor} rounded focus:outline-none focus:ring-1 focus:ring-emerald-500 bg-white ${gColor} shadow-inner cursor-pointer appearance-none text-center">
                                ${optionsHtml}
                            </select>
                            <button type="submit" class="${hasNoGrade ? 'bg-rose-600 hover:bg-rose-700 text-white' : 'bg-slate-200 hover:bg-slate-300 text-slate-600'} rounded px-2 h-6 text-[9px] font-bold uppercase shadow-sm transition">Save</button>
                        </form>
                    `;

                    if (tbody) {
                        tbody.innerHTML += `
                            <tr class="${rowBg} transition-colors">
                                <td class="px-4 py-2.5 font-mono text-[#00205b] font-bold">${sCode}</td>
                                <td class="px-4 py-2.5 text-slate-700">${sDesc}</td>
                                <td class="px-4 py-2.5 text-center font-bold text-slate-600">${sYear}</td>
                                <td class="px-4 py-2.5 text-slate-600 font-semibold">${teacherDisplay}</td>
                                <td class="px-4 py-2.5 text-center font-bold text-slate-600">${sSem}</td>
                                <td class="px-4 py-2.5 text-center font-bold">${sGradeDisplay}</td>
                                <td class="px-4 py-2.5 text-center font-bold text-slate-600">${sUnits}</td>
                            </tr>
                        `;
                    }
                });

                // Display Auto-Computed GWA Row
                let gwaDisplay = '-';
                let gwaColor = 'text-slate-400';
                if (sumUnits > 0) {
                    const computedGwa = sumGradeUnits / sumUnits;
                    gwaDisplay = computedGwa.toFixed(2);
                    gwaColor = (computedGwa > 3.00) ? 'text-rose-600' : 'text-[#107c41]';
                }

                if (tbody) {
                    tbody.innerHTML += `
                        <tr class="bg-slate-100 border-t border-slate-300">
                            <td colspan="5" class="px-4 py-3 text-right font-black text-[#00205b] uppercase tracking-wider text-[10px]">
                                ${tabType === 'history' ? 'Cumulative GWA' : 'Term GWA'}
                            </td>
                            <td class="px-4 py-3 text-center font-black text-base ${gwaColor}">
                                ${gwaDisplay}
                            </td>
                            <td class="px-4 py-3 text-center font-bold text-slate-600 text-[10px]">
                                ${sumUnits.toFixed(2)} Earned Units
                            </td>
                        </tr>
                    `;
                }

            } else {
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="7" class="px-4 py-8 text-center text-[10px] text-slate-400 uppercase tracking-widest font-bold bg-slate-50">No academic records found for this view.</td></tr>';
                }
            }

            // Bind SPA Engine to newly injected forms (Crucial for AJAX submit without page reload)
            if (typeof window.initSPAEngine === 'function') {
                window.initSPAEngine();
            }

        } else {
            throw new Error(data.message);
        }
    } catch(e) {
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="7" class="px-4 py-6 text-center text-[10px] text-rose-500 uppercase tracking-widest font-bold">Error accessing database records. Check console.</td></tr>`;
        }
    }
};

// -------------------------------------------------------------
// MASTERLIST MODALS & CSV EXPORT
// -------------------------------------------------------------
window.openSectionLimitModal = function(btn) {
    document.getElementById('limit_semester').value = btn.getAttribute('data-sem');
    document.getElementById('limit_program').value  = btn.getAttribute('data-prog');
    document.getElementById('limit_year').value     = btn.getAttribute('data-year');
    document.getElementById('limit_section').value  = btn.getAttribute('data-sec');
    document.getElementById('limit_display').innerText = `${btn.getAttribute('data-prog')} | ${btn.getAttribute('data-year')} - Section ${btn.getAttribute('data-sec')}`;
    document.getElementById('limit_input').value    = btn.getAttribute('data-limit');
    window.openModal('sectionLimitModal');
};

window.openMassMoveModal = function(btn) {
    const prog = btn.getAttribute('data-prog');
    document.getElementById('move_semester').value = btn.getAttribute('data-sem');
    document.getElementById('move_sy').value       = btn.getAttribute('data-sy');
    document.getElementById('move_program').value  = prog;
    document.getElementById('move_year').value     = btn.getAttribute('data-year');
    document.getElementById('move_from_display').innerText = `${prog} | ${btn.getAttribute('data-year')} - Section ${btn.getAttribute('data-sec')}`;
    
    const selectAllBtn = document.getElementById('selectAllStudentsBtn');
    if (selectAllBtn) {
        selectAllBtn.checked = false;
    }

    const progSelect = document.getElementById('new_program_name');
    if (progSelect) { 
        for(let i=0; i<progSelect.options.length; i++) { 
            if(progSelect.options[i].value === prog) { 
                progSelect.selectedIndex = i; 
                break; 
            } 
        } 
    }

    const listContainer = document.getElementById('move_students_list');
    listContainer.innerHTML = '';
    try {
        const studentData = JSON.parse(btn.getAttribute('data-students'));
        studentData.forEach(st => {
            const label = document.createElement('label');
            label.className = "flex items-center gap-3 p-3 bg-white border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50 transition-colors shadow-sm";
            label.innerHTML = `<input type="checkbox" name="selected_students[]" value="${st.email}" class="w-4 h-4 text-emerald-600 bg-white border-slate-300 rounded focus:ring-emerald-500 cursor-pointer move-student-cb"><div><div class="text-[11px] font-bold text-[#00205b]">${st.name}</div><div class="text-[9px] font-mono text-slate-400 mt-0.5">${st.id}</div></div>`;
            listContainer.appendChild(label);
        });
    } catch (e) {}
    window.openModal('massMoveModal');
};

window.toggleAllMoveStudents = function(checkbox) { 
    document.querySelectorAll('.move-student-cb').forEach(cb => cb.checked = checkbox.checked); 
};

window.openSectionMasterlist = function(btn) {
    window.isRegistrarModalOpen = true; 
    document.getElementById('masterlist_subtitle').innerText = `${btn.getAttribute('data-prog')} | ${btn.getAttribute('data-year')} | Section ${btn.getAttribute('data-sec')}`;
    try { 
        currentMasterlistStudents = JSON.parse(btn.getAttribute('data-students')); 
        const mSort = document.getElementById('masterlistSort');
        if (mSort) {
            mSort.value = 'alpha_asc'; 
        }
        window.sortMasterlist(); 
    } catch (e) {}
    window.openModal('sectionMasterlistModal');
};

window.sortMasterlist = function() {
    const sortEl = document.getElementById('masterlistSort');
    const sortVal = sortEl ? sortEl.value : 'alpha_asc';
    
    let sorted = [...currentMasterlistStudents].sort((a, b) => {
        const aL = a.last_name ? a.last_name : '';
        const aF = a.first_name ? a.first_name : '';
        const bL = b.last_name ? b.last_name : '';
        const bF = b.first_name ? b.first_name : '';

        const nameA = (aL + ' ' + aF).toUpperCase();
        const nameB = (bL + ' ' + bF).toUpperCase();
        
        if (sortVal === 'alpha_desc') {
            if (nameA < nameB) return 1;
            if (nameA > nameB) return -1;
            return 0;
        } else if (sortVal === 'date_new') {
            const dA = a.enrolled_at ? new Date(a.enrolled_at).getTime() : 0;
            const dB = b.enrolled_at ? new Date(b.enrolled_at).getTime() : 0;
            return dB - dA;
        } else if (sortVal === 'date_old') {
            const dA = a.enrolled_at ? new Date(a.enrolled_at).getTime() : 0;
            const dB = b.enrolled_at ? new Date(b.enrolled_at).getTime() : 0;
            return dA - dB;
        } else if (sortVal === 'balance_desc') {
            const balA = a.balance ? a.balance : 0;
            const balB = b.balance ? b.balance : 0;
            if (balB === balA) {
                if (nameA < nameB) return -1;
                if (nameA > nameB) return 1;
                return 0;
            }
            return balB - balA;
        } else if (sortVal === 'balance_asc') {
            const balA = a.balance ? a.balance : 0;
            const balB = b.balance ? b.balance : 0;
            if (balA === balB) {
                if (nameA < nameB) return -1;
                if (nameA > nameB) return 1;
                return 0;
            }
            return balA - balB;
        } else {
            if (nameA < nameB) return -1;
            if (nameA > nameB) return 1;
            return 0;
        }
    });
    
    const tbody = document.getElementById('masterlist_tbody');
    if (tbody) {
        tbody.innerHTML = '';
        sorted.forEach((st, i) => {
            const tr = document.createElement('tr'); 
            
            const bal = st.balance ? st.balance : 0;
            const has_failed = st.has_failed ? parseInt(st.has_failed) > 0 : false;
            
            let trClass = "hover:bg-slate-50 transition-colors";
            if (bal > 0) trClass = "bg-amber-50/50 hover:bg-amber-50 transition-colors";
            if (has_failed) trClass = "bg-rose-50/50 hover:bg-rose-50 transition-colors";
            
            tr.className = trClass;
            
            const eAt = st.enrolled_at ? st.enrolled_at : '';
            const isInvalidDate = (eAt === '1970-01-01 00:00:00' || eAt === '');
            const dateStr = isInvalidDate ? 'N/A' : new Date(eAt).toLocaleDateString();
            
            let balClass = 'text-slate-400';
            let balStr = '0.00';

            if (bal > 0) {
                balClass = 'text-amber-600 font-bold';
                balStr = '₱' + parseFloat(bal).toLocaleString('en-US', {minimumFractionDigits: 2});
            }

            const rIdx = i + 1;
            const sId = st.id ? st.id : '';
            const sLast = st.last_name ? st.last_name : '';
            const sFirst = st.first_name ? st.first_name : '';
            const sMid = st.middle_name ? st.middle_name : '';
            const sGen = st.gender ? st.gender : '';
            const sEm = st.email ? st.email : '';
            const sStat = st.status ? st.status : '';

            const failedBadge = has_failed ? ' <span class="px-1.5 py-0.5 rounded bg-rose-100 text-rose-700 text-[9px] font-black uppercase tracking-wider ml-1 border border-rose-200">FAILED</span>' : '';

            tr.innerHTML = `
                <td class="px-4 py-3 font-bold text-slate-400">${rIdx}</td>
                <td class="px-4 py-3 font-mono font-black text-[#c5a02c]">${sId}</td>
                <td class="px-4 py-3 font-black text-[#00205b]">${sLast}${failedBadge}</td>
                <td class="px-4 py-3 font-bold text-slate-800">${sFirst}</td>
                <td class="px-4 py-3 text-slate-600">${sMid}</td>
                <td class="px-4 py-3">${sGen}</td>
                <td class="px-4 py-3 text-slate-500">${sEm}</td>
                <td class="px-4 py-3"><span class="px-2 py-1 bg-slate-100 text-slate-600 rounded text-[9px] uppercase tracking-widest font-bold border border-slate-200">${sStat}</span></td>
                <td class="px-4 py-3 text-[10px] text-slate-500 font-bold uppercase tracking-wider">${dateStr}</td>
                <td class="px-4 py-3 text-right font-mono ${balClass}">${balStr}</td>
            `;
            tbody.appendChild(tr);
        });
    }
};

window.downloadMasterlistCSV = function() {
    const subtitleEl = document.getElementById('masterlist_subtitle');
    const subtitle = subtitleEl ? subtitleEl.innerText.replace(/[^a-z0-9]/gi, '_').toLowerCase() : 'list';
    
    let csv = [];
    document.querySelectorAll('#masterlistTable tr').forEach(row => {
        let rowData = [];
        row.querySelectorAll('th, td').forEach(cell => {
            const cleanText = cell.innerText.trim().replace(/"/g, '""');
            rowData.push('"' + cleanText + '"');
        });
        csv.push(rowData.join(','));
    });
    const link = document.createElement('a'); 
    link.download = `masterlist_${subtitle}.csv`; 
    link.href = window.URL.createObjectURL(new Blob([csv.join('\n')], {type: 'text/csv'}));
    document.body.appendChild(link); 
    link.click(); 
    document.body.removeChild(link);
};

// INITIALIZE EVERYTHING & ATTACH FILTER LISTENERS
document.addEventListener('DOMContentLoaded', () => {
    const firstProg = document.querySelector('.prog-tab');
    if(firstProg) window.switchProgTab(firstProg);

    if (typeof window.initSPAEngine === 'function') {
        window.initSPAEngine();
    }

    // Connect Program, Year Level, Section & Semester selects to trigger dynamic fetch
    const progSelect = document.getElementById('f_program');
    if (progSelect) {
        progSelect.addEventListener('change', () => window.triggerDynamicFetch(true));
    }
    const yearLvlSelect = document.getElementById('f_year_lvl');
    if (yearLvlSelect) {
        yearLvlSelect.addEventListener('change', () => window.triggerDynamicFetch(true));
    }
    const secSelect = document.getElementById('f_section');
    if (secSelect) {
        secSelect.addEventListener('change', () => window.triggerDynamicFetch(true));
    }
    const semSelect = document.getElementById('f_semester');
    if (semSelect) {
        semSelect.addEventListener('change', () => window.triggerDynamicFetch(true));
    }
    const searchInput = document.getElementById('rosterSearch');
    if (searchInput) {
        searchInput.addEventListener('input', window.filterRosterCards);
    }

    if (document.getElementById('master_roster_container')) {
        setInterval(() => window.triggerDynamicFetch(), 6000);
    }
});