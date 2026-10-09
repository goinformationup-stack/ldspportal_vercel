// =========================================================
// FEE MANAGEMENT SPECIFIC LOGIC
// =========================================================

window.filterGeneralFees = function() {
    let input = document.getElementById("generalFeeSearch").value.toUpperCase();
    let tr = document.getElementById("generalFeesTable").getElementsByTagName("tr");
    for (let i = 1; i < tr.length; i++) {
        let nameCell = tr[i].getElementsByTagName("td")[1];
        let progCell = tr[i].getElementsByTagName("td")[5];
        if (nameCell || progCell) {
            tr[i].style.display = ((nameCell.innerText + " " + progCell.innerText).toUpperCase().indexOf(input) > -1) ? "" : "none";
        }
    }
};

window.filterSubjects = function() {
    let input = document.getElementById("subjectSearch").value.toUpperCase();
    let tr = document.getElementsByClassName("subject-row");
    for (let i = 0; i < tr.length; i++) {
        let codeCell = tr[i].getElementsByTagName("td")[0];
        let titleCell = tr[i].getElementsByTagName("td")[1];
        if (codeCell || titleCell) {
            tr[i].style.display = ((codeCell.innerText + " " + titleCell.innerText).toUpperCase().indexOf(input) > -1) ? "" : "none";
        }
    }
};

// -------------------------------------------------------------
// MODALS
// -------------------------------------------------------------
window.openEditFeeModal = function(btn) {
    document.getElementById('edit_fee_id').value = btn.getAttribute('data-id');
    document.getElementById('edit_fee_name').value = btn.getAttribute('data-name');
    document.getElementById('edit_fee_amount').value = btn.getAttribute('data-amount');
    document.getElementById('edit_fee_rule').value = btn.getAttribute('data-rule');
    document.getElementById('edit_linked_subject').value = btn.getAttribute('data-linked') || 'None';
    document.getElementById('edit_fee_program').value = btn.getAttribute('data-program');
    document.getElementById('edit_fee_year').value = btn.getAttribute('data-year');
    document.getElementById('edit_fee_semester').value = btn.getAttribute('data-semester') || 'All';
    
    document.getElementById('edit_first_payment').value = btn.getAttribute('data-first');
    document.getElementById('edit_second_payment').value = btn.getAttribute('data-second');

    window.toggleEditInstallments();
    window.openModal('editFeeModal');
};

window.openSubjectFeeModal = function(btn) {
    document.getElementById('sf_code_input').value = btn.getAttribute('data-code');
    document.getElementById('sf_code_display').innerText = btn.getAttribute('data-code');
    document.getElementById('sf_title_display').innerText = btn.getAttribute('data-title');
    document.getElementById('sf_fee_input').value = btn.getAttribute('data-fee');
    window.openModal('subjectFeeModal');
};

// -------------------------------------------------------------
// INSTALLMENTS CALCULATORS
// -------------------------------------------------------------
window.toggleAddInstallments = function() {
    const rule = document.getElementById('add_payment_rule').value;
    const instDiv = document.getElementById('add_fee_installments');
    if(rule === 'Installments Allowed') {
        instDiv.classList.remove('hidden');
        instDiv.classList.add('grid');
        window.calcAddSecondPayment();
    } else {
        instDiv.classList.add('hidden');
        instDiv.classList.remove('grid');
    }
};

window.calcAddSecondPayment = function() {
    const amt = parseFloat(document.getElementById('add_fee_amount').value) || 0;
    let first = parseFloat(document.getElementById('add_first_payment').value) || 0;
    if (first > amt) { 
        first = amt; 
        document.getElementById('add_first_payment').value = first; 
    }
    document.getElementById('add_second_payment').value = (amt - first).toFixed(2);
};

window.toggleEditInstallments = function() {
    const rule = document.getElementById('edit_fee_rule').value;
    const instDiv = document.getElementById('edit_fee_installments');
    if(rule === 'Installments Allowed') {
        instDiv.classList.remove('hidden');
        instDiv.classList.add('grid');
        window.calcEditSecondPayment();
    } else {
        instDiv.classList.add('hidden');
        instDiv.classList.remove('grid');
    }
};

window.calcEditSecondPayment = function() {
    const amt = parseFloat(document.getElementById('edit_fee_amount').value) || 0;
    let first = parseFloat(document.getElementById('edit_first_payment').value) || 0;
    if (first > amt) { 
        first = amt; 
        document.getElementById('edit_first_payment').value = first; 
    }
    document.getElementById('edit_second_payment').value = (amt - first).toFixed(2);
};