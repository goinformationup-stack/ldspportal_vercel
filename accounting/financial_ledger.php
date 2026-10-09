<?php
// 1. FORCE ERROR REPORTING & VISUAL FAILSAFE
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "<div style='position:fixed; top:0; left:0; width:100%; z-index:99999; background:#ef4444; color:white; padding:20px; font-family:monospace; font-weight:bold; box-shadow:0 10px 25px rgba(0,0,0,0.5);'>";
        echo "<h2 style='margin-top:0;'>CRITICAL PHP ERROR DETECTED</h2>";
        echo "<strong>Message:</strong> " . htmlspecialchars($error['message']) . "<br><br>";
        echo "<strong>File:</strong> " . $error['file'] . "<br>";
        echo "<strong>Line:</strong> " . $error['line'];
        echo "</div>";
    }
});

// 2. ISOLATED SESSION HANDLER FOR STAFF
$session_lifetime = 60 * 60 * 24 * 30; // 30 days
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params($session_lifetime, '/');
session_name('LDSP_STAFF_SESSION'); 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

include "../dbconn.php";

$allowed_roles = ['admin', 'admission', 'registrar', 'accounting'];

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../index.php");
    exit();
}

// Rewritten to avoid the '||' symbol
$can_edit = in_array($_SESSION['role'], ['admin', 'accounting']);

// ---------------------------------------------------------
// STRICT ACADEMIC YEAR GENERATOR (Base: 2024-2025)
// ---------------------------------------------------------
$settings_q = $conn->query("SELECT setting_value FROM portal_settings WHERE setting_key = 'active_school_year'");
$active_sy = ($settings_q && $settings_q->num_rows > 0) ? $settings_q->fetch_assoc()['setting_value'] : '2024-2025';

// Parse the start year of the active school year to determine how far to generate
$active_start = (int)explode('-', $active_sy)[0];
if ($active_start < 2024) {
    $active_start = (int)date('Y');
}

$prog_list = [];
$prog_q = $conn->query("SELECT program_name FROM programs ORDER BY program_name ASC");
if ($prog_q) { while ($r = $prog_q->fetch_assoc()) { $prog_list[] = $r['program_name']; } }

// Generate a clean, strict sequence of years starting ONLY from 2024-2025 up to the active year
$years_list = [];
$base_start = 2024;
$end_start = max($base_start, $active_start);

for ($y = $base_start; $y <= $end_start; $y++) {
    $next_y = $y + 1;
    $sy_format = $y . '-' . $next_y;
    $years_list[$sy_format] = $sy_format;
}

// Ensure the exact active_sy string is present in case of unexpected formats in settings
$years_list[$active_sy] = $active_sy;
krsort($years_list); // Sort newest to oldest

// Limit the dropdown list to maximum of 5 recent years
$recent_years = array_slice($years_list, 0, 5, true);

$f_program = $_GET['f_program'] ?? 'All';
$f_year    = $_GET['f_year'] ?? $active_sy; // Auto-locks to the active admissions year!

// ---------------------------------------------------------
// BULLETPROOF DATA FETCHING (MASTER TRANSACTION LEDGER)
// ---------------------------------------------------------
$records = [];
$sql_error_msg = "";

// Query exclusively from `transaction_history` to guarantee NO duplicates and ONLY successful transactions.
$ledger_sql = "
    SELECT 
        th.id,
        th.or_number, 
        th.amount, 
        th.payment_type as p_type,
        th.approved_at as t_date, 
        th.program, 
        IFNULL(u.student_id, a.admission_number) as identifier, 
        th.student_email as user_email,
        IFNULL(p.first_name, a.first_name) as first_name, 
        IFNULL(p.last_name, a.last_name) as last_name, 
        IFNULL(p.middle_name, a.middle_name) as middle_name,
        IFNULL((SELECT year_level FROM enrollment_requests WHERE email = th.student_email AND school_year = th.academic_year AND semester = th.semester ORDER BY id DESC LIMIT 1), a.year_level) as year_level
    FROM transaction_history th
    LEFT JOIN users u ON th.student_email = u.email
    LEFT JOIN user_profiles p ON u.id = p.user_id
    LEFT JOIN admissions a ON th.student_email = a.email OR th.student_email = a.institutional_email
    WHERE 1=1
";

if ($f_program !== 'All') {$ledger_sql .= " AND th.program = '" . $conn->real_escape_string($f_program) . "'"; 
}
if ($f_year !== 'All') {$ledger_sql .= " AND (
        th.academic_year = '" . $conn->real_escape_string($f_year) . "' 
        OR a.admission_number IN (SELECT student_id FROM exam_results WHERE academic_year = '" . $conn->real_escape_string($f_year) . "')
    )"; 
}

$ledger_sql .= " GROUP BY th.id ORDER BY th.approved_at DESC";

$ledger_res = $conn->query($ledger_sql);
if ($ledger_res) {
    while ($row =$ledger_res->fetch_assoc()) { 
        // Determine student type cleanly based on payment type (Kept in backend logic, removed from UI)
        if (stripos($row['p_type'], 'Initial Fee') !== false) {$row['student_type'] = 'New Applicant';
        } else {
            $row['student_type'] = 'Online Enrollee';
        }
        $records[] =$row; 
    }
} else {
    $sql_error_msg .= "Ledger Fetch Error: " . $conn->error;
}

$total_cleared = count($records);

// ---------------------------------------------------------
// HTML TEMPLATE FUNCTIONS
// ---------------------------------------------------------
function renderLedgerRow($row) {
    ob_start();
    
    $lName =$row['last_name'] ?? '';
    $fName =$row['first_name'] ?? '';
    $mName =$row['middle_name'] ?? '';
    
    // Rewritten to avoid the '||' symbol
    $has_name = !(empty($lName) && empty($fName));
    $display_name =$has_name ? trim($lName) . ', ' . trim($fName) . (!empty($mName) ? ' ' . trim($mName) : '') : 'Unknown Student';

    // Extract channel dynamically via regex from the transaction type string
    $pay_type_raw = $row['p_type'] ?? '';$bank_channel = 'On-Site / Cash'; // Default fallback
    
    if (preg_match('/\(via\s+(.*?)\)/i', $pay_type_raw,$matches)) {
        $bank_channel = htmlspecialchars(trim($matches[1]));
        // Clean up the pay type string to remove the "(via BPI)" part from the pill display
        $pay_type_raw = trim(preg_replace('/\(via\s+.*?\)/i', '', $pay_type_raw));
    } elseif ($row['student_type'] === 'Online Enrollee') {$bank_channel = 'Online';
    }

    $amount = number_format((float)($row['amount'] ?? 0), 2);
    $or_num = htmlspecialchars($row['or_number'] ?? '---');
    $t_date = !empty($row['t_date']) ? date('M j, Y', strtotime($row['t_date'])) : '---';
    $t_time = !empty($row['t_date']) ? date('h:i A', strtotime($row['t_date'])) : '';
    ?>
    <tr class="hover:bg-blue-50/40 transition-colors bg-white border-b border-slate-200">
        
        <!-- STUDENT IDENTITY (24%) -->
        <td class="px-3 py-2 align-middle border-r border-slate-300 w-[24%] max-w-0">
            <div class="w-full overflow-x-auto custom-scrollbar pb-1.5">
                <div class="font-bold text-[#00205b] text-xs whitespace-nowrap searchable-name pr-2" title="<?= htmlspecialchars($display_name) ?>"><?= htmlspecialchars($display_name) ?></div>
            </div>
            <div class="pt-1.5 border-t border-slate-200">
                <div class="text-[11px] text-slate-500 font-mono font-black tracking-widest truncate searchable-id flex items-center gap-1.5">
                    <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/></svg>
                    <?= htmlspecialchars($row['identifier'] ?? '---') ?>
                </div>
            </div>
        </td>
        
        <!-- BANK / CHANNEL (12%) - Now matched with Verification Styling -->
        <td class="px-2 py-2 align-middle border-r border-slate-300 w-[12%] truncate text-center">
            <div class="text-[10px] font-bold text-[#00205b] truncate" title="<?= $bank_channel ?>"><?= $bank_channel ?></div>
            <div class="text-[8px] uppercase tracking-widest text-slate-400 mt-0.5 font-black truncate text-center"><?= htmlspecialchars($row['year_level'] ?? 'N/A') ?></div>
        </td>

        <!-- PAYMENT DETAILS (24%) -->
        <td class="px-2 py-2 align-middle border-r border-slate-300 w-[24%]">
            <div class="flex flex-col gap-1 overflow-y-auto custom-scrollbar max-h-[50px] pr-1 w-full">
                <?php 
                $fee_types_array = explode(',',$pay_type_raw);
                foreach($fee_types_array as$ft):
                    $ft = trim(str_ireplace(['Payment for:', 'Payment For:', 'payment for:'], '',$ft));
                    if(!empty($ft)):
                ?>
                    <span class="w-full text-left truncate px-2 py-1 bg-slate-50 border border-slate-200 text-slate-600 rounded text-[9px] font-bold uppercase tracking-widest shadow-sm" title="<?= htmlspecialchars($ft) ?>">
                        <?= htmlspecialchars($ft) ?>
                    </span>
                <?php 
                    endif;
                endforeach; 
                ?>
            </div>
        </td>

        <!-- AMOUNT (10%) -->
        <td class="px-2 py-2 align-middle border-r border-slate-300 w-[10%] text-right truncate">
            <div class="font-mono font-black text-[#00205b] text-[11px]">₱<?= $amount ?></div>
        </td>

        <!-- OR NUMBER (12%) -->
        <td class="px-2 py-2 align-middle border-r border-slate-300 w-[12%] text-center">
            <div class="text-[10px] text-slate-600 font-mono font-bold uppercase tracking-widest break-all searchable-or"><?= $or_num ?></div>
        </td>

        <!-- DATE (10%) -->
        <td class="px-2 py-2 align-middle border-r border-slate-300 text-center w-[10%] truncate">
            <div class="text-[9px] font-bold text-slate-600 tracking-widest"><?= $t_date ?></div>
            <div class="text-[8px] font-bold text-slate-400 uppercase tracking-widest mt-0.5"><?= $t_time ?></div>
        </td>
        
        <!-- NEW PRINT RECEIPT COLUMN (8%) -->
        <td class="px-2 py-2 align-middle text-center w-[8%]">
            <button type="button" onclick="window.open('print_receipt.php?or=<?= urlencode($or_num) ?>', '_blank')" class="bg-slate-100 hover:bg-blue-100 text-slate-500 hover:text-[#00205b] border border-slate-200 p-2 rounded transition-colors shadow-sm cursor-pointer mx-auto flex items-center justify-center" title="Print Receipt">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2-2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            </button>
        </td>

    </tr>
    <?php
    return ob_get_clean();
}

function renderLedgerTable($records,$sql_error_msg) {
    ob_start();
    if (count($records) > 0) {
        foreach ($records as$row) {
            echo renderLedgerRow($row);
        }
    } else {
        if (!empty($sql_error_msg)) {
            echo '<tr><td colspan="7" class="bg-rose-50"><div class="text-center py-10 text-rose-500 text-xs font-bold uppercase tracking-widest">Database Error: ' . htmlspecialchars($sql_error_msg) . '</div></td></tr>';
        } else {
            echo '<tr><td colspan="7" class="bg-slate-50/50"><div class="flex flex-col items-center justify-center min-h-[450px] text-slate-400 text-[10px] font-bold uppercase tracking-widest"><svg class="w-10 h-10 mb-3 opacity-20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>No cleared payments match your current filters.</div></td></tr>';
        }
    }
    return ob_get_clean();
}

// =========================================================
// ASYNC JSON DATA ENDPOINT (FOR ZERO-LAG LIVE REFRESH)
// =========================================================
if (isset($_GET['api_refresh'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'ledger_html' => renderLedgerTable($records,$sql_error_msg),
        'ledger_count' => $total_cleared
    ]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Ledger - LDSP Command Center</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css?v=<?= time(); ?>">
    <style>
        .modal-overlay { opacity: 0; transition: opacity 0.2s ease; pointer-events: none; }
        .modal-content { transform: scale(0.95); opacity: 0; transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); pointer-events: none; }
        .modal-active.modal-overlay { opacity: 1; pointer-events: auto; }
        .modal-active .modal-content { transform: scale(1); opacity: 1; pointer-events: auto; }

        /* SLEEK VERTICAL SCROLLBAR FOR COMPACT ROWS */
        .custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>

<body class="flex h-screen overflow-hidden antialiased bg-[#f4f6f9]">

    <!-- Ambient Background Orbs -->
    <div class="ambient-orb-1"></div>
    <div class="ambient-orb-2"></div>

    <?php include 'sidebar.php'; ?>

    <main class="flex-1 min-w-0 overflow-y-auto h-full w-full pt-20 md:pt-0 relative z-10 custom-scrollbar">
        <div class="p-4 md:p-6 max-w-[1500px] mx-auto relative z-20 fade-in-up">
            
            <header class="mb-6 border-b border-slate-300 pb-4 drop-shadow-sm">
                <div class="flex items-center gap-3 mb-1.5">
                    <h1 class="text-2xl md:text-3xl font-black text-slate-800 tracking-tight font-academic uppercase drop-shadow-sm">Financial Ledger</h1>
                </div>
                <p class="text-slate-500 text-xs flex items-center gap-1.5 font-medium drop-shadow-sm">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.8)]"></span> Logged in as <?= htmlspecialchars($_SESSION['first_name'] ?? 'Staff') ?>
                </p>
            </header>

            <div class="relative z-20">
                <div class="flex flex-col md:flex-row justify-between md:items-end mb-4 gap-3 border-b border-slate-300 pb-3 drop-shadow-sm">
                    <div>
                        <h3 class="font-bold text-slate-800 text-lg font-academic uppercase tracking-tight drop-shadow-sm">Historical Cleared Transactions</h3>
                        <p id="ledger_count" class="text-[9px] text-slate-500 font-bold mt-0.5 uppercase tracking-widest drop-shadow-sm"><?= $total_cleared ?> CLEARED PAYMENTS</p>
                    </div>

                    <!-- Filter Form (AJAX DRIVEN) -->
                    <div class="flex flex-col sm:flex-row items-center gap-2 bg-white/70 backdrop-blur-sm p-1.5 rounded-xl border border-slate-200 shadow-sm w-full md:w-auto">
                        
                        <div class="relative w-full sm:w-[220px]">
                            <input type="text" id="liveSearch" onkeyup="window.filterTable()" class="w-full py-1.5 pl-7 pr-3 text-[11px] font-semibold text-[#00205b] bg-white border border-slate-300 rounded-lg shadow-sm focus:outline-none focus:border-[#00205b]" placeholder="Search Name, ID, OR#...">
                            <svg class="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-[8px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        </div>

                        <select id="filter_program" onchange="window.triggerDynamicFetch()" class="cursor-pointer w-full sm:w-[160px] truncate py-1.5 px-3 text-[11px] font-semibold text-[#00205b] bg-white border border-slate-300 rounded-lg shadow-sm focus:outline-none focus:border-[#00205b] focus:ring-1 focus:ring-[#00205b]/20" title="Filter Program">
                            <option value="All">All Programs</option>
                            <?php foreach ($prog_list as$prog): ?>
                                <option value="<?= htmlspecialchars($prog) ?>" <?= ($f_program === $prog) ? 'selected' : '' ?>><?= htmlspecialchars($prog) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <!-- STRICT ACADEMIC YEAR DYNAMIC FILTER (Max 5 items + View All) -->
                        <select id="filter_year" onchange="window.handleYearFilterChange(this)" data-last-value="<?= htmlspecialchars($f_year) ?>" class="cursor-pointer w-full sm:w-[130px] py-1.5 px-3 text-[11px] font-semibold text-[#00205b] bg-white border border-slate-300 rounded-lg shadow-sm focus:outline-none focus:border-[#00205b] focus:ring-1 focus:ring-[#00205b]/20">
                            <option value="All" <?= ($f_year === 'All') ? 'selected' : '' ?>>All Years</option>
                            <?php foreach ($recent_years as$yr): ?>
                                <option value="<?= htmlspecialchars($yr) ?>" <?= ($f_year === $yr) ? 'selected' : '' ?>><?= htmlspecialchars($yr) ?></option>
                            <?php endforeach; ?>
                            
                            <!-- Preserve an older year if it was hard-refreshed via URL but falls out of the top 5 -->
                            <?php if (!array_key_exists($f_year, $recent_years) &&$f_year !== 'All'): ?>
                                <option value="<?= htmlspecialchars($f_year) ?>" selected><?= htmlspecialchars($f_year) ?></option>
                            <?php endif; ?>

                            <!-- Only show the trigger if there are more than 5 total years -->
                            <?php if (count($years_list) > 5): ?>
                                <option value="open_all_years_modal" class="font-bold text-[#c5a02c]">↳ View All Years...</option>
                            <?php endif; ?>
                        </select>

                        <div class="flex gap-1.5 w-full sm:w-auto">
                            <button type="button" onclick="window.resetFilters()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-300 text-[9px] font-bold uppercase tracking-widest py-1.5 px-4 rounded-lg transition-colors w-full sm:w-auto shadow-sm flex items-center justify-center cursor-pointer">Reset</button>
                        </div>
                    </div>
                </div>

                <!-- UNIFIED READ-ONLY LEDGER QUEUE TABLE -->
                <div class="bg-white/95 backdrop-blur-md border border-slate-200 overflow-hidden mb-8 border-t-[4px] border-t-[#00205b] z-20 shadow-xl rounded-xl">
                    <div class="px-4 py-3 border-b border-slate-300 bg-slate-50 flex justify-between items-center relative z-20">
                        <h3 class="font-black text-[#00205b] text-xs uppercase tracking-widest flex items-center gap-2">
                            <div class="p-1 rounded-md bg-[#00205b]/10"><svg class="w-3.5 h-3.5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg></div> 
                            Transaction Database
                        </h3>
                    </div>

                    <div class="overflow-x-auto overflow-y-auto min-h-[450px] max-h-[70vh] bg-white relative z-20 custom-scrollbar">
                        <!-- min-w-[1000px] ensures it won't squish on mobile phones -->
                        <table class="w-full text-left border-collapse text-sm table-fixed min-w-[1000px]">
                            <thead class="bg-slate-100/95 backdrop-blur-sm text-slate-600 text-[9px] uppercase font-black tracking-widest border-b-2 border-slate-300 sticky top-0 z-30 shadow-sm">
                                <tr>
                                    <!-- Exactly 7 Columns summing to 100% -->
                                    <th class="px-3 py-3 border-r border-slate-300 w-[24%]">Student Identity</th>
                                    <th class="px-2 py-3 border-r border-slate-300 w-[12%] text-center">Bank / Channel</th>
                                    <th class="px-2 py-3 border-r border-slate-300 w-[24%]">Payment Details</th>
                                    <th class="px-2 py-3 border-r border-slate-300 w-[10%] text-right">Amount (₱)</th>
                                    <th class="px-2 py-3 border-r border-slate-300 w-[12%] text-center">O.R. Number</th>
                                    <th class="px-2 py-3 border-r border-slate-300 w-[10%] text-center">Date</th>
                                    <th class="px-2 py-3 border-slate-300 w-[8%] text-center">Receipt</th>
                                </tr>
                            </thead>
                            <tbody id="ledger_tbody" class="bg-transparent">
                                <?= renderLedgerTable($records,$sql_error_msg) ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-4 py-2 bg-slate-50/90 border-t border-slate-200 flex items-center justify-between text-[9px] font-bold text-slate-500 uppercase tracking-widest">
                        <span id="footer_count">Viewing <?= $total_cleared ?> item(s)</span>
                        <span>Scroll table to view additional records</span>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- ALL YEARS FLOATING CARD MODAL -->
    <div id="allYearsModal" class="fixed inset-0 z-[200] hidden items-center justify-center p-4 modal-overlay bg-slate-900/50 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-xl shadow-2xl border-t-4 border-t-[#00205b] w-full max-w-sm modal-content relative">
            <div class="px-5 py-4 border-b border-slate-200 flex justify-between items-center bg-white rounded-t-xl">
                <h3 class="font-black text-[#00205b] text-[11px] uppercase tracking-widest flex items-center gap-2">
                    <svg class="w-4 h-4 text-[#c5a02c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    Academic Years Archive
                </h3>
                <button type="button" onclick="window.closeAllYearsModal()" class="text-slate-400 hover:text-rose-500 bg-slate-50 hover:bg-rose-50 p-1.5 rounded transition-colors focus:outline-none cursor-pointer">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="p-5 max-h-[60vh] overflow-y-auto custom-scrollbar">
                <div class="grid grid-cols-2 gap-3">
                    <button type="button" id="btn_year_All" onclick="window.selectYearFilter('All')" class="year-btn col-span-2 px-3 py-2 border <?= ($f_year === 'All') ? 'border-[#00205b] bg-blue-50 text-[#00205b]' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' ?> rounded-lg text-[10px] font-bold uppercase tracking-widest transition-colors shadow-sm cursor-pointer">
                        View All Years
                    </button>
                    <?php foreach ($years_list as $yr):$safe_yr = htmlspecialchars($yr);$id_yr = str_replace('-', '_', $safe_yr);$isActive = ($f_year ===$safe_yr);
                    ?>
                        <button type="button" id="btn_year_<?= $id_yr ?>" onclick="window.selectYearFilter('<?= $safe_yr ?>')" class="year-btn px-3 py-2 border <?= $isActive ? 'border-[#00205b] bg-blue-50 text-[#00205b]' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' ?> rounded-lg text-xs font-bold transition-colors shadow-sm cursor-pointer">
                            <?= $safe_yr ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- EXTERNAL JS INJECTIONS -->
    <script src="financial_ledger.js?v=<?= time() ?>"></script>

</body>
</html>