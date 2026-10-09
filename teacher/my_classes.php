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
    header("Location: index.php"); exit(); 
}

$last_name = $_SESSION['last_name'] ?? '';
$first_name = $_SESSION['first_name'] ?? '';
$teacher_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;
$selected_subject = $_GET['subject_id'] ?? '';

// FETCH GLOBAL ACTIVE ACADEMIC YEAR & SEMESTER
$active_semester = '1st Semester';
$active_academic_year = '2025-2026';
try {
    $sem_query = $conn->query("SELECT setting_value FROM portal_settings WHERE setting_key = 'active_semester'");
    if ($sem_query && $sem_query->num_rows > 0) $active_semester = $sem_query->fetch_assoc()['setting_value'];
    
    $ay_query = $conn->query("SELECT setting_value FROM portal_settings WHERE setting_key = 'academic_year'");
    if ($ay_query && $ay_query->num_rows > 0) $active_academic_year = $ay_query->fetch_assoc()['setting_value'];
} catch (Exception $e) {}

// FETCH PROGRAMS FOR THE FILTER DROPDOWN
$programs = [];
try {
    $p_query = $conn->query("SELECT program_id, program_name FROM programs WHERE is_archived = 0 OR is_archived IS NULL ORDER BY program_name ASC");
    if (!$p_query) $p_query = $conn->query("SELECT program_id, program_name FROM programs ORDER BY program_name ASC");
    if ($p_query && $p_query->num_rows > 0) {
        while ($row = $p_query->fetch_assoc()) $programs[$row['program_id']] = $row['program_name'];
    }
} catch (Exception $e) {}

// =========================================================
// RENDER GRADING ROWS (PREMIUM SAAS UI)
// =========================================================
function renderGradingRows($enrolled_students) {
    ob_start();
    if (empty($enrolled_students)) {
        echo '<tr><td colspan="6" class="py-20 px-6 text-center">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-100 mb-4 shadow-inner">
                    <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                </div>
                <h3 class="text-slate-800 font-bold text-base mb-1">No Students Enrolled</h3>
                <p class="text-slate-500 text-sm">There are currently no students mapped to this section.</p>
              </td></tr>';
        return ob_get_clean();
    }
    
    foreach ($enrolled_students as $student): 
        $t_grade = $student['teacher_grade'] ?? '';
        $r_grade = $student['registrar_grade'] ?? '';
        
        $is_registrar_graded = (empty($t_grade) && !empty($r_grade));
        $grade = $is_registrar_graded ? $r_grade : $t_grade;
        $is_failed = ($grade === '5.00' || $grade === '5.0' || $grade === '5');
        
        // Base styling
        $row_class = 'hover:bg-slate-50';
        $text_color = 'text-slate-800';
        $border_color = 'border-slate-200';
        $name_badge = '';
        $icon_color = 'text-slate-400';
        $avatar_bg = 'bg-[#00205b] text-white';
        
        // Extract Initials
        $f_init = !empty($student['first_name']) ? mb_substr($student['first_name'], 0, 1) : '';
        $l_init = !empty($student['last_name']) ? mb_substr($student['last_name'], 0, 1) : '';
        $initials = strtoupper($f_init . $l_init);
        if (empty($initials)) $initials = "?";

        // Registrar / Failed Overrides
        if ($is_registrar_graded) {
            $row_class = 'bg-blue-50/50 hover:bg-blue-100/50';
            $text_color = 'text-blue-900';
            $border_color = 'border-blue-300';
            $icon_color = 'text-blue-500';
            $avatar_bg = 'bg-blue-600 text-white';
            $name_badge = ' <span class="ml-2 inline-flex items-center gap-1 px-2 py-0.5 bg-blue-100 text-blue-700 text-[10px] rounded-full font-bold uppercase tracking-wider border border-blue-200" title="Graded by Registrar"><i class="fa-solid fa-shield-halved"></i> Registrar</span>';
            if ($is_failed) {
                $name_badge .= ' <span class="ml-1 px-2 py-0.5 bg-rose-100 text-rose-700 text-[10px] rounded-full font-bold uppercase tracking-wider border border-rose-200">Failed</span>';
            }
        } elseif ($is_failed) {
            $row_class = 'bg-rose-50/30 hover:bg-rose-50/70';
            $text_color = 'text-rose-900';
            $border_color = 'border-rose-300';
            $icon_color = 'text-rose-500';
            $avatar_bg = 'bg-rose-600 text-white';
            $name_badge = ' <span class="ml-2 px-2 py-0.5 bg-rose-100 text-rose-700 text-[10px] rounded-full font-bold uppercase tracking-wider border border-rose-200">Failed</span>';
        } else if (!empty($grade)) {
            $text_color = 'text-emerald-700';
        }
    ?>
        <tr class="<?= $row_class ?> transition-colors group border-b border-slate-100 last:border-none">
            <td class="w-12 py-3 px-4 text-center align-middle">
                <input type="checkbox" onchange="handleCheckboxChange(this)" class="w-4 h-4 text-[#00205b] bg-white border-slate-300 rounded focus:ring-[#00205b] cursor-pointer transition-all grade-cb" value="<?= $student['student_user_id'] ?>">
            </td>
            
            <td class="py-3 px-4 align-middle">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full <?= $avatar_bg ?> flex items-center justify-center font-bold text-xs shrink-0 shadow-sm">
                        <?= $initials ?>
                    </div>
                    <div class="min-w-0">
                        <div class="font-bold text-slate-800 text-sm flex items-center flex-wrap">
                            <span class="truncate"><?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) ?></span>
                            <?= $name_badge ?>
                        </div>
                        <div class="text-xs font-mono font-medium text-slate-500 mt-0.5"><?= htmlspecialchars($student['student_id'] ?? 'N/A') ?></div>
                    </div>
                </div>
            </td>
            
            <td class="py-3 px-4 align-middle hidden md:table-cell">
                <div class="text-xs font-semibold text-slate-700"><?= htmlspecialchars($student['display_program'] ?? 'N/A') ?></div>
                <div class="text-[11px] font-medium text-slate-500 mt-0.5">Year <?= htmlspecialchars($student['display_year'] ?? 'N/A') ?></div>
            </td>
            
            <td class="py-3 px-4 align-middle text-center hidden sm:table-cell">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 text-[10px] font-bold uppercase tracking-wider border border-emerald-100">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                </span>
            </td>
            
            <td class="py-3 px-4 align-middle text-right w-36">
                <div class="relative w-full max-w-[100px] ml-auto text-left student-grade-wrapper" id="grade_wrapper_<?= $student['student_user_id'] ?>">
                    <input type="hidden" name="grades[<?= $student['student_user_id'] ?>]" value="<?= htmlspecialchars($grade) ?>" class="grade-input-hidden">
                    <button type="button" onclick="openGlobalGradeModal(<?= $student['student_user_id'] ?>)" class="w-full h-[36px] flex items-center justify-between px-3 bg-white border <?= $border_color ?> hover:border-[#00205b] hover:shadow-md rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-[#00205b]/20 transition-all text-sm font-bold <?= $text_color ?>">
                        <span class="grade-display"><?= !empty($grade) ? htmlspecialchars($grade) : '<span class="text-slate-400 font-normal text-xs">Unset</span>' ?></span>
                        <svg class="w-4 h-4 <?= $icon_color ?> pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                </div>
            </td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

// =========================================================
// AJAX POST: SAVE GRADES
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_grades') {
    header('Content-Type: application/json');
    try {
        if (!empty($_POST['grades']) && is_array($_POST['grades'])) {
            $subj_id = (int)$_POST['subject_id'];
            
            // Get subject metadata to mirror grading to Registrar's table
            $sub_query = $conn->query("SELECT course_code, descriptive_title, units, semester FROM prospectus WHERE id = $subj_id");
            $sub_info = $sub_query ? $sub_query->fetch_assoc() : null;

            $stmt_enroll = $conn->prepare("INSERT INTO enrollments (student_id, subject_id, grade) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE grade = VALUES(grade)");
            
            if ($sub_info) {
                // Official permanent academic record
                $stmt_sg = $conn->prepare("INSERT INTO student_grades (student_id, subject_code, subject_description, units, semester, grade) VALUES ((SELECT student_id FROM users WHERE id = ?), ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE grade = VALUES(grade)");
            }

            foreach ($_POST['grades'] as $student_user_id => $grade) {
                $grade_val = trim($grade) === '' ? NULL : trim($grade);
                $sid = (int)$student_user_id;
                
                // 1. Save to teacher's view
                $stmt_enroll->bind_param("iis", $sid, $subj_id, $grade_val);
                $stmt_enroll->execute();
                
                // 2. Mirror to registrar's view
                if ($sub_info && $grade_val !== NULL) {
                    $stmt_sg->bind_param("isssss", $sid, $sub_info['course_code'], $sub_info['descriptive_title'], $sub_info['units'], $sub_info['semester'], $grade_val);
                    $stmt_sg->execute();
                }
            }
            
            $enrolled_students = [];
            $stu_query = $conn->prepare("
                SELECT u.id as student_user_id, u.student_id, p.last_name, p.first_name, er.program as display_program, er.year_level as display_year, 
                       e.grade as teacher_grade, sg.grade as registrar_grade
                FROM users u 
                INNER JOIN (
                    SELECT user_id, MAX(id) as max_id FROM enrollment_requests 
                    WHERE semester = ? AND final_status = 'Enrolled' 
                    GROUP BY user_id
                ) latest_er ON u.id = latest_er.user_id
                INNER JOIN enrollment_requests er ON latest_er.max_id = er.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                LEFT JOIN enrollments e ON u.id = e.student_id AND e.subject_id = ?
                LEFT JOIN student_grades sg ON u.student_id = sg.student_id AND sg.subject_code = ? AND sg.semester = ?
                WHERE u.role = 'student'
                ORDER BY er.assigned_section ASC, p.last_name ASC, p.first_name ASC
            ");
            $stu_query->bind_param("siss", $active_semester, $subj_id, $sub_info['course_code'], $active_semester);
            $stu_query->execute();
            $res = $stu_query->get_result();
            while ($row = $res->fetch_assoc()) { $enrolled_students[] = $row; }
            
            echo json_encode(['status' => 'success', 'message' => 'Grades officially saved.', 'updates' => ['grading_tbody' => renderGradingRows($enrolled_students)]]);
            exit();
        }
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => 'Error saving grades.']); exit(); }
}

// =========================================================
// AJAX GET: REFRESH GRADING LIST
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['api_refresh']) && !empty($_GET['subject_id'])) {
    header('Content-Type: application/json');
    $subj_id = (int)$_GET['subject_id'];
    $refresh_students = [];
    $prog_condition = "";
    
    $det = $conn->query("SELECT p.program_id, pr.program_name as program, p.year_level, p.course_code FROM prospectus p LEFT JOIN programs pr ON p.program_id = pr.program_id WHERE p.id = $subj_id")->fetch_assoc();
    if ($det) {
        $s_prog = $conn->real_escape_string($det['program'] ?? '');
        $s_year = $conn->real_escape_string($det['year_level'] ?? '');
        $course_code = $conn->real_escape_string($det['course_code'] ?? '');
        
        if (!empty($s_prog)) $prog_condition .= " AND (er.program = '$s_prog' OR er.program LIKE '%$s_prog%') ";
        if (!empty($s_year)) $prog_condition .= " AND er.year_level = '$s_year' ";
        
        $stu_query = $conn->query("
            SELECT u.id as student_user_id, u.student_id, p.last_name, p.first_name,
                   er.program as display_program, er.year_level as display_year, er.assigned_section, 
                   e.grade as teacher_grade, sg.grade as registrar_grade
            FROM users u 
            INNER JOIN (
                SELECT user_id, MAX(id) as max_id FROM enrollment_requests 
                WHERE semester = '$active_semester' AND final_status = 'Enrolled' 
                GROUP BY user_id
            ) latest_er ON u.id = latest_er.user_id
            INNER JOIN enrollment_requests er ON latest_er.max_id = er.id
            LEFT JOIN user_profiles p ON u.id = p.user_id
            LEFT JOIN enrollments e ON u.id = e.student_id AND e.subject_id = $subj_id
            LEFT JOIN student_grades sg ON u.student_id = sg.student_id AND sg.subject_code = '$course_code' AND sg.semester = '$active_semester'
            WHERE u.role = 'student' $prog_condition
            ORDER BY er.assigned_section ASC, p.last_name ASC, p.first_name ASC
        ");
        if ($stu_query) { while ($row = $stu_query->fetch_assoc()) { $refresh_students[] = $row; } }
    }
    echo json_encode(['status' => 'success', 'updates' => ['grading_tbody' => renderGradingRows($refresh_students)]]);
    exit();
}

// =========================================================
// AJAX GET: FETCH CLASSES (ACTIVE OR ARCHIVE)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_fetch_classes'])) {
    header('Content-Type: application/json');
    $tab = $_GET['tab'] ?? 'active';
    
    $html = '';
    $count = 0;

    $sql = "
        SELECT p.id as subject_id, p.course_code, p.descriptive_title, p.units, 
               pr.program_name, p.year_level, p.semester, p.curriculum_year,
               (SELECT COUNT(e.id) FROM enrollments e WHERE e.subject_id = p.id AND e.status = 'Enrolled') as student_count
        FROM prospectus p
        LEFT JOIN programs pr ON p.program_id = pr.program_id
        WHERE p.teacher_id = ? 
    ";

    if ($tab === 'active') {
        $sql .= " AND p.semester = ? AND p.curriculum_year = ? AND (p.is_archived = 0 OR p.is_archived IS NULL) ORDER BY pr.program_name ASC, p.year_level ASC, p.course_code ASC";
    } else {
        $sql .= " AND (NOT (p.semester = ? AND p.curriculum_year = ?) OR p.is_archived = 1) ORDER BY p.curriculum_year DESC, p.semester DESC, p.course_code ASC";
    }

    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $teacher_id, $active_semester, $active_academic_year);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            $count = $res->num_rows;
            while ($row = $res->fetch_assoc()) {
                $prog_name = !empty($row['program_name']) ? htmlspecialchars($row['program_name']) : 'General / Core';
                $code = htmlspecialchars($row['course_code']);
                $title = htmlspecialchars($row['descriptive_title']);
                $year = htmlspecialchars($row['year_level']);
                $term = htmlspecialchars($row['semester']);
                $ay = htmlspecialchars($row['curriculum_year']);
                
                // MODERN LIST ROWS
                $html .= '
                <tr class="hover:bg-slate-50 transition-colors group border-b border-slate-100 last:border-none" 
                    data-code="'.strtoupper($code).'" 
                    data-title="'.strtoupper($title).'" 
                    data-program="'.strtoupper($prog_name).'" 
                    data-year="'.strtoupper($year).'">
                    
                    <td class="py-4 px-4 align-middle min-w-[250px]">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-[#00205b]/5 flex items-center justify-center text-[#00205b] font-bold text-xs shrink-0 shadow-sm border border-slate-200">
                                '.$code.'
                            </div>
                            <div class="min-w-0">
                                <div class="font-bold text-slate-800 text-sm leading-tight group-hover:text-[#00205b] transition-colors truncate">'.$title.'</div>
                                <span class="inline-block mt-0.5 text-xs font-medium text-slate-500">'.$row['units'].' Units</span>
                            </div>
                        </div>
                    </td>
                    
                    <td class="py-4 px-4 align-middle hidden sm:table-cell">
                        <div class="text-sm font-semibold text-slate-700 truncate max-w-[200px]" title="'.$prog_name.'">'.$prog_name.'</div>
                        <div class="text-xs font-medium text-slate-500 mt-0.5">Year '.$year.'</div>
                    </td>
                    
                    <td class="py-4 px-4 align-middle hidden md:table-cell">
                        <div class="text-sm font-semibold text-slate-700">'.$term.'</div>
                        <div class="text-xs font-medium text-slate-500 mt-0.5">A.Y. '.$ay.'</div>
                    </td>
                    
                    <td class="py-4 px-4 align-middle text-center w-28">
                        <div class="inline-flex flex-col items-center justify-center">
                            <span class="text-lg font-black '.($row['student_count'] > 0 ? 'text-[#00205b]' : 'text-slate-300').' leading-none">'.htmlspecialchars($row['student_count']).'</span>
                            <span class="text-[9px] font-bold uppercase tracking-widest text-slate-400 mt-1">Students</span>
                        </div>
                    </td>
                    
                    <td class="py-4 px-4 align-middle text-right w-28">
                        <a href="?subject_id='.htmlspecialchars($row['subject_id']).'" class="inline-flex items-center justify-center gap-2 bg-white hover:bg-[#00205b] text-[#00205b] hover:text-white border border-slate-300 hover:border-[#00205b] px-4 py-2 rounded-lg text-xs font-bold transition-all shadow-sm focus:outline-none focus:ring-2 focus:ring-[#00205b]/20 whitespace-nowrap">
                            Manage
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    </td>
                </tr>';
            }
        } else {
            $empty_msg = $tab === 'active' ? "You have no active classes assigned." : "You have no archived classes.";
            $html = '<tr><td colspan="5" class="py-20 px-6 text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-100 mb-4 shadow-inner">
                            <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477-4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                        </div>
                        <h3 class="text-slate-800 font-bold text-base mb-1">No Classes Found</h3>
                        <p class="text-slate-500 text-sm">'.$empty_msg.'</p>
                    </td></tr>';
        }
        
        echo json_encode(['html' => $html, 'count' => $count]);
        
    } catch (Exception $e) {
        echo json_encode(['html' => '<tr><td colspan="5" class="p-8 text-center text-rose-500 text-sm font-bold">Error connecting to records database.</td></tr>', 'count' => 0]);
    }
    exit;
}

// =========================================================
// INITIAL PAGE LOAD DATA (IF GRADING VIEW)
// =========================================================
$active_subject_details = null;
$enrolled_students = [];

if (!empty($selected_subject)) {
    try {
        $sub_query = $conn->prepare("
            SELECT p.id, p.course_code as subject_code, COALESCE(NULLIF(p.descriptive_title, ''), p.subject_title, 'Untitled') as description, 
                   pr.program_name as program, p.year_level, 'All' as section 
            FROM prospectus p LEFT JOIN programs pr ON p.program_id = pr.program_id 
            WHERE p.teacher_id = ? AND p.id = ?
        ");
        $sub_query->bind_param("ii", $teacher_id, $selected_subject);
        $sub_query->execute();
        $res = $sub_query->get_result();
        if ($row = $res->fetch_assoc()) {
            $active_subject_details = $row;
            
            $prog_condition = "";
            $params = [$selected_subject, $active_subject_details['subject_code'], $active_semester];
            $types = "iss";
            $s_prog = $active_subject_details['program'] ?? '';
            $s_year = $active_subject_details['year_level'] ?? '';

            if (!empty($s_prog)) {
                $prog_condition = " AND (er.program = ? OR er.program LIKE CONCAT('%', ?, '%') OR ? LIKE CONCAT('%', er.program, '%')) ";
                $params[] = $s_prog; $params[] = $s_prog; $params[] = $s_prog;
                $types .= "sss";
            }
            if (!empty($s_year)) {
                $prog_condition .= " AND er.year_level = ? ";
                $params[] = $s_year;
                $types .= "s";
            }

            $stu_query_str = "
                SELECT u.id as student_user_id, u.student_id, p.last_name, p.first_name,
                       er.program as display_program, er.year_level as display_year, er.assigned_section, 
                       e.grade as teacher_grade, sg.grade as registrar_grade
                FROM users u 
                INNER JOIN (
                    SELECT user_id, MAX(id) as max_id FROM enrollment_requests 
                    WHERE semester = '" . $conn->real_escape_string($active_semester) . "' AND final_status = 'Enrolled' 
                    GROUP BY user_id
                ) latest_er ON u.id = latest_er.user_id
                INNER JOIN enrollment_requests er ON latest_er.max_id = er.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                LEFT JOIN enrollments e ON u.id = e.student_id AND e.subject_id = ?
                LEFT JOIN student_grades sg ON u.student_id = sg.student_id AND sg.subject_code = ? AND sg.semester = ?
                WHERE u.role = 'student' $prog_condition
                ORDER BY er.assigned_section ASC, p.last_name ASC, p.first_name ASC
            ";
            $stu_query = $conn->prepare($stu_query_str);
            $stu_query->bind_param($types, ...$params);
            $stu_query->execute();
            $stu_res = $stu_query->get_result();
            while ($srow = $stu_res->fetch_assoc()) { $enrolled_students[] = $srow; }
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Classes - LDSP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../Style.css?v=<?php echo time(); ?>">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 8px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .animate-up { opacity: 0; animation: fadeUpSmooth 0.4s ease-out forwards; }
        @keyframes fadeUpSmooth { 0% { opacity: 0; transform: translateY(10px); } 100% { opacity: 1; transform: translateY(0); } }
        
        /* Modern Checkbox styling */
        input[type="checkbox"].grade-cb {
            appearance: none; background-color: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 4px; display: grid; place-content: center;
        }
        input[type="checkbox"].grade-cb::before {
            content: ""; width: 0.65em; height: 0.65em; transform: scale(0); transition: 120ms transform ease-in-out;
            box-shadow: inset 1em 1em white; transform-origin: center; clip-path: polygon(14% 44%, 0 65%, 50% 100%, 100% 16%, 80% 0%, 43% 62%);
        }
        input[type="checkbox"].grade-cb:checked { background-color: #00205b; border-color: #00205b; }
        input[type="checkbox"].grade-cb:checked::before { transform: scale(1); }
    </style>
</head>
<body class="flex h-screen overflow-hidden antialiased bg-[#f4f6f9] text-slate-800">
    
    <div id="toastContainer" class="fixed top-5 right-5 z-[100] flex flex-col gap-3 pointer-events-none"></div>

    <!-- GLOBAL GRADE ASSIGNMENT MODAL -->
    <div id="globalGradeModal" class="fixed inset-0 z-[150] hidden flex items-center justify-center bg-slate-900/60 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl border-t-[4px] border-t-[#00205b] w-full max-w-[340px] transform scale-95 transition-all p-5 relative overflow-hidden">
            
            <div class="flex justify-between items-center mb-5 pb-3 border-b border-slate-100">
                <h3 class="font-black text-slate-900 text-sm uppercase tracking-wider flex items-center gap-2">
                    <div class="w-7 h-7 rounded-md bg-indigo-50 flex items-center justify-center text-[#00205b]">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                    </div>
                    <span id="gradeModalTitle">Assign Grade</span>
                </h3>
                <button type="button" onclick="closeGlobalGradeModal()" class="text-slate-400 hover:text-rose-500 transition-colors bg-white hover:bg-rose-50 p-1.5 rounded-lg focus:outline-none">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
            
            <div class="grid grid-cols-3 gap-2 mb-4">
                <?php $grade_choices = ['1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00', '5.00'];
                foreach($grade_choices as $choice): 
                    $isFail = $choice === '5.00';
                    $btnClass = $isFail ? 'text-rose-600 border-rose-200 bg-rose-50 hover:bg-rose-600 hover:text-white hover:border-rose-600' : 'text-[#00205b] border-slate-200 bg-white hover:border-[#00205b] hover:bg-[#00205b] hover:text-white';
                ?>
                    <button type="button" onclick="applyGrade('<?= $choice ?>')" class="border <?= $btnClass ?> font-black text-sm py-2.5 rounded-lg transition-all shadow-sm focus:outline-none">
                        <?= $choice ?>
                    </button>
                <?php endforeach; ?>
            </div>
            
            <button type="button" onclick="applyGrade('')" class="w-full bg-slate-50 hover:bg-slate-100 text-slate-500 font-bold uppercase tracking-widest py-3 rounded-xl border border-slate-200 transition-colors text-[10px] focus:outline-none">
                Clear Current Value
            </button>
        </div>
    </div>

    <?php include 'sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto h-full w-full pt-20 md:pt-0 relative custom-scrollbar z-10">
        <div class="p-4 md:p-6 lg:p-8 max-w-7xl mx-auto relative z-20 h-full flex flex-col w-full max-w-full">
            
            <!-- CLEAN HEADER -->
            <header class="mb-6 flex flex-col md:flex-row md:justify-between md:items-end gap-4 animate-up shrink-0 w-full">
                <div>
                    <h1 class="text-2xl md:text-3xl font-black text-slate-900 tracking-tight">My Classes</h1>
                    <p class="text-xs md:text-sm text-slate-500 mt-1 font-medium">Manage your currently assigned subjects and access past academic records.</p>
                </div>
                
                <div class="bg-white border border-slate-200 shadow-sm rounded-lg px-4 py-2 flex flex-col text-right">
                    <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-0.5">Active Term</span>
                    <span class="text-xs font-black text-[#00205b]"><?= htmlspecialchars($active_semester) ?> | <?= htmlspecialchars($active_academic_year) ?></span>
                </div>
            </header>

            <?php if (empty($selected_subject)): ?>
                <!-- MAIN CLASS ROSTER VIEW (MODERN SAAS UI) -->
                <div class="flex-1 flex flex-col bg-white border border-slate-200 rounded-xl shadow-sm animate-up delay-1 overflow-hidden w-full max-w-full">
                    
                    <!-- TOOLBAR (Tabs + Filters) -->
                    <div class="border-b border-slate-200 bg-slate-50/80 flex flex-col xl:flex-row justify-between items-start xl:items-center p-4 shrink-0 gap-4 w-full">
                        
                        <!-- Segmented Tabs -->
                        <div class="inline-flex bg-slate-200/50 p-1 rounded-lg border border-slate-200" id="classTabs">
                            <button onclick="switchTab('active')" id="tab_active" class="px-4 py-1.5 rounded-md text-xs font-black uppercase tracking-wider bg-white text-[#00205b] shadow-sm transition-all focus:outline-none flex items-center gap-2">
                                Active <span id="active_count" class="bg-indigo-50 text-[#00205b] px-1.5 py-0.5 rounded text-[9px] border border-indigo-100">0</span>
                            </button>
                            <button onclick="switchTab('archive')" id="tab_archive" class="px-4 py-1.5 rounded-md text-xs font-bold uppercase tracking-wider bg-transparent text-slate-500 hover:text-slate-700 transition-all focus:outline-none flex items-center gap-2">
                                Archive <span id="archive_count" class="bg-slate-200/50 text-slate-500 px-1.5 py-0.5 rounded text-[9px]">0</span>
                            </button>
                        </div>
                        
                        <!-- Sleek Filters -->
                        <div class="flex flex-col sm:flex-row items-center gap-2 w-full xl:w-auto">
                            <div class="relative w-full sm:w-44">
                                <select id="filterProgram" onchange="filterClasses()" class="bg-white border border-slate-300 text-slate-700 text-xs font-semibold rounded-lg focus:outline-none focus:ring-2 focus:ring-[#00205b]/20 focus:border-[#00205b] block w-full p-2 cursor-pointer shadow-sm transition-all">
                                    <option value="">All Programs</option>
                                    <?php foreach($programs as $p): ?><option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            
                            <select id="filterYear" onchange="filterClasses()" class="bg-white border border-slate-300 text-slate-700 text-xs font-semibold rounded-lg focus:outline-none focus:ring-2 focus:ring-[#00205b]/20 focus:border-[#00205b] block w-full sm:w-28 p-2 cursor-pointer shadow-sm transition-all">
                                <option value="">All Years</option>
                                <option value="1st Year">1st Year</option>
                                <option value="2nd Year">2nd Year</option>
                                <option value="3rd Year">3rd Year</option>
                                <option value="4th Year">4th Year</option>
                            </select>
                            
                            <div class="relative w-full sm:w-64">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                                </div>
                                <input type="text" id="classSearch" onkeyup="filterClasses()" placeholder="Search class code or title..." class="bg-white border border-slate-300 text-slate-800 text-xs font-medium rounded-lg focus:outline-none focus:ring-2 focus:ring-[#00205b]/20 focus:border-[#00205b] block w-full pl-9 p-2 shadow-sm transition-all placeholder:text-slate-400">
                            </div>
                        </div>
                    </div>
                    
                    <!-- DATA LIST (RESPONSIVE TABLE WRAPPER) -->
                    <div class="overflow-x-auto overflow-y-auto flex-1 custom-scrollbar w-full relative bg-white">
                        <table class="w-full text-left border-collapse min-w-[800px] divide-y divide-slate-200" id="classesTable">
                            <thead class="bg-white sticky top-0 z-10 shadow-[0_1px_2px_rgba(0,0,0,0.05)]">
                                <tr>
                                    <th class="py-3 px-4 text-slate-400 text-[10px] font-bold uppercase tracking-widest">Subject Overview</th>
                                    <th class="py-3 px-4 text-slate-400 text-[10px] font-bold uppercase tracking-widest hidden sm:table-cell">Program Map</th>
                                    <th class="py-3 px-4 text-slate-400 text-[10px] font-bold uppercase tracking-widest hidden md:table-cell">Academic Term</th>
                                    <th class="py-3 px-4 text-slate-400 text-[10px] font-bold uppercase tracking-widest text-center">Enrolled</th>
                                    <th class="py-3 px-4 text-slate-400 text-[10px] font-bold uppercase tracking-widest text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody id="classes_container" class="bg-white divide-y divide-slate-100">
                                <tr><td colspan="5" class="py-16 text-center text-slate-400 text-xs font-bold uppercase tracking-widest">
                                    <svg class="animate-spin h-6 w-6 text-[#00205b] mx-auto mb-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                    Loading classes...
                                </td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
            <?php else: ?>
                <!-- SPECIFIC SUBJECT GRADING VIEW -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden relative z-30 flex flex-col flex-1 animate-up delay-1 w-full max-w-full">
                    
                    <div class="p-4 md:p-5 flex flex-col md:flex-row items-start md:items-center justify-between gap-4 border-b border-slate-200 bg-slate-50/50 w-full">
                        <div class="flex items-center gap-4 w-full md:w-auto">
                            <a href="my_classes.php" class="w-10 h-10 rounded-full bg-white border border-slate-300 text-slate-500 hover:text-[#00205b] hover:border-[#00205b] hover:shadow-md flex items-center justify-center transition-all shadow-sm shrink-0" title="Back to Classes">
                                <svg class="w-5 h-5 pr-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" /></svg>
                            </a>
                            <div class="min-w-0">
                                <h2 class="font-black text-slate-900 text-xl tracking-tight leading-none mb-1.5 truncate">
                                    <?= htmlspecialchars($active_subject_details['subject_code'] ?? '') ?>
                                </h2>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-[#00205b] text-white uppercase tracking-wider">
                                        SEC <?= htmlspecialchars($active_subject_details['section'] ?? 'ALL') ?>
                                    </span>
                                    <span class="text-[10px] font-semibold text-slate-500 uppercase tracking-widest">
                                        <?= htmlspecialchars($active_subject_details['program'] ? $active_subject_details['program'] . ' - ' : '') ?><?= htmlspecialchars($active_subject_details['year_level'] ?? '') ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-3 w-full md:w-auto justify-end shrink-0">
                            <div class="hidden sm:flex flex-col text-right mr-2">
                                <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Enrolled</span>
                                <span class="text-base font-black text-slate-800 leading-none"><?= count($enrolled_students) ?></span>
                            </div>
                            
                            <button type="button" onclick="exportGradesToCSV('gradingTable', 'Class_Grades_<?= htmlspecialchars($selected_subject) ?>')" class="bg-white border border-slate-300 text-slate-700 hover:border-[#00205b] hover:text-[#00205b] px-4 py-2 rounded-lg text-xs font-bold uppercase tracking-wider shadow-sm transition-all flex items-center gap-2 focus:outline-none focus:ring-2 focus:ring-[#00205b]/20">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                Export
                            </button>
                            
                            <!-- Save Button pinned to top for convenience -->
                            <button type="button" onclick="document.getElementById('saveGradesTrigger').click();" class="flex items-center gap-2 px-5 py-2 bg-[#00205b] hover:bg-[#001233] text-white rounded-lg text-xs font-black uppercase tracking-wider transition-all shadow-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 transform hover:-translate-y-0.5">
                                Save Grades
                            </button>
                        </div>
                    </div>

                    <?php if (empty($enrolled_students)): ?>
                        <div class="flex-1 flex flex-col items-center justify-center py-20 px-4 text-center w-full">
                            <div class="w-20 h-20 bg-slate-50 text-slate-300 rounded-full flex items-center justify-center mb-5 shadow-inner border border-slate-200">
                                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                            </div>
                            <h4 class="text-slate-800 text-lg font-black uppercase tracking-widest mb-2">Empty Roster</h4>
                            <p class="text-slate-500 text-sm font-medium tracking-wide">There are currently no students officially enrolled in this section.</p>
                        </div>
                    <?php else: ?>
                        <div class="flex flex-col z-10 relative flex-1 w-full bg-white">
                            <form id="gradingForm" method="POST" action="my_classes.php?subject_id=<?= htmlspecialchars($selected_subject) ?>" class="flex flex-col m-0 h-full spa-form w-full relative">
                                <input type="hidden" name="action" value="save_grades">
                                <input type="hidden" name="subject_id" value="<?= htmlspecialchars($selected_subject) ?>">
                                <button type="submit" id="saveGradesTrigger" class="hidden">Hidden Submit</button>
                                
                                <div class="overflow-x-auto custom-scrollbar overflow-y-auto flex-1 w-full pb-20 max-h-[65vh]">
                                    <!-- CLEAN SAAS TABLE FOR GRADING -->
                                    <table class="w-full text-left border-collapse min-w-[800px]" id="gradingTable">
                                        <thead class="bg-white sticky top-0 z-20 shadow-[0_1px_2px_rgba(0,0,0,0.05)]">
                                            <tr>
                                                <th class="w-12 py-3 px-4 text-center border-b border-slate-200">
                                                    <input type="checkbox" id="selectAllGrades" onclick="toggleAllGrades(this)" class="w-4 h-4 text-[#00205b] bg-slate-100 border-slate-300 rounded focus:ring-[#00205b] cursor-pointer transition-all" title="Select All Students">
                                                </th>
                                                <th class="py-3 px-4 text-slate-400 text-[10px] font-bold uppercase tracking-widest border-b border-slate-200">Student Identity</th>
                                                <th class="py-3 px-4 text-slate-400 text-[10px] font-bold uppercase tracking-widest border-b border-slate-200 hidden md:table-cell">Program/Year</th>
                                                <th class="py-3 px-4 text-slate-400 text-[10px] font-bold uppercase tracking-widest border-b border-slate-200 text-center hidden sm:table-cell">Status</th>
                                                <th class="py-3 px-4 text-slate-400 text-[10px] font-bold uppercase tracking-widest border-b border-slate-200 text-right w-40">Final Grade</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white" id="grading_tbody">
                                            <?= renderGradingRows($enrolled_students) ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- FLOATING ACTION BAR FOR GROUP GRADING -->
                                <div id="floating_group_btn_wrap" class="fixed bottom-8 left-1/2 -translate-x-1/2 bg-[#00205b] text-white px-5 py-2.5 rounded-full shadow-[0_10px_40px_rgba(0,32,91,0.3)] z-[100] hidden items-center gap-4 animate-up border border-indigo-900/50 backdrop-blur-md">
                                    <div class="flex items-center gap-2 border-r border-indigo-400/30 pr-4">
                                        <div class="w-6 h-6 rounded-full bg-indigo-500/20 flex items-center justify-center text-indigo-100 font-bold text-xs" id="checked_count">0</div>
                                        <span class="text-xs font-medium text-indigo-100 uppercase tracking-widest">Selected</span>
                                    </div>
                                    <button type="button" onclick="openGroupGradeModal()" class="text-sm font-black uppercase tracking-wider hover:text-emerald-400 transition-colors focus:outline-none flex items-center gap-2">
                                        Assign Grade <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                    </button>
                                </div>

                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
    </main>

    <script>
        window.TEACHER_INIT_DATA = {
            activeSemester: '<?= addslashes($active_semester) ?>'
        };
    </script>
    <script src="sidebar.js?v=<?php echo time(); ?>"></script>
    <script src="my_classes.js?v=<?php echo time(); ?>"></script>
</body>
</html>