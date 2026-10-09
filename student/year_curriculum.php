<?php
// 1. ISOLATED SESSION HANDLER FOR STUDENT (BULLETPROOF)
if (session_status() === PHP_SESSION_NONE) {
    $session_lifetime = 60 * 60 * 24 * 30; // 30 days
    ini_set('session.gc_maxlifetime', $session_lifetime);
    session_set_cookie_params($session_lifetime, '/'); 
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

include "../dbconn.php";

// =========================================================
// STRICT STUDENT-ONLY ACCESS
// =========================================================
if (!isset($_SESSION['role']) \vert{}\vert{}$_SESSION['role'] !== 'student') {
    header("Location: ../index.php"); 
    exit();
}

$student_email = $conn->real_escape_string($_SESSION['email'] ?? '');

// =========================================================
// V2 DATA FETCH: UNIFIED USER & PROFILE
// =========================================================
$user_q =$conn->query("
    SELECT u.id AS user_internal_id, p.* 
    FROM users u 
    LEFT JOIN user_profiles p ON u.id = p.user_id 
    WHERE u.email = '$student_email'
");
$user_data = ($user_q && $user_q->num_rows > 0) ?$user_q->fetch_assoc() : [];

$student_program_raw = $user_data['program'] ?? '';$student_program_safe = $conn->real_escape_string(trim($student_program_raw));
$account_on_hold = empty($student_program_safe);
$user_internal_id = (int)($user_data['user_internal_id'] ?? 0);

$student_program_id = 0;
if (!$account_on_hold) {
    $p_id_q =$conn->query("SELECT program_id FROM programs WHERE program_name = '$student_program_safe' LIMIT 1");
    if ($p_id_q &&$p_id_q->num_rows > 0) { 
        $student_program_id = (int)$p_id_q->fetch_assoc()['program_id']; 
    }
}

// =========================================================
// FETCH OFFICIAL CURRICULUM FOR THE ASSIGNED PROGRAM
// =========================================================
$active_curriculum_year = ''; 
$student_curriculum = [];$student_total_units = 0; 
$counted_course_codes = []; 

if (!$account_on_hold &&$student_program_id > 0) {
    
    // SMART LEGACY ROUTING: Ensure the student sees the curriculum they originally enrolled under!
    // Check admissions table first to find their assigned cohort year.
    $adm_q =$conn->query("SELECT academic_year FROM admissions WHERE provisioned_user_id = $user_internal_id LIMIT 1");
    if ($adm_q &&$adm_q->num_rows > 0) {
        $active_curriculum_year =$adm_q->fetch_assoc()['academic_year'];
    }

    // Fallback: If no specific year is tied to the student, show the Published Default for their program
    if (empty($active_curriculum_year)) {
        $def_q =$conn->query("SELECT active_year FROM program_defaults WHERE program = '$student_program_safe'");
        if ($def_q &&$def_q->num_rows > 0) { 
            $active_curriculum_year =$def_q->fetch_assoc()['active_year']; 
        } else {
            $y_q =$conn->query("SELECT curriculum_year FROM prospectus WHERE program_id = $student_program_id ORDER BY curriculum_year DESC LIMIT 1");
            if ($y_q &&$y_q->num_rows > 0) { 
                $active_curriculum_year =$y_q->fetch_assoc()['curriculum_year']; 
            }
        }
    }

    // Fetch the actual subjects for their specific curriculum year
    if (!empty($active_curriculum_year)) {$safe_year = $conn->real_escape_string($active_curriculum_year);
        $c_q =$conn->query("SELECT * FROM prospectus WHERE program_id = $student_program_id AND curriculum_year = '$safe_year' AND IFNULL(is_archived, 0) = 0 ORDER BY year_level ASC, semester ASC, course_code ASC");

        if ($c_q &&$c_q->num_rows > 0) {
            while ($row =$c_q->fetch_assoc()) {
                $student_curriculum[$row['year_level']][$row['semester']][] =$row;
                $clean_code = strtoupper(trim($row['course_code']));
                if (!in_array($clean_code,$counted_course_codes)) { 
                    $student_total_units += (int)$row['units']; 
                    $counted_course_codes[] =$clean_code; 
                }
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
    <title>Year Curriculum - Student Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css">
    
    <style>
        /* PERFECT 1-PAGE A4 PRINT CSS */
        @media print { 
            @page { size: A4 portrait; margin: 10mm; } 
            
            body, html { 
                margin: 0 !important; 
                padding: 0 !important; 
                height: auto !important; 
                overflow: visible !important; 
                background: white !important;
            }
            
            main, #mainScrollArea, #spa-content-root, .print-wrapper { 
                display: block !important; 
                margin: 0 !important; 
                padding: 0 !important; 
                overflow: visible !important; 
                width: 100% !important; 
                max-width: none !important;
                position: static !important;
                background: transparent !important;
                border: none !important;
            }
            
            .no-print, header, aside, #sidebar, .ambient-orb-1, .ambient-orb-2, #floatingBackToTop { 
                display: none !important; 
            }
            
            #documentCanvas { 
                width: 210mm !important; 
                max-width: 210mm !important; 
                height: auto !important; 
                margin: 0 auto !important; 
                padding: 0 !important; 
                box-shadow: none !important; 
                border: none !important; 
                zoom: 1 !important; /* Force reset zoom for printing */
            }

            * { color: black !important; }

            .gap-y-8 { gap: 0.5rem !important; }
            .gap-x-6 { gap: 0.5rem !important; }
            .mb-6 { margin-bottom: 0.5rem !important; }
            .mb-3 { margin-bottom: 0.25rem !important; }
            .mb-2 { margin-bottom: 0.25rem !important; }
            .pb-4 { padding-bottom: 0.25rem !important; }
            .pb-1 { padding-bottom: 0.1rem !important; }
            .p-1\.5 { padding: 3px 5px !important; line-height: 1.1 !important; }
            .text-[10px] { font-size: 8px !important; }
            .text-[12px] { font-size: 10px !important; }
            h3.text-[14px] { font-size: 11px !important; margin-bottom: 1px !important; padding-bottom: 0 !important;}

            .page-break-inside-avoid { page-break-inside: avoid !important; }
            table { page-break-inside: avoid !important; border-collapse: collapse !important; width: 100% !important; margin-bottom: 4px !important;}
            tr { page-break-inside: avoid !important; page-break-after: auto !important; }
            th, td { border: 1px solid black !important; }
        }
    </style>
</head>
<body class="flex h-screen overflow-hidden antialiased relative bg-[#f8fafc]">

    <div class="ambient-orb-1 no-print"></div>
    <div class="ambient-orb-2 no-print"></div>

    <?php include 'sidebar.php'; ?>

    <main id="mainScrollArea" class="flex-1 overflow-y-auto h-full w-full pt-20 md:pt-0 relative custom-scrollbar z-10">
        <div id="spa-content-root" class="p-4 md:p-8 lg:p-10 max-w-[1200px] mx-auto relative z-20">

            <?php if ($account_on_hold): ?>
                <div class="glossy-panel p-8 md:p-12 text-center border-t-[5px] !border-t-rose-500 max-w-2xl mx-auto shadow-[0_10px_30px_rgba(244,63,94,0.1)] mt-10 animate-up">
                    <div class="glossy-panel-header bg-gradient-to-r from-rose-400 to-rose-600"></div>
                    <h2 class="text-2xl font-black text-[#00205b] uppercase tracking-widest mb-4 drop-shadow-sm">Curriculum Locked</h2>
                    <p class="text-slate-500 text-sm mb-6 font-medium">You do not have a formal academic program assigned to your account yet. Please update your Master Profile on the Home Page to generate your personalized curriculum.</p>
                    <a href="home_page.php" class="btn-glossy-animated inline-flex">Go to Home Page</a>
                </div>
            <?php else: ?>

                <!-- HEADER & ACTIONS -->
                <header class="mb-6 flex flex-col xl:flex-row justify-between xl:items-end gap-6 no-print animate-up">
                    <div>
                        <div class="flex items-center gap-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1">
                            <span>My Portal</span>
                            <span class="bg-indigo-100 text-indigo-600 px-2 py-0.5 rounded ml-2 border border-indigo-200">READ ONLY</span>
                        </div>
                        <h1 class="text-3xl md:text-4xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">Year Curriculum</h1>
                        <p class="text-slate-500 mt-1 text-xs font-medium">Preview and print your official academic roadmap for <strong class="text-[#00205b]"><?= htmlspecialchars($student_program_safe) ?></strong>.</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 w-full sm:w-auto justify-between sm:justify-end">
                        
                        <!-- ZOOM CONTROLS -->
                        <div class="flex items-center bg-white border border-slate-200 rounded-lg shadow-sm overflow-hidden no-print h-[34px]">
                            <button onclick="window.zoomCurriculum(-0.1)" class="px-3 h-full flex items-center justify-center text-slate-500 hover:bg-slate-100 hover:text-[#00205b] transition-colors focus:outline-none border-r border-slate-200" title="Zoom Out">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M20 12H4" /></svg>
                            </button>
                            <span id="zoomLevelDisplay" class="text-[10px] font-black text-[#00205b] uppercase tracking-widest px-3 min-w-[3.5rem] h-full flex items-center justify-center select-none">
                                100%
                            </span>
                            <button onclick="window.zoomCurriculum(0.1)" class="px-3 h-full flex items-center justify-center text-slate-500 hover:bg-slate-100 hover:text-[#00205b] transition-colors focus:outline-none border-l border-slate-200" title="Zoom In">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4" /></svg>
                            </button>
                        </div>

                        <!-- BROWSER PRINT ENGINE -->
                        <button onclick="window.print()" class="btn-primary !bg-[#00205b] hover:!bg-[#001233] !py-2 !px-4 !text-[10px] shadow-sm w-full sm:w-auto justify-center h-[34px]">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2-2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" /></svg> Print / Save PDF
                        </button>
                    </div>
                </header>

                <!-- STRICT A4 WORD-STYLE VIEWER -->
                <div class="w-full overflow-x-auto bg-slate-200/50 rounded-xl border border-slate-200 shadow-inner p-4 md:p-8 custom-scrollbar animate-up delay-1 print-wrapper">
                    
                    <!-- THE PHYSICAL PAPER -->
                    <div id="documentCanvas" class="bg-white mx-auto shadow-[0_15px_35px_rgba(0,0,0,0.15)] border border-slate-300 relative z-20 transition-transform duration-200" style="width: 210mm; min-width: 210mm; min-height: 297mm; box-sizing: border-box; padding: 15mm; transform-origin: top center;">
                        <div class="w-full h-full text-black">

                            <!-- DOCUMENT HEADER -->
                            <div class="text-center mb-6 border-b-2 border-black pb-4 shrink-0">
                                <h1 class="text-[20px] font-black uppercase font-academic tracking-wider m-0 leading-tight">LDSP Academic Curriculum</h1>
                                <h2 class="text-[14px] font-bold uppercase m-0 mt-1 leading-tight"><?= htmlspecialchars($student_program_safe) ?></h2>
                                <p class="text-[11px] font-semibold m-0 mt-1 leading-tight text-black">Curriculum Year: <?= htmlspecialchars($active_curriculum_year ?: 'N/A') ?></p>
                                <p class="text-[10px] m-0 leading-tight mt-1 text-black">Total Program Credit Units: <strong><?= $student_total_units ?></strong></p>
                            </div>

                            <?php if (empty($student_curriculum)): ?>
                                <div class="text-center py-20">
                                    <p class="text-slate-500 text-sm font-bold uppercase tracking-widest">No active curriculum records found for your program.</p>
                                </div>
                            <?php else: ?>
                                <div class="w-full flex flex-col gap-6">
                                    <?php 
                                    $year_levels_order = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
                                    
                                    foreach ($year_levels_order as$year):
                                        if (!isset($student_curriculum[$year])) continue;
                                    ?>
                                    <div class="w-full page-break-inside-avoid">
                                        <h3 class="text-[14px] font-black text-black uppercase mb-3 border-b-2 border-black pb-1"><?= $year ?></h3>
                                        
                                        <!-- STRICT 2-COLUMN GRID -->
                                        <div class="grid grid-cols-2 gap-x-6 gap-y-8 items-start w-full">
                                            <?php foreach (['1st Semester', '2nd Semester'] as $sem): ?>
                                                <div class="w-full overflow-hidden">
                                                    <?php if (isset($student_curriculum[$year][$sem])): 
                                                        $term_subjects =$student_curriculum[$year][$sem];
                                                        $term_units = array_sum(array_column($term_subjects, 'units'));
                                                    ?>
                                                        <div class="flex justify-between items-end mb-2 border-b border-gray-400 pb-1 min-h-[28px]">
                                                            <div class="flex items-center gap-3">
                                                                <h4 class="font-bold text-black uppercase text-[12px] tracking-wider"><?= $sem ?></h4>
                                                            </div>
                                                            <span class="font-bold text-[10px] bg-gray-100 text-black px-2 py-0.5 border border-gray-300 rounded shrink-0">Units: <?= $term_units ?></span>
                                                        </div>
                                                        
                                                        <table class="w-full text-left border-collapse border border-black table-fixed bg-white">
                                                            <thead class="bg-gray-100 text-black uppercase text-[9px] tracking-wider">
                                                                <tr>
                                                                    <th class="border border-black p-1.5 w-[20%] font-bold">Code</th>
                                                                    <th class="border border-black p-1.5 w-[50%] font-bold">Descriptive Title</th>
                                                                    <th class="border border-black p-1.5 text-center w-[10%] font-bold">Units</th>
                                                                    <th class="border border-black p-1.5 w-[20%] font-bold">Pre-Req</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-black">
                                                                <?php foreach ($term_subjects as$subj): ?>
                                                                    <tr>
                                                                        <td class="border border-black p-1.5 font-mono font-bold text-[10px] text-black align-middle">
                                                                            <?= htmlspecialchars($subj['course_code']) ?>
                                                                        </td>
                                                                        <td class="border border-black p-1.5 text-[10px] leading-snug text-black font-medium align-middle">
                                                                            <?= htmlspecialchars($subj['descriptive_title']) ?>
                                                                        </td>
                                                                        <td class="border border-black p-1.5 text-center font-bold text-[10px] text-black align-middle">
                                                                            <?= htmlspecialchars($subj['units']) ?>
                                                                        </td>
                                                                        <td class="border border-black p-1.5 text-[10px] font-medium leading-snug align-middle text-black">
                                                                            <?= htmlspecialchars($subj['prerequisite'] ?? 'None') ?>
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
                                    <?php if (isset($student_curriculum[$year]['Summer'])):$term_subjects = $student_curriculum[$year]['Summer'];
                                        $term_units = array_sum(array_column($term_subjects, 'units'));
                                    ?>
                                    <!-- Strict 50% width to match the column layout of the grid above -->
                                    <div class="mt-4 w-[50%] page-break-inside-avoid">
                                        <div class="flex justify-between items-end mb-2 border-b border-gray-400 pb-1 min-h-[28px]">
                                            <div class="flex items-center gap-3">
                                                <h4 class="font-bold text-black uppercase text-[12px] tracking-wider">Summer</h4>
                                            </div>
                                            <span class="font-bold text-[10px] bg-gray-100 text-black px-2 py-0.5 border border-gray-300 rounded-sm shrink-0">Units: <?= $term_units ?></span>
                                        </div>
                                        
                                        <table class="w-full text-left border-collapse border border-black table-fixed bg-white">
                                            <thead class="bg-gray-100 text-black uppercase text-[9px] tracking-wider">
                                                <tr>
                                                    <th class="border border-black p-1.5 w-[20%] font-bold">Code</th>
                                                    <th class="border border-black p-1.5 w-[50%] font-bold">Descriptive Title</th>
                                                    <th class="border border-black p-1.5 text-center w-[10%] font-bold">Units</th>
                                                    <th class="border border-black p-1.5 w-[20%] font-bold">Pre-Req</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-black">
                                                <?php foreach ($term_subjects as$subj): ?>
                                                    <tr>
                                                        <td class="border border-black p-1.5 font-mono font-bold text-[10px] text-black align-middle">
                                                            <?= htmlspecialchars($subj['course_code']) ?>
                                                        </td>
                                                        <td class="border border-black p-1.5 text-[10px] leading-snug text-black font-medium align-middle">
                                                            <?= htmlspecialchars($subj['descriptive_title']) ?>
                                                        </td>
                                                        <td class="border border-black p-1.5 text-center font-bold text-[10px] text-black align-middle">
                                                            <?= htmlspecialchars($subj['units']) ?>
                                                        </td>
                                                        <td class="border border-black p-1.5 text-[10px] font-medium leading-snug align-middle text-black">
                                                            <?= htmlspecialchars($subj['prerequisite'] ?? 'None') ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <button id="floatingBackToTop" onclick="scrollToTop()" class="fixed bottom-6 right-6 md:bottom-10 md:right-10 bg-gradient-to-br from-[#003882] to-[#00205b] hover:from-[#004ba8] hover:to-[#00205b] border border-[#001233] text-white w-14 h-14 rounded-full shadow-[0_10px_25px_rgba(0,32,91,0.5),inset_0_2px_0_rgba(255,255,255,0.3)] z-[90] flex items-center justify-center transition-all duration-300 opacity-0 pointer-events-none translate-y-4 focus:outline-none group">
            <svg class="w-6 h-6 drop-shadow-sm group-hover:-translate-y-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 15l7-7 7 7" /></svg>
        </button>

        <!-- Toast Notification Container -->
        <div id="toast-container" class="fixed top-6 right-6 z-[100] flex flex-col gap-3 pointer-events-none"></div>
    </main>

    <script src="student.js?v=<?= time() ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="year_curriculum.js?v=<?= time() ?>"></script>
    
    <script>
        let currentScale = 1;
        window.zoomCurriculum = function(increment) {
            const canvas = document.getElementById('documentCanvas');
            const display = document.getElementById('zoomLevelDisplay');
            if(!canvas || !display) return;
            
            let newScale = currentScale + increment;
            if(newScale < 0.5) newScale = 0.5;
            if(newScale > 1.5) newScale = 1.5;
            
            currentScale = newScale;
            canvas.style.transform = `scale(${currentScale})`;
            display.innerText = Math.round(currentScale * 100) + '%';
        }
    </script>
</body>
</html>