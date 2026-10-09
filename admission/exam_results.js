// =========================================================
// EXAM RESULTS ARCHIVE SPECIFIC LOGIC (READ-ONLY)
// =========================================================

window.filterTable = function() {
    let input = document.getElementById("liveSearch");
    if (!input) return;

    let filter = input.value.toUpperCase();
    let tbody = document.getElementById("results_tbody");
    if (!tbody) return;

    let tr = tbody.getElementsByTagName("tr");

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
};

// -------------------------------------------------------------
// PROFILE MODAL
// -------------------------------------------------------------
window.openProfileModal = function(button) {
    try {
        const data = JSON.parse(button.getAttribute('data-profile'));
        
        const safeSet = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.innerText = (value && value !== 'null' && value !== 'undefined' && String(value).trim() !== '') ? value : 'N/A';
        };

        const fName = data.first_name || '';
        const mName = data.middle_name ? ' ' + data.middle_name : '';
        const lName = data.last_name ? data.last_name + ', ' : '';
        const constructedName = (lName + fName + mName).trim();
        const finalName = data.applicant_name || constructedName || 'Unknown Applicant';

        safeSet('m_applicant_name', finalName);
        
        const initialEl = document.getElementById('m_initial');
        const picEl = document.getElementById('m_profile_pic');
        
        let profilePicUrl = '';
        if (data.uploaded_files) {
            const trimmedFiles = data.uploaded_files.trim();
            if (trimmedFiles !== '') {
                const files = trimmedFiles.split(',');
                for (let i = 0; i < files.length; i++) {
                    if (files[i].trim() !== '') {
                        profilePicUrl = files[0].trim().replace('../', '');
                        break;
                    }
                }
            }
        }
        
        if (profilePicUrl !== '') {
            if(picEl) {
                picEl.src = '../' + profilePicUrl;
                picEl.classList.remove('hidden');
            }
            if(initialEl) initialEl.classList.add('opacity-0');
        } else {
            if(picEl) {
                picEl.classList.add('hidden');
                picEl.src = '';
            }
            if(initialEl) {
                initialEl.classList.remove('opacity-0');
                initialEl.innerText = finalName.charAt(0).toUpperCase();
            }
        }

        safeSet('m_studentid', 'ID: ' + (data.admission_number || 'Pending'));
        
        let typeVal = data.admission_type || data.student_type || 'New';
        safeSet('m_student_type', typeVal);
        
        safeSet('m_dob', data.dob || data.date_of_birth);
        safeSet('m_gender', data.gender || data.sex);
        safeSet('m_pob', data.pob || data.place_of_birth);
        safeSet('m_email', data.personal_email || data.email);
        safeSet('m_inst_email', data.institutional_email || 'Not Provisioned');
        safeSet('m_phone', data.phone || data.contact_number);
        safeSet('m_address', data.address || data.home_address);
        
        let progVal = data.evaluated_program || data.program || 'UNASSIGNED';
        let yearVal = data.evaluated_year || data.year_level || 'N/A';
        let secVal = data.evaluated_section || data.assigned_section || 'N/A';
        
        safeSet('m_detail_placement', `${progVal} / ${yearVal} / Sec ${secVal}`);
        safeSet('m_program', progVal);
        safeSet('m_type', typeVal);
        safeSet('m_year_level', yearVal);

        safeSet('m_school', data.school_last_attended || data.last_school);
        safeSet('m_school_year', data.school_year_attended || data.year_graduated);
        safeSet('m_influence', data.influence_source || data.source_of_info);
        
        safeSet('m_father_name', data.father_name);
        safeSet('m_father_occ', data.father_occupation);
        safeSet('m_father_con', data.father_contact);
        
        safeSet('m_mother_name', data.mother_name);
        safeSet('m_mother_occ', data.mother_occupation);
        safeSet('m_mother_con', data.mother_contact);
        
        safeSet('m_em_name', data.emergency_contact_name || data.emergency_name);
        safeSet('m_em_con', data.emergency_contact_number || data.emergency_number);

        // Documents
        const docList = document.getElementById('m_documents_list');
        if (docList) {
            docList.innerHTML = '';
            
            if (data.uploaded_files) {
                let validFiles = [];
                const rawFiles = data.uploaded_files.split(',');
                for (let i = 0; i < rawFiles.length; i++) {
                    if (rawFiles[i].trim() !== '') {
                        validFiles.push(rawFiles[i].trim());
                    }
                }
                
                let studentType = data.admission_type || data.student_type || '';
                
                let labels = ['Clearance Document'];
                if (studentType.includes('Freshman') || studentType.includes('New') || studentType.includes('Transferee')) { 
                    labels = ['2x2 ID Photo', 'PSA Birth Certificate']; 
                }
                
                if (validFiles.length === 0) {
                    docList.innerHTML = '<span class="text-[9px] text-slate-400 italic">No documents uploaded.</span>';
                } else {
                    validFiles.forEach((file, i) => {
                        const cleanFile = file.replace('../', '');
                        const fileUrl = '../' + cleanFile;
                        const label = labels[i] ? labels[i] : `Document ${i + 1}`;
                        
                        const btnHtml = `
                            <button type="button" onclick="window.viewDocument('${fileUrl}')" class="flex items-center gap-2 px-3 py-1.5 bg-slate-50 border border-slate-200 rounded hover:border-[#00205b] hover:bg-blue-50 transition-colors shadow-sm group cursor-pointer">
                                <div class="p-1 bg-[#00205b]/10 rounded text-[#00205b] group-hover:bg-[#00205b] group-hover:text-white transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </div>
                                <div class="text-left">
                                    <span class="block text-[8px] font-black text-[#00205b] uppercase tracking-widest">${label}</span>
                                    <span class="block text-[7px] font-bold text-slate-400 uppercase tracking-widest group-hover:text-[#00205b]">View File</span>
                                </div>
                            </button>
                        `;
                        docList.innerHTML += btnHtml;
                    });
                }
            } else {
                docList.innerHTML = '<span class="text-[9px] text-slate-400 italic">No documents uploaded.</span>';
            }
        }
        
        const modal = document.getElementById('profileModal');
        if(modal) {
            modal.classList.remove('hidden'); 
            modal.classList.add('flex'); 
            void modal.offsetWidth; 
            modal.classList.add('modal-active');
        }
    } catch(e) {
        console.error("Profile Modal Error:", e);
    }
};

window.closeProfileModal = function() { 
    const modal = document.getElementById('profileModal');
    if(modal) {
        modal.classList.remove('modal-active');
        setTimeout(() => { modal.classList.add('hidden'); modal.classList.remove('flex'); }, 200);
    }
};

// -------------------------------------------------------------
// DOCUMENT VIEWER ENGINE
// -------------------------------------------------------------
window.viewDocument = function(url) {
    const modal = document.getElementById('doc-modal');
    const img = document.getElementById('docImage');
    const iframe = document.getElementById('docIframe');
    const loader = document.getElementById('docLoader');
    const err = document.getElementById('docError');
    const dlBtn = document.getElementById('docDownloadBtn');
    const fallback = document.getElementById('docFallbackLink');

    if(img) img.classList.add('hidden');
    if(iframe) iframe.classList.add('hidden');
    if(err) err.classList.add('hidden');
    if(loader) loader.classList.remove('hidden');

    if(dlBtn) dlBtn.href = url;
    if(fallback) fallback.href = url;

    const cleanUrl = url.split('?')[0];
    const ext = cleanUrl.split('.').pop().toLowerCase();
    
    let isImage = false;
    if (ext === 'jpg' || ext === 'jpeg' || ext === 'png' || ext === 'gif' || ext === 'webp') { isImage = true; }

    if (isImage) {
        if(img) {
            img.src = url;
            img.classList.remove('hidden');
        }
    } else if (ext === 'pdf') {
        if(iframe) {
            iframe.src = url;
            iframe.classList.remove('hidden');
        }
    } else {
        if(loader) loader.classList.add('hidden');
        if(err) {
            err.classList.remove('hidden');
            err.classList.add('flex');
        }
    }

    if(modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
};

window.closeDocModal = function() { 
    const modal = document.getElementById('doc-modal');
    if(modal) {
        modal.classList.add('hidden'); 
        modal.classList.remove('flex'); 
        
        const iframe = document.getElementById('docIframe');
        const img = document.getElementById('docImage');
        if (iframe) iframe.src = ''; 
        if (img) img.src = ''; 
    }
};