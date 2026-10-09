// =========================================================
// FINANCIAL LEDGER SPECIFIC LOGIC (ZERO-REFRESH SPA)
// =========================================================

let ledgerLastDataString = '';

window.resetFilters = function() {
    const filterProg = document.getElementById('filter_program');
    const filterYear = document.getElementById('filter_year');
    
    if (filterProg) filterProg.value = 'All';
    if (filterYear) {
        filterYear.value = 'All';
        filterYear.setAttribute('data-last-value', 'All');
    }
    
    // Update active states in the floating modal archive if it exists
    document.querySelectorAll('.year-btn').forEach(btn => {
        btn.classList.remove('border-[#00205b]', 'bg-blue-50', 'text-[#00205b]');
        btn.classList.add('border-slate-200', 'bg-white', 'text-slate-600');
    });
    const activeBtn = document.getElementById('btn_year_All');
    if (activeBtn) {
        activeBtn.classList.remove('border-slate-200', 'bg-white', 'text-slate-600');
        activeBtn.classList.add('border-[#00205b]', 'bg-blue-50', 'text-[#00205b]');
    }
    
    window.triggerDynamicFetch(true);
};

window.triggerDynamicFetch = async function(force = false) {
    const ledgerBody = document.getElementById('ledger_tbody');
    
    // Prevent fetching if ANY shared modal is open (checks the flag in accounting.js)
    if (!ledgerBody || (!force && window.isAccountingModalOpen)) return; 

    const filterProg = document.getElementById('filter_program');
    const filterYear = document.getElementById('filter_year');
    
    const params = new URLSearchParams({
        api_refresh: 1,
        f_program: filterProg ? filterProg.value : 'All',
        f_year: filterYear ? filterYear.value : 'All'
    });

    // Update the URL without reloading the page so filters persist
    const cleanUrl = window.location.pathname + '?' + params.toString().replace('api_refresh=1&', '').replace('&api_refresh=1', '');
    window.history.replaceState({}, '', cleanUrl);

    try {
        const response = await fetch(`${window.location.pathname}?${params.toString()}`, { 
            headers: { 'X-Requested-With': 'XMLHttpRequest' } 
        });
        
        if (!response.ok) return;
        
        const data = await response.json();
        const newDataString = JSON.stringify(data);
        
        // Only update the DOM if the data actually changed (prevents glitching)
        if (!force && newDataString === ledgerLastDataString) return; 
        ledgerLastDataString = newDataString;

        if (data.ledger_html) {
            ledgerBody.innerHTML = data.ledger_html;
        }

        const footerCountEl = document.getElementById('footer_count');
        const ledgerCountEl = document.getElementById('ledger_count');
        if (footerCountEl) footerCountEl.innerText = `Viewing ${data.ledger_count} item(s)`;
        if (ledgerCountEl) ledgerCountEl.innerText = `${data.ledger_count} CLEARED PAYMENTS`;
        
    } catch (err) { 
        console.error("Ledger sync error:", err); 
    }
};

// Handle Intercepting "View All Years" from the dropdown
window.handleYearFilterChange = function(select) {
    if (select.value === 'open_all_years_modal') {
        const modal = document.getElementById('allYearsModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => modal.classList.add('modal-active'), 10);
            window.isAccountingModalOpen = true;
        }
        // Revert select visually to previous valid value so it doesn't stay stuck
        select.value = select.getAttribute('data-last-value') || 'All';
        return;
    }
    
    select.setAttribute('data-last-value', select.value);
    window.triggerDynamicFetch();
};

window.closeAllYearsModal = function() {
    const modal = document.getElementById('allYearsModal');
    if (modal) {
        modal.classList.remove('modal-active');
        setTimeout(() => { 
            modal.classList.add('hidden'); 
            modal.classList.remove('flex'); 
            window.isAccountingModalOpen = false;
        }, 200);
    }
};

window.selectYearFilter = function(year) {
    const select = document.getElementById('filter_year');
    if (select) {
        let optionExists = Array.from(select.options).some(opt => opt.value === year);
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
    
    // Update active visual states in the modal
    document.querySelectorAll('.year-btn').forEach(btn => {
        btn.classList.remove('border-[#00205b]', 'bg-blue-50', 'text-[#00205b]');
        btn.classList.add('border-slate-200', 'bg-white', 'text-slate-600');
    });
    
    const activeBtn = document.getElementById('btn_year_' + year.replace('-', '_'));
    if (activeBtn) {
        activeBtn.classList.remove('border-slate-200', 'bg-white', 'text-slate-600');
        activeBtn.classList.add('border-[#00205b]', 'bg-blue-50', 'text-[#00205b]');
    }
    
    window.closeAllYearsModal();
    window.triggerDynamicFetch();
};

// Live Search Filter applied locally on the table items
window.filterTable = function() {
    let input = document.getElementById("liveSearch");
    if (!input) return;
    
    let filter = input.value.toUpperCase();
    let activeTable = document.getElementById('ledger_tbody');
    
    if (activeTable) {
        let tr = activeTable.getElementsByTagName("tr");
        for (let i = 0; i < tr.length; i++) {
            let tdName = tr[i].querySelector('.searchable-name');
            let tdId = tr[i].querySelector('.searchable-id');
            let tdOR = tr[i].querySelector('.searchable-or');
            if (tdName || tdId || tdOR) { 
                let txtValue = (tdName ? tdName.textContent || tdName.innerText : "") + " " + 
                               (tdId ? tdId.textContent || tdId.innerText : "") + " " + 
                               (tdOR ? tdOR.textContent || tdOR.innerText : "");
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    tr[i].style.display = "";
                } else {
                    tr[i].style.display = "none";
                }
            }
        }
    }
};

document.addEventListener('DOMContentLoaded', () => {
    // Start the silent background loop (every 5 seconds)
    if (document.getElementById('ledger_tbody')) { 
        setInterval(() => window.triggerDynamicFetch(), 5000); 
    }
});