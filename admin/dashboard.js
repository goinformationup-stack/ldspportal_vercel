// =========================================================
// DASHBOARD SPECIFIC LOGIC (dashboard.js)
// =========================================================

let lastDataString = ''; 
let isFormDirty = false;
let programChart, genderChart, modalityChart; // Global chart instances

// --- SPA FORM INTERCEPTION ENGINE ---
function initSPAEngine() {
    document.addEventListener('submit', async (e) => {
        if (e.target.hasAttribute('data-no-spa')) return;
        
        // Allow GET forms (like the filter or redirect) to process normally
        if (e.target.method.toUpperCase() === 'GET') return;

        e.preventDefault();
        const form = e.target;
        const submitBtn = form.querySelector('button[type="submit"]:focus') || form.querySelector('button[type="submit"]');
        const originalBtnText = submitBtn ? submitBtn.innerHTML : '';

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = `<svg class="animate-spin h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Processing...`;
        }

        const formData = new FormData(form);
        formData.append('ajax_post', '1');
        if (e.submitter && e.submitter.name) { formData.append(e.submitter.name, e.submitter.value || '1'); }

        try {
            const response = await fetch(window.location.href, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await response.json();

            if (data.status === 'success' || data.status === 'error') {
                if (typeof window.showToast === 'function') window.showToast(data.message, data.status);
                if (data.status === 'success') {
                    form.reset(); 
                    isFormDirty = false;
                    
                    if (data.updates) {
                        for (const [id, html] of Object.entries(data.updates)) {
                            const el = document.getElementById(id);
                            if (el) el.innerHTML = html;
                        }
                    }
                    window.triggerDynamicFetch(); // Force quick UI sync
                }
            }
        } catch (err) {
            console.error("SPA Error:", err);
            if (typeof window.showToast === 'function') window.showToast("An error occurred while processing the request.", "error");
        } finally {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalBtnText; }
        }
    });
}

// --- NUMBER ANIMATION ENGINE ---
function easeOutExpo(t) { return t === 1 ? 1 : 1 - Math.pow(2, -10 * t); }

function animateCountUp(el, startVal, endVal, duration = 1200) {
    if (startVal === endVal) return;
    let startTime = null;
    const step = (currentTime) => {
        if (!startTime) startTime = currentTime;
        const progress = Math.min((currentTime - startTime) / duration, 1);
        const currentVal = Math.floor(easeOutExpo(progress) * (endVal - startVal) + startVal);
        el.innerText = currentVal.toLocaleString('en-US');
        if (progress < 1) {
            requestAnimationFrame(step);
        } else {
            el.innerText = endVal.toLocaleString('en-US');
            el.setAttribute('data-val', endVal);
        }
    };
    requestAnimationFrame(step);
}

window.triggerNumberUpdate = function(elementId, newValue) {
    const el = document.getElementById(elementId);
    if (!el) return;
    const startValue = parseInt(el.getAttribute('data-val')) || 0;
    const endValue = parseInt(newValue) || 0;
    if (startValue !== endValue) {
        animateCountUp(el, startValue, endValue);
    }
};

// --- FORMAL CHART JS SETUP ---
function initializeCharts() {
    if (typeof Chart === 'undefined' || !window.DASHBOARD_INIT_DATA) return;

    // Global sleek chart styling
    Chart.defaults.color = '#94a3b8'; // Slate 400
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.font.size = window.innerWidth < 768 ? 11 : 12;
    
    // Modern Tooltips
    Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15, 23, 42, 0.9)'; // Slate 900
    Chart.defaults.plugins.tooltip.titleFont = { family: "'Inter', sans-serif", size: 13, weight: '600' };
    Chart.defaults.plugins.tooltip.bodyFont = { family: "'Inter', sans-serif", size: 12, weight: '400' };
    Chart.defaults.plugins.tooltip.padding = 12;
    Chart.defaults.plugins.tooltip.cornerRadius = 8;
    Chart.defaults.plugins.tooltip.displayColors = false;

    // Animations
    const standardAnimation = {
        duration: 1000,
        easing: 'easeOutQuart'
    };

    // 1. Program Bar Chart
    const ctxP = document.getElementById('programChart');
    if (ctxP) {
        // Subtle Gradient
        const gradIndigo = ctxP.getContext('2d').createLinearGradient(0, 0, 0, 400);
        gradIndigo.addColorStop(0, 'rgba(99, 102, 241, 0.9)'); // Indigo 500
        gradIndigo.addColorStop(1, 'rgba(67, 56, 202, 0.9)'); // Indigo 700

        programChart = new Chart(ctxP, {
            type: 'bar',
            data: {
                labels: window.DASHBOARD_INIT_DATA.progLabels,
                datasets: [{ 
                    label: 'Enrolled Students', 
                    data: window.DASHBOARD_INIT_DATA.progCounts, 
                    backgroundColor: gradIndigo, 
                    hoverBackgroundColor: 'rgba(79, 70, 229, 1)', // Indigo 600
                    borderRadius: 6,
                    borderSkipped: false,
                    barPercentage: 0.6,
                    categoryPercentage: 0.8
                }]
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false, 
                animation: standardAnimation,
                plugins: { legend: { display: false } },
                scales: {
                    y: { 
                        grid: { color: 'rgba(226, 232, 240, 0.6)', borderDash: [4, 4] }, // Slate 200
                        border: { display: false }, 
                        beginAtZero: true,
                        ticks: { precision: 0, padding: 10 } 
                    },
                    x: { 
                        grid: { display: false }, 
                        border: { display: false },
                        ticks: { padding: 10 }
                    }
                }
            }
        });
    }

    // 2. Gender Doughnut
    const ctxG = document.getElementById('genderChart');
    if (ctxG) {
        genderChart = new Chart(ctxG, {
            type: 'doughnut',
            data: {
                labels: ['Male', 'Female', 'Unspecified'],
                datasets: [{ 
                    data: window.DASHBOARD_INIT_DATA.genderData, 
                    backgroundColor: [
                        'rgba(79, 70, 229, 0.9)', // Indigo 600
                        'rgba(16, 185, 129, 0.9)', // Emerald 500
                        'rgba(203, 213, 225, 0.9)'  // Slate 300
                    ],
                    hoverBackgroundColor: [
                        'rgba(67, 56, 202, 1)', 
                        'rgba(5, 150, 105, 1)', 
                        'rgba(148, 163, 184, 1)'
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 4
                }]
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false, 
                cutout: '70%',
                animation: standardAnimation,
                plugins: { 
                    legend: { 
                        position: 'bottom', 
                        labels: { 
                            usePointStyle: true, 
                            pointStyle: 'circle',
                            padding: 20, 
                            font: { size: 12, weight: '500' } 
                        } 
                    } 
                } 
            }
        });
    }

    // 3. Modality Horizontal Bar
    const ctxM = document.getElementById('modalityChart');
    if (ctxM) {
        modalityChart = new Chart(ctxM, {
            type: 'bar',
            data: {
                labels: ['Hybrid', 'Online', 'Modular'],
                datasets: [{ 
                    label: 'Students', 
                    data: window.DASHBOARD_INIT_DATA.modeData, 
                    backgroundColor: [
                        'rgba(14, 165, 233, 0.85)', // Sky 500
                        'rgba(99, 102, 241, 0.85)', // Indigo 500
                        'rgba(245, 158, 11, 0.85)'  // Amber 500
                    ],
                    hoverBackgroundColor: [
                        'rgba(2, 132, 199, 1)',
                        'rgba(79, 70, 229, 1)',
                        'rgba(217, 119, 6, 1)'
                    ],
                    borderRadius: 6,
                    borderSkipped: false,
                    barPercentage: 0.5
                }]
            },
            options: { 
                indexAxis: 'y', 
                responsive: true, 
                maintainAspectRatio: false, 
                animation: standardAnimation,
                plugins: { legend: { display: false } },
                scales: { 
                    x: { 
                        grid: { color: 'rgba(226, 232, 240, 0.6)', borderDash: [4, 4] }, 
                        border: { display: false }, 
                        beginAtZero: true,
                        ticks: { precision: 0 } 
                    },
                    y: { 
                        grid: { display: false }, 
                        border: { display: false },
                        ticks: { font: { weight: '600' } }
                    }
                } 
            }
        });
    }
}

// =========================================================
// ZERO-LAG ASYNCHRONOUS LIVE DATA POLLING
// =========================================================
document.addEventListener('focusin', (e) => {
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) { isFormDirty = true; }
});
document.addEventListener('focusout', (e) => {
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) {
        setTimeout(() => { if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) { isFormDirty = false; } }, 150);
    }
});

// Overwrite the global triggerDynamicFetch specific to dashboard filters
window.triggerDynamicFetch = async function() {
    if (isFormDirty) return; // Prevent overwriting user input

    try {
        const prog = document.getElementById('sort_program') ? document.getElementById('sort_program').value : 'All';
        const year = document.getElementById('sort_year') ? document.getElementById('sort_year').value : 'All';
        
        const response = await fetch(`dashboard.php?api_refresh=1&sort_program=${encodeURIComponent(prog)}&sort_year=${encodeURIComponent(year)}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        if (!response.ok) return;
        const result = await response.json();
        
        const newDataString = JSON.stringify(result);
        if (newDataString === lastDataString) return; 
        lastDataString = newDataString;

        // 1. Update Quick-Stat Counters
        window.triggerNumberUpdate('stat_total_enrolled', result.stats.total_enrolled);
        window.triggerNumberUpdate('stat_active_staff', result.stats.active_staff);
        window.triggerNumberUpdate('stat_total_enroll_reqs', result.stats.total_enroll_reqs);
        window.triggerNumberUpdate('stat_pending_admissions', result.stats.pending_admissions);
        window.triggerNumberUpdate('stat_freshmen_count', result.stats.freshmen_count);
        window.triggerNumberUpdate('stat_regular_count', result.stats.regular_count);
        window.triggerNumberUpdate('stat_irregular_count', result.stats.irregular_count);

        // 2. Smoothly Update Chart.js Data
        if (programChart && result.charts.program) {
            programChart.data.labels = result.charts.program.labels;
            programChart.data.datasets[0].data = result.charts.program.data;
            programChart.update(); 
        }

        if (genderChart && result.charts.gender) {
            genderChart.data.datasets[0].data = result.charts.gender.data;
            genderChart.update();
        }

        if (modalityChart && result.charts.modality) {
            modalityChart.data.datasets[0].data = result.charts.modality.data;
            modalityChart.update();
        }

    } catch (err) {
        console.error("Auto-sync error:", err);
    }
};

// Start Engines on Load
document.addEventListener('DOMContentLoaded', () => {
    initSPAEngine();
    initializeCharts();
    setInterval(window.triggerDynamicFetch, 5000);
});