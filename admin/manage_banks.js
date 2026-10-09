// =========================================================
// MANAGE BANKS SPECIFIC LOGIC (manage_banks.js)
// =========================================================

let lastBankDataString = ''; // CACHE VAR to prevent UI flickering

// ---------------------------------------------------------
// 1. MODAL CONTROLLERS (Ensuring they exist for this page)
// ---------------------------------------------------------
window.openModal = window.openModal || function(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex'); 
    void modal.offsetWidth; // Trigger reflow
    modal.classList.add('modal-active');
};

window.closeModal = window.closeModal || function(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    modal.classList.remove('modal-active');
    setTimeout(() => {
        modal.classList.remove('flex'); 
        modal.classList.add('hidden');
    }, 400); // Matches CSS transition duration
};

// ---------------------------------------------------------
// 2. ZERO-LAG ASYNCHRONOUS LIVE DATA POLLING
// ---------------------------------------------------------
window.triggerDynamicFetch = async function() {
    // PROTECTION: Prevent background refresh if user has the Edit Modal open
    if (document.querySelector('.modal-active')) {
        return;
    }

    try {
        const response = await fetch(`manage_banks.php?api_refresh=1`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) return;
        
        const data = await response.json();
        const newDataString = JSON.stringify(data);
        
        if (newDataString === lastBankDataString) return; 
        lastBankDataString = newDataString;

        const container = document.getElementById('bank_cards_container');
        if (container) container.innerHTML = data.html;
        
    } catch (err) {
        console.error("Auto-sync error:", err);
    }
};

// Poll every 5 seconds seamlessly in the background
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('bank_cards_container')) {
        triggerDynamicFetch();
        setInterval(triggerDynamicFetch, 5000);
    }
});

// ---------------------------------------------------------
// 3. ADD BANK: FORM UI TOGGLE
// ---------------------------------------------------------
window.toggleAddFields = function(type) {
    const stdFields = document.getElementById('add_standard_fields');
    const accName = document.getElementById('add_account_name');
    const accNum = document.getElementById('add_account_number');
    const payLink = document.getElementById('add_payment_link');
    const bankLabel = document.getElementById('add_bank_name_label');
    const payOpt = document.getElementById('add_payment_link_opt');

    if (!stdFields) return;

    if (type === 'link') {
        stdFields.classList.add('hidden');
        accName.removeAttribute('required');
        accNum.removeAttribute('required');
        payLink.setAttribute('required', 'required');
        bankLabel.innerText = "Payment Portal Name";
        payOpt.innerText = "(Required)";
    } else {
        stdFields.classList.remove('hidden');
        accName.setAttribute('required', 'required');
        accNum.setAttribute('required', 'required');
        payLink.removeAttribute('required');
        bankLabel.innerText = "Bank Name";
        payOpt.innerText = "(Optional)";
    }
};

// ---------------------------------------------------------
// 4. EDIT BANK: FORM UI TOGGLE
// ---------------------------------------------------------
window.toggleEditFields = function(type) {
    const stdFields = document.getElementById('edit_standard_fields');
    const accName = document.getElementById('edit_account_name');
    const accNum = document.getElementById('edit_account_number');
    const payLink = document.getElementById('edit_payment_link');
    const bankLabel = document.getElementById('edit_bank_name_label');
    const payOpt = document.getElementById('edit_payment_link_opt');

    if (!stdFields) return;

    if (type === 'link') {
        stdFields.classList.add('hidden');
        accName.removeAttribute('required');
        accNum.removeAttribute('required');
        payLink.setAttribute('required', 'required');
        bankLabel.innerText = "Payment Portal Name";
        payOpt.innerText = "(Required)";
    } else {
        stdFields.classList.remove('hidden');
        accName.setAttribute('required', 'required');
        accNum.setAttribute('required', 'required');
        payLink.removeAttribute('required');
        bankLabel.innerText = "Bank Name";
        payOpt.innerText = "(Optional)";
    }
};

// ---------------------------------------------------------
// 5. EDIT MODAL POPULATOR
// ---------------------------------------------------------
window.openEditModal = function(id, bankName, accountName, accountNumber, paymentLink) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_bank_name').value = bankName;
    document.getElementById('edit_payment_link').value = paymentLink;
    
    const isLinkOnly = (accountName === 'N/A' && accountNumber === 'N/A');
    
    if (isLinkOnly) {
        document.getElementById('edit_type_link').checked = true;
        document.getElementById('edit_account_name').value = '';
        document.getElementById('edit_account_number').value = '';
        toggleEditFields('link');
    } else {
        document.getElementById('edit_type_std').checked = true;
        document.getElementById('edit_account_name').value = accountName;
        document.getElementById('edit_account_number').value = accountNumber;
        toggleEditFields('standard');
    }
    
    window.openModal('editModal');
};