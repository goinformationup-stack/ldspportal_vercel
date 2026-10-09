// =========================================================
// HOME PAGE / MASTER PROFILE LOGIC (SPA ENGINE)
// =========================================================

let lastDataString = '';

// Helper: Normalize HTML string for exact change detection
function cleanHtml(str) {
    return (str || '').replace(/\s+/g, ' ').trim();
}

// 1. Form Dirty Tracker (Prevents glitching while typing)
window.isFormDirty = function() {
    const activeEl = document.activeElement;
    if (activeEl && ['INPUT', 'TEXTAREA', 'SELECT'].includes(activeEl.tagName)) {
        return true;
    }
    return false;
};

// 2. SPA Form Interception Engine
window.initSPAEngine = function() {
    document.querySelectorAll('.spa-form').forEach(form => {
        if (form.dataset.spaBound) return;
        form.dataset.spaBound = 'true';
        
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const submitBtn = Array.from(form.elements).find(el => el.type === 'submit' && el.matches(':focus')) || form.querySelector('button[type="submit"]');
            const originalText = submitBtn ? submitBtn.innerHTML : 'Save Profile';
            
            if (submitBtn) {
                submitBtn.innerHTML = '<svg class="w-4 h-4 animate-spin inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Processing...';
                submitBtn.disabled = true;
            }
            
            const formData = new FormData(form);
            formData.append('ajax_post', '1');
            if (submitBtn && submitBtn.name) formData.append(submitBtn.name, submitBtn.value || '1');

            try {
                const res = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                
                const data = await res.json();
                
                if (data.status === 'success') {
                    if (window.showToast) window.showToast(data.message, 'success');
                    if (data.new_html) {
                        const root = document.getElementById('spa-content-root');
                        if (root) {
                            root.innerHTML = data.new_html;
                            lastDataString = cleanHtml(data.new_html);
                            window.rebindAll();
                        }
                    }
                } else {
                    if (window.showToast) window.showToast(data.message, 'error');
                    if (submitBtn) {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                }
            } catch (err) {
                console.error(err);
                if (window.showToast) window.showToast("Network Error. Please try again.", 'error');
                if (submitBtn) {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            }
        });
    });
};

// 3. Silent DOM Replacement (Real-time Background Polling)
window.triggerDynamicFetch = async function(force = false) {
    if (!force && window.isFormDirty()) return;
    
    try {
        const response = await fetch(`home_page.php?api_refresh=1`, { 
            headers: { 'X-Requested-With': 'XMLHttpRequest' } 
        });
        if (!response.ok) return;
        const data = await response.json();
        
        if (data.new_html) {
            const cleanedIncoming = cleanHtml(data.new_html);
            if (cleanedIncoming !== lastDataString) {
                lastDataString = cleanedIncoming;
                const root = document.getElementById('spa-content-root');
                if (root) {
                    root.innerHTML = data.new_html;
                    window.rebindAll();
                }
            }
        }
    } catch (err) { 
        console.error("Auto-sync error:", err); 
    }
};

window.rebindAll = function() {
    window.initSPAEngine();
};

window.updateDob = function() {
    const m = document.getElementById('dob_month').value;
    const d = document.getElementById('dob_day').value;
    const y = document.getElementById('dob_year').value;
    const dobInput = document.getElementById('actual_dob');
    if (m && d && y && dobInput) dobInput.value = `${y}-${m}-${d}`;
    else if (dobInput) dobInput.value = '';
};

window.initLocationAPI = function() {
    const regionSel = document.getElementById('addr_region');
    const provSel = document.getElementById('addr_province');
    const citySel = document.getElementById('addr_city');
    const brgySel = document.getElementById('addr_brgy');
    const actualAddr = document.getElementById('actual_address');

    if (!regionSel || regionSel.dataset.bound) return;
    regionSel.dataset.bound = 'true';

    async function fetchLocation(url) {
        try { const res = await fetch(url); return await res.json(); } catch(e) { return []; }
    }

    async function loadRegions() {
        const regions = await fetchLocation('https://psgc.gitlab.io/api/regions/');
        regions.sort((a,b) => a.name.localeCompare(b.name)).forEach(r => regionSel.add(new Option(r.name, r.code)));
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

        let cities = provSel.value === 'NCR' 
            ? await fetchLocation(`https://psgc.gitlab.io/api/regions/${regionSel.value}/cities-municipalities/`) 
            : await fetchLocation(`https://psgc.gitlab.io/api/provinces/${provSel.value}/cities-municipalities/`);
            
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
        if (parts.length > 0) { actualAddr.value = parts.join(', '); window.isFormDirty = true; }
    }

    loadRegions();
};

window.initSchoolSearch = function() {
    const schoolInput = document.getElementById('live-school-input');
    const dropdown = document.getElementById('custom-school-dropdown');
    const loader = document.getElementById('school-loader');
    
    if (!schoolInput || schoolInput.dataset.searchBound) return;
    schoolInput.dataset.searchBound = 'true';

    let nationalSchoolRegistry = window.LDSP_SCHOOLS || [];
    if(loader) loader.classList.remove('hidden');

    if (typeof Papa !== 'undefined') {
        Papa.parse("../schools_masterlist.csv", {
            download: true, header: true, skipEmptyLines: true,
            complete: function(results) {
                const csvSchools = results.data.map(row => row.school_name || row[0]).filter(name => name);
                nationalSchoolRegistry = [...new Set([...nationalSchoolRegistry, ...csvSchools])];
                if(loader) loader.classList.add('hidden');
            },
            error: function() { if(loader) loader.classList.add('hidden'); }
        });
    }

    window.selectSchool = function(fullSchoolName) {
        if(schoolInput) schoolInput.value = fullSchoolName;
        if(dropdown) dropdown.classList.add('hidden');
        window.isFormDirty = true;
    };

    window.forceRecordSchool = function(newSchoolName) {
        if(schoolInput) schoolInput.value = newSchoolName;
        if(dropdown) dropdown.classList.add('hidden');
        window.isFormDirty = true;
    };

    document.addEventListener('click', function(e) {
        const container = document.getElementById('school-search-container');
        if (container && !container.contains(e.target) && dropdown) dropdown.classList.add('hidden');
    });

    schoolInput.addEventListener('input', function(e) {
        const query = e.target.value.trim().toLowerCase();
        if (query.length < 2) { if(dropdown) dropdown.classList.add('hidden'); return; }

        let optionsHtml = `<li class="px-5 py-3 bg-slate-50 hover:bg-slate-100 cursor-pointer border-b border-slate-200 transition-colors" onclick="forceRecordSchool('${e.target.value.replace(/'/g, "\\'")}')"><div class="text-[#00205b] font-black text-[10px] uppercase tracking-widest mb-1">Unlisted Institution?</div><div class="text-slate-600 text-xs font-semibold">Record exactly as typed: <span class="font-black text-slate-800 bg-white px-2 py-0.5 border border-slate-300 shadow-sm rounded-md ml-1">"${e.target.value}"</span></div></li>`;
        let matchCount = 0;
        
        for (let i = 0; i < nationalSchoolRegistry.length; i++) {
            if (nationalSchoolRegistry[i].toLowerCase().includes(query)) {
                optionsHtml += `<li class="px-5 py-3 hover:bg-slate-50 cursor-pointer border-b border-slate-200/60 last:border-0 transition-colors flex justify-between items-center gap-4 group" onclick="selectSchool('${nationalSchoolRegistry[i].replace(/'/g, "\\'")}')"><div class="text-slate-800 font-bold text-sm truncate drop-shadow-sm">${nationalSchoolRegistry[i]}</div><span class="text-[9px] px-2 py-1 uppercase font-black tracking-widest border bg-slate-50 text-slate-400 border-slate-200 rounded group-hover:bg-[#00205b] group-hover:text-white transition-colors shadow-sm">Directory</span></li>`;
                matchCount++;
                if (matchCount >= 25) break; 
            }
        }
        if(dropdown) { dropdown.innerHTML = optionsHtml; dropdown.classList.remove('hidden'); }
    });

    schoolInput.addEventListener('focus', function() {
        if (dropdown && dropdown.innerHTML.trim() !== '') dropdown.classList.remove('hidden');
    });
};

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('spa-content-root');
    if (root) lastDataString = cleanHtml(root.innerHTML);
    
    window.rebindAll();
    window.initLocationAPI();
    window.initSchoolSearch();
    
    setInterval(() => window.triggerDynamicFetch(), 5000);
});