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
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php"); 
    exit();
}

$student_email = $conn->real_escape_string($_SESSION['email'] ?? '');

// =========================================================
// V2 DATA FETCH: UNIFIED USER & PROFILE
// =========================================================
$user_q = $conn->query("
    SELECT u.id AS user_internal_id, p.* 
    FROM users u 
    LEFT JOIN user_profiles p ON u.id = p.user_id 
    WHERE u.email = '$student_email'
");
$user_data = ($user_q && $user_q->num_rows > 0) ? $user_q->fetch_assoc() : [];
$user_internal_id = (int)($user_data['user_internal_id'] ?? 0);

// =========================================================
// FETCH GRADES & ENROLLMENT HISTORY (V2)
// =========================================================
$student_grades = [];
if ($user_internal_id > 0) {
    // Join enrollments to prospectus to get course details, and left join users/user_profiles for teacher's name
    $grades_q = $conn->query("
        SELECT e.grade, p.course_code AS subject_code, p.units, COALESCE(CONCAT(t.first_name, ' ', t.last_name), 'TBA') AS teacher_name 
        FROM enrollments e 
        JOIN prospectus p ON e.subject_id = p.id 
        LEFT JOIN users t_u ON p.teacher_id = t_u.id
        LEFT JOIN user_profiles t ON t_u.id = t.user_id
        WHERE e.student_id = $user_internal_id
    ");
    if ($grades_q && $grades_q->num_rows > 0) { 
        while ($g = $grades_q->fetch_assoc()) { $student_grades[] = $g; } 
    }
}

$all_enrolled_records = [];
$all_enroll_q = $conn->query("
    SELECT * FROM enrollment_requests 
    WHERE user_id = $user_internal_id AND (final_status = 'Enrolled' OR enrolled_at IS NOT NULL) 
    ORDER BY school_year DESC, semester DESC, created_at DESC
");
if ($all_enroll_q && $all_enroll_q->num_rows > 0) { 
    while ($r = $all_enroll_q->fetch_assoc()) { $all_enrolled_records[] = $r; } 
}

$total_enrolled_units = 0; 
$total_graded_units = 0; 
$total_grade_points = 0; 
$gwa = 0;
$grouped_grades = [];

// Structure the grades by term
if (!empty($all_enrolled_records)) {
    foreach ($all_enrolled_records as $rec) {
        $r_sy = $rec['school_year']; 
        $r_sem = $rec['semester']; 
        $r_yl = $rec['year_level']; 
        $r_prog = $rec['program'];
        $term_key = $r_yl . ' | ' . $r_sem . ' (' . $r_sy . ')';
        
        if (!isset($grouped_grades[$term_key])) {
            $grouped_grades[$term_key] = [
                'school_year' => $r_sy, 
                'semester' => $r_sem, 
                'year_level' => $r_yl, 
                'subjects' => []
            ];
            
            $r_subjects = []; 
            $r_safe_prog = $conn->real_escape_string($r_prog);
            $r_pid_q = $conn->query("SELECT program_id FROM programs WHERE program_name = '$r_safe_prog' LIMIT 1");
            
            if ($r_pid_q && $r_pid_q->num_rows > 0) {
                $r_pid = $r_pid_q->fetch_assoc()['program_id'];
                $r_cur_year = '';
                $r_def_q = $conn->query("SELECT active_year FROM program_defaults WHERE program = '$r_safe_prog'");
                if ($r_def_q && $r_def_q->num_rows > 0) { 
                    $r_cur_year = $r_def_q->fetch_assoc()['active_year']; 
                } else {
                    $r_y_q = $conn->query("SELECT curriculum_year FROM prospectus WHERE program_id = $r_pid ORDER BY curriculum_year DESC LIMIT 1");
                    if ($r_y_q && $r_y_q->num_rows > 0) { 
                        $r_cur_year = $r_y_q->fetch_assoc()['curriculum_year']; 
                    }
                }
                
                $r_cur_q = $conn->query("SELECT * FROM prospectus WHERE program_id = $r_pid AND curriculum_year = '$r_cur_year' AND year_level = '$r_yl' AND semester = '$r_sem' AND IFNULL(is_archived, 0) = 0");
                if (!$r_cur_q || $r_cur_q->num_rows == 0) { 
                    $r_cur_q = $conn->query("SELECT * FROM prospectus WHERE program_id = $r_pid AND year_level = '$r_yl' AND semester = '$r_sem' AND IFNULL(is_archived, 0) = 0"); 
                }

                if ($r_cur_q) { 
                    while ($rs = $r_cur_q->fetch_assoc()) { $r_subjects[] = $rs; } 
                }
            }
            
            foreach ($r_subjects as $subj) {
                $code = strtoupper(trim($subj['course_code']));
                $unit_val = (float)$subj['units'];
                $total_enrolled_units += $unit_val; 

                $found_grade = null;
                foreach ($student_grades as $sg) {
                    if (strtoupper(trim($sg['subject_code'])) === $code) {
                        $found_grade = [
                            'subject_code' => $subj['course_code'], 
                            'subject_description' => $subj['descriptive_title'], 
                            'units' => $subj['units'], 
                            'teacher_name' => $sg['teacher_name'], 
                            'grade' => $sg['grade'], 
                            'date_graded' => null
                        ];
                        break;
                    }
                }
                
                if ($found_grade) {
                    $grouped_grades[$term_key]['subjects'][] = $found_grade;
                    if (is_numeric($found_grade['grade'])) { 
                        $total_graded_units += $unit_val; 
                        $total_grade_points += ((float)$found_grade['grade'] * $unit_val); 
                    }
                } else {
                    $grouped_grades[$term_key]['subjects'][] = [
                        'subject_code' => $subj['course_code'], 
                        'subject_description' => $subj['descriptive_title'], 
                        'units' => $subj['units'], 
                        'teacher_name' => 'Pending Assignment', 
                        'grade' => 'TBA', 
                        'date_graded' => null
                    ];
                }
            }
        }
    }
}

if ($total_graded_units > 0) { 
    $gwa = $total_grade_points / $total_graded_units; 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Grades - Student Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css">
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 8px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .animate-up { opacity: 0; animation: fadeUpSmooth 0.5s ease-out forwards; }
        @keyframes fadeUpSmooth { 0% { opacity: 0; transform: translateY(15px); } 100% { opacity: 1; transform: translateY(0); } }
        
        /* STRICT EXCEL GRID FOR GRADES */
        .excel-wall { border-collapse: collapse; width: 100%; min-w-[700px]; }
        .excel-wall th { background-color: #f8fafc; color: #475569; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em; border: 1px solid #cbd5e1; padding: 0.75rem 0.75rem; position: sticky; top: 0; z-index: 10; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .excel-wall td { border: 1px solid #cbd5e1; padding: 0.6rem 0.75rem; }
    </style>
</head>
<body class="flex h-screen overflow-hidden antialiased relative bg-[#f8fafc]">

    <div class="ambient-orb-1 no-print"></div>
    <div class="ambient-orb-2 no-print"></div>

    <?php include 'sidebar.php'; ?>

    <main id="mainScrollArea" class="flex-1 overflow-y-auto h-full w-full pt-20 md:pt-0 relative custom-scrollbar z-10">
        <div class="p-4 md:p-8 lg:p-10 max-w-[1200px] mx-auto relative z-20">

            <div class="block no-print animate-up delay-1">
                <div class="bg-white rounded-xl mb-10 border border-slate-200 border-t-[4px] !border-t-[#00205b] shadow-sm relative z-20 overflow-hidden">
                    
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center px-6 py-5 border-b border-slate-200 bg-slate-50 gap-4">
                        <div>
                            <h3 class="font-black text-[#00205b] text-xl uppercase tracking-tight font-academic drop-shadow-sm flex items-center gap-2">
                                <div class="p-1.5 rounded-md bg-white border border-slate-200 shadow-sm">
                                    <svg class="w-5 h-5 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </div>
                                My Academic Grades
                            </h3>
                            <p class="text-[10px] text-slate-500 uppercase tracking-widest font-bold mt-1.5">Official records of your academic performance</p>
                        </div>
                    </div>

                    <div class="p-6 md:p-8">
                        <?php if(empty($grouped_grades)): ?>
                            <div class="bg-slate-50 text-center p-12 rounded-lg border border-dashed border-slate-300 text-slate-400 text-xs font-bold uppercase tracking-widest">
                                No grades or enrolled subjects have been posted to your account yet.
                            </div>
                        <?php else: ?>
                            
                            <!-- CUMULATIVE STANDING SUMMARY CARD (PLAIN & PROFESSIONAL) -->
                            <div class="bg-white rounded-lg p-5 border border-slate-200 shadow-sm flex flex-col sm:flex-row justify-between items-center gap-4 mb-8">
                                <div class="text-center sm:text-left">
                                    <h4 class="text-[#00205b] font-black uppercase text-xs tracking-widest mb-1">Cumulative Standing</h4>
                                    <p class="text-slate-500 text-[10px] font-bold uppercase tracking-wide">Overall assessment of completed academic records</p>
                                </div>
                                <div class="flex gap-4 w-full sm:w-auto">
                                    <div class="bg-slate-50 border border-slate-200 rounded-md p-3 text-center flex-1 sm:min-w-[110px] shadow-sm">
                                        <span class="block text-[9px] text-slate-500 uppercase tracking-widest font-bold mb-1">Total Units</span>
                                        <span class="block text-xl font-black text-[#00205b] font-mono leading-none"><?= number_format($total_enrolled_units, 2) ?></span>
                                    </div>
                                    <div class="bg-indigo-50 border border-indigo-200 rounded-md p-3 text-center flex-1 sm:min-w-[110px] shadow-sm">
                                        <span class="block text-[9px] text-indigo-700 uppercase tracking-widest font-black mb-1">Gen. Average</span>
                                        <span class="block text-xl font-black text-[#00205b] font-academic leading-none"><?= $total_graded_units > 0 ? number_format($gwa, 2) : 'N/A' ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- GRADES PER TERM (STRICT EXCEL FORMAT) -->
                            <?php foreach($grouped_grades as $term_title => $term_data): ?>
                                <div class="mb-8">
                                    <div class="bg-slate-100 border border-slate-300 border-b-0 px-4 py-2.5 rounded-t-lg flex items-center justify-between">
                                        <h4 class="font-black text-[#00205b] text-[11px] uppercase tracking-widest"><?= htmlspecialchars($term_title) ?></h4>
                                    </div>
                                    <div class="overflow-x-auto bg-white border border-slate-300 rounded-b-lg shadow-sm custom-scrollbar">
                                        <table class="excel-wall">
                                            <thead>
                                                <tr>
                                                    <th class="w-[15%] text-center">Subject Code</th>
                                                    <th class="w-[40%]">Description</th>
                                                    <th class="w-[10%] text-center">Units</th>
                                                    <th class="w-[20%]">Instructor</th>
                                                    <th class="w-[15%] text-center">Final Grade</th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white">
                                                <?php foreach($term_data['subjects'] as $grade): ?>
                                                    <tr class="hover:bg-slate-50 transition-colors">
                                                        <td class="text-center font-bold text-[#00205b] font-mono text-[11px] bg-slate-50/30">
                                                            <?= htmlspecialchars($grade['subject_code']) ?>
                                                        </td>
                                                        <td class="text-xs font-semibold text-slate-800 whitespace-normal break-words leading-tight">
                                                            <?= htmlspecialchars($grade['subject_description']) ?>
                                                        </td>
                                                        <td class="text-center font-bold text-slate-600 text-[11px]">
                                                            <?= number_format((float)$grade['units'], 2) ?>
                                                        </td>
                                                        <td class="text-[10px] font-bold text-slate-700 uppercase whitespace-normal break-words">
                                                            <?= htmlspecialchars($grade['teacher_name']) ?>
                                                        </td>
                                                        <td class="text-center font-black text-sm <?= is_numeric($grade['grade']) ? 'text-[#00205b]' : 'text-slate-400' ?> bg-slate-50/30">
                                                            <?= is_numeric($grade['grade']) ? number_format((float)$grade['grade'], 2) : htmlspecialchars($grade['grade']) ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
        </div>
        
        <!-- FLOATING BACK TO TOP -->
        <button id="floatingBackToTop" onclick="scrollToTop()" class="fixed bottom-6 right-6 md:bottom-10 md:right-10 bg-white border border-slate-200 text-[#00205b] w-12 h-12 rounded-full shadow-lg z-[90] flex items-center justify-center transition-all duration-300 opacity-0 pointer-events-none translate-y-4 focus:outline-none group hover:bg-slate-50 hover:border-[#00205b]">
            <svg class="w-5 h-5 group-hover:-translate-y-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7" /></svg>
        </button>

    </main>

    <script src="student.js?v=<?= time() ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
</body>
</html>