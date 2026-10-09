<?php
// =========================================================
// REGISTRAR MASTER LIST & CLASS ROSTERS
// Modern, Balanced, Accessible Architecture (PHP 8+)
// =========================================================

// 1. FORCE ERROR REPORTING & VISUAL FAILSAFE
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "<div style='position:fixed; top:0; left:0; width:100%; z-index:99999; background:#ef4444; color:white; padding:15px; font-family:monospace; font-weight:bold; box-shadow:0 10px 25px rgba(0,0,0,0.5);'>";
        echo "<h2 style='margin-top:0; font-size:16px;'>CRITICAL PHP ERROR DETECTED</h2>";
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

// 3. CACHE BUSTING
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

include "../dbconn.php";

// =========================================================
// STRICT ROLE ENFORCEMENT: REGISTRAR ONLY
// =========================================================
if (!isset($_SESSION['role'])) {
    header("Location: ../index.php");
    exit();
}
if ($_SESSION['role'] !== 'registrar') {
    header("Location: ../index.php");
    exit();
}

$can_edit = true;
$success_msg = "";
$error_msg = "";

// =========================================================
// AUTO-MIGRATE PERSONAL EMAIL TRANSACTIONS TO INSTITUTIONAL
// =========================================================
$conn->query("
    UPDATE transaction_history th
    JOIN admissions a ON th.student_email = a.email
    JOIN users u ON a.provisioned_user_id = u.id
    SET th.student_email = u.email
    WHERE a.provisioned_user_id IS NOT NULL 
      AND th.student_email != u.email
");

// =========================================================
// GLOBAL DATA FETCHING
// =========================================================
$sem_list = ['1st Semester', '2nd Semester', 'Summer'];
$year_list = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

$prog_list = [];
$prog_query = $conn->query("SELECT program_name FROM programs WHERE is_archived = 0 OR is_archived IS NULL ORDER BY program_name ASC");
if ($prog_query) { 
    while($r = $prog_query->fetch_assoc()) { 
        $prog_list[] = $r['program_name']; 
    } 
}

$settings = [];
$settings_q = $conn->query("SELECT * FROM portal_settings");
if ($settings_q) {
    while ($row = $settings_q->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}
$active_school_year = isset($settings['active_school_year']) ? $settings['active_school_year'] : '2024-2025';
$active_semester    = isset($settings['active_semester']) ? $settings['active_semester'] : '1st Semester';

// =========================================================
// STRICT ACADEMIC YEAR GENERATOR (Base: 2024-2025)
// =========================================================
$active_start = (int)explode('-', $active_school_year)[0];
if ($active_start < 2024) {
    $active_start = (int)date('Y');
}

$years_list = [];
$base_start = 2024;
$end_start = max($base_start, $active_start);

for ($y = $base_start; $y <= $end_start; $y++) {
    $next_y = $y + 1;
    $sy_format = $y . '-' . $next_y;
    $years_list[$sy_format] = $sy_format;
}

$years_list[$active_school_year] = $active_school_year;
krsort($years_list);
$recent_years = array_slice($years_list, 0, 5, true);

$f_semester = isset($_GET['f_semester']) ? $_GET['f_semester'] : $active_semester; 
$f_year     = isset($_GET['f_year']) ? $_GET['f_year'] : $active_school_year;
$f_program  = isset($_GET['f_program']) ? $_GET['f_program'] : 'All';
$f_year_lvl = isset($_GET['f_year_lvl']) ? $_GET['f_year_lvl'] : 'All';
$f_section  = isset($_GET['f_section']) ? $_GET['f_section'] : '';

$safe_sem   = $conn->real_escape_string($f_semester);
$safe_year  = $conn->real_escape_string($f_year);

$default_section_limit = isset($settings['default_section_limit']) ? (int)$settings['default_section_limit'] : 60;

// =========================================================
// REAL DATABASE-DRIVEN SECTIONS FOR CURRENT TERM ONLY
// =========================================================
$sec_dropdown_list = [];
$sec_sql = "
    SELECT DISTINCT er.assigned_section AS sec_name 
    FROM enrollment_requests er 
    JOIN users u ON er.user_id = u.id 
    WHERE er.school_year = '$safe_year' 
      AND er.semester = '$safe_sem' 
      AND (er.final_status = 'Enrolled' OR er.enrolled_at IS NOT NULL)
      AND u.role = 'student' 
      AND COALESCE(u.is_archived, 0) = 0
      AND er.assigned_section IS NOT NULL 
      AND er.assigned_section != '' 
      AND er.assigned_section != 'UNASSIGNED'
";
if ($f_program !== 'All') {
    $sec_sql .= " AND er.program = '" . $conn->real_escape_string($f_program) . "'";
}
if ($f_year_lvl !== 'All') {
    $sec_sql .= " AND er.year_level = '" . $conn->real_escape_string($f_year_lvl) . "'";
}
$sec_sql .= " ORDER BY er.assigned_section ASC";

$sec_list_q = $conn->query($sec_sql);
if ($sec_list_q) {
    while ($sr = $sec_list_q->fetch_assoc()) {
        $clean_sec = trim($sr['sec_name']);
        if ($clean_sec !== '' && !in_array($clean_sec, $sec_dropdown_list)) {
            $sec_dropdown_list[] = $clean_sec;
        }
    }
}

// Restrict "All Sections" to force single-section load and allow "empty" default (reduces lag)
if (!empty($sec_dropdown_list)) {
    if ($f_section === 'All' || (!empty($f_section) && !in_array($f_section, $sec_dropdown_list))) {
        $f_section = '';
    }
} else {
    $f_section = '';
}
$safe_sec = $conn->real_escape_string($f_section);

// =========================================================
// POST ACTION PROCESSOR & AJAX INTERCEPTOR
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // NEW: Save Missing Grade Logic
    if (isset($_POST['update_missing_grade'])) {
        $st_id        = $conn->real_escape_string($_POST['student_id']);
        $subject_code = $conn->real_escape_string($_POST['subject_code']);
        $subject_desc = $conn->real_escape_string($_POST['subject_desc']);
        $units        = $conn->real_escape_string($_POST['units']);
        $semester     = $conn->real_escape_string($_POST['semester']);
        $new_grade    = $conn->real_escape_string($_POST['new_grade']);

        $u_q = $conn->query("SELECT id FROM users WHERE student_id = '$st_id' LIMIT 1");
        $internal_uid = ($u_q && $u_q->num_rows > 0) ? $u_q->fetch_assoc()['id'] : 0;

        if ($new_grade === 'CLEAR') {
            // 1. Delete from permanent student_grades
            $conn->query("DELETE FROM student_grades WHERE student_id = '$st_id' AND subject_code = '$subject_code'");
            
            // 2. Clear from active enrollments
            if ($internal_uid > 0) {
                $conn->query("UPDATE enrollments e JOIN prospectus p ON e.subject_id = p.id SET e.grade = NULL WHERE e.student_id = $internal_uid AND p.course_code = '$subject_code'");
            }
            $success_msg = "Grade successfully removed for $subject_code.";
        } else {
            // 1. Update/Insert into permanent student_grades
            $check_g = $conn->query("SELECT id FROM student_grades WHERE student_id = '$st_id' AND subject_code = '$subject_code'");
            if ($check_g && $check_g->num_rows > 0) {
                $conn->query("UPDATE student_grades SET grade = '$new_grade' WHERE student_id = '$st_id' AND subject_code = '$subject_code'");
            } else {
                $conn->query("INSERT INTO student_grades (student_id, subject_code, subject_description, units, semester, grade) VALUES ('$st_id', '$subject_code', '$subject_desc', '$units', '$semester', '$new_grade')");
            }

            // 2. Mirror update to active enrollments table
            if ($internal_uid > 0) {
                $conn->query("UPDATE enrollments e JOIN prospectus p ON e.subject_id = p.id SET e.grade = '$new_grade' WHERE e.student_id = $internal_uid AND p.course_code = '$subject_code'");
                
                // 3. Auto-Irregular if grade is 5.00
                if ($new_grade === '5.00' || $new_grade === '5.0' || $new_grade === '5') {
                    $conn->query("UPDATE enrollment_requests SET student_status = 'Irregular' WHERE user_id = $internal_uid AND semester = '$safe_sem' AND school_year = '$safe_year'");
                }
            }
            $success_msg = "Grade ($new_grade) saved for $subject_code.";
        }
    }

    if (isset($_POST['update_global_limit'])) {
        $gl = max(1, (int)$_POST['global_limit_value']);
        $conn->query("INSERT INTO portal_settings (setting_key, setting_value) VALUES ('default_section_limit', '$gl') ON DUPLICATE KEY UPDATE setting_value = '$gl'");
        $success_msg = "Global section capacity updated to $gl students.";
        $default_section_limit = $gl;
    }
    if (isset($_POST['update_placement'])) {
        $student_email    = $conn->real_escape_string($_POST['student_email']);
        $user_internal_id = isset($_POST['user_internal_id']) ? (int)$_POST['user_internal_id'] : 0;
        $new_prog         = $conn->real_escape_string($_POST['program_name']);
        $new_year         = $conn->real_escape_string($_POST['year_level']);
        $new_sec          = $conn->real_escape_string(strtoupper(trim($_POST['section_name'])));
        $new_status       = $conn->real_escape_string($_POST['student_status']);
        $semester         = $conn->real_escape_string($_POST['semester_context']); 
        $target_sy        = $conn->real_escape_string($_POST['sy_context']);

        $check = $conn->query("SELECT id FROM enrollment_requests WHERE (user_id = $user_internal_id OR email = '$student_email') AND semester = '$semester' AND school_year = '$target_sy'");
        if ($check) {
            if ($check->num_rows > 0) {
                $conn->query("UPDATE enrollment_requests SET program = '$new_prog', year_level = '$new_year', assigned_section = '$new_sec', student_status = '$new_status' WHERE (user_id = $user_internal_id OR email = '$student_email') AND semester = '$semester' AND school_year = '$target_sy'");
            } else {
                $conn->query("INSERT INTO enrollment_requests (user_id, email, program, year_level, assigned_section, student_status, semester, school_year, final_status, enrolled_at, created_at) VALUES ($user_internal_id, '$student_email', '$new_prog', '$new_year', '$new_sec', '$new_status', '$semester', '$target_sy', 'Enrolled', NOW(), NOW())");
            }
        }
        $success_msg = "Placement updated: $new_prog ($new_year) - Sec $new_sec.";
    }
    if (isset($_POST['update_section_limit'])) {
        $sem   = $conn->real_escape_string($_POST['limit_semester']);
        $prog  = $conn->real_escape_string($_POST['limit_program']);
        $year  = $conn->real_escape_string($_POST['limit_year']);
        $sec   = $conn->real_escape_string($_POST['limit_section']);
        $limit = max(1, (int)$_POST['new_limit']);
        $conn->query("INSERT INTO section_settings (semester, program, year_level, section_name, student_limit) VALUES ('$sem', '$prog', '$year', '$sec', $limit) ON DUPLICATE KEY UPDATE student_limit = $limit");
        $success_msg = "Section $sec limit updated to $limit.";
    }
    if (isset($_POST['mass_move_section'])) {
        $sem        = $conn->real_escape_string($_POST['move_semester']);
        $target_sy  = $conn->real_escape_string($_POST['move_sy']);
        $year       = $conn->real_escape_string($_POST['move_year']);
        $new_prog   = $conn->real_escape_string($_POST['new_program_name']);
        $new_sec    = $conn->real_escape_string(strtoupper(trim($_POST['new_section_name'])));
        $emails     = isset($_POST['selected_students']) ? $_POST['selected_students'] : [];

        if (empty($emails)) {
            $error_msg = "No students selected.";
        } elseif (empty($new_sec)) {
            $error_msg = "Specify valid section.";
        } elseif (empty($new_prog)) {
            $error_msg = "Specify valid program.";
        } else {
            $emails_str = implode("','", array_map([$conn, 'real_escape_string'], $emails));
            $conn->query("UPDATE enrollment_requests SET program = '$new_prog', assigned_section = '$new_sec' WHERE semester = '$sem' AND school_year = '$target_sy' AND email IN ('$emails_str')");
            $conn->query("UPDATE user_profiles p JOIN users u ON p.user_id = u.id SET p.program = '$new_prog' WHERE u.email IN ('$emails_str')");
            $success_msg = "Successfully force-moved " . count($emails) . " student(s) to Section $new_sec.";
        }
    }
    
    // JS SPA Engine Hook
    if (isset($_POST['ajax_post'])) {
        if(ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        $final_msg = empty($error_msg) ? $success_msg : $error_msg;
        $final_status = empty($error_msg) ? 'success' : 'error';
        echo json_encode([
            'status' => $final_status,
            'message' => $final_msg
        ]);
        exit();
    }
}

// =========================================================
// FETCH MASTER ROSTER DATA
// =========================================================
$section_limits = [];
$limits_q = $conn->query("SELECT program, year_level, section_name, student_limit FROM section_settings WHERE semester = '$safe_sem'");
if ($limits_q) { 
    while ($r = $limits_q->fetch_assoc()) { 
        $section_limits[$r['program']][$r['year_level']][$r['section_name']] = (int)$r['student_limit']; 
    } 
}

// DATA FETCH FOR MASTERLIST (NOW CHECKS FOR FAILED 5.00 GRADES)
$roster_res = $conn->query("
    SELECT u.id, u.email, u.student_id, p.phone,
           p.first_name, p.last_name, p.middle_name, p.gender as u_gender,
           p.dob, p.pob, p.address, 
           COALESCE(NULLIF(p.school_last_attended, ''), a.school_last_attended, 'Not Provided') as school_last_attended, 
           COALESCE(NULLIF(p.school_year_attended, ''), a.school_year_attended, 'Not Provided') as school_year_attended,
           p.father_name, p.father_occupation, p.father_contact,
           p.mother_name, p.mother_occupation, p.mother_contact,
           p.emergency_contact_name, p.emergency_contact_number,
           COALESCE(er.program, p.program, 'UNASSIGNED') as display_program, 
           COALESCE(er.year_level, 'UNASSIGNED') as display_year, 
           COALESCE(NULLIF(er.learning_mode, ''), a.evaluated_modality, 'Face-to-Face') as display_mode, 
           COALESCE(er.assigned_section, 'UNASSIGNED') as assigned_section, 
           COALESCE(er.student_status, 'Regular') as student_status, 
           COALESCE(er.final_status, 'Not Enrolled') as request_status, 
           COALESCE(er.semester, '$safe_sem') as semester, 
           COALESCE(er.school_year, '$safe_year') as school_year,
           
           COALESCE(th_paid.total_paid, er.total_paid_accumulated, 0.00) as live_paid,
           COALESCE(er.balance, 0.00) as balance, 
           er.assessed_fee, er.total_paid_accumulated, er.payment_amount, er.enrolled_at, er.retake_fee,
           
           (SELECT COUNT(*) FROM student_grades sg WHERE sg.student_id = u.student_id AND (sg.grade = '5.00' OR sg.grade = '5.0' OR sg.grade = '5')) as has_failed
           
    FROM users u 
    LEFT JOIN user_profiles p ON u.id = p.user_id
    LEFT JOIN admissions a ON u.id = a.provisioned_user_id
    LEFT JOIN (
        SELECT student_email, SUM(amount) as total_paid 
        FROM transaction_history 
        WHERE academic_year = '$safe_year' AND semester = '$safe_sem'
        GROUP BY student_email
    ) th_paid ON u.email = th_paid.student_email
    INNER JOIN (
        SELECT er1.* FROM enrollment_requests er1
        INNER JOIN (
            SELECT user_id, MAX(id) as max_id FROM enrollment_requests 
            WHERE semester = '$safe_sem' AND school_year = '$safe_year' 
            GROUP BY user_id
        ) er2 ON er1.id = er2.max_id
    ) er ON u.id = er.user_id 
    WHERE u.role = 'student' AND COALESCE(u.is_archived, 0) = 0 AND (er.final_status = 'Enrolled' OR er.enrolled_at IS NOT NULL) 
    ORDER BY p.last_name ASC, p.first_name ASC
");

// Process students into Sections Map and Metrics
$sections = [];
$total_students = 0; 
$total_with_balance = 0;
$total_males = 0;
$total_females = 0;
$total_regular = 0;
$total_irreg = 0;

if ($roster_res && $roster_res->num_rows > 0) {
    while ($row = $roster_res->fetch_assoc()) {
        $db_balance = isset($row['balance']) ? (float)$row['balance'] : 0;
        $assessed   = isset($row['assessed_fee']) ? (float)$row['assessed_fee'] : 0;
        $paid_acc   = isset($row['live_paid']) ? (float)$row['live_paid'] : 0;
        
        if ($db_balance > 0) {
            $row['balance'] = $db_balance;
        } else {
            $row['balance'] = max(0, $assessed - $paid_acc);
        }

        $p = !empty($row['display_program']) && $row['display_program'] !== 'TBD' ? $row['display_program'] : 'UNASSIGNED';
        $y = !empty($row['display_year']) && $row['display_year'] !== 'TBD' ? $row['display_year'] : 'UNASSIGNED';
        $s = !empty($row['assigned_section']) ? $row['assigned_section'] : 'UNASSIGNED';

        // Check dropdown filters (including section filter)
        if ($f_program !== 'All' && $p !== $f_program) continue;
        if ($f_year_lvl !== 'All' && $y !== $f_year_lvl) continue;
        
        // Skip populating section tables if NO section is selected (prevents lag, leaves list empty)
        if ($f_section === '') continue; 
        if ($f_section !== '' && $s !== $f_section) continue; 

        $secKey = $p . '||' . $y . '||' . $s;
        if (!isset($sections[$secKey])) {
            $limit = $default_section_limit;
            if (isset($section_limits[$p][$y][$s])) {
                $limit = $section_limits[$p][$y][$s];
            }
            $sections[$secKey] = [
                'program'    => $p,
                'year_level' => $y,
                'section'    => $s,
                'limit'      => $limit,
                'students'   => [],
                'males'      => 0,
                'females'    => 0,
                'balances'   => 0
            ];
        }

        $sections[$secKey]['students'][] = $row;
        $total_students++;

        if ($row['balance'] > 0) {
            $total_with_balance++;
            $sections[$secKey]['balances']++;
        }

        $g = strtoupper(trim($row['u_gender'] ?? ''));
        if ($g === 'MALE' || $g === 'M') {
            $total_males++;
            $sections[$secKey]['males']++;
        } elseif ($g === 'FEMALE' || $g === 'F') {
            $total_females++;
            $sections[$secKey]['females']++;
        }

        if (stripos($row['student_status'], 'Irregular') !== false) {
            $total_irreg++;
        } else {
            $total_regular++;
        }
    }
}

// =========================================================
// HTML TEMPLATE RENDER FUNCTION (SLIM MODERN SECTION CARDS MATRIX)
// =========================================================
function renderMasterRoster($sections, $prog_list, $year_list, $can_edit, $safe_sem, $default_section_limit, $active_school_year, $safe_sec) {
    ob_start();

    if (empty($sections)) {
        if ($safe_sec === '') {
            echo '<div class="bg-white rounded-xl border border-slate-200 p-8 text-center shadow-sm fade-in-up">';
            echo '<div class="w-14 h-14 bg-indigo-50 rounded-full flex items-center justify-center mx-auto mb-3 text-indigo-400 border border-indigo-100">';
            echo '<i class="fa-solid fa-list-check text-2xl"></i>';
            echo '</div>';
            echo '<h3 class="text-base font-bold text-slate-800">Please Select a Section</h3>';
            echo '<p class="text-sm text-slate-500 mt-1 max-w-md mx-auto">To maintain optimal system performance, please choose a specific section from the dropdown above to manage the class master list.</p>';
            echo '</div>';
        } else {
            echo '<div class="bg-white rounded-xl border border-slate-200 p-8 text-center shadow-sm fade-in-up">';
            echo '<div class="w-14 h-14 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-3 text-slate-400">';
            echo '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>';
            echo '</div>';
            echo '<h3 class="text-base font-bold text-slate-800">No Enrolled Classes Found</h3>';
            echo '<p class="text-sm text-slate-500 mt-1 max-w-md mx-auto">There are no student rosters registered under the selected Academic Year, Semester, Program, and Section filters.</p>';
            echo '<div class="mt-4">';
            echo '<button type="button" onclick="window.resetMasterFilters()" class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-bold rounded-lg bg-emerald-700 text-white hover:bg-emerald-800 transition shadow-sm uppercase tracking-wider">Reset Filters</button>';
            echo '</div>';
            echo '</div>';
        }
        return ob_get_clean();
    }

    // Grid changed to 1 column full width so it doesn't look cramped
    echo '<div class="grid grid-cols-1 gap-6 w-full" id="sectionCardsContainer">';

    $cardIndex = 0;
    foreach ($sections as $secKey => $sec) {
        $cardIndex++;
        $enrolled_count = count($sec['students']);
        $cap_limit      = $sec['limit'];
        $pct_filled     = $cap_limit > 0 ? min(100, round(($enrolled_count / $cap_limit) * 100)) : 100;

        $capColor     = 'bg-emerald-500';
        $capTextColor = 'text-emerald-700';
        if ($pct_filled >= 95) {
            $capColor     = 'bg-rose-500';
            $capTextColor = 'text-rose-700';
        } elseif ($pct_filled >= 75) {
            $capColor     = 'bg-amber-500';
            $capTextColor = 'text-amber-700';
        }

        // Prepare JSON students for this section's modals
        $mapped_students_full = [];
        foreach ($sec['students'] as $s) {
            $s_id = isset($s['student_id']) ? $s['student_id'] : 'N/A';
            $last = isset($s['last_name']) ? $s['last_name'] : '';
            $first = isset($s['first_name']) ? $s['first_name'] : '';
            $mid = isset($s['middle_name']) ? $s['middle_name'] : '';
            $gen = isset($s['u_gender']) ? $s['u_gender'] : 'N/A';
            $fullName = (!empty($last) || !empty($first)) ? trim($last) . ', ' . trim($first) : 'Unknown';

            $mapped_students_full[] = [
                'id' => $s_id,
                'user_id' => $s['id'],
                'name' => $fullName,
                'last_name' => $last,
                'first_name' => $first,
                'middle_name' => $mid,
                'gender' => $gen,
                'email' => isset($s['email']) ? $s['email'] : 'N/A',
                'phone' => isset($s['phone']) ? $s['phone'] : 'N/A',
                'status' => isset($s['request_status']) ? $s['request_status'] : 'N/A',
                'balance' => isset($s['balance']) ? (float)$s['balance'] : 0,
                'has_failed' => isset($s['has_failed']) ? (int)$s['has_failed'] : 0,
                'enrolled_at' => isset($s['enrolled_at']) ? $s['enrolled_at'] : '1970-01-01 00:00:00',
                'program' => isset($s['display_program']) ? $s['display_program'] : 'UNASSIGNED',
                'year_level' => isset($s['display_year']) ? $s['display_year'] : 'UNASSIGNED',
                'semester' => isset($s['semester']) ? $s['semester'] : '',
                'assigned_section' => isset($s['assigned_section']) ? $s['assigned_section'] : 'N/A',
                'dob' => isset($s['dob']) ? $s['dob'] : 'N/A',
                'address' => isset($s['address']) ? $s['address'] : 'N/A',
                'learning_mode' => isset($s['display_mode']) ? $s['display_mode'] : 'Face-to-Face',
                'school_last_attended' => isset($s['school_last_attended']) ? $s['school_last_attended'] : 'Not Provided',
                'school_year_attended' => isset($s['school_year_attended']) ? $s['school_year_attended'] : 'Not Provided'
            ];
        }
        $json_students_full = htmlspecialchars(json_encode($mapped_students_full, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm flex flex-col justify-between overflow-hidden transition-all duration-200 hover:shadow-md" data-section-name="<?= htmlspecialchars($sec['section']) ?>" data-program="<?= htmlspecialchars($sec['program']) ?>">
            
            <!-- CARD HEADER -->
            <div class="p-4 border-b border-slate-100 bg-white">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-xl font-bold text-slate-900 tracking-tight">Section <?= htmlspecialchars($sec['section']) ?></h3>
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-md bg-slate-100 text-slate-700 border border-slate-200">
                                <?= htmlspecialchars($sec['year_level']) ?>
                            </span>
                        </div>
                        <div class="text-sm text-slate-500 font-medium mt-1 truncate">
                            <?= htmlspecialchars($sec['program']) ?>
                        </div>
                    </div>

                    <!-- Capacity Progress -->
                    <div class="text-right">
                        <div class="text-sm font-bold <?= $capTextColor ?>">
                            <?= $enrolled_count ?> / <?= $cap_limit ?> Enrolled
                        </div>
                        <div class="w-32 bg-slate-100 rounded-full h-2 mt-1.5 overflow-hidden ml-auto">
                            <div class="<?= $capColor ?> h-2 rounded-full" style="width: <?= $pct_filled ?>%"></div>
                        </div>
                    </div>
                </div>

                <!-- ACTION BUTTONS -->
                <div class="flex flex-wrap items-center gap-2 mt-4 pt-3 border-t border-slate-100">
                    <button type="button" data-sy="<?= htmlspecialchars($sec['students'][0]['school_year'] ?? $active_school_year, ENT_QUOTES, 'UTF-8') ?>" data-prog="<?= htmlspecialchars($sec['program'], ENT_QUOTES, 'UTF-8') ?>" data-year="<?= htmlspecialchars($sec['year_level'], ENT_QUOTES, 'UTF-8') ?>" data-sec="<?= htmlspecialchars($sec['section'], ENT_QUOTES, 'UTF-8') ?>" data-sem="<?= htmlspecialchars($safe_sem, ENT_QUOTES, 'UTF-8') ?>" data-students="<?= $json_students_full ?>" onclick="window.openGradesGrid(this)" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-indigo-50 text-indigo-700 border border-indigo-200 hover:bg-indigo-100 transition shadow-sm cursor-pointer">
                        <svg class="w-3.5 h-3.5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Grades Grid
                    </button>
                    <button type="button" data-sy="<?= htmlspecialchars($sec['students'][0]['school_year'] ?? $active_school_year, ENT_QUOTES, 'UTF-8') ?>" data-prog="<?= htmlspecialchars($sec['program'], ENT_QUOTES, 'UTF-8') ?>" data-year="<?= htmlspecialchars($sec['year_level'], ENT_QUOTES, 'UTF-8') ?>" data-sec="<?= htmlspecialchars($sec['section'], ENT_QUOTES, 'UTF-8') ?>" data-students="<?= $json_students_full ?>" onclick="window.openSectionMasterlist(this)" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-slate-50 text-slate-700 border border-slate-200 hover:bg-slate-100 transition shadow-sm cursor-pointer">
                        <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Print Masterlist
                    </button>
                    <?php if ($can_edit && $sec['section'] !== 'UNASSIGNED'): ?>
                        <button type="button" data-sem="<?= htmlspecialchars($safe_sem, ENT_QUOTES, 'UTF-8') ?>" data-prog="<?= htmlspecialchars($sec['program'], ENT_QUOTES, 'UTF-8') ?>" data-year="<?= htmlspecialchars($sec['year_level'], ENT_QUOTES, 'UTF-8') ?>" data-sec="<?= htmlspecialchars($sec['section'], ENT_QUOTES, 'UTF-8') ?>" data-limit="<?= $cap_limit ?>" onclick="window.openSectionLimitModal(this)" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-slate-50 text-slate-700 border border-slate-200 hover:bg-slate-100 transition shadow-sm cursor-pointer">
                            <svg class="w-3.5 h-3.5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                            Limit
                        </button>
                        <button type="button" data-sy="<?= htmlspecialchars($sec['students'][0]['school_year'] ?? $active_school_year, ENT_QUOTES, 'UTF-8') ?>" data-sem="<?= htmlspecialchars($safe_sem, ENT_QUOTES, 'UTF-8') ?>" data-prog="<?= htmlspecialchars($sec['program'], ENT_QUOTES, 'UTF-8') ?>" data-year="<?= htmlspecialchars($sec['year_level'], ENT_QUOTES, 'UTF-8') ?>" data-sec="<?= htmlspecialchars($sec['section'], ENT_QUOTES, 'UTF-8') ?>" data-students="<?= $json_students_full ?>" onclick="window.openMassMoveModal(this)" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 transition shadow-sm cursor-pointer">
                            <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                            Mass Move
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- CARD BODY: ROSTER TABLE -->
            <div class="p-0 flex-grow flex flex-col justify-between">
                <div class="max-h-[500px] overflow-y-auto custom-scrollbar">
                    <table class="w-full text-left text-xs excel-table whitespace-nowrap">
                        <thead class="bg-slate-100 text-slate-700 font-semibold border-b border-slate-200 sticky top-0 z-10 text-xs">
                            <tr>
                                <th class="py-2.5 px-3 w-8 text-center font-bold text-slate-500">#</th>
                                <th class="py-2.5 px-3 font-bold text-slate-900">ID Number</th>
                                <th class="py-2.5 px-3 font-bold text-slate-900">Student Name</th>
                                <th class="py-2.5 px-2 text-center w-10 font-bold text-slate-900">Gen</th>
                                <th class="py-2.5 px-3 text-center font-bold text-slate-900">Status</th>
                                <th class="py-2.5 px-3 text-right font-bold text-slate-900">Balance</th>
                                <th class="py-2.5 px-3 font-bold text-slate-900 bg-slate-50">Placement & Academics</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php 
                            $sNum = 0;
                            foreach ($sec['students'] as $student): 
                                $sNum++;
                                $hasName = (!empty($student['last_name']) || !empty($student['first_name']));
                                $student_display_name = $hasName ? trim($student['last_name']) . ', ' . trim($student['first_name']) . (!empty($student['middle_name']) ? ' ' . trim($student['middle_name']) : '') : 'Unknown Student';
                                
                                $has_balance = (isset($student['balance']) && $student['balance'] > 0);
                                $has_failed  = (isset($student['has_failed']) && (int)$student['has_failed'] > 0);

                                $row_bg = 'bg-white hover:bg-slate-50';
                                if ($has_balance) { $row_bg = 'bg-amber-50/50 hover:bg-amber-50'; }
                                if ($has_failed) { $row_bg = 'bg-rose-50/50 hover:bg-rose-50'; }

                                if ($has_failed) {
                                    $student_display_name .= ' <span class="px-1.5 py-0.5 rounded bg-rose-100 text-rose-700 text-[9px] font-black uppercase tracking-wider ml-1 border border-rose-200" title="Has failing grade (5.00)">FAILED</span>';
                                }

                                $u_gen = isset($student['u_gender']) ? $student['u_gender'] : 'N/A';
                                $genDisplay = (strtoupper(substr($u_gen, 0, 1)) === 'M') ? '<span class="text-blue-600 font-bold">M</span>' : ((strtoupper(substr($u_gen, 0, 1)) === 'F') ? '<span class="text-pink-600 font-bold">F</span>' : '<span class="text-slate-400">-</span>');
                                
                                $status = isset($student['request_status']) ? $student['request_status'] : 'Not Enrolled';
                                $status_ui = ($status === 'Enrolled') ? "<span class='text-[9px] bg-emerald-100 text-emerald-800 border border-emerald-200 px-1.5 py-0.5 rounded-full font-bold uppercase'>Enrolled</span>" : "<span class='text-[9px] bg-slate-100 text-slate-600 border border-slate-200 px-1.5 py-0.5 rounded-full font-bold uppercase'>{$status}</span>";

                                $bal_text = '0.00';
                                if ($has_balance) { $bal_text = '₱'.number_format($student['balance'], 2); }
                                $bal_class = $has_balance ? 'text-amber-600 font-bold' : 'text-slate-400';

                                $single_student_json = htmlspecialchars(json_encode($mapped_students_full[$sNum-1], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                            ?>
                                <tr class="<?= $row_bg ?> transition student-row" data-name="<?= strtolower(strip_tags($student_display_name)) ?>" data-id="<?= strtolower($student['student_id'] ?? '') ?>">
                                    <td class="py-2 px-3 text-slate-400 font-mono text-center bg-slate-50 text-[11px]"><?= $sNum ?></td>
                                    <td class="py-2 px-3 font-mono font-bold text-slate-900"><?= htmlspecialchars($student['student_id'] ?? 'N/A') ?></td>
                                    <td class="py-2 px-3 font-semibold text-slate-900 text-sm"><?= $student_display_name ?></td>
                                    <td class="py-2 px-2 text-center"><?= $genDisplay ?></td>
                                    <td class="py-2 px-3 text-center"><?= $status_ui ?></td>
                                    <td class="py-2 px-3 text-right font-mono <?= $bal_class ?>"><?= $bal_text ?></td>
                                    <td class="py-1 px-3 bg-slate-50 border-l border-slate-200">
                                        <?php if ($can_edit): ?>
                                            <form method="POST" class="spa-form flex items-center gap-1.5 m-0 p-0 w-max">
                                                <input type="hidden" name="student_email" value="<?= htmlspecialchars($student['email']) ?>">
                                                <input type="hidden" name="user_internal_id" value="<?= htmlspecialchars($student['id']) ?>">
                                                <input type="hidden" name="semester_context" value="<?= htmlspecialchars($student['semester']) ?>">
                                                <input type="hidden" name="sy_context" value="<?= htmlspecialchars(isset($student['school_year']) ? $student['school_year'] : $active_school_year) ?>">

                                                <select name="program_name" class="border border-slate-300 rounded text-xs font-semibold text-slate-800 h-7 px-1 w-24 focus:outline-none focus:ring-1 focus:ring-emerald-500 bg-white cursor-pointer">
                                                    <?php foreach ($prog_list as $p): ?>
                                                        <option value="<?= htmlspecialchars($p) ?>" <?= ($student['display_program'] === $p) ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                                                    <?php endforeach; ?>
                                                </select>

                                                <select name="year_level" class="border border-slate-300 rounded text-xs font-semibold text-slate-800 h-7 px-1 w-20 focus:outline-none focus:ring-1 focus:ring-emerald-500 bg-white cursor-pointer">
                                                    <?php foreach ($year_list as $y): ?>
                                                        <option value="<?= htmlspecialchars($y) ?>" <?= ($student['display_year'] === $y) ? 'selected' : '' ?>><?= htmlspecialchars($y) ?></option>
                                                    <?php endforeach; ?>
                                                </select>

                                                <select name="student_status" class="border border-slate-300 rounded text-xs font-semibold text-slate-800 h-7 px-1 w-16 focus:outline-none focus:ring-1 focus:ring-emerald-500 bg-white cursor-pointer">
                                                    <option value="Regular" <?= ($student['student_status'] === 'Regular') ? 'selected' : '' ?>>Reg</option>
                                                    <option value="Irregular" <?= ($student['student_status'] === 'Irregular') ? 'selected' : '' ?>>Irreg</option>
                                                </select>

                                                <?php $sec_val = $student['assigned_section'] !== 'UNASSIGNED' ? htmlspecialchars($student['assigned_section']) : ''; ?>
                                                <input type="text" name="section_name" placeholder="Sec" value="<?= $sec_val ?>" class="border border-slate-300 rounded text-xs font-bold text-slate-900 text-center uppercase h-7 w-12 focus:outline-none focus:ring-1 focus:ring-emerald-500 bg-white" required>

                                                <button type="submit" name="update_placement" value="1" class="bg-emerald-700 hover:bg-emerald-800 text-white rounded px-3 h-7 text-[10px] font-bold uppercase tracking-wider shadow-sm cursor-pointer transition">Save</button>
                                                <button type="button" data-profile="<?= $single_student_json ?>" onclick="window.openStudentModal(this)" class="bg-white hover:bg-slate-100 border border-slate-300 text-slate-700 rounded px-3 h-7 text-[10px] font-bold uppercase tracking-wider shadow-sm cursor-pointer ml-1 transition flex items-center gap-1">Academics</button>
                                            </form>
                                        <?php else: ?>
                                            <button type="button" data-profile="<?= $single_student_json ?>" onclick="window.openStudentModal(this)" class="bg-white hover:bg-slate-100 border border-slate-300 text-slate-700 rounded px-3 h-7 text-xs font-bold uppercase tracking-wider shadow-sm cursor-pointer transition">View Academics</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- CARD FOOTER -->
                <div class="bg-slate-50 px-5 py-3 border-t border-slate-100 text-xs text-slate-500 flex items-center justify-between font-medium">
                    <div class="flex items-center gap-4">
                        <span><i class="fa-solid fa-mars text-blue-500 mr-1.5"></i> Male: <?= $sec['males'] ?></span>
                        <span><i class="fa-solid fa-venus text-pink-500 mr-1.5"></i> Female: <?= $sec['females'] ?></span>
                        <?php if ($sec['balances'] > 0): ?>
                            <span class="text-rose-700 font-bold"><i class="fa-solid fa-circle-exclamation mr-1.5"></i> <?= $sec['balances'] ?> Balances</span>
                        <?php endif; ?>
                    </div>
                    <div class="text-sm">
                        Total: <strong class="text-slate-900"><?= $enrolled_count ?></strong> Enrolled
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    echo '</div>';
    return ob_get_clean();
}

// =========================================================
// ASYNC ACADEMIC RECORDS API (DUAL ENDPOINTS)
// =========================================================
if (isset($_GET['fetch_academics'])) {
    if (isset($_GET['student_id'])) {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json');
        
        $student_id = $conn->real_escape_string($_GET['student_id']);
        $prog = isset($_GET['program']) ? $conn->real_escape_string($_GET['program']) : '';
        $year = isset($_GET['year_level']) ? $conn->real_escape_string($_GET['year_level']) : '';
        $sem  = isset($_GET['semester']) ? $conn->real_escape_string($_GET['semester']) : '';
        
        $grades = [];
        $sy_q = $conn->query("SELECT setting_value FROM portal_settings WHERE setting_key = 'active_school_year'");
        $sy = ($sy_q && $sy_q->num_rows > 0) ? $sy_q->fetch_assoc()['setting_value'] : 'Not Assigned';
        $error_msg = "";
        
        try {
            $query = "
                SELECT 
                    p.course_code as subject_code, 
                    p.descriptive_title as subject_description, 
                    p.units, 
                    p.semester, 
                    p.year_level,
                    sg.grade, 
                    CONCAT(tp.first_name, ' ', tp.last_name) as teacher_name
                FROM prospectus p
                JOIN programs pr ON p.program_id = pr.program_id
                LEFT JOIN student_grades sg ON p.course_code = sg.subject_code AND sg.student_id = '$student_id'
                LEFT JOIN users tu ON p.teacher_id = tu.id
                LEFT JOIN user_profiles tp ON tu.id = tp.user_id
                WHERE pr.program_name = '$prog' 
                  AND p.year_level = '$year' 
                  AND p.semester = '$sem'
                  AND (p.is_archived = 0 OR p.is_archived IS NULL)
                  
                UNION
                
                SELECT 
                    sg.subject_code, 
                    sg.subject_description, 
                    sg.units, 
                    sg.semester, 
                    'Credited' as year_level,
                    sg.grade, 
                    CONCAT(tp2.first_name, ' ', tp2.last_name) as teacher_name
                FROM student_grades sg
                LEFT JOIN prospectus p2 ON sg.subject_code = p2.course_code
                LEFT JOIN users tu2 ON p2.teacher_id = tu2.id
                LEFT JOIN user_profiles tp2 ON tu2.id = tp2.user_id
                WHERE sg.student_id = '$student_id'
            ";
                              
            $q = @$conn->query($query);
            if ($q && $q->num_rows > 0) {
                while ($row = $q->fetch_assoc()) {
                    $grades[] = $row;
                }
            }
            
            echo json_encode([
                'status' => 'success', 
                'school_year' => $sy, 
                'subjects' => $grades,
                'debug_msg' => $error_msg
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit();
    }
}

// FULL HISTORY ACADEMIC API
if (isset($_GET['fetch_full_academics'])) {
    if (isset($_GET['student_id'])) {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json');

        $student_id = $conn->real_escape_string($_GET['student_id']);
        $user_internal_id = isset($_GET['user_internal_id']) ? (int)$_GET['user_internal_id'] : 0;
        $history = [];
        
        if ($user_internal_id > 0) {
            $q1 = $conn->query("
                SELECT 
                    p.year_level, p.semester, p.course_code as subject_code, p.descriptive_title as subject_description, p.units,
                    e.grade as enrollment_grade, sg.grade as sg_grade, CONCAT(tp.first_name, ' ', tp.last_name) as teacher_name
                FROM enrollments e
                JOIN prospectus p ON e.subject_id = p.id
                LEFT JOIN student_grades sg ON p.course_code = sg.subject_code AND sg.student_id = '$student_id'
                LEFT JOIN users tu ON p.teacher_id = tu.id
                LEFT JOIN user_profiles tp ON tu.id = tp.user_id
                WHERE e.student_id = $user_internal_id
            ");
            if ($q1) {
                while($row = $q1->fetch_assoc()) {
                    $grade = !empty($row['sg_grade']) ? $row['sg_grade'] : $row['enrollment_grade'];
                    $history[] = [
                        'year_level' => $row['year_level'],
                        'semester' => $row['semester'],
                        'subject_code' => $row['subject_code'],
                        'subject_description' => $row['subject_description'],
                        'units' => $row['units'],
                        'grade' => $grade,
                        'teacher_name' => $row['teacher_name']
                    ];
                }
            }
        }
        
        $q2 = $conn->query("
            SELECT 
                COALESCE(p.year_level, 'Credited/Past') as year_level, COALESCE(sg.semester, p.semester, 'N/A') as semester,
                sg.subject_code, sg.subject_description, sg.units, sg.grade, CONCAT(tp.first_name, ' ', tp.last_name) as teacher_name
            FROM student_grades sg
            LEFT JOIN prospectus p ON sg.subject_code = p.course_code
            LEFT JOIN users tu ON p.teacher_id = tu.id
            LEFT JOIN user_profiles tp ON tu.id = tp.user_id
            WHERE sg.student_id = '$student_id'
        ");
        if ($q2) {
            while($row = $q2->fetch_assoc()) {
                $is_dup = false;
                foreach($history as $h) { 
                    if ($h['subject_code'] === $row['subject_code']) { 
                        $is_dup = true; 
                        break; 
                    } 
                } 
                if (!$is_dup) {
                    $history[] = $row;
                }
            }
        }

        $year_order = ['1st Year' => 1, '2nd Year' => 2, '3rd Year' => 3, '4th Year' => 4, 'Credited/Past' => 0];
        $sem_order  = ['1st Semester' => 1, '2nd Semester' => 2, 'Summer' => 3, 'N/A' => 0];

        usort($history, function($a, $b) use ($year_order, $sem_order) {
            $yA = isset($year_order[$a['year_level']]) ? $year_order[$a['year_level']] : 99;
            $yB = isset($year_order[$b['year_level']]) ? $year_order[$b['year_level']] : 99;
            if ($yA === $yB) {
                $sA = isset($sem_order[$a['semester']]) ? $sem_order[$a['semester']] : 99;
                $sB = isset($sem_order[$b['semester']]) ? $sem_order[$b['semester']] : 99;
                if ($sA == $sB) return 0;
                return ($sA > $sB) ? 1 : -1;
            }
            if ($yA == $yB) return 0;
            return ($yA > $yB) ? 1 : -1;
        });

        echo json_encode(['status' => 'success', 'history' => $history]);
        exit();
    }
}

// =========================================================
// ASYNC GRADES GRID API
// =========================================================
if (isset($_GET['fetch_section_grades'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    $prog = $conn->real_escape_string($_GET['program']);
    $year = $conn->real_escape_string($_GET['year_level']);
    $sem  = $conn->real_escape_string($_GET['semester']);
    $sec  = $conn->real_escape_string($_GET['section']);
    $sy   = isset($_GET['school_year']) ? $conn->real_escape_string($_GET['school_year']) : $safe_year;

    $subjects = [];
    $sub_q = $conn->query("SELECT p.id, p.course_code, p.descriptive_title, p.units FROM prospectus p JOIN programs pr ON p.program_id = pr.program_id WHERE pr.program_name = '$prog' AND p.year_level = '$year' AND p.semester = '$sem' AND (p.is_archived = 0 OR p.is_archived IS NULL) ORDER BY p.course_code ASC");
    if ($sub_q) {
        while($row = $sub_q->fetch_assoc()) {
            $subjects[] = $row;
        }
    }

    $grades = [];
    $grade_q = $conn->query("
        SELECT e.student_id as user_id, p.course_code, e.grade 
        FROM enrollments e 
        JOIN prospectus p ON e.subject_id = p.id
        JOIN enrollment_requests er ON e.student_id = er.user_id
        WHERE er.program = '$prog' AND er.year_level = '$year' AND er.assigned_section = '$sec' AND er.semester = '$sem' AND er.school_year = '$sy'
    ");
    if ($grade_q) {
        while($row = $grade_q->fetch_assoc()) {
            $grades[$row['user_id']][$row['course_code']] = $row['grade'];
        }
    }
    
    $fallback_q = $conn->query("
        SELECT u.id as user_id, sg.subject_code, sg.grade
        FROM student_grades sg
        JOIN users u ON sg.student_id = u.student_id
        JOIN enrollment_requests er ON u.id = er.user_id
        WHERE er.program = '$prog' AND er.year_level = '$year' AND er.assigned_section = '$sec' AND er.semester = '$sem' AND er.school_year = '$sy'
    ");
    if ($fallback_q) {
        while($row = $fallback_q->fetch_assoc()) {
            if (empty($grades[$row['user_id']][$row['subject_code']])) {
                if (!empty($row['grade'])) {
                    $grades[$row['user_id']][$row['subject_code']] = $row['grade'];
                }
            }
        }
    }

    echo json_encode([
        'status' => 'success',
        'subjects' => $subjects,
        'grades' => $grades
    ]);
    exit();
}

// =========================================================
// ASYNC JSON DATA ENDPOINTS (FOR LIVE REFRESH - FULLY CONNECTED)
// =========================================================
if (isset($_GET['api_refresh'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    echo json_encode([
        'status'             => 'success',
        'roster_html'        => renderMasterRoster($sections, $prog_list, $year_list, $can_edit, $safe_sem, $default_section_limit, $active_school_year, $safe_sec),
        'total_enrolled'     => $total_students,
        'active_sections'    => count($sections),
        'male_count'         => $total_males,
        'female_count'       => $total_females,
        'gender_ratio'       => ($total_students > 0 ? round(($total_males / $total_students) * 100) : 0) . '% M / ' . ($total_students > 0 ? round(($total_females / $total_students) * 100) : 0) . '% F',
        'reg_count'          => $total_regular,
        'irreg_count'        => $total_irreg,
        'total_balance'      => $total_with_balance,
        'available_sections' => $sec_dropdown_list
    ]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Roster - LDSP Registrar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../Style.css?v=<?= time(); ?>">
    <style>
        .modal-overlay { opacity: 0; transition: opacity 0.2s ease; pointer-events: none; }
        .modal-content { transform: scale(0.97); opacity: 0; transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); pointer-events: none; }
        .modal-active.modal-overlay { opacity: 1; pointer-events: auto; }
        .modal-active .modal-content { transform: scale(1); opacity: 1; pointer-events: auto; }
        
        .excel-table th, .excel-table td {
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #e2e8f0;
        }
        .excel-table th:last-child, .excel-table td:last-child {
            border-right: none;
        }
        .custom-scrollbar::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="flex h-screen overflow-hidden antialiased bg-[#f4f6f9]">

    <div class="ambient-orb-1"></div>
    <div class="ambient-orb-2"></div>

    <!-- SIDEBAR CONNECTION -->
    <?php include 'sidebar.php'; ?>

    <main id="mainScrollArea" class="flex-1 min-w-0 overflow-y-auto h-full w-full pt-16 md:pt-0 relative custom-scrollbar z-10">
        <div class="p-3 md:p-5 lg:p-6 max-w-[1600px] mx-auto relative z-20 space-y-4">

            <!-- 1. HEADER & GLOBAL AUDIT BAR -->
            <header class="border-b border-slate-200/80 pb-3 flex flex-col md:flex-row justify-between md:items-end gap-2 drop-shadow-sm fade-in-up">
                <div>
                    <div class="flex items-center gap-2.5 mb-0.5">
                        <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded bg-emerald-100 text-emerald-800">
                            Registrar Division
                        </span>
                        <span class="text-xs text-slate-500 font-medium flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.8)]"></span>
                            Logged in as <?= htmlspecialchars(isset($_SESSION['first_name']) ? $_SESSION['first_name'] : 'Registrar') ?>
                        </span>
                    </div>
                    <h1 class="text-xl md:text-2xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">Master Class Roster</h1>
                    <p class="text-slate-500 text-xs font-medium">Official academic enrollment lists, section limits, student transfers, and grading evaluations.</p>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button" onclick="window.print()" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold uppercase tracking-wider rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 shadow-sm transition cursor-pointer">
                        <i class="fa-solid fa-print text-slate-400"></i> Print Page
                    </button>
                </div>
            </header>

            <!-- 2. COMPACT BALANCED METRIC SUMMARY CARDS -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 fade-in-up">
                <div class="bg-white p-2.5 px-3.5 rounded-lg border border-slate-200 shadow-sm">
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Enrolled</div>
                    <div id="stat_total_enrolled" class="text-xl font-bold text-slate-900 mt-0.5"><?= number_format($total_students) ?></div>
                    <div class="text-[10px] text-slate-400 mt-0.5">Students in selected term</div>
                </div>
                <div class="bg-white p-2.5 px-3.5 rounded-lg border border-slate-200 shadow-sm">
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Active Sections</div>
                    <div id="stat_active_sections" class="text-xl font-bold text-emerald-600 mt-0.5"><?= count($sections) ?></div>
                    <div class="text-[10px] text-slate-400 mt-0.5">Classes configured</div>
                </div>
                <div class="bg-white p-2.5 px-3.5 rounded-lg border border-slate-200 shadow-sm">
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Gender Balance</div>
                    <div class="text-xs font-semibold text-slate-700 mt-1 flex items-center justify-between">
                        <span><i class="fa-solid fa-mars text-blue-500 mr-1"></i> M: <span id="stat_male_count"><?= $total_males ?></span></span>
                        <span><i class="fa-solid fa-venus text-pink-500 mr-1"></i> F: <span id="stat_female_count"><?= $total_females ?></span></span>
                    </div>
                    <div id="stat_gender_ratio" class="text-[10px] text-slate-400 mt-0.5">Ratio: <?= $total_students > 0 ? round(($total_males / $total_students) * 100) : 0 ?>% M / <?= $total_students > 0 ? round(($total_females / $total_students) * 100) : 0 ?>% F</div>
                </div>
                <div class="bg-white p-2.5 px-3.5 rounded-lg border border-slate-200 shadow-sm">
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Standing Distribution</div>
                    <div class="text-xs font-semibold text-slate-700 mt-1 flex items-center justify-between">
                        <span id="stat_reg_count" class="text-emerald-700 font-bold">Reg: <?= $total_regular ?></span>
                        <span id="stat_irreg_count" class="text-amber-700 font-bold">Irreg: <?= $total_irreg ?></span>
                    </div>
                    <div class="text-[10px] text-slate-400 mt-0.5">Classification split</div>
                </div>
            </div>

            <!-- COLLAPSIBLE FILTERS & CONSOLE (DROPBOX) -->
            <details class="group bg-white border border-slate-200 shadow-sm rounded-xl relative z-30 fade-in-up" open>
                <summary class="flex justify-between items-center font-bold cursor-pointer list-none p-3.5 px-4 hover:bg-slate-50 transition-colors [&::-webkit-details-marker]:hidden rounded-xl">
                    <span class="flex items-center gap-2 text-sm text-[#00205b] uppercase tracking-wider">
                        <i class="fa-solid fa-sliders text-emerald-600"></i> Filters & Enrollment Console
                    </span>
                    <span class="transition-transform duration-300 group-open:-rotate-180 text-slate-400">
                        <i class="fa-solid fa-chevron-down"></i>
                    </span>
                </summary>
                
                <div class="p-3 bg-[#f4f6f9] border-t border-slate-200 space-y-3 rounded-b-xl shadow-inner">
                    <!-- 3. UNIFIED SLIM FILTER CONTROL BAR WITH SECTION DROPDOWN -->
                    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-2.5 px-3.5">
                        <form id="masterFilterForm" method="GET" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5 items-end">
                            
                            <!-- School Year -->
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Academic Year</label>
                                <select id="f_year" name="f_year" onchange="window.handleYearFilterChange(this)" data-last-value="<?= htmlspecialchars($f_year) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-2.5 py-1 text-xs font-bold text-[#00205b] focus:ring-1 focus:ring-emerald-500 focus:outline-none h-8 cursor-pointer">
                                    <?php foreach ($recent_years as $yr): ?>
                                        <option value="<?= htmlspecialchars($yr) ?>" <?= $f_year === $yr ? 'selected' : '' ?>>
                                            A.Y. <?= htmlspecialchars($yr) ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if (!array_key_exists($f_year, $recent_years) && $f_year !== 'All'): ?>
                                        <option value="<?= htmlspecialchars($f_year) ?>" selected>A.Y. <?= htmlspecialchars($f_year) ?></option>
                                    <?php endif; ?>
                                    <option value="open_all_years_modal" class="font-bold text-emerald-700 bg-emerald-50">
                                        ↳ Archive / All Years...
                                    </option>
                                </select>
                            </div>

                            <!-- Semester -->
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Semester</label>
                                <select id="f_semester" name="f_semester" onchange="window.triggerDynamicFetch(true)" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-2.5 py-1 text-xs font-bold text-[#00205b] focus:ring-1 focus:ring-emerald-500 focus:outline-none h-8 cursor-pointer">
                                    <?php foreach ($sem_list as $sem): ?>
                                        <option value="<?= htmlspecialchars($sem) ?>" <?= $f_semester === $sem ? 'selected' : '' ?>><?= htmlspecialchars($sem) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Program -->
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Program</label>
                                <select id="f_program" name="f_program" onchange="window.triggerDynamicFetch(true)" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-2.5 py-1 text-xs font-semibold text-slate-800 focus:ring-1 focus:ring-emerald-500 focus:outline-none h-8 cursor-pointer">
                                    <option value="All">All Programs</option>
                                    <?php foreach ($prog_list as $prog): ?>
                                        <option value="<?= htmlspecialchars($prog) ?>" <?= $f_program === $prog ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($prog) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Year Level -->
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Year Level</label>
                                <select id="f_year_lvl" name="f_year_lvl" onchange="window.triggerDynamicFetch(true)" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-2.5 py-1 text-xs font-semibold text-slate-800 focus:ring-1 focus:ring-emerald-500 focus:outline-none h-8 cursor-pointer">
                                    <option value="All">All Year Levels</option>
                                    <?php foreach ($year_list as $y): ?>
                                        <option value="<?= htmlspecialchars($y) ?>" <?= $f_year_lvl === $y ? 'selected' : '' ?>><?= htmlspecialchars($y) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Section Dropdown (Connected to Real DB Data) -->
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Section</label>
                                <select id="f_section" name="f_section" onchange="window.triggerDynamicFetch(true)" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-2.5 py-1 text-xs font-semibold text-slate-800 focus:ring-1 focus:ring-emerald-500 focus:outline-none h-8 cursor-pointer">
                                    <?php if (empty($sec_dropdown_list)): ?>
                                        <option value="">No Sections Available</option>
                                    <?php else: ?>
                                        <option value="">Please select a section</option>
                                        <?php foreach ($sec_dropdown_list as $sec_opt): ?>
                                            <option value="<?= htmlspecialchars($sec_opt) ?>" <?= $f_section === $sec_opt ? 'selected' : '' ?>>
                                                Section <?= htmlspecialchars($sec_opt) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <!-- Live Quick Search -->
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Search Student</label>
                                <div class="relative">
                                    <input type="text" id="rosterSearch" placeholder="Name or ID..." oninput="window.filterRosterCards()" class="w-full bg-slate-50 border border-slate-300 rounded-lg pl-8 pr-2.5 py-1 text-xs font-medium text-slate-900 focus:ring-1 focus:ring-emerald-500 focus:outline-none h-8">
                                    <i class="fa-solid fa-search absolute left-2.5 top-2.5 text-slate-400 text-xs"></i>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- 4. SLIM WORKBOOK CONSOLE BAR -->
                    <div class="bg-white border border-slate-200 rounded-xl flex flex-col xl:flex-row items-start xl:items-center justify-between shadow-sm relative z-20 overflow-hidden">
                        <div class="p-2.5 px-4 flex items-center gap-3 w-full xl:w-auto border-l-4 border-emerald-600 bg-emerald-50/20">
                            <div class="w-8 h-8 rounded-lg bg-emerald-100 flex items-center justify-center text-emerald-800 shrink-0">
                                <svg class="w-4 h-4 drop-shadow-sm" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                            </div>
                            <div>
                                <h3 class="font-bold text-slate-900 text-xs md:text-sm tracking-tight">Active Enrollment Console</h3>
                                <p id="active_console_label" class="text-[11px] text-slate-500 font-medium">A.Y. <?= htmlspecialchars($f_year) ?> &bull; <?= htmlspecialchars($safe_sem) ?><?= ($f_section !== '' ? ' &bull; Section ' . htmlspecialchars($f_section) : '') ?></p>
                            </div>
                        </div>
                        
                        <div class="p-2 px-4 flex flex-col sm:flex-row items-center justify-start xl:justify-end gap-2.5 w-full xl:w-auto border-t xl:border-t-0 xl:border-l border-slate-200">
                            <form method="POST" class="spa-form flex items-center gap-1.5 bg-slate-50 border border-slate-200 p-1 rounded-md shadow-inner w-full sm:w-auto">
                                <input type="hidden" name="update_global_limit" value="1">
                                <label class="text-[10px] font-bold uppercase tracking-wider text-slate-500 pl-1 whitespace-nowrap">Global Max:</label>
                                <input type="number" name="global_limit_value" value="<?= $default_section_limit ?>" min="1" class="w-12 h-6 text-center text-xs font-bold text-slate-900 border border-slate-300 rounded bg-white shadow-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                <button type="submit" class="bg-white border border-slate-300 text-slate-700 hover:text-emerald-700 hover:bg-slate-100 h-6 px-1.5 rounded flex items-center justify-center transition shadow-sm cursor-pointer" title="Save Global Limit">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                </button>
                            </form>
                            
                            <div class="flex gap-2 w-full sm:w-auto">
                                <div id="roster_balance_badge" class="<?= $total_with_balance > 0 ? '' : 'hidden' ?> px-2.5 py-1 bg-amber-50 border border-amber-200 text-amber-800 rounded-md text-xs font-bold shadow-sm flex items-center gap-1 flex-1 sm:flex-none justify-center">
                                    <svg class="w-3.5 h-3.5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                    <span id="roster_balance_text"><?= $total_with_balance ?> Balances</span>
                                </div>
                                <div id="roster_total_badge" class="px-2.5 py-1 bg-slate-900 text-white rounded-md text-xs font-bold shadow-sm flex-1 sm:flex-none text-center flex items-center justify-center">
                                    <span id="roster_total_text"><?= $total_students ?> Registered</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </details>

            <!-- 5. MAIN WORKBOOK CONTAINER -->
            <div id="master_roster_container" class="relative z-20 fade-in-up">
                <?= renderMasterRoster($sections, $prog_list, $year_list, $can_edit, $safe_sem, $default_section_limit, $active_school_year, $safe_sec) ?>
            </div>

        </div>
    </main>

    <!-- ALL YEARS FLOATING CARD MODAL -->
    <div id="allYearsModal" class="fixed inset-0 z-[200] hidden items-center justify-center p-4 modal-overlay bg-slate-900/50 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl border-t-4 border-t-[#00205b] w-full max-w-sm modal-content relative">
            <div class="px-5 py-3.5 border-b border-slate-200 flex justify-between items-center bg-white rounded-t-2xl">
                <h3 class="font-bold text-[#00205b] text-xs uppercase tracking-wider flex items-center gap-2">
                    <svg class="w-4 h-4 text-[#c5a02c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2-2v12a2 2 0 002 2z"/></svg>
                    Academic Years Archive
                </h3>
                <button type="button" onclick="window.closeAllYearsModal()" class="text-slate-400 hover:text-rose-500 bg-slate-50 hover:bg-rose-50 p-1 rounded-lg transition focus:outline-none cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="p-4 max-h-[60vh] overflow-y-auto custom-scrollbar">
                <div class="grid grid-cols-2 gap-2">
                    <?php foreach ($years_list as $yr): 
                        $safe_yr = htmlspecialchars($yr);
                        $id_yr = str_replace('-', '_', $safe_yr);
                        $isActive = ($f_year === $safe_yr);
                    ?>
                        <button type="button" id="btn_year_<?= $id_yr ?>" onclick="window.selectYearFilter('<?= $safe_yr ?>')" class="year-btn px-2.5 py-1.5 border <?= $isActive ? 'border-emerald-600 bg-emerald-50 text-emerald-800 font-bold' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 font-semibold' ?> rounded-lg text-xs transition shadow-sm cursor-pointer">
                            <?= $safe_yr ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION LIMIT MODAL -->
    <div id="sectionLimitModal" class="fixed inset-0 hidden items-center justify-center p-4 modal-overlay bg-slate-900/60 backdrop-blur-sm z-[100]">
        <div class="bg-white border-t-4 border-t-indigo-600 rounded-2xl shadow-2xl w-full max-w-sm modal-content relative">
            <div class="p-4 border-b border-slate-200 flex justify-between items-center bg-white rounded-t-2xl">
                <h3 class="font-bold text-slate-900 text-xs uppercase tracking-wide flex items-center gap-2">
                    <div class="p-1 rounded-md bg-indigo-100 text-indigo-700"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg></div>
                    Set Section Limit
                </h3>
                <button type="button" onclick="window.closeModal('sectionLimitModal')" class="text-slate-400 hover:text-rose-500 transition bg-slate-50 hover:bg-rose-50 p-1 rounded-lg focus:outline-none cursor-pointer">
                    <svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
            <form method="POST" class="spa-form p-5 space-y-3.5 bg-slate-50 rounded-b-2xl">
                <input type="hidden" name="limit_semester" id="limit_semester">
                <input type="hidden" name="limit_program" id="limit_program">
                <input type="hidden" name="limit_year" id="limit_year">
                <input type="hidden" name="limit_section" id="limit_section">
                
                <div class="bg-white border border-slate-200 p-3 shadow-sm text-center rounded-lg">
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">Target Section</div>
                    <div id="limit_display" class="font-bold text-[#00205b] text-xs"></div>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5 text-center">Maximum Student Capacity</label>
                    <input type="number" name="new_limit" id="limit_input" required min="1" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-base font-mono font-bold text-slate-900 text-center shadow-inner focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>
                <div class="flex gap-2 mt-4 pt-2.5 border-t border-slate-200">
                    <button type="button" onclick="window.closeModal('sectionLimitModal')" class="flex-1 bg-white hover:bg-slate-100 text-slate-700 border border-slate-300 px-3 py-2 rounded-lg text-xs font-bold uppercase tracking-wider shadow-sm transition cursor-pointer">Cancel</button>
                    <button type="submit" name="update_section_limit" value="1" class="flex-1 bg-emerald-700 hover:bg-emerald-800 text-white shadow-md px-3 py-2 rounded-lg text-xs font-bold uppercase tracking-wider cursor-pointer transition">Save Limit</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MASS MOVE MODAL -->
    <div id="massMoveModal" class="fixed inset-0 hidden items-center justify-center p-4 modal-overlay bg-slate-900/60 backdrop-blur-sm z-[100]">
        <div class="bg-white border-t-4 border-t-emerald-600 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col modal-content relative">
            <div class="p-4 border-b border-slate-200 flex justify-between items-center sticky top-0 bg-white rounded-t-2xl shadow-sm z-20">
                <h3 class="font-bold text-slate-900 text-xs uppercase tracking-wide flex items-center gap-2">
                    <div class="p-1 rounded-md bg-emerald-100 text-emerald-700"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg></div>
                    Transfer Students (Mass Move)
                </h3>
                <button type="button" onclick="window.closeModal('massMoveModal')" class="text-slate-400 hover:text-rose-500 transition bg-slate-50 hover:bg-rose-50 p-1 rounded-lg focus:outline-none cursor-pointer">
                    <svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
            <form method="POST" class="spa-form flex flex-col flex-1 overflow-hidden bg-slate-50 rounded-b-2xl">
                <input type="hidden" name="move_semester" id="move_semester">
                <input type="hidden" name="move_program" id="move_program">
                <input type="hidden" name="move_year" id="move_year">
                <input type="hidden" name="move_sy" id="move_sy">
                
                <div class="p-4 border-b border-slate-200 bg-white">
                    <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 shadow-sm space-y-2.5">
                        <div>
                            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">Origin Section</div>
                            <div id="move_from_display" class="font-bold text-[#00205b] text-xs"></div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 pt-2 border-t border-slate-200">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-700 uppercase tracking-wider mb-1">Target Program</label>
                                <select name="new_program_name" id="new_program_name" class="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-900 focus:outline-none focus:ring-1 focus:ring-emerald-500 cursor-pointer">
                                    <?php foreach ($prog_list as $prog): ?>
                                        <option value="<?= htmlspecialchars($prog) ?>"><?= htmlspecialchars($prog) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-700 uppercase tracking-wider mb-1">Target Section Name</label>
                                <input type="text" name="new_section_name" required placeholder="e.g. 1-B" class="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs font-bold text-slate-900 text-center uppercase focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="p-4 flex-1 overflow-y-auto custom-scrollbar">
                    <div class="flex items-center justify-between mb-2 border-b border-slate-200 pb-1.5">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Select Students to Move</span>
                        <label class="flex items-center gap-1.5 cursor-pointer group">
                            <input type="checkbox" id="selectAllStudentsBtn" onchange="window.toggleAllMoveStudents(this)" class="w-3.5 h-3.5 text-emerald-600 bg-white border-slate-300 rounded focus:ring-emerald-500 cursor-pointer">
                            <span class="text-xs font-semibold text-slate-600 group-hover:text-emerald-700">Select All</span>
                        </label>
                    </div>
                    <div id="move_students_list" class="space-y-1"></div>
                </div>
                
                <div class="p-3 px-5 bg-white border-t border-slate-200 flex gap-2.5 sticky bottom-0 rounded-b-2xl">
                    <button type="button" onclick="window.closeModal('massMoveModal')" class="flex-1 bg-white hover:bg-slate-100 text-slate-700 border border-slate-300 px-3 py-2 rounded-lg text-xs font-bold uppercase tracking-wider shadow-sm transition cursor-pointer">Cancel</button>
                    <button type="submit" name="mass_move_section" value="1" class="flex-1 bg-emerald-700 hover:bg-emerald-800 text-white shadow-md px-3 py-2 rounded-lg text-xs font-bold uppercase tracking-wider cursor-pointer transition">Confirm Move</button>
                </div>
            </form>
        </div>
    </div>

    <!-- SECTION MASTERLIST MODAL -->
    <div id="sectionMasterlistModal" class="fixed inset-0 hidden items-center justify-center p-4 modal-overlay bg-slate-900/60 backdrop-blur-sm z-[100]">
        <div class="bg-white border-t-4 border-t-[#c5a02c] rounded-2xl shadow-2xl w-full max-w-6xl max-h-[90vh] flex flex-col modal-content relative">
            <div class="p-4 border-b border-slate-200 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2.5 sticky top-0 bg-white rounded-t-2xl shadow-sm z-20">
                <div>
                    <h3 class="font-bold text-slate-900 text-xs uppercase tracking-wide flex items-center gap-2">
                        <div class="p-1 rounded-md bg-amber-100 text-amber-800"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg></div> 
                        Official Class Masterlist
                    </h3>
                    <div id="masterlist_subtitle" class="text-xs font-medium text-slate-500 mt-0.5 ml-7"></div>
                </div>
                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <select id="masterlistSort" onchange="window.sortMasterlist()" class="bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-800 cursor-pointer shadow-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="alpha_asc">Sort: A-Z</option>
                        <option value="alpha_desc">Sort: Z-A</option>
                        <option value="date_new">Sort: Newest</option>
                        <option value="date_old">Sort: Oldest</option>
                        <option value="balance_desc">Sort: Highest Balance</option>
                    </select>
                    <button onclick="window.downloadMasterlistCSV()" class="bg-emerald-700 hover:bg-emerald-800 text-white px-3 py-1.5 rounded-lg text-xs font-bold uppercase tracking-wider shadow-sm transition flex items-center gap-1 cursor-pointer">
                        Export CSV <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                    </button>
                    <button onclick="window.closeModal('sectionMasterlistModal')" class="text-slate-400 hover:text-rose-500 transition bg-slate-50 hover:bg-rose-50 p-1.5 rounded-lg focus:outline-none cursor-pointer">
                        <svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </div>
            <div class="flex-1 overflow-auto custom-scrollbar bg-slate-50 rounded-b-2xl">
                <table id="masterlistTable" class="w-full text-left text-xs border-collapse whitespace-nowrap">
                    <thead class="bg-white text-slate-800 uppercase font-bold tracking-wider border-b border-slate-300 sticky top-0 z-30 shadow-sm text-[11px]">
                        <tr>
                            <th class="px-3 py-2 border-r border-slate-200 w-10 text-center">#</th>
                            <th class="px-3 py-2 border-r border-slate-200">ID Number</th>
                            <th class="px-3 py-2 border-r border-slate-200">Last Name</th>
                            <th class="px-3 py-2 border-r border-slate-200">First Name</th>
                            <th class="px-3 py-2 border-r border-slate-200">Middle Name</th>
                            <th class="px-3 py-2 border-r border-slate-200 text-center">Gender</th>
                            <th class="px-3 py-2 border-r border-slate-200">Email</th>
                            <th class="px-3 py-2 border-r border-slate-200 text-center">Status</th>
                            <th class="px-3 py-2 border-r border-slate-200">Enrolled At</th>
                            <th class="px-3 py-2 text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody id="masterlist_tbody" class="divide-y divide-slate-200 bg-white">
                        <!-- Populated by JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- SECTION GRADES GRID MODAL -->
    <div id="sectionGradesModal" class="fixed inset-0 hidden items-center justify-center p-4 modal-overlay bg-slate-900/60 backdrop-blur-sm z-[100]">
        <div class="bg-white border-t-4 border-t-emerald-600 rounded-2xl shadow-2xl w-full max-w-[95vw] lg:max-w-7xl max-h-[90vh] flex flex-col modal-content relative">
            <div class="p-4 border-b border-slate-200 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2.5 sticky top-0 bg-white rounded-t-2xl shadow-sm z-20">
                <div>
                    <h3 class="font-bold text-slate-900 text-xs uppercase tracking-wide flex items-center gap-2">
                        <div class="p-1 rounded-md bg-emerald-100 text-emerald-800"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg></div> 
                        Section Grades Evaluation Sheet
                    </h3>
                    <div id="gradesgrid_subtitle" class="text-xs font-medium text-slate-500 mt-0.5 ml-7"></div>
                </div>
                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <button onclick="window.downloadGradesCSV()" class="bg-emerald-700 hover:bg-emerald-800 text-white px-3 py-1.5 rounded-lg text-xs font-bold uppercase tracking-wider shadow-sm transition flex items-center gap-1 cursor-pointer">
                        Export CSV <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                    </button>
                    <button onclick="window.closeModal('sectionGradesModal')" class="text-slate-400 hover:text-rose-500 transition bg-slate-50 hover:bg-rose-50 p-1.5 rounded-lg focus:outline-none cursor-pointer">
                        <svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </div>
            <div class="flex-1 overflow-auto custom-scrollbar bg-slate-50 rounded-b-2xl relative">
                <div id="gradesLoader" class="absolute inset-0 flex flex-col items-center justify-center bg-white/80 z-40 hidden">
                    <svg class="animate-spin h-6 w-6 text-emerald-600 mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Generating Grades Grid...</span>
                </div>
                <table id="gradesGridTable" class="w-full text-left text-xs border-collapse whitespace-nowrap excel-table bg-white">
                    <thead id="gradesGridThead" class="bg-slate-100 text-slate-800 uppercase font-bold tracking-wider border-b border-slate-300 sticky top-0 z-30 shadow-sm text-[11px]">
                        <!-- Populated by JS -->
                    </thead>
                    <tbody id="gradesGridTbody" class="divide-y divide-slate-200">
                        <!-- Populated by JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- STUDENT PROFILE MODAL WITH DYNAMIC DB AJAX LOAD & TOGGLE -->
    <div id="studentProfileModal" class="fixed inset-0 hidden items-center justify-center p-4 modal-overlay bg-slate-900/70 backdrop-blur-sm z-[100]">
        <div class="bg-white rounded-2xl shadow-2xl border-t-4 border-t-blue-600 w-full max-w-4xl max-h-[95vh] flex flex-col modal-content relative" id="profileModalInner">
            <div class="bg-white border-b border-slate-200 p-4 flex justify-between items-center sticky top-0 rounded-t-2xl shadow-sm z-20">
                <h3 class="font-bold text-slate-900 text-xs uppercase tracking-wide flex items-center gap-2">
                    <div class="p-1 rounded-md bg-blue-100 text-blue-700"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477-4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg></div>
                    Student Profile & Academic Records
                </h3>
                <button onclick="window.closeStudentModal()" class="text-slate-400 hover:text-rose-500 transition bg-slate-50 hover:bg-rose-50 p-1.5 rounded-lg focus:outline-none cursor-pointer">
                    <svg class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
            
            <div class="p-5 flex-1 overflow-y-auto custom-scrollbar bg-slate-50 space-y-4">
                <!-- TOP IDENTITY CARD -->
                <div class="flex flex-col md:flex-row items-start gap-3.5 border-b border-slate-200 pb-3.5">
                    <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center text-blue-700 font-bold text-xl shadow-inner border border-blue-200 shrink-0" id="m_initial">?</div>
                    <div class="flex-1 w-full">
                        <div class="flex flex-col md:flex-row md:justify-between md:items-start gap-2">
                            <div>
                                <h4 class="text-lg font-black text-[#00205b] tracking-tight uppercase" id="m_detail_name">Student Name</h4>
                                <div class="flex flex-wrap items-center gap-1.5 mt-0.5">
                                    <span class="text-xs font-bold text-[#c5a02c] font-mono tracking-wider" id="m_studentid">ID: Pending</span>
                                    <span class="bg-blue-100 text-blue-800 border border-blue-200 px-1.5 py-0.5 rounded text-[10px] uppercase font-bold" id="m_student_type">Status</span>
                                </div>
                            </div>
                            <div class="bg-white p-2 px-3 rounded-lg border border-slate-200 text-right w-full md:w-auto shadow-sm">
                                <p class="text-[9px] font-bold uppercase tracking-wider text-slate-400">Current Placement</p>
                                <p class="text-xs font-bold text-[#00205b]" id="m_prog_year_sec">Program / Year / Sec</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ACADEMIC RECORDS TABLE -->
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="bg-slate-50 border-b border-slate-200 p-2.5 px-3.5 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2">
                        <h5 class="text-xs font-bold uppercase tracking-wider text-emerald-800 flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                            Academic Records
                        </h5>
                        <div class="flex items-center gap-1 bg-slate-200/60 p-0.5 rounded-lg border border-slate-200">
                            <button type="button" id="tab_current_term" class="px-2.5 py-1 bg-emerald-700 text-white text-[10px] font-bold uppercase tracking-wider rounded shadow-sm transition cursor-pointer" onclick="window.loadAcademicTab('current')">Current Term</button>
                            <button type="button" id="tab_full_history" class="px-2.5 py-1 bg-transparent text-slate-600 hover:text-emerald-700 text-[10px] font-bold uppercase tracking-wider rounded transition cursor-pointer" onclick="window.loadAcademicTab('history')">Complete History</button>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs border-collapse whitespace-nowrap">
                            <thead class="bg-slate-100 text-slate-800 uppercase font-bold tracking-wider text-[11px]">
                                <tr>
                                    <th class="px-3 py-2 border-b border-slate-300 w-24">Subject Code</th>
                                    <th class="px-3 py-2 border-b border-slate-300">Descriptive Title</th>
                                    <th class="px-3 py-2 border-b border-slate-300 text-center w-20">Year Level</th>
                                    <th class="px-3 py-2 border-b border-slate-300">Instructor</th>
                                    <th class="px-3 py-2 border-b border-slate-300 text-center w-20">Semester</th>
                                    <th class="px-3 py-2 border-b border-slate-300 text-center w-16">Grade</th>
                                    <th class="px-3 py-2 border-b border-slate-300 text-center w-16">Units</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-xs font-medium" id="m_subjects_table">
                                <!-- Populated dynamically by master_list.js -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm space-y-1.5">
                        <h5 class="text-[10px] font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100 pb-1 mb-1.5">Personal Details</h5>
                        <div class="grid grid-cols-3 gap-1"><span class="text-xs font-semibold text-slate-500">Email:</span><span class="col-span-2 text-xs font-semibold text-slate-800 truncate" id="m_email"></span></div>
                        <div class="grid grid-cols-3 gap-1"><span class="text-xs font-semibold text-slate-500">Gender:</span><span class="col-span-2 text-xs font-semibold text-slate-800" id="m_gender"></span></div>
                        <div class="grid grid-cols-3 gap-1"><span class="text-xs font-semibold text-slate-500">DOB:</span><span class="col-span-2 text-xs font-semibold text-slate-800" id="m_dob"></span></div>
                        <div class="grid grid-cols-3 gap-1"><span class="text-xs font-semibold text-slate-500">Address:</span><span class="col-span-2 text-xs font-semibold text-slate-800 truncate" id="m_address"></span></div>
                    </div>
                    <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm space-y-1.5">
                        <h5 class="text-[10px] font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100 pb-1 mb-1.5">Registration Context</h5>
                        <div class="grid grid-cols-3 gap-1"><span class="text-xs font-semibold text-slate-500">Modality:</span><span class="col-span-2 text-xs font-semibold text-slate-800" id="m_modality"></span></div>
                        <div class="grid grid-cols-3 gap-1"><span class="text-xs font-semibold text-slate-500">Term:</span><span class="col-span-2 text-xs font-semibold text-slate-800" id="m_semester"></span></div>
                        <div class="grid grid-cols-3 gap-1"><span class="text-xs font-semibold text-slate-500">Prev School:</span><span class="col-span-2 text-xs font-semibold text-slate-800 truncate" id="m_school"></span></div>
                        <div class="grid grid-cols-3 gap-1"><span class="text-xs font-semibold text-slate-500">School Yr:</span><span class="col-span-2 text-xs font-semibold text-slate-800" id="m_school_year"></span></div>
                    </div>
                </div>
            </div>
            
            <div class="p-3 px-5 border-t border-slate-200 bg-white sticky bottom-0 text-right rounded-b-2xl">
                <button type="button" onclick="window.closeStudentModal()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold uppercase tracking-wider text-xs px-4 py-2 rounded-lg transition cursor-pointer">Close Profile</button>
            </div>
        </div>
    </div>

    <!-- TOAST NOTIFICATION CONTAINER -->
    <div id="toastContainer" class="fixed top-5 right-5 z-[100] flex flex-col gap-2 pointer-events-none"></div>

    <script src="registrar.js?v=<?= time() ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="master_list.js?v=<?= time() ?>"></script>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof window.initSPAEngine === 'function') {
                window.initSPAEngine();
            }
            
            <?php if (!empty($success_msg)): ?> window.showToast("<?= addslashes($success_msg) ?>", 'success'); <?php endif; ?>
            <?php if (!empty($error_msg)): ?> window.showToast("<?= addslashes($error_msg) ?>", 'error'); <?php endif; ?>
        });
    </script>
</body>
</html>