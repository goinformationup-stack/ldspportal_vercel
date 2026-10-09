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

// 2. ISOLATED SESSION HANDLER FOR STAFF (BULLETPROOF)
$session_lifetime = 60 * 60 * 24 * 30; // 30 days
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params($session_lifetime, '/');
session_name('LDSP_STAFF_SESSION'); 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. CACHE BUSTING
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

include "../dbconn.php";

// =========================================================
// STRICT ROLE ENFORCEMENT: REGISTRAR ONLY
// =========================================================
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'registrar') {
    header("Location: ../index.php");
    exit();
}

// =========================================================
// FETCH ALL PROGRAMS FOR THE SELECTOR
// =========================================================
$programs = [];
try {
    $prog_query = $conn->query("SELECT program_id, program_name FROM programs WHERE is_archived = 0 OR is_archived IS NULL ORDER BY program_name ASC");
    if (!$prog_query) {
        $prog_query = $conn->query("SELECT program_id, program_name FROM programs ORDER BY program_name ASC");
    }

    if ($prog_query && $prog_query->num_rows > 0) {
        while ($row = $prog_query->fetch_assoc()) { 
            $programs[$row['program_id']] = $row['program_name']; 
        }
    }
} catch (Exception $e) {}

$active_program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : (empty($programs) ? 0 : array_key_first($programs));
$active_program_name = $programs[$active_program_id] ?? 'Unknown Program';

// =========================================================
// FETCH CURRICULUM YEARS 
// =========================================================
$curriculum_years = [];
if ($active_program_id > 0) {
    $year_q = $conn->query("SELECT DISTINCT curriculum_year FROM prospectus WHERE program_id = $active_program_id");
    if ($year_q) { while($r = $year_q->fetch_assoc()){ $curriculum_years[] = $r['curriculum_year']; } }
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

// =========================================================
// FETCH CURRICULUM FOR INITIAL RENDER (READ ONLY)
// =========================================================
$curriculum = [];
$total_program_units = 0;
$counted_course_codes = []; 

if ($active_program_id > 0 && !empty($active_year)) {
    try {
        $safe_year = $conn->real_escape_string($active_year);
        // We only fetch active (non-archived) subjects for the read-only view
        $curr_query = $conn->query("SELECT * FROM prospectus WHERE program_id = $active_program_id AND curriculum_year = '$safe_year' AND IFNULL(is_archived, 0) = 0 ORDER BY year_level ASC, semester ASC, course_code ASC");
        
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
    } catch (Exception $e) {}
}

// =========================================================
// HTML TEMPLATE FUNCTION
// =========================================================
function renderCurriculumTable($curriculum) {
    ob_start();
    $year_levels_order = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
    
    foreach ($year_levels_order as $year):
        if (!isset($curriculum[$year])) continue;
    ?>
    <div class="w-full page-break-inside-avoid">
        <h3 class="text-[14px] font-black text-slate-800 print:text-black uppercase mb-3 border-b-2 border-slate-300 print:border-black pb-1"><?= $year ?></h3>
        
        <div class="grid grid-cols-1 lg:grid-cols-2 print:grid-cols-2 gap-x-6 gap-y-8 items-start w-full">
            <?php foreach (['1st Semester', '2nd Semester'] as $sem): ?>
                <div class="w-full overflow-hidden">
                    <?php if (isset($curriculum[$year][$sem])): 
                        $term_subjects = $curriculum[$year][$sem];
                        $term_units = array_sum(array_column($term_subjects, 'units'));
                    ?>
                        <div class="flex justify-between items-end mb-2 border-b border-slate-300 print:border-gray-400 pb-1 min-h-[28px]">
                            <div class="flex items-center gap-3">
                                <h4 class="font-bold text-slate-700 print:text-black uppercase text-[12px] tracking-wider"><?= $sem ?></h4>
                            </div>
                            <span class="font-bold text-[10px] bg-slate-100 text-slate-600 print:text-black px-2 py-0.5 border border-slate-200 print:border-gray-300 rounded shrink-0">Units: <?= $term_units ?></span>
                        </div>
                        
                        <div class="overflow-x-auto custom-scrollbar w-full pb-2">
                            <table class="w-full text-left border-collapse border border-slate-300 print:border-black bg-white min-w-[400px]">
                                <thead class="bg-slate-100 print:bg-gray-100 text-slate-600 print:text-black uppercase text-[9px] tracking-wider">
                                    <tr>
                                        <th class="border border-slate-300 print:border-black p-1.5 w-[20%] font-bold">Code</th>
                                        <th class="border border-slate-300 print:border-black p-1.5 w-[50%] font-bold">Descriptive Title</th>
                                        <th class="border border-slate-300 print:border-black p-1.5 text-center w-[10%] font-bold">Units</th>
                                        <th class="border border-slate-300 print:border-black p-1.5 w-[20%] font-bold">Pre-Req</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200 print:divide-black">
                                    <?php foreach ($term_subjects as $subj): ?>
                                        <tr class="transition-colors duration-150 hover:bg-slate-50">
                                            <td class="border border-slate-300 print:border-black p-1.5 font-mono font-bold text-[10px] text-[#00205b] align-middle">
                                                <?= htmlspecialchars($subj['course_code']) ?>
                                            </td>
                                            <td class="border border-slate-300 print:border-black p-1.5 text-[10px] leading-snug text-slate-700 font-medium align-middle">
                                                <?= htmlspecialchars($subj['descriptive_title']) ?>
                                            </td>
                                            <td class="border border-slate-300 print:border-black p-1.5 text-center font-bold text-[10px] text-[#c5a02c] align-middle">
                                                <?= htmlspecialchars($subj['units']) ?>
                                            </td>
                                            <td class="border border-slate-300 print:border-black p-1.5 text-[10px] font-medium leading-snug align-middle text-slate-600 print:text-black">
                                                <?= htmlspecialchars($subj['prerequisite'] ?? 'None') ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
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
    <div class="mt-8 w-full md:w-1/2 mx-auto page-break-inside-avoid">
        <div class="flex justify-between items-end mb-2 border-b border-slate-400 print:border-gray-400 pb-1 min-h-[28px]">
            <div class="flex items-center gap-3">
                <h4 class="font-bold text-slate-700 print:text-black uppercase text-[12px] tracking-wider">Summer</h4>
            </div>
            <span class="font-bold text-[10px] bg-slate-100 text-slate-600 print:text-black px-2 py-0.5 border border-slate-200 print:border-gray-300 rounded-sm shrink-0">Units: <?= $term_units ?></span>
        </div>
        
        <div class="overflow-x-auto custom-scrollbar w-full pb-2">
            <table class="w-full text-left border-collapse border border-slate-300 print:border-black min-w-[400px] bg-white">
                <thead class="bg-slate-100 print:bg-gray-100 text-slate-600 print:text-black uppercase text-[9px] tracking-wider">
                    <tr>
                        <th class="border border-slate-300 print:border-black p-1.5 w-[20%] font-bold">Code</th>
                        <th class="border border-slate-300 print:border-black p-1.5 w-[50%] font-bold">Descriptive Title</th>
                        <th class="border border-slate-300 print:border-black p-1.5 text-center w-[10%] font-bold">Units</th>
                        <th class="border border-slate-300 print:border-black p-1.5 w-[20%] font-bold">Pre-Req</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 print:divide-black">
                    <?php foreach ($term_subjects as $subj): ?>
                        <tr class="transition-colors duration-150 hover:bg-slate-50">
                            <td class="border border-slate-300 print:border-black p-1.5 font-mono font-bold text-[10px] text-[#00205b] align-middle">
                                <?= htmlspecialchars($subj['course_code']) ?>
                            </td>
                            <td class="border border-slate-300 print:border-black p-1.5 text-[10px] leading-snug text-slate-700 font-medium align-middle">
                                <?= htmlspecialchars($subj['descriptive_title']) ?>
                            </td>
                            <td class="border border-slate-300 print:border-black p-1.5 text-center font-bold text-[10px] text-[#c5a02c] align-middle">
                                <?= htmlspecialchars($subj['units']) ?>
                            </td>
                            <td class="border border-slate-300 print:border-black p-1.5 text-[10px] font-medium leading-snug align-middle text-slate-600 print:text-black">
                                <?= htmlspecialchars($subj['prerequisite'] ?? 'None') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; 

    return ob_get_clean();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Curriculum Masterlist - LDSP Registrar</title>
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

            <div class="flex flex-wrap justify-end items-center gap-3 mb-6 border-b border-slate-200 pb-4 no-print fade-in-up">
                <button onclick="window.downloadAsPDF('<?= addslashes($active_program_name) ?>', '<?= addslashes($active_year) ?>')" class="bg-gradient-to-r from-emerald-500 to-emerald-400 text-white border border-emerald-600 px-4 py-2 h-[38px] text-[10px] uppercase tracking-wider font-bold rounded-lg shadow-md hover:shadow-lg hover:-translate-y-0.5 transition-transform flex items-center justify-center gap-2 cursor-pointer">
                    <svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                    Download PDF
                </button>
                <button onclick="window.print()" class="bg-gradient-to-r from-[#003882] to-[#00205b] text-white border border-[#001233] px-4 py-2 h-[38px] text-[10px] uppercase tracking-wider font-bold rounded-lg shadow-md hover:shadow-lg hover:-translate-y-0.5 transition-transform flex items-center justify-center gap-2 cursor-pointer">
                    <svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" /></svg>
                    Print
                </button>
            </div>

            <!-- STRICT A4 PORTRAIT CONTAINER (DO NOT ALTER FOR PRINT GENERATION) -->
            <div class="fade-in-up relative transition-opacity duration-300 z-20">
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
                                <?= renderCurriculumTable($curriculum) ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <button id="floatingBackToTop" onclick="window.scrollToTop()" class="fixed bottom-6 right-6 md:bottom-10 md:right-10 bg-gradient-to-br from-[#003882] to-[#00205b] hover:from-[#004ba8] hover:to-[#00205b] border border-[#001233] text-white w-14 h-14 rounded-full shadow-[0_10px_25px_rgba(0,32,91,0.5),inset_0_2px_0_rgba(255,255,255,0.3)] z-[90] flex items-center justify-center transition-all duration-300 opacity-0 pointer-events-none translate-y-4 focus:outline-none group">
            <svg class="w-6 h-6 drop-shadow-sm group-hover:-translate-y-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 15l7-7 7 7" /></svg>
        </button>
    </main>

    <script src="registrar.js?v=<?= time() ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="curriculum.js?v=<?= time() ?>"></script>
</body>
</html>