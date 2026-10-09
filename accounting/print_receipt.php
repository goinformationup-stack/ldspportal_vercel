<?php
// accounting/print_receipt.php

// 1. Initialize Session to get the logged-in Staff Member's Name
$session_lifetime = 60 * 60 * 24 * 30;
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params($session_lifetime, '/');
session_name('LDSP_STAFF_SESSION'); 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "../dbconn.php";

$or = isset($_GET['or']) ? $conn->real_escape_string(trim($_GET['or'])) : '';

if (empty($or)) {
    die("<div style='text-align:center; padding: 50px; font-family: sans-serif; color: #e11d48;'><h3>Error: Missing Official Receipt Number.</h3></div>");
}

// 2. Fetch Transaction Details & Enrollment Request Data
$q = $conn->query("
    SELECT th.*, u.id as user_id, u.student_id as actual_student_id,
           p.first_name, p.last_name, p.middle_name,
           er.id as req_id, er.balance as current_balance, er.year_level, er.paid_through,
           er.total_paid_accumulated, er.student_status, er.retake_fee,
           a.assigned_initial_fee
    FROM transaction_history th
    LEFT JOIN users u ON th.student_email = u.email
    LEFT JOIN user_profiles p ON u.id = p.user_id
    LEFT JOIN enrollment_requests er ON (u.id = er.user_id AND th.academic_year = er.school_year AND th.semester = er.semester)
    LEFT JOIN admissions a ON u.id = a.provisioned_user_id
    WHERE th.or_number = '$or'
    LIMIT 1
");

if (!$q || $q->num_rows === 0) {
    die("<div style='text-align:center; padding: 50px; font-family: sans-serif; color: #e11d48;'><h3>Receipt record not found for O.R. #$or</h3></div>");
}

$txn = $q->fetch_assoc();
$student_name = trim(($txn['last_name'] ?? '') . ', ' . ($txn['first_name'] ?? '') . ' ' . ($txn['middle_name'] ?? ''));
if (empty(trim($student_name, ', '))) {
    $student_name = $txn['student_email'];
}
$student_id = !empty($txn['actual_student_id']) ? $txn['actual_student_id'] : '26-00001';
$amount = number_format((float)$txn['amount'], 2);
$date_verified = date('F j, Y h:i A', strtotime($txn['approved_at']));
$current_balance = isset($txn['current_balance']) ? (float)$txn['current_balance'] : 0;

// 3. Fetch the Official Accounting Staff Name from Database for Liability
$staff_name = ""; 
$staff_q = $conn->query("
    SELECT p.first_name, p.last_name, p.middle_name 
    FROM users u 
    JOIN user_profiles p ON u.id = p.user_id 
    WHERE u.role = 'accounting' AND u.status = 'Active' 
    ORDER BY u.id ASC LIMIT 1
");

if ($staff_q && $staff_q->num_rows > 0) {
    $staff = $staff_q->fetch_assoc();
    $fname = trim($staff['first_name'] ?? '');
    $mname = trim($staff['middle_name'] ?? '');
    $lname = trim($staff['last_name'] ?? '');
    
    $m_initial = !empty($mname) ? substr($mname, 0, 1) . '.' : '';
    $full_name = trim(preg_replace('/\s+/', ' ', "$fname $m_initial $lname"));
    
    if (!empty($full_name)) {
        $staff_name = strtoupper($full_name);
    }
}

// 4. Clean formatting for Output
$pay_type_clean = htmlspecialchars(str_ireplace('Payment for:', '', $txn['payment_type']));
$channel_clean = !empty($txn['paid_through']) ? htmlspecialchars($txn['paid_through']) : '';

// 5. Breakdown Calculation Logic
$responsive_breakdown_html = "";

if ($current_balance > 0) {
    $prog_safe = $conn->real_escape_string($txn['program'] ?? '');
    $yl_safe = $conn->real_escape_string($txn['year_level'] ?? '');
    $sem_safe = $conn->real_escape_string($txn['semester'] ?? '');
    $adm_init = !empty($txn['assigned_initial_fee']) ? $txn['assigned_initial_fee'] : 'Miscellaneous Fee';
    $status = $txn['student_status'] ?? 'Regular';

    $fees = [];
    
    // Gen Fees
    $q1 = $conn->query("SELECT fee_name, amount, payment_rule, first_payment, second_payment FROM accounting_fees WHERE (target_program = 'All' OR target_program = '$prog_safe' OR target_program LIKE '%$prog_safe%') AND (target_year = 'All' OR target_year = '$yl_safe') AND (target_semester = 'All' OR target_semester = '$sem_safe' OR target_semester IS NULL)");
    if($q1) {
        while($f = $q1->fetch_assoc()) {
            if ($status === 'Regular' && stripos($f['fee_name'], 'Tuition') !== false) continue;
            if (stripos($f['fee_name'], 'Tuition') !== false && stripos($adm_init, 'Misc') !== false) continue;
            if (stripos($f['fee_name'], 'Misc') !== false && stripos($adm_init, 'Tuition') !== false) continue;
            
            if ($f['payment_rule'] === 'Installments Allowed' && (float)$f['first_payment'] > 0) {
                $fees[] = ['name'=>$f['fee_name'], 'amount'=>(float)$f['amount'], 'is_inst'=>true, 'f1'=>(float)$f['first_payment'], 'f2'=>(float)$f['second_payment']];
            } else {
                $fees[] = ['name'=>$f['fee_name'], 'amount'=>(float)$f['amount']];
            }
        }
    }
    
    // Subj Fees
    $enrolled = [];
    $req_id = (int)($txn['req_id'] ?? 0);
    $user_id = (int)($txn['user_id'] ?? 0);
    
    if($req_id > 0) {
        $q2 = $conn->query("SELECT subject_id FROM enrollments WHERE enrollment_request_id = $req_id");
        if($q2) { while($r = $q2->fetch_assoc()) { $enrolled[] = $r['subject_id']; } }
    }
    if(empty($enrolled) && $user_id > 0) {
        $q3 = $conn->query("SELECT evaluated_subjects, enrollment_term FROM admissions WHERE provisioned_user_id = $user_id LIMIT 1");
        if($q3 && $q3->num_rows>0) {
            $adm = $q3->fetch_assoc();
            if($adm['enrollment_term'] === "{$txn['year_level']} - {$txn['semester']}" && !empty($adm['evaluated_subjects'])) {
                $enrolled = array_map('intval', explode(',', $adm['evaluated_subjects']));
            }
        }
    }
    if(empty($enrolled)) {
        $q4 = $conn->query("SELECT program_id FROM programs WHERE program_name = '$prog_safe' LIMIT 1");
        if($q4 && $q4->num_rows>0) {
            $pid = (int)$q4->fetch_assoc()['program_id'];
            $q5 = $conn->query("SELECT id FROM prospectus WHERE program_id = $pid AND year_level = '$yl_safe' AND semester = '$sem_safe' AND IFNULL(is_archived,0)=0");
            if($q5) { while($r = $q5->fetch_assoc()) { $enrolled[] = $r['id']; } }
        }
    }

    if(!empty($enrolled)) {
        $ids = implode(',', array_filter($enrolled));
        if(!empty($ids)) {
            $q6 = $conn->query("SELECT course_code, descriptive_title, subject_fee FROM prospectus WHERE id IN ($ids) AND subject_fee > 0");
            if($q6) {
                while($s = $q6->fetch_assoc()) {
                    $fees[] = ['name'=>$s['course_code'].' - '.$s['descriptive_title'], 'amount'=>(float)$s['subject_fee']];
                }
            }
        }
    }

    // Retake
    if ((float)($txn['retake_fee'] ?? 0) > 0) { $fees[] = ['name'=>'Failed/Retake Subject Fees', 'amount'=>(float)$txn['retake_fee']]; }

    // Waterfall calculation
    $rem = (float)($txn['total_paid_accumulated'] ?? 0);
    foreach($fees as &$f) {
        $apply = min($f['amount'], max(0, $rem));
        $f['cov'] = $apply;
        $rem -= $apply;
    }
    unset($f);

    foreach($fees as $f) {
        if(!empty($f['is_inst'])) {
            $c1 = min($f['f1'], $f['cov']); $u1 = $f['f1'] - $c1;
            if($u1 > 0.01) { 
                $responsive_breakdown_html .= "<div class='flex justify-between items-center py-1 w-full text-[9px] sm:text-xs'><span class='truncate pr-2 sm:text-slate-600 sm:w-2/3'>{$f['name']} <span class='sm:text-[10px] sm:text-slate-400 block sm:inline'>(1st Installment)</span></span><span class='shrink-0 sm:font-mono sm:text-slate-800 font-bold'>".number_format($u1,2)."</span></div>"; 
            }
            $c2 = min($f['f2'], $f['cov'] - $c1); $u2 = $f['f2'] - $c2;
            if($u2 > 0.01) { 
                $responsive_breakdown_html .= "<div class='flex justify-between items-center py-1 w-full text-[9px] sm:text-xs'><span class='truncate pr-2 sm:text-slate-600 sm:w-2/3'>{$f['name']} <span class='sm:text-[10px] sm:text-slate-400 block sm:inline'>(2nd Installment)</span></span><span class='shrink-0 sm:font-mono sm:text-slate-800 font-bold'>".number_format($u2,2)."</span></div>"; 
            }
        } else {
            $u = $f['amount'] - $f['cov'];
            if($u > 0.01) { 
                $responsive_breakdown_html .= "<div class='flex justify-between items-center py-1 w-full text-[9px] sm:text-xs'><span class='truncate pr-2 sm:text-slate-600 sm:w-2/3'>{$f['name']}</span><span class='shrink-0 sm:font-mono sm:text-slate-800 font-bold'>".number_format($u,2)."</span></div>"; 
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt #<?= htmlspecialchars($or) ?> - LDSP Accounting</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Courier Prime', monospace; /* Default base font for thermal fallback */
            -webkit-print-color-adjust: exact !important; 
            print-color-adjust: exact !important; 
        }
        
        @media print {
            .no-print { display: none !important; }

            /* --- 1. DYNAMIC PAPER LAYOUT (STRICT 1-PAGE LOCK) --- */
            body.printing-dynamic {
                background: white !important; 
                margin: 0 !important; 
                padding: 0 !important; 
                /* Force exactly 1 viewport width/height (1 piece of paper) */
                width: 100vw !important;
                height: 100vh !important; 
                max-height: 100vh !important;
                overflow: hidden !important; /* CRITICAL: Destroys any chance of a second page */
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
            }
            body.printing-dynamic .receipt-dynamic {
                /* Creates the margins physically within the 1-page bounds */
                width: 92vw !important;
                height: 94vh !important;
                margin: 3vh 4vw !important;
                padding: 3vh 4vw !important; 
                
                box-sizing: border-box !important;
                border: 2px solid #cbd5e1 !important; 
                box-shadow: none !important;
                page-break-inside: avoid !important;
                
                /* Flexbox perfectly distributes spacing without overflowing */
                display: flex !important;
                flex-direction: column !important;
                justify-content: space-between !important;
            }

            /* --- 2. THERMAL POS ROLL (80MM) LAYOUT --- */
            body.printing-thermal {
                width: 80mm !important;
                height: auto !important;
                max-height: none !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 0 !important;
                display: block !important;
            }
            body.printing-thermal .receipt-thermal {
                box-shadow: none !important;
                border: none !important;
                margin: 0 !important;
                padding: 4mm !important; /* Tight margin for 80mm paper */
                width: 80mm !important; /* STRICT 80mm WIDTH */
                max-width: 80mm !important;
                page-break-inside: avoid !important;
                display: block !important;
            }
        }
    </style>
</head>
<body class="bg-slate-100 min-h-screen py-4 sm:py-8 flex flex-col items-center justify-center p-2 sm:p-4 printing-dynamic" id="body-tag">

    <!-- Dynamic Print @page CSS Injector -->
    <style id="dynamic-page-css">
        @media print { @page { size: auto; margin: 0; } }
    </style>

    <!-- Action Toolbar (Responsive, Hidden during print) -->
    <div class="no-print w-[95%] sm:w-full max-w-xl mb-4 flex flex-col sm:flex-row justify-between items-center bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-slate-200 gap-3 font-sans">
        <div class="flex flex-col sm:flex-row items-center gap-2 w-full sm:w-auto">
            <label class="text-xs text-slate-500 font-semibold whitespace-nowrap">Format:</label>
            <select id="paper_format" onchange="switchFormat()" class="bg-slate-50 border border-slate-300 text-[#00205b] text-xs font-bold rounded px-2 py-1.5 focus:outline-none focus:border-[#00205b] w-full sm:w-auto cursor-pointer shadow-sm">
                <option value="dynamic">Auto-Fit Standard Paper (A4, Letter, Legal)</option>
                <option value="thermal">POS Thermal Roll (80mm)</option>
            </select>
        </div>
        <div class="flex w-full sm:w-auto gap-2">
            <button onclick="window.print()" class="flex-1 sm:flex-none justify-center bg-[#00205b] hover:bg-blue-900 text-white text-xs font-bold uppercase tracking-wider px-6 py-3 sm:py-2.5 rounded-md shadow flex items-center gap-1.5 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                Print
            </button>
            <button onclick="window.close()" class="flex-1 sm:flex-none justify-center bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold uppercase tracking-wider px-4 py-3 sm:py-2.5 rounded-md transition border border-slate-300">
                Close
            </button>
        </div>
    </div>


    <!-- ========================================== -->
    <!-- 1. DYNAMIC PAPER LAYOUT (AUTO-FIT 1 PAGE)  -->
    <!-- ========================================== -->
    <div id="receipt-dynamic" class="receipt-dynamic bg-white w-[95%] sm:w-full max-w-xl sm:border-2 border-slate-300 rounded-none sm:rounded-xl shadow-none sm:shadow-xl p-5 sm:p-8 relative overflow-hidden sm:font-sans text-[10px] sm:text-xs flex flex-col justify-between">
        
        <div class="flex-1 flex flex-col justify-start">
            <!-- Header with Logo -->
            <div class="border-b-[1.5px] sm:border-b-2 border-dashed sm:border-solid border-black sm:border-slate-800 pb-3 sm:pb-4 mb-3 sm:mb-4 text-center shrink-0">
                <img src="../logo.jpg" alt="LDSP Logo" class="w-10 h-10 sm:w-16 sm:h-16 mx-auto mb-1 sm:mb-2 object-contain grayscale contrast-125 sm:grayscale-0 sm:rounded-full sm:shadow-sm" onerror="this.style.display='none'">
                
                <h1 class="text-[13px] sm:text-lg font-bold sm:font-black sm:text-[#00205b] tracking-wider uppercase leading-tight">Lyceum de San Pablo, Inc.</h1>
                <p class="text-[9px] sm:text-[11px] sm:text-slate-600 font-semibold tracking-wide">San Pablo City, Laguna, Philippines</p>
                <p class="text-[9px] sm:text-[10px] sm:text-slate-500 uppercase tracking-widest font-bold mt-0.5 sm:mt-1">Accounting Office</p>
                
                <div class="mt-2 sm:mt-3 sm:px-3 sm:py-1 sm:bg-slate-100 sm:rounded-full sm:border sm:border-slate-300 inline-block">
                    <span class="text-[11px] sm:text-xs font-bold sm:font-black sm:text-slate-800 uppercase tracking-widest">Official Electronic Receipt</span>
                </div>
            </div>

            <!-- Meta Details Grid -->
            <div class="flex flex-col sm:flex-row justify-between gap-2 sm:gap-3 pb-3 sm:pb-4 border-b-[1.5px] sm:border-b border-dashed border-black sm:border-slate-300 text-left shrink-0">
                <div class="flex justify-between sm:block w-full">
                    <span class="font-bold sm:text-slate-400 block text-[10px] uppercase sm:tracking-wider shrink-0">OR Number:</span>
                    <span class="font-bold sm:font-mono sm:text-[#00205b] sm:text-sm tracking-widest text-right break-all ml-2 sm:ml-0"><?= htmlspecialchars($or) ?></span>
                    <span class="hidden sm:block text-[10px] text-slate-500 font-medium mt-0.5"><?= $date_verified ?></span>
                </div>
                <div class="flex justify-between sm:hidden w-full">
                    <span class="font-bold shrink-0">Date:</span>
                    <span class="text-right truncate ml-2"><?= $date_verified ?></span>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row justify-between gap-2 sm:gap-3 py-3 sm:py-3 border-b-[1.5px] sm:border-b border-dashed border-black sm:border-slate-300 text-left shrink-0">
                <div class="flex flex-col sm:block w-full">
                    <span class="font-bold sm:text-slate-400 block text-[10px] uppercase sm:tracking-wider mb-0.5 sm:mb-0">Student:</span>
                    <span class="font-bold sm:text-slate-800 sm:text-xs uppercase break-words pl-2 sm:pl-0"><?= htmlspecialchars($student_name) ?></span>
                    <span class="block sm:text-[11px] font-mono sm:text-slate-500 font-bold mt-0.5 pl-2 sm:pl-0">ID: <?= htmlspecialchars($student_id) ?></span>
                </div>
                <div class="flex flex-col sm:block w-full sm:text-right mt-2 sm:mt-0">
                    <span class="font-bold sm:text-slate-400 block text-[10px] uppercase sm:tracking-wider mb-0.5 sm:mb-0">Program & Term:</span>
                    <span class="font-bold sm:text-slate-800 sm:text-xs uppercase truncate pl-2 sm:pl-0 block"><?= htmlspecialchars($txn['program']) ?></span>
                    <span class="block pl-2 sm:pl-0"><?= htmlspecialchars($txn['semester']) ?> • A.Y. <?= htmlspecialchars($txn['academic_year']) ?></span>
                </div>
            </div>

            <!-- Particulars -->
            <div class="py-3 sm:py-4 border-b-[1.5px] sm:border-b border-dashed border-black sm:border-slate-200 shrink-0">
                <span class="font-bold sm:text-slate-400 block text-[10px] uppercase sm:tracking-wider mb-1 sm:mb-2">Transaction Particulars:</span>
                
                <div class="flex justify-between items-start w-full">
                    <div class="font-bold sm:text-slate-800 sm:text-xs pr-2 break-words uppercase">
                        <?= $pay_type_clean ?>
                        <?php if(!empty($channel_clean)): ?>
                            <span class="block text-[9px] sm:text-[10px] font-normal sm:text-slate-400 mt-0.5">Channel: <?= $channel_clean ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="text-right font-bold sm:font-mono sm:text-slate-900 sm:text-sm shrink-0 mt-0.5 sm:mt-0">
                        <span class="sm:hidden">P</span><span class="hidden sm:inline">₱</span> <?= $amount ?>
                    </div>
                </div>
            </div>

            <!-- Total & Balance -->
            <div class="sm:bg-slate-50 p-0 sm:p-4 sm:rounded-b-lg sm:border-x sm:border-b border-slate-200 mb-4 sm:mb-6 sm:shadow-sm mt-3 sm:mt-0 shrink-0">
                
                <div class="flex justify-between items-center font-bold sm:font-black uppercase sm:text-sm w-full">
                    <span class="shrink-0 sm:text-[#00205b]">Total Paid:</span>
                    <span class="text-right sm:text-emerald-600 sm:font-mono">PHP <?= $amount ?></span>
                </div>
                
                <?php if($current_balance > 0): ?>
                    <div class="mt-3 sm:mt-4 pt-3 sm:pt-4 border-t-[1.5px] sm:border-t border-dashed sm:border-solid border-black sm:border-slate-200">
                        <span class="font-bold sm:font-black sm:text-slate-600 uppercase sm:tracking-wider text-[10px] block mb-1 sm:mb-2">Balance Breakdown:</span>
                        
                        <div class="sm:bg-white sm:border border-rose-100 sm:rounded px-2 sm:px-3 py-1 mb-2 sm:mb-3 sm:divide-y divide-slate-100 sm:shadow-inner uppercase">
                            <?= $responsive_breakdown_html ?>
                            
                            <div class="flex justify-between items-center pt-2 sm:pt-3 mt-1 pb-1 border-t border-black sm:border-transparent">
                                <span class="font-bold sm:font-black sm:text-slate-700 uppercase sm:tracking-wider sm:text-[11px]">Total Due</span>
                                <span class="font-bold sm:font-black sm:font-mono sm:text-rose-600 sm:text-sm text-right">PHP <?= number_format($current_balance, 2) ?></span>
                            </div>
                        </div>
                        
                        <div class="hidden sm:flex bg-rose-50 border border-rose-200 p-2.5 rounded-md items-start gap-2">
                            <svg class="w-4 h-4 text-rose-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <p class="text-[10px] text-rose-600 font-medium leading-snug">
                                <strong>NOTICE:</strong> You have an outstanding balance. Please settle the remaining amount before the end of the term.
                            </p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center sm:text-right text-[10px] font-bold sm:text-emerald-600 uppercase tracking-widest mt-3 pt-3 border-t-[1.5px] sm:border-t border-dashed sm:border-solid border-black sm:border-slate-200">
                        * Fully Cleared *
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer Signatures (Pushed to bottom of the available flex space) -->
        <div class="flex justify-center pt-4 sm:pt-6 text-center border-t-[1.5px] sm:border-t border-dashed sm:border-solid border-black sm:border-slate-200 mt-auto shrink-0">
            <div class="w-full sm:w-1/2">
                <p class="text-[9px] sm:text-[9px] sm:text-slate-400 font-bold uppercase sm:tracking-wider mb-5 sm:mb-8">Processed By:</p>
                <p class="text-[11px] sm:text-xs font-bold sm:font-black sm:text-[#00205b] uppercase sm:tracking-wide truncate px-2 mb-1">
                    <?= htmlspecialchars($staff_name) ?>
                </p>
                <div class="hidden sm:block border-t border-slate-400 pt-1">
                    <p class="text-[10px] font-bold text-slate-800 uppercase tracking-widest">Accounting Staff</p>
                    <p class="text-[8px] text-slate-400 uppercase tracking-widest mt-0.5">LDSP Accounting Office</p>
                </div>
            </div>
        </div>

        <!-- Thermal Footer Note -->
        <div class="sm:hidden text-center text-[8px] mt-4 uppercase text-slate-500 shrink-0">
            This is a system generated<br>official electronic receipt.
        </div>

    </div>


    <!-- ========================================== -->
    <!-- 2. THERMAL POS ROLL (80MM) LAYOUT          -->
    <!-- ========================================== -->
    <div id="receipt-thermal" class="receipt-thermal hidden bg-white mx-auto p-4 border border-slate-300 shadow-2xl text-black" style="width: 80mm; font-family: 'Courier Prime', Courier, monospace; font-size: 10px; line-height: 1.3;">
        
        <div class="text-center mb-3">
            <img src="../logo.jpg" class="w-10 h-10 mx-auto mb-1 object-contain mix-blend-multiply" style="filter: grayscale(100%) contrast(1.2);" onerror="this.style.display='none'">
            <div class="font-bold text-[13px] uppercase">LYCEUM DE SAN PABLO</div>
            <div class="text-[9px]">San Pablo City, Laguna</div>
            <div class="text-[9px]">Accounting Office</div>
        </div>

        <div class="border-t-[1.5px] border-dashed border-black my-2"></div>
        
        <div class="text-[10px] font-bold uppercase mb-2 text-center">OFFICIAL RECEIPT</div>

        <div class="space-y-1 mb-2">
            <div class="flex justify-between w-full"><span class="shrink-0">OR#:</span><span class="font-bold truncate text-right"><?= htmlspecialchars($or) ?></span></div>
            <div class="flex justify-between w-full"><span class="shrink-0">DATE:</span><span class="truncate pl-2 text-right"><?= $date_verified ?></span></div>
        </div>

        <div class="border-t-[1.5px] border-dashed border-black my-2"></div>

        <div class="space-y-1 mt-2">
            <div class="font-bold">STUDENT:</div>
            <div class="pl-2 uppercase truncate"><?= htmlspecialchars($student_name) ?></div>
            <div class="pl-2">ID: <?= htmlspecialchars($student_id) ?></div>
            <div class="mt-2 font-bold">COURSE & TERM:</div>
            <div class="pl-2 uppercase leading-snug"><?= htmlspecialchars($txn['program']) ?></div>
            <div class="pl-2"><?= htmlspecialchars($txn['semester']) ?></div>
            <div class="pl-2">A.Y. <?= htmlspecialchars($txn['academic_year']) ?></div>
        </div>

        <div class="border-t-[1.5px] border-dashed border-black my-3"></div>

        <div class="font-bold mb-1">PARTICULARS:</div>
        <div class="flex justify-between items-start mb-1 w-full">
            <span class="uppercase pr-2 leading-snug w-2/3 break-words"><?= $pay_type_clean ?></span>
            <span class="font-bold w-1/3 text-right shrink-0">P <?= $amount ?></span>
        </div>
        <?php if(!empty($channel_clean)): ?>
            <div class="text-[9px] uppercase">VIA: <?= $channel_clean ?></div>
        <?php endif; ?>

        <div class="border-t-[1.5px] border-dashed border-black my-3"></div>

        <div class="flex justify-between font-bold text-[12px] uppercase w-full">
            <span class="shrink-0">TOTAL PAID:</span>
            <span class="text-right">PHP <?= $amount ?></span>
        </div>

        <?php if($current_balance > 0): ?>
            <div class="border-t-[1.5px] border-dashed border-black my-3"></div>
            <div class="font-bold mb-1 uppercase">BALANCE BREAKDOWN:</div>
            <div class="space-y-0.5 text-[9px] pl-2 uppercase w-full">
                <?= $responsive_breakdown_html ?>
            </div>
            <div class="flex justify-between mt-2 font-bold text-[11px] uppercase pt-1 border-t border-black w-full">
                <span class="shrink-0">TOTAL DUE:</span>
                <span class="text-right">PHP <?= number_format($current_balance, 2) ?></span>
            </div>
        <?php else: ?>
            <div class="border-t-[1.5px] border-dashed border-black my-3"></div>
            <div class="text-center font-bold uppercase tracking-widest text-[11px]">* FULLY CLEARED *</div>
        <?php endif; ?>

        <div class="border-t-[1.5px] border-dashed border-black my-3"></div>

        <div class="text-center mt-5 mb-2">
            <div class="text-[9px]">PROCESSED BY:</div>
            <div class="font-bold uppercase text-[11px] mt-1 break-words px-2"><?= htmlspecialchars($staff_name) ?></div>
        </div>

        <div class="text-center text-[8px] mt-4 uppercase">
            This is a system generated<br>official electronic receipt.
        </div>
        
    </div>

    <!-- Script to toggle Paper Formatting -->
    <script>
        function switchFormat() {
            const format = document.getElementById('paper_format').value;
            const dynamic = document.getElementById('receipt-dynamic');
            const thermal = document.getElementById('receipt-thermal');
            const body = document.getElementById('body-tag');
            const styleTag = document.getElementById('dynamic-page-css');

            // Reset body print classes
            body.classList.remove('printing-dynamic', 'printing-thermal');
            body.classList.add('printing-' + format);

            if (format === 'thermal') {
                dynamic.classList.add('hidden');
                thermal.classList.remove('hidden');
                styleTag.innerHTML = '@media print { @page { size: 80mm auto; margin: 0; } }';
            } else {
                thermal.classList.add('hidden');
                dynamic.classList.remove('hidden');
                styleTag.innerHTML = '@media print { @page { size: auto; margin: 0; } }';
            }
        }
    </script>

</body>
</html>