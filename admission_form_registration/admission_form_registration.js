// =========================================================
// ADMISSION REGISTRATION FULL LOGIC (UNCUT & UNIFIED)
// =========================================================

// --- 9. NAME FORMATTING UTILITY ---
window.formatNameInput = function(input) {
    // Remove any numbers from the input immediately
    let val = input.value.replace(/[0-9]/g, '');
    
    // Auto-capitalize the first letter of each word
    let words = val.split(' ');
    for (let i = 0; i < words.length; i++) {
        if (words[i].length > 0) {
            words[i] = words[i].charAt(0).toUpperCase() + words[i].substring(1);
        }
    }
    input.value = words.join(' ');
};

// --- 10. SCHOOL YEAR FORMATTING UTILITY ---
window.updateSchoolYear = function() {
    const startInput = document.getElementById('sy_start');
    const endInput = document.getElementById('sy_end');
    const actual = document.getElementById('actual_school_year');
    
    if (actual && startInput && endInput) {
        const startVal = startInput.value.trim();
        if (startVal.length === 4) {
            const nextYear = parseInt(startVal) + 1;
            endInput.value = nextYear;
            actual.value = startVal + '-' + nextYear;
        } else {
            endInput.value = '';
            actual.value = '';
        }
    }
};

// --- 1. EMAIL DUPLICATION & PRE-FILL LOGIC ---
document.addEventListener("DOMContentLoaded", () => {
    const emailInput = document.getElementById('applicant_email');
    const emailWarning = document.getElementById('email_warning');
    const newEmailNotice = document.getElementById('new_email_notice');
    const submitBtn = document.getElementById('submit_btn');
    
    const activeSchoolYear = window.LDSP_CONFIG ? window.LDSP_CONFIG.academicYear : (document.querySelector('input[name="academic_year"]')?.value || '');
    
    const origEmailEl = document.getElementById('original_reapply_email');
    const reapplyAdmEl = document.getElementById('reapply_adm_no');

    if (emailInput) {
        emailInput.addEventListener('input', function() {
            // Auto-convert to lowercase instantly
            this.value = this.value.toLowerCase();

            if (emailWarning) emailWarning.classList.add('hidden');
            emailInput.classList.remove('!border-rose-500', 'focus:!border-rose-500', 'bg-rose-50');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            }

            if (origEmailEl && reapplyAdmEl) {
                if (this.value.trim() !== origEmailEl.value.trim() && this.value.trim() !== '') {
                    reapplyAdmEl.disabled = true; 
                    if (newEmailNotice) newEmailNotice.classList.remove('hidden');
                } else {
                    reapplyAdmEl.disabled = false; 
                    if (newEmailNotice) newEmailNotice.classList.add('hidden');
                }
            }
        });

        emailInput.addEventListener('blur', function() {
            const email = this.value.trim();

            if (email) {
                const formData = new FormData();
                formData.append('ajax_check_email', '1');
                formData.append('email', email);
                formData.append('school_year', activeSchoolYear);

                const fetchUrl = window.LDSP_CONFIG && window.LDSP_CONFIG.fetchUrl ? window.LDSP_CONFIG.fetchUrl : window.location.href;

                fetch(fetchUrl, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.exists) {
                        if (emailWarning) emailWarning.classList.remove('hidden');
                        if (newEmailNotice) newEmailNotice.classList.add('hidden');
                        emailInput.classList.add('!border-rose-500', 'focus:!border-rose-500', 'bg-rose-50');
                        if (submitBtn) {
                            submitBtn.disabled = true;
                            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                        }
                    }
                })
                .catch(error => console.error('Error checking email:', error));
            }
        });
        
        if (emailInput.value) {
            emailInput.dispatchEvent(new Event('blur'));
        }
    }

    // Pre-fill DOB Splitter
    const existingDob = document.getElementById('actual_dob');
    if(existingDob && existingDob.value) {
        const parts = existingDob.value.split('-'); 
        if(parts.length === 3) {
            const months = {"01":"Jan", "02":"Feb", "03":"Mar", "04":"Apr", "05":"May", "06":"Jun", "07":"Jul", "08":"Aug", "09":"Sep", "10":"Oct", "11":"Nov", "12":"Dec"};
            selectDob('year', parts[0], parts[0]);
            selectDob('month', parts[1], months[parts[1]]);
            selectDob('day', parts[2], parts[2]);
        }
    }

    // Pre-fill School Year Splitter
    const actualSy = document.getElementById('actual_school_year');
    if (actualSy && actualSy.value) {
        const parts = actualSy.value.split('-');
        if (parts.length > 0) {
            const syStart = document.getElementById('sy_start');
            if (syStart) {
                syStart.value = parts[0].trim();
                window.updateSchoolYear();
            }
        }
    }
});

// --- 2. SINGLE FILE UPLOAD SYSTEM (5MB LIMIT, TOAST & PREVIEW/UI) ---
let toastTimeout;

window.showFileSizeToast = function() {
    const toast = document.getElementById('file-size-toast');
    if (!toast) return;
    toast.classList.remove('hidden');
    
    // Trigger animation
    setTimeout(() => {
        toast.classList.remove('translate-y-[-20px]', 'opacity-0');
        toast.classList.add('translate-y-0', 'opacity-100');
    }, 10);
    
    // Reset timer if already running
    clearTimeout(toastTimeout);
    toastTimeout = setTimeout(hideFileSizeToast, 4000); // Auto hide after 4 seconds
};

window.hideFileSizeToast = function() {
    const toast = document.getElementById('file-size-toast');
    if (!toast) return;
    toast.classList.remove('translate-y-0', 'opacity-100');
    toast.classList.add('translate-y-[-20px]', 'opacity-0');
    
    setTimeout(() => {
        toast.classList.add('hidden');
    }, 300); // Wait for transition to finish before hiding
};

window.handleSingleFileUpload = function(input, cardId) {
    const card = document.getElementById(cardId);
    const btnContainer = input.nextElementSibling; 

    if (input.files && input.files[0]) {
        const file = input.files[0];
        
        // 1. STRICT 5MB LIMIT CHECK
        const maxSize = 5 * 1024 * 1024; // 5MB in bytes
        if (file.size > maxSize) {
            input.value = ''; // Reset the input immediately to block the file
            showFileSizeToast(); // Trigger the floating HTML message
            return; // Halt process
        }

        // 2. PROCEED IF VALID
        const size = (file.size / (1024 * 1024)).toFixed(2) + ' MB';
        const objectURL = URL.createObjectURL(file);

        // Update card text
        const filenameText = card.querySelector('.filename-text');
        const filesizeText = card.querySelector('.filesize-text');
        if (filenameText) filenameText.textContent = file.name;
        if (filesizeText) filesizeText.textContent = size;

        // Setup the Preview (Eye) Button
        const previewBtn = card.querySelector('.preview-btn');
        if (previewBtn) {
            previewBtn.onclick = function(e) {
                e.preventDefault();
                if (typeof openDocModal === 'function') {
                    openDocModal(objectURL, file.type);
                }
            };
        }

        // Toggle UI states
        if (btnContainer) btnContainer.classList.add('hidden');
        if (card) card.classList.remove('hidden');
    }
};

window.removeSingleFile = function(inputId, cardId) {
    const input = document.getElementById(inputId);
    const card = document.getElementById(cardId);
    if (!input || !card) return;
    
    const btnContainer = input.nextElementSibling;

    // Clear the file input
    input.value = '';

    // Revert UI to Upload state
    card.classList.add('hidden');
    if (btnContainer) btnContainer.classList.remove('hidden');
};

// --- 3. DOCUMENT OVERLAY (MESSENGER-STYLE ZOOM / PAN) LOGIC ---
let currentZoom = 1;
let isDragging = false;
let startX, startY, translateX = 0, translateY = 0;

window.openDocModal = function(url, type) {
    const modal = document.getElementById('doc-modal');
    const img = document.getElementById('doc-image');
    const iframe = document.getElementById('doc-iframe');
    const controls = document.getElementById('doc-zoom-controls');
    
    if(!modal) return;
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    
    setTimeout(() => { modal.classList.remove('opacity-0'); }, 10);
    
    currentZoom = 1; translateX = 0; translateY = 0;
    updateTransform();

    if (type.startsWith('image/')) { 
        if(img) {
            img.src = url;
            img.classList.remove('hidden');
        }
        if(iframe) iframe.classList.add('hidden');
        if(controls) controls.classList.remove('hidden');
    } else {
        if(iframe) {
            iframe.src = url;
            iframe.classList.remove('hidden');
        }
        if(img) img.classList.add('hidden');
        if(controls) controls.classList.add('hidden');
    }
};

window.closeDocModal = function() {
    const modal = document.getElementById('doc-modal');
    if(!modal) return;
    modal.classList.add('opacity-0'); 
    
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        const img = document.getElementById('doc-image');
        const iframe = document.getElementById('doc-iframe');
        if(img) img.src = '';
        if(iframe) iframe.src = '';
    }, 300);
};

function zoomDoc(step) {
    currentZoom += step;
    if (currentZoom < 0.5) currentZoom = 0.5;
    if (currentZoom > 4) currentZoom = 4;
    updateTransform();
}

function resetZoom() {
    currentZoom = 1;
    translateX = 0;
    translateY = 0;
    updateTransform();
}

function updateTransform() {
    const img = document.getElementById('doc-image');
    if(img) img.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentZoom})`;
}

const docContainer = document.getElementById('doc-container');
if(docContainer) {
    docContainer.addEventListener('wheel', (e) => {
        const img = document.getElementById('doc-image');
        if (img && !img.classList.contains('hidden')) {
            e.preventDefault();
            window.zoomDoc(e.deltaY > 0 ? -0.1 : 0.1);
        }
    }, { passive: false });
}

function startDrag(e) {
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
    updateTransform();
});

window.addEventListener('mouseup', () => {
    isDragging = false;
    const img = document.getElementById('doc-image');
    if(img) {
        img.classList.remove('cursor-grabbing');
        img.classList.add('cursor-grab');
    }
});

// --- 4. VIEW TRANSITIONS (SMOOTH) ---
window.switchView = function(viewId) {
    if (viewId === 'instructions-view' && window.location.search.includes('reapply')) {
        const fallbackUrl = window.LDSP_CONFIG ? window.LDSP_CONFIG.indexUrl : 'admission.php';
        window.location.href = fallbackUrl;
        return;
    }
    
    const formView = document.getElementById('form-view');
    const instView = document.getElementById('instructions-view');

    if (!formView || !instView) return;

    if (viewId === 'form-view') {
        instView.classList.remove('view-active');
        instView.classList.add('view-hidden');
        setTimeout(() => {
            formView.classList.remove('view-hidden');
            formView.classList.add('view-active');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }, 400); 
    } else {
        formView.classList.remove('view-active');
        formView.classList.add('view-hidden');
        setTimeout(() => {
            instView.classList.remove('view-hidden');
            instView.classList.add('view-active');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }, 400);
    }
};

// --- 5. CUSTOM DATE OF BIRTH DROPDOWNS ---
window.toggleDobDropdown = function(id) {
    document.querySelectorAll('.dropdown-container ul').forEach(ul => {
        if(ul.id !== id) ul.classList.add('hidden');
    });
    const list = document.getElementById(id);
    if(list) list.classList.toggle('hidden');
};

window.selectDob = function(type, value, text) {
    const displaySpan = document.getElementById('display_' + type);
    if(displaySpan) {
        displaySpan.innerText = text;
        displaySpan.classList.remove('text-gray-500');
        displaySpan.classList.add('text-[#111827]', 'font-semibold');
    }
    
    const dobVal = document.getElementById('dob_' + type + '_val');
    if(dobVal) dobVal.value = value;
    
    const list = document.getElementById(type + '_list');
    if(list) list.classList.add('hidden');
    
    const m = document.getElementById('dob_month_val')?.value;
    const d = document.getElementById('dob_day_val')?.value;
    const y = document.getElementById('dob_year_val')?.value;
    const actualDob = document.getElementById('actual_dob');
    
    if (m && d && y && actualDob) {
        actualDob.value = `${y}-${m}-${d}`;
    }
};

document.addEventListener('click', function(e) {
    if (!e.target.closest('.dropdown-container')) {
        document.querySelectorAll('.dropdown-container ul').forEach(ul => {
            ul.classList.add('hidden');
        });
    }
});


// --- 6. HYBRID SCHOOL SEARCH LOGIC (CSV + LIVE MAP + SELF-LEARNING DB) ---
const schoolInput = document.getElementById('live-school-input');
const schoolDropdown = document.getElementById('custom-school-dropdown');
const schoolLoader = document.getElementById('school-loader');
let schoolDebounceTimer;
let nationalSchoolRegistry = [];

document.addEventListener("DOMContentLoaded", () => {
    if(!schoolInput || !schoolDropdown || !schoolLoader) return;

    schoolLoader.classList.remove('hidden');
    
    // 1. Load from self-learning Database
    if(window.LDSP_CONFIG && window.LDSP_CONFIG.dbSchools) {
        window.LDSP_CONFIG.dbSchools.forEach(school => { nationalSchoolRegistry.push(school); });
    }

    // 2. Load from static CSV Masterlist
    const csvPath = window.LDSP_CONFIG && window.LDSP_CONFIG.csvPath ? window.LDSP_CONFIG.csvPath : "../schools_masterlist.csv";

    if (typeof Papa !== 'undefined') {
        Papa.parse(csvPath, {
            download: true,
            header: true,
            skipEmptyLines: true,
            complete: function(results) {
                const csvSchools = results.data.map(row => row.school_name || row[0]).filter(name => name);
                nationalSchoolRegistry = [...new Set([...nationalSchoolRegistry, ...csvSchools])];
                schoolLoader.classList.add('hidden');
            },
            error: function() {
                schoolLoader.classList.add('hidden');
            }
        });
    } else {
        schoolLoader.classList.add('hidden');
    }
});

// Self-Learning Engine: Silently save new schools to database
window.learnNewSchool = function(schoolName) {
    if (!schoolName || schoolName.trim() === '') return;
    
    const fetchUrl = window.LDSP_CONFIG && window.LDSP_CONFIG.fetchUrl ? window.LDSP_CONFIG.fetchUrl : window.location.href;
    const formData = new FormData();
    formData.append('ajax_add_school', '1');
    formData.append('school_name', schoolName.trim());
    
    fetch(fetchUrl, { method: 'POST', body: formData }).catch(e => console.error(e));
    
    if (!nationalSchoolRegistry.includes(schoolName.trim())) {
        nationalSchoolRegistry.push(schoolName.trim());
    }
};

window.selectSchool = function(fullSchoolName) {
    if(schoolInput) schoolInput.value = fullSchoolName;
    if(schoolDropdown) schoolDropdown.classList.add('hidden');
    learnNewSchool(fullSchoolName);
};

window.forceRecordSchool = function(newSchoolName) {
    if(schoolInput) schoolInput.value = newSchoolName;
    if(schoolDropdown) schoolDropdown.classList.add('hidden');
    learnNewSchool(newSchoolName);
};

document.addEventListener('click', function(e) {
    const searchContainer = document.getElementById('school-search-container');
    if (searchContainer && schoolDropdown && !searchContainer.contains(e.target)) {
        schoolDropdown.classList.add('hidden');
    }
});

if(schoolInput) {
    schoolInput.addEventListener('input', function(e) {
        const query = e.target.value.trim();
        clearTimeout(schoolDebounceTimer);
        
        if (query.length < 2) {
            schoolDropdown.classList.add('hidden');
            schoolLoader.classList.add('hidden');
            return;
        }

        // STEP 1: Search Local CSV / DB Registry Instantly
        let localMatches = [];
        const lowerQuery = query.toLowerCase();
        for (let i = 0; i < nationalSchoolRegistry.length; i++) {
            if (nationalSchoolRegistry[i].toLowerCase().includes(lowerQuery)) {
                localMatches.push(nationalSchoolRegistry[i]);
                if (localMatches.length >= 10) break; // Limit to 10 local matches
            }
        }

        const getCustomOptionHtml = (q) => `
            <li class="px-4 py-3 bg-[#f8fafc] hover:bg-[#f1f5f9] cursor-pointer transition-colors border-t border-slate-200" onclick="forceRecordSchool('${q.replace(/'/g, "\\'")}')">
                <div class="text-[var(--ldsp-blue)] font-black text-[9px] uppercase tracking-widest mb-1">Institution Not Found?</div>
                <div class="text-gray-600 text-xs font-medium">Record exactly as typed: <span class="font-bold text-[#00205b] bg-white px-1.5 py-0.5 border border-gray-200 rounded shadow-sm">"${q}"</span></div>
            </li>
        `;

        let optionsHtml = '';
        localMatches.forEach(school => {
            optionsHtml += `
                <li class="px-4 py-2.5 hover:bg-[#f8fafc] cursor-pointer transition-colors flex justify-between items-center gap-3" onclick="selectSchool('${school.replace(/'/g, "\\'")}')">
                    <div class="text-[#00205b] font-semibold text-xs truncate">${school}</div>
                    <span class="text-[8px] px-1.5 py-0.5 uppercase font-black tracking-[0.1em] bg-[#00205b] text-white rounded shadow-sm shrink-0">DepEd / CHED</span>
                </li>
            `;
        });

        schoolDropdown.innerHTML = optionsHtml + getCustomOptionHtml(query);
        schoolDropdown.classList.remove('hidden');

        // STEP 2: Fire Live Map Search in the Background
        schoolLoader.classList.remove('hidden');
        schoolDebounceTimer = setTimeout(async () => {
            try {
                // Search OSM specifically prioritizing schools in PH
                const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query + ' school')}&countrycodes=ph&addressdetails=1&limit=5`);
                const data = await res.json();
                
                let mapHtml = '';
                if (data && data.length > 0) {
                    data.forEach(place => {
                        const cleanName = place.display_name.replace(/,\s*Philippines$/i, '');
                        const mainName = place.name || place.address.amenity || "Institution";
                        
                        // Avoid exact duplicates with local masterlist
                        if (!localMatches.some(s => s.toLowerCase() === mainName.toLowerCase())) {
                            mapHtml += `
                                <li class="px-4 py-2.5 hover:bg-emerald-50/30 cursor-pointer transition-colors flex justify-between items-center gap-3 border-t border-slate-100" onclick="selectSchool('${mainName.replace(/'/g, "\\'")}')">
                                    <div class="flex flex-col overflow-hidden">
                                        <span class="text-[#00205b] font-bold text-xs truncate">${mainName}</span>
                                        <span class="text-slate-500 text-[9px] truncate">${cleanName}</span>
                                    </div>
                                    <span class="text-[8px] px-1.5 py-0.5 uppercase font-black tracking-[0.1em] bg-emerald-50 text-emerald-600 border border-emerald-200 rounded shrink-0 shadow-sm">Live Map</span>
                                </li>
                            `;
                        }
                    });
                }
                
                // Merge Local + Live Map + Custom Option
                schoolDropdown.innerHTML = optionsHtml + mapHtml + getCustomOptionHtml(query);
                schoolLoader.classList.add('hidden');
            } catch (e) {
                console.error("Live Map fetch failed", e);
                schoolLoader.classList.add('hidden');
            }
        }, 800); // 800ms debounce
    });

    schoolInput.addEventListener('focus', function() {
        if (schoolDropdown.innerHTML.trim() !== '') {
            schoolDropdown.classList.remove('hidden');
        }
    });
}

// --- 7. PSGC COMPLETE ADDRESS LOGIC ---
const regionSel = document.getElementById('addr_region');
const provSel = document.getElementById('addr_province');
const citySel = document.getElementById('addr_city');
const brgySel = document.getElementById('addr_brgy');
const actualAddr = document.getElementById('actual_address');

if (regionSel && provSel && citySel && brgySel && actualAddr) {
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
        citySel.innerHTML = '<option value="" disabled selected>City / Mun</option>';
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
        citySel.innerHTML = '<option value="" disabled selected>City / Mun</option>';
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
        
        const hasRegion = regionSel.selectedIndex > 0;
        const hasProvince = provSel.selectedIndex > 0;
        const hasCity = citySel.selectedIndex > 0;
        const hasBrgy = brgySel.selectedIndex > 0;

        if (hasBrgy) parts.push(brgySel.options[brgySel.selectedIndex].text);
        if (hasCity) parts.push(citySel.options[citySel.selectedIndex].text);
        if (hasProvince && provSel.value !== 'NCR') parts.push(provSel.options[provSel.selectedIndex].text);
        if (hasRegion) parts.push(regionSel.options[regionSel.selectedIndex].text);
        
        // ONLY populate the final address if all levels are selected
        if (hasRegion && hasProvince && hasCity && hasBrgy) {
            actualAddr.value = parts.join(', ');
        } else {
            actualAddr.value = ''; // Force it to remain empty to trigger the required validation
        }
    }

    loadRegions();
}

// --- 8. PLACE OF BIRTH (OPENSTREETMAP NOMINATIM API) ---
const pobInput = document.getElementById('live-pob-input');
const pobDropdown = document.getElementById('custom-pob-dropdown');
const pobLoader = document.getElementById('pob-loader');
let pobDebounceTimer;

if (pobInput && pobDropdown && pobLoader) {

    window.selectPob = function(fullName) {
        pobInput.value = fullName;
        pobDropdown.classList.add('hidden');
    };

    document.addEventListener('click', function(e) {
        const pobContainer = document.getElementById('pob-search-container');
        if (pobContainer && !pobContainer.contains(e.target)) {
            pobDropdown.classList.add('hidden');
        }
    });

    pobInput.addEventListener('input', function(e) {
        const query = e.target.value.trim();
        
        clearTimeout(pobDebounceTimer);
        
        if (query.length < 3) {
            pobDropdown.classList.add('hidden');
            pobLoader.classList.add('hidden');
            return;
        }

        pobLoader.classList.remove('hidden');

        // Debounce to respect Nominatim's 1 request/sec limit
        pobDebounceTimer = setTimeout(async () => {
            try {
                // Fetch from OpenStreetMap Nominatim restricted to Philippines
                const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&countrycodes=ph&addressdetails=1&limit=5`);
                const data = await res.json();
                
                let optionsHtml = '';
                
                if (data && data.length > 0) {
                    data.forEach(place => {
                        // Clean up "Philippines" from the end to make it localized
                        const cleanName = place.display_name.replace(/,\s*Philippines$/i, '');
                        // Extract specific parts if available
                        const cityTown = place.address.city || place.address.town || place.address.municipality || place.name;
                        
                        optionsHtml += `
                            <li class="px-4 py-2.5 hover:bg-[#f8fafc] cursor-pointer transition-colors flex justify-between items-center gap-3" onclick="selectPob('${cleanName.replace(/'/g, "\\'")}')">
                                <div class="flex flex-col overflow-hidden">
                                    <span class="text-[#00205b] font-bold text-xs truncate">${cityTown}</span>
                                    <span class="text-slate-500 text-[9px] truncate">${cleanName}</span>
                                </div>
                                <span class="text-[8px] px-1.5 py-0.5 uppercase font-black tracking-[0.1em] bg-emerald-50 text-emerald-600 border border-emerald-200 rounded shrink-0 shadow-sm">Map</span>
                            </li>
                        `;
                    });
                } else {
                    optionsHtml = `
                        <li class="px-4 py-3 bg-[#f8fafc] hover:bg-[#f1f5f9] cursor-pointer transition-colors" onclick="selectPob('${query.replace(/'/g, "\\'")}')">
                            <div class="text-[var(--ldsp-blue)] font-black text-[9px] uppercase tracking-widest mb-1">Location Not Found?</div>
                            <div class="text-gray-600 text-xs font-medium">Use exactly as typed: <span class="font-bold text-[#00205b] bg-white px-1.5 py-0.5 border border-gray-200 rounded shadow-sm">"${query}"</span></div>
                        </li>
                    `;
                }

                pobDropdown.innerHTML = optionsHtml;
                pobDropdown.classList.remove('hidden');
                pobLoader.classList.add('hidden');
                
            } catch (error) {
                console.error("Map Search Error: ", error);
                pobLoader.classList.add('hidden');
            }
        }, 600); // 600ms delay to prevent API blocking
    });

    pobInput.addEventListener('focus', function() {
        if (pobDropdown.innerHTML.trim() !== '') {
            pobDropdown.classList.remove('hidden');
        }
    });
}