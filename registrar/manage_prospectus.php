<?php
// 1. ISOLATED SESSION HANDLER FOR STAFF (BULLETPROOF)
if (session_status() === PHP_SESSION_NONE) {
    $session_lifetime = 60 * 60 * 24 * 30; // 30 days
    ini_set('session.gc_maxlifetime', $session_lifetime);
    session_set_cookie_params($session_lifetime, '/');
    session_name('LDSP_STAFF_SESSION'); 
    session_start();
}

// 2. CACHE BUSTING
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

include "../dbconn.php";

// =========================================================
// VIEWER ACCESS LOGIC (STRICTLY READ ONLY)
// Allows Registrar, Admin, and Program Head to view this page
// =========================================================
$allowed_roles = ['admin', 'registrar', 'programhead'];

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../index.php");
    exit();
}

// ---------------------------------------------------------
// HTML TEMPLATE FUNCTIONS (USED BY PHP & FETCH API)
// ---------------------------------------------------------

function renderPdfActions($current_pdf) {
    ob_start();
    if (!empty($current_pdf) && file_exists($current_pdf)): ?>
        <a href="<?= htmlspecialchars($current_pdf) ?>" target="_blank" class="bg-gradient-to-r from-[#003882] to-[#00205b] text-white border border-[#001233] px-5 py-2.5 rounded-xl text-[10px] uppercase tracking-wider font-bold shadow-md hover:-translate-y-0.5 transition-transform flex items-center justify-center">View Attached PDF</a>
    <?php else: ?>
        <span class="text-[11px] text-slate-500 font-bold uppercase tracking-widest bg-slate-100/80 px-4 py-2 rounded-xl border border-slate-200 shadow-inner">No PDF Attached</span>
    <?php endif;
    return ob_get_clean();
}

function renderCurriculumTable($curriculum, $show_status) {
    ob_start();
    $year_levels_order = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
    
    foreach ($year_levels_order as $year):
        if (!isset($curriculum[$year])) continue;
    ?>
    <div class="w-full page-break-inside-avoid">
        <h3 class="text-[14px] font-black text-black uppercase mb-3 border-b-2 border-black pb-1"><?= $year ?></h3>
        
        <div class="grid grid-cols-2 gap-6 items-start w-full">
            <?php foreach (['1st Semester', '2nd Semester'] as $sem): ?>
                <div class="w-full">
                    <?php if (isset($curriculum[$year][$sem])): 
                        $term_subjects = $curriculum[$year][$sem];
                        $term_units = array_sum(array_column($term_subjects, 'units'));
                    ?>
                        <div class="flex justify-between items-end mb-2 border-b border-gray-400 pb-1">
                            <h4 class="font-bold text-black uppercase text-[12px] tracking-wider"><?= $sem ?></h4>
                            <span class="font-bold text-[11px] bg-gray-100 px-2 py-0.5 border border-gray-300">Units: <?= $term_units ?></span>
                        </div>
                        
                        <table class="w-full text-left border-collapse border border-black table-fixed">
                            <thead class="bg-gray-100 text-black uppercase text-[10px] tracking-wider">
                                <tr>
                                    <th class="border border-black p-1.5 w-[20%] font-bold">Code</th>
                                    <th class="border border-black p-1.5 w-[40%] font-bold">Descriptive Title</th>
                                    <th class="border border-black p-1.5 text-center w-[12%] font-bold">Units</th>
                                    <th class="border border-black p-1.5 w-[15%] font-bold">Pre-Req</th>
                                    <th class="border border-black p-1.5 w-[13%] font-bold">Teacher</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($term_subjects as $subj): ?>
                                    <tr class="hover:bg-slate-50 transition duration-150 relative group">
                                        <td class="border border-black p-1.5 font-mono font-semibold text-[11px] <?= ($show_status === 'archived') ? 'text-slate-400 line-through' : 'text-[#00205b]' ?> break-words align-middle overflow-hidden">
                                            <div id="display-row-<?= $subj['id'] ?>"><?= htmlspecialchars($subj['course_code']) ?></div>
                                        </td>
                                        <td class="border border-black p-1.5 text-[11px] leading-snug <?= ($show_status === 'archived') ? 'text-slate-400' : 'text-slate-800 font-medium' ?> break-words align-middle overflow-hidden">
                                            <?= htmlspecialchars($subj['descriptive_title']) ?>
                                        </td>
                                        <td class="border border-black p-1.5 text-center font-bold text-[11px] <?= ($show_status === 'archived') ? 'text-slate-400' : 'text-[#c5a02c]' ?> align-middle overflow-hidden">
                                            <?= htmlspecialchars($subj['units']) ?>
                                        </td>
                                        <td class="border border-black p-1.5 text-[11px] font-semibold leading-snug break-words align-middle overflow-hidden relative text-slate-600 print:text-black">
                                            <?= htmlspecialchars($subj['prerequisite'] ?? 'None') ?>
                                        </td>
                                        <td class="border border-black p-1.5 text-[11px] font-semibold leading-snug break-words align-middle overflow-hidden relative <?= empty($subj['teacher_id']) ? 'text-rose-500' : 'text-[#00205b]' ?> print:text-black">
                                            <?= !empty($subj['teacher_id']) ? htmlspecialchars(($subj['t_last'] ?? '') . ', ' . substr($subj['t_first'] ?? 'T', 0, 1) . '.') : 'TBA' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- SUMMER SECTION -->
    <?php if (isset($curriculum[$year]['Summer'])): 
        $term_subjects = $curriculum[$year]['Summer'];
        $term_units = array_sum(array_column($term_subjects, 'units'));
    ?>
    <div class="mt-4 w-1/2 mx-auto page-break-inside-avoid">
        <div class="flex justify-between items-end mb-2 border-b border-gray-400 pb-1">
            <h4 class="font-bold text-black uppercase text-[12px] tracking-wider">Summer</h4>
            <span class="font-bold text-[11px] bg-gray-100 px-2 py-0.5 border border-gray-300">Units: <?= $term_units ?></span>
        </div>
        
        <table class="w-full text-left border-collapse border border-black table-fixed">
            <thead class="bg-gray-100 text-black uppercase text-[10px] tracking-wider">
                <tr>
                    <th class="border border-black p-1.5 w-[20%] font-bold">Code</th>
                    <th class="border border-black p-1.5 w-[40%] font-bold">Descriptive Title</th>
                    <th class="border border-black p-1.5 text-center w-[12%] font-bold">Units</th>
                    <th class="border border-black p-1.5 w-[15%] font-bold">Pre-Req</th>
                    <th class="border border-black p-1.5 w-[13%] font-bold">Teacher</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($term_subjects as $subj): ?>
                    <tr class="hover:bg-slate-50 transition duration-150 relative group">
                        <td class="border border-black p-1.5 font-mono font-semibold text-[11px] <?= ($show_status === 'archived') ? 'text-slate-400 line-through' : 'text-[#00205b]' ?> break-words align-middle overflow-hidden">
                            <div id="display-row-<?= $subj['id'] ?>"><?= htmlspecialchars($subj['course_code']) ?></div>
                        </td>
                        <td class="border border-black p-1.5 text-[11px] leading-snug <?= ($show_status === 'archived') ? 'text-slate-400' : 'text-slate-800 font-medium' ?> break-words align-middle overflow-hidden">
                            <?= htmlspecialchars($subj['descriptive_title']) ?>
                        </td>
                        <td class="border border-black p-1.5 text-center font-bold text-[11px] <?= ($show_status === 'archived') ? 'text-slate-400' : 'text-[#c5a02c]' ?> align-middle overflow-hidden">
                            <?= htmlspecialchars($subj['units']) ?>
                        </td>
                        <td class="border border-black p-1.5 text-[11px] font-semibold leading-snug break-words align-middle overflow-hidden relative text-slate-600 print:text-black">
                            <?= htmlspecialchars($subj['prerequisite'] ?? 'None') ?>
                        </td>
                        <td class="border border-black p-1.5 text-[11px] font-semibold leading-snug break-words align-middle overflow-hidden relative <?= empty($subj['teacher_id']) ? 'text-rose-500' : 'text-[#00205b]' ?> print:text-black">
                            <?= !empty($subj['teacher_id']) ? htmlspecialchars(($subj['t_last'] ?? '') . ', ' . substr($subj['t_first'] ?? 'T', 0, 1) . '.') : 'TBA' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; 

    return ob_get_clean();
}


// =========================================================
// ASYNC JSON DATA ENDPOINT (FOR ZERO-LAG LIVE REFRESH)
// =========================================================
if (isset($_GET['api_refresh'])) {
    header('Content-Type: application/json');
    
    $active_program_id = (int)($_GET['program_id'] ?? 0);
    $active_year = $_GET['year'] ?? '';
    $show_status = $_GET['status'] ?? 'active';

    $curriculum = [];
    $total_program_units = 0;
    $counted_course_codes = []; 
    $current_pdf = null;

    if ($active_program_id > 0 && !empty($active_year)) {
        $safe_year = $conn->real_escape_string($active_year);
        $safe_prog_name = "";
        
        $prog_q = $conn->query("SELECT program_name FROM programs WHERE program_id = $active_program_id");
        if ($prog_q && $prog_q->num_rows > 0) {
            $safe_prog_name = $conn->real_escape_string($prog_q->fetch_assoc()['program_name']);
        }
        
        $archived_flag = ($show_status === 'archived') ? 1 : 0;

        // V2 JOIN to grab teacher names correctly
        $curr_query = $conn->query("
            SELECT p.*, t_p.first_name as t_first, t_p.last_name as t_last 
            FROM prospectus p 
            LEFT JOIN users t_u ON p.teacher_id = t_u.id 
            LEFT JOIN user_profiles t_p ON t_u.id = t_p.user_id 
            WHERE p.program_id = $active_program_id 
            AND p.curriculum_year = '$safe_year' 
            AND IFNULL(p.is_archived, 0) = $archived_flag 
            ORDER BY p.year_level ASC, p.semester ASC, p.course_code ASC
        ");
        
        if ($curr_query && $curr_query->num_rows > 0) {
            while ($row = $curr_query->fetch_assoc()) {
                $curriculum[$row['year_level']][$row['semester']][] = $row;
                $clean_code = strtoupper(trim($row['course_code']));
                if (!in_array($clean_code, $counted_course_codes)) {
                    $total_program_units += (int)$row['units'];
                    $counted_course_codes[] = $clean_code; 
                }
            }
        }

        $pdf_q = $conn->query("SELECT pdf_file FROM active_curriculums WHERE program = '$safe_prog_name' AND curriculum_year = '$safe_year'");
        if ($pdf_q && $pdf_q->num_rows > 0) {
            $current_pdf = $pdf_q->fetch_assoc()['pdf_file'];
        }
    }

    echo json_encode([
        'curriculum_html' => renderCurriculumTable($curriculum, $show_status),
        'pdf_html'        => renderPdfActions($current_pdf),
        'total_units'     => $total_program_units
    ]);
    exit();
}

// ---------------------------------------------------------
// FETCH ALL PROGRAMS FOR THE SELECTOR
// ---------------------------------------------------------
$programs = [];
try {
    $prog_query = $conn->query("SELECT program_id, program_name FROM programs ORDER BY program_name ASC");
    if ($prog_query && $prog_query->num_rows > 0) {
        while ($row = $prog_query->fetch_assoc()) { 
            $programs[$row['program_id']] = $row['program_name']; 
        }
    }
} catch (Exception $e) {}

$active_program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : (empty($programs) ? 0 : array_key_first($programs));
$active_program_name = $programs[$active_program_id] ?? 'Unknown Program';

// ---------------------------------------------------------
// FETCH CURRICULUM YEARS 
// ---------------------------------------------------------
$curriculum_years = [];
if ($active_program_id > 0) {
    $safe_prog_name = $conn->real_escape_string($active_program_name);
    
    $year_q = $conn->query("SELECT DISTINCT curriculum_year FROM prospectus WHERE program_id = $active_program_id");
    if ($year_q) { while($r = $year_q->fetch_assoc()){ $curriculum_years[] = $r['curriculum_year']; } }
    
    $year_q2 = $conn->query("SELECT DISTINCT curriculum_year FROM active_curriculums WHERE program = '$safe_prog_name'");
    if ($year_q2) {
        while($r = $year_q2->fetch_assoc()){ 
            if(!in_array($r['curriculum_year'], $curriculum_years)) { $curriculum_years[] = $r['curriculum_year']; }
        }
    }
    rsort($curriculum_years); 
}

if (isset($_GET['year']) && !empty($_GET['year'])) {
    $active_year = $_GET['year'];
} else {
    $safe_prog_name = $conn->real_escape_string($active_program_name);
    $def_q = $conn->query("SELECT active_year FROM program_defaults WHERE program = '$safe_prog_name'");
    if ($def_q && $def_q->num_rows > 0) {
        $active_year = $def_q->fetch_assoc()['active_year'];
    } else {
        $active_year = !empty($curriculum_years) ? $curriculum_years[0] : (date('Y') . '-' . (date('Y') + 1));
    }
}

if (!in_array($active_year, $curriculum_years) && !empty($active_year)) {
    array_unshift($curriculum_years, $active_year);
}

$show_status = isset($_GET['status']) ? $_GET['status'] : 'active';

// ---------------------------------------------------------
// FETCH CURRICULUM & PDF FOR THE ACTIVE PROGRAM & YEAR
// ---------------------------------------------------------
$curriculum = [];
$total_program_units = 0;
$counted_course_codes = []; 
$current_pdf = null;

if ($active_program_id > 0 && !empty($active_year)) {
    try {
        $safe_year = $conn->real_escape_string($active_year);
        $safe_prog_name = $conn->real_escape_string($active_program_name);
        $archived_flag = ($show_status === 'archived') ? 1 : 0;

        // V2 JOIN to retrieve faculty names
        $curr_query = $conn->query("
            SELECT p.*, t_p.first_name as t_first, t_p.last_name as t_last 
            FROM prospectus p 
            LEFT JOIN users t_u ON p.teacher_id = t_u.id 
            LEFT JOIN user_profiles t_p ON t_u.id = t_p.user_id 
            WHERE p.program_id = $active_program_id 
            AND p.curriculum_year = '$safe_year' 
            AND IFNULL(p.is_archived, 0) = $archived_flag 
            ORDER BY p.year_level ASC, p.semester ASC, p.course_code ASC
        ");
        
        if ($curr_query && $curr_query->num_rows > 0) {
            while ($row = $curr_query->fetch_assoc()) {
                $curriculum[$row['year_level']][$row['semester']][] = $row;
                $clean_code = strtoupper(trim($row['course_code']));
                if (!in_array($clean_code, $counted_course_codes)) {
                    $total_program_units += (int)$row['units'];
                    $counted_course_codes[] = $clean_code; 
                }
            }
        }

        // Fetch PDF if exists
        $pdf_q = $conn->query("SELECT pdf_file FROM active_curriculums WHERE program = '$safe_prog_name' AND curriculum_year = '$safe_year'");
        if ($pdf_q && $pdf_q->num_rows > 0) {
            $current_pdf = $pdf_q->fetch_assoc()['pdf_file'];
        }
    } catch (Exception $e) {}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Curriculum Viewer - LDSP Registrar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <link rel="stylesheet" href="../Style.css?v=<?= time(); ?>">
    <style>
        /* STRICT A4 PORTRAIT SYSTEM (DO NOT ALTER FOR CORRECT PDF GENERATION) */
        .paper-container { background: white; box-shadow: 0 15px 35px rgba(0,0,0,0.1), 0 5px 15px rgba(0,0,0,0.05); margin: 2rem auto; position: relative; border: 1px solid #e5e7eb; width: 210mm; min-height: 297mm; box-sizing: border-box; border-radius: 4px; background-color: #ffffff; }
        .paper-content { padding: 10mm; width: 100%; height: 100%; box-sizing: border-box; }
        .pdf-generating .no-print { display: none !important; }
        .page-break-inside-avoid { page-break-inside: avoid !important; break-inside: avoid !important; }

        @media print {
            @page { size: A4 portrait; margin: 10mm; }
            body { background: white; margin: 0; padding: 0; color: black; overflow: hidden !important; }
            ::-webkit-scrollbar { display: none !important; }
            .no-print, .ambient-orb-1, .ambient-orb-2 { display: none !important; }
            
            .paper-container { 
                box-shadow: none !important; margin: 0 !important; border: none !important; 
                transform: scale(0.60) !important; transform-origin: top left !important; 
                width: 166.66% !important; height: 166.66% !important; max-height: none !important; 
                border-radius: 0 !important; page-break-after: avoid !important; page-break-inside: avoid !important; padding: 0 !important; 
            }
            .paper-content { height: 100% !important; padding: 0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            table { width: 100%; border-collapse: collapse; table-layout: fixed !important; }
            th, td { border: 1px solid black !important; padding: 6px 8px !important; word-wrap: break-word !important; }
        }
    </style>
</head>
<body class="flex h-screen overflow-hidden antialiased bg-[#f4f6f9]">

    <div class="ambient-orb-1 no-print"></div>
    <div class="ambient-orb-2 no-print"></div>

    <?php include 'sidebar.php'; ?>

    <main id="mainScrollArea" class="flex-1 min-w-0 overflow-y-auto h-full w-full pt-20 md:pt-0 relative custom-scrollbar z-10">
        <div class="p-6 md:p-10 lg:p-12 max-w-[1200px] mx-auto relative z-20">

            <header class="mb-6 flex flex-col xl:flex-row justify-between xl:items-end gap-6 no-print fade-in-up">
                <div>
                    <div class="flex items-center gap-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1">
                        <span>Curriculum Masterlist</span>
                        <span class="bg-rose-100 text-rose-700 px-2 py-0.5 rounded ml-2 border border-rose-200 shadow-sm">READ ONLY</span>
                    </div>
                    <h1 class="text-2xl md:text-3xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">View Curriculum</h1>
                    <p class="text-slate-500 mt-1 text-xs drop-shadow-sm">Official academic catalog and approved subject prerequisites.</p>
                </div>
                
                <form method="GET" class="bg-white/90 backdrop-blur-md p-4 rounded-xl shadow-md border border-slate-200 flex flex-col sm:flex-row items-end gap-4 w-full xl:w-auto">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($show_status) ?>">
                    <div class="w-full sm:w-auto relative z-20">
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 pl-1">Target Program</label>
                        <select name="program_id" id="f_program_id" onchange="document.getElementById('year_selector').disabled=true; this.form.submit()" class="w-full sm:w-64 bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs font-semibold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b] cursor-pointer hover:bg-slate-50">
                            <?php if (empty($programs)): ?>
                                <option value="">No Programs Found</option>
                            <?php else: ?>
                                <?php foreach($programs as $id => $name): ?>
                                    <option value="<?= htmlspecialchars($id) ?>" <?= $active_program_id == $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="w-full sm:w-auto flex items-end gap-2 relative z-20">
                        <div class="flex-1">
                            <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 pl-1">Curriculum Year</label>
                            <select id="year_selector" name="year" onchange="this.form.submit()" class="w-full sm:w-44 bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs font-bold text-[#00205b] shadow-sm focus:outline-none focus:border-[#00205b] cursor-pointer hover:bg-slate-50">
                                <?php foreach($curriculum_years as $y): ?>
                                    <option value="<?= htmlspecialchars($y) ?>" <?= $active_year === $y ? 'selected' : '' ?>>S.Y. <?= htmlspecialchars($y) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </form>
            </header>

            <?php if ($active_program_id > 0): ?>
                
                <div class="flex flex-wrap justify-between items-center gap-3 mb-6 pb-2 border-b border-slate-300/50 no-print drop-shadow-sm animate-up delay-1">
                    <div class="flex items-center gap-3 overflow-x-auto whitespace-nowrap custom-scrollbar pb-1">
                        <a href="manage_prospectus.php?program_id=<?= urlencode($active_program_id) ?>&year=<?= urlencode($active_year) ?>&status=active" class="px-6 py-2.5 rounded-xl text-[11px] font-bold uppercase tracking-widest transition-all <?= ($show_status !== 'archived') ? 'bg-gradient-to-b from-[#003882] to-[#00205b] text-white shadow-md border border-[#001233]' : 'bg-white/80 backdrop-blur-sm border border-slate-200 text-slate-500 hover:bg-white hover:text-[#00205b] shadow-sm hover:shadow-md' ?>">
                            Active Curriculum
                        </a>
                        <a href="manage_prospectus.php?program_id=<?= urlencode($active_program_id) ?>&year=<?= urlencode($active_year) ?>&status=archived" class="px-6 py-2.5 rounded-xl text-[11px] font-bold uppercase tracking-widest transition-all flex items-center gap-2 <?= ($show_status === 'archived') ? 'bg-gradient-to-b from-slate-600 to-slate-700 text-white shadow-md border border-slate-800' : 'bg-white/80 backdrop-blur-sm border border-slate-200 text-slate-500 hover:bg-white hover:text-slate-700 shadow-sm hover:shadow-md' ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>
                            Archive
                        </a>
                    </div>

                    <div class="flex items-center gap-3 z-20">
                        <span class="text-[10px] font-black text-[#00205b] px-4 uppercase tracking-widest bg-blue-50/80 backdrop-blur-sm py-2 rounded-xl border border-blue-200 shadow-sm flex items-center gap-1.5 h-[38px]">
                            <svg class="w-4 h-4 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            Scale is 60%
                        </span>
                        <button onclick="window.downloadAsPDF('<?= addslashes($active_program_name) ?>', '<?= addslashes($active_year) ?>')" class="bg-gradient-to-r from-emerald-500 to-emerald-400 text-white border border-emerald-600 px-4 py-2 h-[38px] text-[10px] uppercase tracking-wider font-bold rounded-lg shadow-md hover:shadow-lg hover:-translate-y-0.5 transition-transform flex items-center justify-center gap-2 cursor-pointer">
                            <svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                            Download PDF
                        </button>
                        <button onclick="window.print()" class="bg-gradient-to-r from-[#003882] to-[#00205b] text-white border border-[#001233] px-4 py-2 h-[38px] text-[10px] uppercase tracking-wider font-bold rounded-lg shadow-md hover:shadow-lg hover:-translate-y-0.5 transition-transform flex items-center justify-center gap-2 cursor-pointer">
                            <svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" /></svg>
                            Print
                        </button>
                    </div>
                </div>

                <div class="bg-white/90 backdrop-blur-md border border-slate-200 rounded-xl shadow-md p-5 mb-8 flex flex-col sm:flex-row items-center justify-between gap-4 no-print z-20 animate-up delay-2 border-t-4 border-t-[#00205b]">
                    <div class="flex items-center gap-4 relative z-20 w-full sm:w-auto">
                        <div class="bg-indigo-50/80 text-indigo-600 p-2.5 rounded-xl border border-indigo-100 shadow-sm">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                        </div>
                        <div>
                            <h3 class="text-sm font-black text-indigo-900 uppercase tracking-[0.1em] drop-shadow-sm">Master PDF Prospectus</h3>
                            <p class="text-[11px] font-semibold text-slate-500 mt-0.5">Official attached PDF document for S.Y. <?= htmlspecialchars($active_year) ?></p>
                        </div>
                    </div>
                    
                    <div id="pdf_actions_container" class="relative z-20 w-full sm:w-auto flex justify-end">
                        <?= renderPdfActions($current_pdf) ?>
                    </div>
                </div>

                <!-- STRICT A4 PORTRAIT CONTAINER -->
                <div class="animate-up delay-3 z-20 relative">
                    <?php if (empty($curriculum)): ?>
                        <div class="text-center py-16 text-slate-400 text-xs font-bold uppercase tracking-[0.2em] bg-white/50 rounded-xl border border-dashed border-slate-300 shadow-sm">No subjects found in the curriculum database.</div>
                    <?php else: ?>
                        <div id="documentCanvas" class="paper-container mx-auto">
                            <div class="paper-content">
                                
                                <div class="text-center mb-6 border-b-2 border-black pb-4 shrink-0">
                                    <h1 class="text-[20px] font-black uppercase font-academic tracking-wider m-0 leading-tight text-[#00205b] print:text-black">LDSP Academic Curriculum</h1>
                                    <h2 class="text-[14px] font-bold uppercase m-0 mt-1 leading-tight text-slate-800 print:text-black"><?= htmlspecialchars($active_program_name) ?></h2>
                                    <p class="text-[11px] font-semibold m-0 mt-1 leading-tight text-slate-600 print:text-black">Curriculum Year: <?= htmlspecialchars($active_year) ?></p>
                                    <p class="text-[10px] m-0 leading-tight mt-1 text-slate-500 print:text-black">Total Program Credit Units: <strong id="print_total_units" class="text-slate-800 print:text-black"><?= $total_program_units ?></strong></p>
                                </div>

                                <div id="curriculum_container" class="w-full flex flex-col gap-6">
                                    <?= renderCurriculumTable($curriculum, $show_status) ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <div class="text-center py-16 bg-white/90 backdrop-blur-md border border-dashed border-slate-300 shadow-md rounded-xl no-print z-20 animate-up delay-2">
                    <div class="flex flex-col items-center justify-center gap-3">
                        <svg class="w-10 h-10 text-slate-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
                        <p class="text-slate-500 text-sm font-bold uppercase tracking-widest drop-shadow-sm">No Academic Programs Available.</p>
                        <p class="text-slate-400 text-xs">Please wait for the Administrator to configure academic programs.</p>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <button id="floatingBackToTop" onclick="window.scrollToTop()" class="fixed bottom-6 right-6 md:bottom-10 md:right-10 bg-gradient-to-br from-[#003882] to-[#00205b] hover:from-[#004ba8] hover:to-[#00205b] border border-[#001233] text-white w-14 h-14 rounded-full shadow-[0_10px_25px_rgba(0,32,91,0.5),inset_0_2px_0_rgba(255,255,255,0.3)] z-[90] flex items-center justify-center transition-all duration-300 opacity-0 pointer-events-none translate-y-4 focus:outline-none group">
            <svg class="w-6 h-6 drop-shadow-sm group-hover:-translate-y-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 15l7-7 7 7" /></svg>
        </button>
    </main>

    <script>
        // Securely pass initial PHP data to JS
        window.PROSPECTUS_INIT_DATA = {
            activeProgramId: '<?= addslashes($active_program_id) ?>',
            activeYear: '<?= addslashes($active_year) ?>',
            currentTab: '<?= addslashes($show_status) ?>'
        };
    </script>
    <script src="registrar.js?v=<?= time() ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="curriculum.js?v=<?= time() ?>"></script>
</body>
</html>