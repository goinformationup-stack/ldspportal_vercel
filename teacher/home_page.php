<?php
if (session_status() === PHP_SESSION_NONE) {
    $session_lifetime = 60 * 60 * 24 * 30; 
    ini_set('session.gc_maxlifetime', $session_lifetime);
    session_set_cookie_params($session_lifetime, '/'); 
    session_name('LDSP_TEACHER_SESSION');
    session_start();
}
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
include "../dbconn.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') { 
    header("Location: index.php"); 
    exit(); 
}

$last_name = $_SESSION['last_name'] ?? '';
$first_name = $_SESSION['first_name'] ?? '';
$teacher_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;

// FETCH GLOBAL ACTIVE ACADEMIC YEAR & SEMESTER
$active_semester = '1st Semester';
$active_academic_year = '2025-2026';
try {
    $sem_query = $conn->query("SELECT setting_value FROM portal_settings WHERE setting_key = 'active_semester'");
    if ($sem_query && $sem_query->num_rows > 0) $active_semester = $sem_query->fetch_assoc()['setting_value'];

    $ay_query = $conn->query("SELECT setting_value FROM portal_settings WHERE setting_key = 'academic_year'");
    if ($ay_query && $ay_query->num_rows > 0) $active_academic_year = $ay_query->fetch_assoc()['setting_value'];
} catch (Exception $e) {}

// Calculate Overview Stats
$my_subjects = [];
try {
    // We fetch the actual subjects to render a useful data table instead of an empty box.
    $sub_query = $conn->prepare("
        SELECT p.id, p.course_code, p.descriptive_title, pr.program_name as program, p.year_level 
        FROM prospectus p 
        LEFT JOIN programs pr ON p.program_id = pr.program_id 
        WHERE p.teacher_id = ? AND p.semester = ? AND (p.is_archived = 0 OR p.is_archived IS NULL)
        ORDER BY p.course_code ASC
    ");
    $sub_query->bind_param("is", $teacher_id, $active_semester);
    $sub_query->execute();
    $res = $sub_query->get_result();
    while ($row = $res->fetch_assoc()) { $my_subjects[] = $row; }
} catch (Exception $e) {}

$overview_active_classes = count($my_subjects);
$overview_total_students = 0;
$overview_pending_grades = 0;

if ($overview_active_classes > 0) {
    try {
        $py_conditions = [];
        foreach ($my_subjects as $sub) {
            $p = $conn->real_escape_string($sub['program'] ?? '');
            $y = $conn->real_escape_string($sub['year_level'] ?? '');
            if (!empty($p) && !empty($y)) { $py_conditions[] = "((er.program = '$p' OR er.program LIKE '%$p%') AND er.year_level = '$y')"; }
            elseif (!empty($y)) { $py_conditions[] = "(er.year_level = '$y')"; }
            elseif (!empty($p)) { $py_conditions[] = "(er.program = '$p' OR er.program LIKE '%$p%')"; }
        }
        if (!empty($py_conditions)) {
            $py_condition_str = implode(' OR ', $py_conditions);
            $ts_query = $conn->query("SELECT COUNT(DISTINCT er.email) as total FROM enrollment_requests er WHERE er.semester = '$active_semester' AND er.final_status = 'Enrolled' AND ($py_condition_str)");
            if ($ts_query) $overview_total_students = $ts_query->fetch_assoc()['total'];
        }
        foreach ($my_subjects as $sub) {
            $sub_prog = $conn->real_escape_string($sub['program'] ?? '');
            $sub_yr = $conn->real_escape_string($sub['year_level'] ?? '');
            $sub_id = (int)$sub['id'];
            $prog_sql = !empty($sub_prog) ? "AND (er.program = '$sub_prog' OR er.program LIKE '%$sub_prog%')" : "";
            $year_sql = !empty($sub_yr) ? "AND er.year_level = '$sub_yr'" : "";
            $pg_query = $conn->query("SELECT COUNT(u.id) as missing FROM enrollment_requests er JOIN users u ON er.email = u.email LEFT JOIN enrollments e ON u.id = e.student_id AND e.subject_id = $sub_id WHERE er.semester = '$active_semester' AND er.final_status = 'Enrolled' $prog_sql $year_sql AND (e.grade IS NULL OR e.grade = '')");
            if ($pg_query) $overview_pending_grades += $pg_query->fetch_assoc()['missing'];
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Dashboard - LDSP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../Style.css?v=<?php echo time(); ?>">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 8px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .animate-up { opacity: 0; animation: fadeUpSmooth 0.5s ease-out forwards; }
        @keyframes fadeUpSmooth { 0% { opacity: 0; transform: translateY(15px); } 100% { opacity: 1; transform: translateY(0); } }
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
    </style>
</head>
<body class="flex h-screen overflow-hidden antialiased bg-[#f4f6f9] text-slate-800">
    
    <div class="ambient-orb-1"></div>
    <div class="ambient-orb-2"></div>
    
    <?php include 'sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto h-full w-full pt-20 md:pt-0 relative custom-scrollbar z-10">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1400px] mx-auto relative z-20 h-full flex flex-col w-full max-w-full">
            
            <!-- 1. HEADER & GLOBAL AUDIT BAR -->
            <header class="border-b border-slate-200/80 pb-4 mb-6 flex flex-col md:flex-row justify-between md:items-end gap-4 drop-shadow-sm animate-up">
                <div>
                    <div class="flex items-center gap-2.5 mb-1">
                        <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded bg-emerald-100 text-emerald-800 border border-emerald-200">
                            Faculty Division
                        </span>
                        <span class="text-xs text-slate-500 font-medium flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.8)]"></span>
                            Logged in as <?= htmlspecialchars($first_name . ' ' . $last_name) ?>
                        </span>
                    </div>
                    <h1 class="text-xl md:text-2xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">Faculty Dashboard</h1>
                    <p class="text-slate-500 text-xs font-medium mt-0.5">Overview of your teaching load and grading status.</p>
                </div>

                <div class="bg-white border border-slate-200 shadow-sm rounded-lg px-4 py-2 flex flex-col text-right">
                    <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-0.5">Active Term</span>
                    <span class="text-xs font-black text-[#00205b]"><?= htmlspecialchars($active_semester) ?> | <?= htmlspecialchars($active_academic_year) ?></span>
                </div>
            </header>

            <!-- 2. COMPACT BALANCED METRIC SUMMARY CARDS -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 animate-up delay-1">
                <div class="bg-white p-4 px-5 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between group hover:shadow-md transition-all">
                    <div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Active Classes</div>
                        <div class="text-2xl font-black text-slate-900 mt-1"><?= $overview_active_classes ?></div>
                    </div>
                    <div class="w-10 h-10 rounded-full bg-slate-50 flex items-center justify-center text-slate-400 border border-slate-100 group-hover:bg-slate-100 transition-colors">
                        <i class="fa-solid fa-chalkboard text-lg"></i>
                    </div>
                </div>
                
                <div class="bg-white p-4 px-5 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between group hover:shadow-md transition-all">
                    <div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Students</div>
                        <div class="text-2xl font-black text-emerald-600 mt-1"><?= $overview_total_students ?></div>
                    </div>
                    <div class="w-10 h-10 rounded-full bg-emerald-50 flex items-center justify-center text-emerald-500 border border-emerald-100 group-hover:bg-emerald-100 transition-colors">
                        <i class="fa-solid fa-users text-lg"></i>
                    </div>
                </div>
                
                <div class="bg-white p-4 px-5 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between group hover:shadow-md transition-all">
                    <div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Pending Grades</div>
                        <div class="text-2xl font-black <?= $overview_pending_grades > 0 ? 'text-rose-600' : 'text-slate-900' ?> mt-1"><?= $overview_pending_grades ?></div>
                    </div>
                    <div class="w-10 h-10 rounded-full <?= $overview_pending_grades > 0 ? 'bg-rose-50 text-rose-500 border-rose-100 group-hover:bg-rose-100' : 'bg-slate-50 text-slate-400 border-slate-100 group-hover:bg-slate-100' ?> flex items-center justify-center border transition-colors">
                        <i class="fa-solid fa-clipboard-list text-lg"></i>
                    </div>
                </div>
            </div>

            <!-- 3. CURRENT TEACHING LOAD DATA GRID (Replaces the empty box) -->
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden animate-up delay-2 flex flex-col flex-1">
                <div class="bg-slate-50 border-b border-slate-200 p-3.5 px-5 flex justify-between items-center shrink-0">
                    <h2 class="text-xs font-bold text-[#00205b] uppercase tracking-widest flex items-center gap-2">
                        <i class="fa-solid fa-book-open text-slate-400"></i> Current Teaching Load
                    </h2>
                    <a href="my_classes.php" class="text-[10px] font-bold text-indigo-600 hover:text-indigo-800 uppercase tracking-wider transition-colors border border-indigo-200 bg-indigo-50 hover:bg-indigo-100 px-2.5 py-1 rounded shadow-sm focus:outline-none">
                        Manage Classes &rarr;
                    </a>
                </div>
                <div class="overflow-x-auto custom-scrollbar flex-1">
                    <table class="w-full text-left text-xs whitespace-nowrap">
                        <thead class="bg-white text-slate-400 text-[10px] uppercase font-bold tracking-wider border-b border-slate-200 sticky top-0 z-10">
                            <tr>
                                <th class="px-5 py-3">Course Code</th>
                                <th class="px-5 py-3">Descriptive Title</th>
                                <th class="px-5 py-3">Program</th>
                                <th class="px-5 py-3 text-center">Year Level</th>
                                <th class="px-5 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($my_subjects)): ?>
                                <tr>
                                    <td colspan="5" class="px-5 py-16 text-center">
                                        <div class="w-12 h-12 rounded-full bg-slate-50 border border-slate-200 text-slate-300 flex items-center justify-center mx-auto mb-3 shadow-inner">
                                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                        </div>
                                        <span class="text-slate-500 font-bold uppercase tracking-widest text-[10px]">No assigned subjects for this semester.</span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($my_subjects as $subj): ?>
                                    <tr class="hover:bg-slate-50 transition-colors group">
                                        <td class="px-5 py-3.5 font-mono font-black text-[#00205b]"><?= htmlspecialchars($subj['course_code']) ?></td>
                                        <td class="px-5 py-3.5 font-bold text-slate-800 truncate max-w-[250px]"><?= htmlspecialchars($subj['descriptive_title'] ?? 'Untitled') ?></td>
                                        <td class="px-5 py-3.5 font-semibold text-slate-600"><?= htmlspecialchars($subj['program'] ?? 'General/Core') ?></td>
                                        <td class="px-5 py-3.5 text-center text-[10px] font-bold text-slate-500 uppercase tracking-wider"><?= htmlspecialchars($subj['year_level']) ?></td>
                                        <td class="px-5 py-3.5 text-right">
                                            <a href="my_classes.php?subject_id=<?= $subj['id'] ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider rounded border border-slate-300 bg-white text-slate-700 hover:text-[#00205b] hover:border-[#00205b] hover:bg-indigo-50 transition-colors shadow-sm focus:outline-none">
                                                <i class="fa-solid fa-pen-to-square"></i> Grade
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <script src="sidebar.js?v=<?php echo time(); ?>"></script>
    <script src="home_page.js?v=<?php echo time(); ?>"></script>
</body>
</html>