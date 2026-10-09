<?php
// 1. UNIFIED STAFF SESSION CONFIGURATION
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
// UNLOCKED ACCESS: Admins, Program Heads, AND Registrars
// =========================================================
$allowed_roles = ['admin', 'programhead', 'registrar'];

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../index.php");
    exit();
}

// Strictly enforce edit permissions
$can_edit = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'programhead');

$success_msg = "";
$error_msg = "";

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
// PROCESS PURE PHP ACTIONS (LOCKED BEHIND $can_edit)
// =========================================================
if ($can_edit) {

    // HELPER: Handles returning JSON for AJAX requests instead of refreshing page
    function completeAction($redirect_url) {
        if (isset($_REQUEST['ajax_request']) && $_REQUEST['ajax_request'] == '1') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit();
        }
        header("Location: " . $redirect_url);
        exit();
    }

    // SET AS DEFAULT/PUBLISHED YEAR
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_default_year'])) {
        $year_to_set = trim($conn->real_escape_string($_POST['year_to_set']));
        $target_prog_name = $conn->real_escape_string($active_program_name);
        
        if (!empty($year_to_set) && $active_program_id > 0) {
            $conn->query("INSERT INTO program_defaults (program, active_year) VALUES ('$target_prog_name', '$year_to_set') ON DUPLICATE KEY UPDATE active_year = '$year_to_set'");
            completeAction("manage_prospectus.php?program_id=" . $active_program_id . "&year=" . urlencode($year_to_set) . "&status=active&msg=default_set");
        }
    }

    // CREATE A BRAND NEW YEAR (BLANK SLATE)
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_new_year'])) {
        $new_year = trim($conn->real_escape_string($_POST['new_year'] ?? ''));
        if (!empty($new_year) && $active_program_id > 0) {
            // Check if it should auto-open the builder after reload
            $auto_open = (isset($_POST['auto_open_builder']) && $_POST['auto_open_builder'] == '1') ? '&open_builder=1' : '';
            completeAction("manage_prospectus.php?program_id=" . $active_program_id . "&year=" . urlencode($new_year) . "&status=active&msg=year_created" . $auto_open);
        }
    }

    // IMPORT SUBJECTS FROM OTHER CURRICULUMS
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['import_subjects'])) {
        if (!empty($_POST['selected_import_subjects']) && is_array($_POST['selected_import_subjects'])) {
            $target_year = $conn->real_escape_string($_POST['target_curriculum_year']);
            $ids = array_map('intval', $_POST['selected_import_subjects']);
            $ids_string = implode(',', $ids);
            
            $sql = "INSERT INTO prospectus (program_id, curriculum_year, year_level, semester, course_code, descriptive_title, subject_title, units, prerequisite, instructor, subject_fee, unit_price, unit_cost)
                    SELECT $active_program_id, '$target_year', year_level, semester, course_code, descriptive_title, subject_title, units, prerequisite, instructor, subject_fee, unit_price, unit_cost 
                    FROM prospectus WHERE id IN ($ids_string)";
            
            if($conn->query($sql)) {
                completeAction("manage_prospectus.php?program_id=" . $active_program_id . "&year=" . urlencode($target_year) . "&status=active&msg=imported");
            }
        }
    }

    // RENAME A CURRICULUM YEAR 
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['rename_year'])) {
        $old_year = trim($conn->real_escape_string($_POST['old_year'] ?? ''));
        $new_year = trim($conn->real_escape_string($_POST['new_year'] ?? ''));
        $target_prog_name = $conn->real_escape_string($active_program_name);

        if (!empty($new_year) && !empty($old_year) && $old_year !== $new_year) {
            $stmt1 = $conn->prepare("UPDATE prospectus SET curriculum_year = ? WHERE program_id = ? AND curriculum_year = ?");
            if ($stmt1) { $stmt1->bind_param("sis", $new_year, $active_program_id, $old_year); $stmt1->execute(); }
            
            $stmt3 = $conn->prepare("UPDATE program_defaults SET active_year = ? WHERE program = ? AND active_year = ?");
            if ($stmt3) { $stmt3->bind_param("sss", $new_year, $target_prog_name, $old_year); $stmt3->execute(); }

            completeAction("manage_prospectus.php?program_id=" . $active_program_id . "&year=" . urlencode($new_year) . "&status=active&msg=year_renamed");
        }
    }

    // ADD A NEW SUBJECT
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_subject'])) {
        $target_prog_id = (int)($_POST['target_program_id'] ?? 0);
        $curr_year   = trim($conn->real_escape_string($_POST['curriculum_year'] ?? ''));
        $year_level  = $conn->real_escape_string($_POST['year_level'] ?? '');
        $semester    = $conn->real_escape_string($_POST['semester'] ?? '');
        $course_code = strtoupper(trim($conn->real_escape_string($_POST['course_code'] ?? '')));
        $subj_title  = ucwords(strtolower(trim($conn->real_escape_string($_POST['descriptive_title'] ?? ''))));
        $units       = (int)($_POST['units'] ?? 0);
        $prereq      = trim($conn->real_escape_string($_POST['prerequisite'] ?? ''));
        $instructor  = trim($conn->real_escape_string($_POST['instructor'] ?? ''));
        
        if (empty($prereq)) $prereq = 'None';
        if (empty($instructor)) $instructor = 'TBA';

        if ($target_prog_id > 0 && !empty($curr_year) && !empty($course_code) && !empty($subj_title) && $units > 0) {
            $stmt = $conn->prepare("INSERT INTO prospectus (program_id, curriculum_year, year_level, semester, course_code, descriptive_title, units, prerequisite, instructor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) { 
                $stmt->bind_param("isssssiss", $target_prog_id, $curr_year, $year_level, $semester, $course_code, $subj_title, $units, $prereq, $instructor);
                if ($stmt->execute()) {
                    completeAction("manage_prospectus.php?program_id=" . $target_prog_id . "&year=" . urlencode($curr_year) . "&status=active&msg=added");
                }
                $stmt->close();
            }
        }
    }

    // EDIT A SUBJECT
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_subject'])) {
        $edit_id     = (int)$_POST['subject_id'];
        $course_code = strtoupper(trim($conn->real_escape_string($_POST['course_code'] ?? '')));
        $subj_title  = ucwords(strtolower(trim($conn->real_escape_string($_POST['descriptive_title'] ?? ''))));
        $units       = (int)($_POST['units'] ?? 0);
        $prereq      = trim($conn->real_escape_string($_POST['prerequisite'] ?? ''));
        $instructor  = trim($conn->real_escape_string($_POST['instructor'] ?? ''));

        if (empty($prereq)) $prereq = 'None';
        if (empty($instructor)) $instructor = 'TBA';

        if (!empty($course_code) && !empty($subj_title) && $units > 0) {
            $stmt = $conn->prepare("UPDATE prospectus SET course_code = ?, descriptive_title = ?, units = ?, prerequisite = ?, instructor = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("ssissi", $course_code, $subj_title, $units, $prereq, $instructor, $edit_id);
                if ($stmt->execute()) {
                    $target_year = isset($_GET['year']) ? urlencode($_GET['year']) : '';
                    completeAction("manage_prospectus.php?program_id=" . $active_program_id . "&year=" . $target_year . "&status=active&msg=edited");
                }
                $stmt->close();
            }
        }
    }

    // ARCHIVING, RESTORING, & PERMANENT DELETION
    if (isset($_GET['archive']) && is_numeric($_GET['archive'])) {
        $target_id = (int)$_GET['archive'];
        $conn->query("UPDATE prospectus SET is_archived = 1 WHERE id = $target_id");
        $target_year = isset($_GET['year']) ? urlencode($_GET['year']) : '';
        completeAction("manage_prospectus.php?program_id=" . $active_program_id . "&year=" . $target_year . "&status=active&msg=archived");
    }
    if (isset($_GET['restore']) && is_numeric($_GET['restore'])) {
        $target_id = (int)$_GET['restore'];
        $conn->query("UPDATE prospectus SET is_archived = 0 WHERE id = $target_id");
        $target_year = isset($_GET['year']) ? urlencode($_GET['year']) : '';
        completeAction("manage_prospectus.php?program_id=" . $active_program_id . "&year=" . $target_year . "&status=archived&msg=restored");
    }
    if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
        $target_id = (int)$_GET['delete'];
        $current_status = urlencode($_GET['status'] ?? 'active');
        $conn->query("DELETE FROM prospectus WHERE id = $target_id");
        $target_year = isset($_GET['year']) ? urlencode($_GET['year']) : '';
        completeAction("manage_prospectus.php?program_id=" . $active_program_id . "&year=" . $target_year . "&status={$current_status}&msg=deleted");
    }

    // SEMESTER BULK ACTION
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['semester_bulk_action'])) {
        if (!empty($_POST['selected_subjects']) && is_array($_POST['selected_subjects'])) {
            $action = $_POST['semester_bulk_action']; 
            $ids = array_map('intval', $_POST['selected_subjects']);
            $ids_string = implode(',', $ids);
            
            $status_redirect = isset($_POST['current_status']) ? urlencode($_POST['current_status']) : 'active';
            $target_year = isset($_GET['year']) ? urlencode($_GET['year']) : '';
            
            if ($action === 'archive') {
                $conn->query("UPDATE prospectus SET is_archived = 1 WHERE id IN ($ids_string)");
                completeAction("manage_prospectus.php?program_id=" . $active_program_id . "&year=" . $target_year . "&status=$status_redirect&msg=bulk_archived");
            } elseif ($action === 'restore') {
                $conn->query("UPDATE prospectus SET is_archived = 0 WHERE id IN ($ids_string)");
                completeAction("manage_prospectus.php?program_id=" . $active_program_id . "&year=" . $target_year . "&status=$status_redirect&msg=restored");
            }
        }
    }

    // BULK EDIT CREDIT
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_edit_credit'])) {
        if (!empty($_POST['selected_subjects']) && is_array($_POST['selected_subjects'])) {
            $new_units = (int)$_POST['new_units'];
            $ids = array_map('intval', $_POST['selected_subjects']);
            $ids_string = implode(',', $ids);
            
            if ($new_units > 0) {
                $conn->query("UPDATE prospectus SET units = $new_units WHERE id IN ($ids_string)");
                $target_year = isset($_GET['year']) ? urlencode($_GET['year']) : '';
                $status_redirect = isset($_POST['current_status']) ? urlencode($_POST['current_status']) : 'active';
                completeAction("manage_prospectus.php?program_id=" . $active_program_id . "&year=" . $target_year . "&status=$status_redirect&msg=edited");
            }
        }
    }

} // End of $can_edit block

// =========================================================
// ASYNC JSON DATA ENDPOINTS
// =========================================================

// 1. Fetch Available Years for a Specific Program
if (isset($_GET['api_get_years'])) {
    header('Content-Type: application/json');
    $prog_id = (int)($_GET['program_id'] ?? 0);
    $years = [];
    if ($prog_id > 0) {
        $q = $conn->query("SELECT DISTINCT curriculum_year FROM prospectus WHERE program_id = $prog_id AND curriculum_year IS NOT NULL AND curriculum_year != '' ORDER BY curriculum_year DESC");
        if ($q) {
            while ($r = $q->fetch_assoc()) {
                $years[] = $r['curriculum_year'];
            }
        }
    }
    echo json_encode(['years' => $years]);
    exit();
}

// 2. Fetch Past Curriculum Subjects for the Reference Modal
if (isset($_GET['api_get_past'])) {
    header('Content-Type: application/json');
    $import_prog_id = (int)($_GET['import_program_id'] ?? 0);
    $src_year = $conn->real_escape_string($_GET['year'] ?? '');
    
    $html = '';
    if ($import_prog_id > 0 && !empty($src_year)) {
        $q = $conn->query("SELECT * FROM prospectus WHERE program_id = $import_prog_id AND curriculum_year = '$src_year' AND IFNULL(is_archived, 0) = 0 ORDER BY year_level ASC, semester ASC, course_code ASC");
        
        if ($q && $q->num_rows > 0) {
            $html .= '<div class="overflow-x-auto w-full custom-scrollbar pb-2">';
            $html .= '<table class="w-full text-left border-collapse border border-slate-200 bg-white shadow-sm rounded-lg overflow-hidden min-w-[550px]">';
            $html .= '<thead class="bg-slate-100 text-slate-600 uppercase text-[9px] tracking-wider">';
            $html .= '<tr>';
            $html .= '<th class="p-2 border border-slate-200 text-center w-[5%]"><input type="checkbox" id="selectAllPast" class="w-3 h-3 text-emerald-600 rounded" onclick="toggleAllPastSubjects(this)"></th>';
            $html .= '<th class="p-2 border border-slate-200 w-[15%]">Year/Sem</th>';
            $html .= '<th class="p-2 border border-slate-200 w-[15%]">Code</th>';
            $html .= '<th class="p-2 border border-slate-200 w-[55%]">Title</th>';
            $html .= '<th class="p-2 border border-slate-200 w-[10%] text-center">Units</th>';
            $html .= '</tr></thead><tbody class="divide-y divide-slate-100">';
            
            while ($row = $q->fetch_assoc()) {
                $html .= '<tr class="hover:bg-slate-50 transition-colors">';
                $html .= '<td class="p-2 border border-slate-200 text-center"><input type="checkbox" name="selected_import_subjects[]" value="'.$row['id'].'" class="past-subject-cb w-3 h-3 text-emerald-600 border-slate-300 rounded focus:ring-emerald-500"></td>';
                $html .= '<td class="p-2 border border-slate-200 text-[9px] font-bold text-slate-500">'.htmlspecialchars($row['year_level']).'<br><span class="text-indigo-500">'.htmlspecialchars($row['semester']).'</span></td>';
                $html .= '<td class="p-2 border border-slate-200 text-[10px] font-mono font-bold text-[#00205b]">'.htmlspecialchars($row['course_code']).'</td>';
                $html .= '<td class="p-2 border border-slate-200 text-[10px] text-slate-700 font-medium">'.htmlspecialchars($row['descriptive_title']).'</td>';
                $html .= '<td class="p-2 border border-slate-200 text-[10px] font-bold text-center text-[#c5a02c]">'.htmlspecialchars($row['units']).'</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
        } else {
            $html = '<div class="text-center py-8 text-slate-400 text-xs font-bold uppercase tracking-widest border border-dashed border-slate-300 rounded-lg bg-slate-50">No subjects found for this program/year.</div>';
        }
    }
    echo json_encode(['html' => $html]);
    exit();
}

// 3. Data Endpoint for Live View Switching & Workspace Updating
if (isset($_GET['api_refresh'])) {
    header('Content-Type: application/json');
    $active_program_id = (int)($_GET['program_id'] ?? 0);
    $active_year = $_GET['year'] ?? '';
    $show_status = $_GET['status'] ?? 'active';

    $curriculum = [];
    $total_program_units = 0;

    if ($active_program_id > 0 && !empty($active_year)) {
        $safe_year = $conn->real_escape_string($active_year);
        $archived_flag = ($show_status === 'archived') ? 1 : 0;

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
            $counted_course_codes = []; 
            while ($row = $curr_query->fetch_assoc()) {
                $curriculum[$row['year_level']][$row['semester']][] = $row;
                $clean_code = strtoupper(trim($row['course_code']));
                if (!in_array($clean_code, $counted_course_codes)) {
                    $total_program_units += (int)$row['units'];
                    $counted_course_codes[] = $clean_code; 
                }
            }
        }
    }

    echo json_encode([
        'curriculum_html' => renderCurriculumTable($curriculum, $show_status, $can_edit, $active_program_id, $active_year),
        'total_units'     => $total_program_units
    ]);
    exit();
}

// ---------------------------------------------------------
// FETCH CURRICULUM YEARS & DEFAULT PUBLISHED YEAR
// ---------------------------------------------------------
$curriculum_years = [];
$published_default_year = '';

if ($active_program_id > 0) {
    $safe_prog_name = $conn->real_escape_string($active_program_name);
    $def_q = $conn->query("SELECT active_year FROM program_defaults WHERE program = '$safe_prog_name'");
    if ($def_q && $def_q->num_rows > 0) {
        $published_default_year = $def_q->fetch_assoc()['active_year'];
    }
    
    $year_q = $conn->query("SELECT DISTINCT curriculum_year FROM prospectus WHERE program_id = $active_program_id");
    if ($year_q) { 
        while($r = $year_q->fetch_assoc()){ 
            if(!empty($r['curriculum_year'])) {
                $curriculum_years[] = $r['curriculum_year']; 
            }
        } 
    }
}

if (isset($_GET['year']) && !empty($_GET['year'])) {
    $active_year = $_GET['year'];
} else {
    $active_year = !empty($published_default_year) ? $published_default_year : (!empty($curriculum_years) ? $curriculum_years[0] : (date('Y') . '-' . (date('Y') + 1)));
}

if (!in_array($active_year, $curriculum_years) && !empty($active_year)) {
    array_unshift($curriculum_years, $active_year);
}
if (!empty($published_default_year) && !in_array($published_default_year, $curriculum_years)) {
    array_unshift($curriculum_years, $published_default_year);
}

$curriculum_years = array_unique($curriculum_years);
rsort($curriculum_years); 

$show_status = isset($_GET['status']) ? $_GET['status'] : 'active';
$is_currently_published = ($active_year === $published_default_year);

// ---------------------------------------------------------
// FETCH CURRICULUM FOR INITIAL RENDER
// ---------------------------------------------------------
$curriculum = [];
$total_program_units = 0;
$counted_course_codes = []; 

if ($active_program_id > 0 && !empty($active_year)) {
    try {
        $safe_year = $conn->real_escape_string($active_year);
        $archived_flag = ($show_status === 'archived') ? 1 : 0;

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
    } catch (Exception $e) {}
}

// =========================================================
// HTML TEMPLATE FUNCTIONS
// =========================================================

function renderCurriculumTable($curriculum, $show_status, $can_edit, $active_program_id, $active_year) {
    ob_start();
    $year_levels_order = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
    $is_active_mode = ($can_edit && $show_status !== 'archived');
    
    if (empty($curriculum)) {
        echo '<div class="text-center py-16 text-slate-400 text-[10px] md:text-xs font-bold uppercase tracking-widest border border-dashed border-slate-300 rounded-xl bg-slate-50 mx-2 mt-2">No subjects found in this draft. Start adding or importing them above!</div>';
        return ob_get_clean();
    }

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
                        $semester_id = preg_replace('/[^a-zA-Z0-9]/', '_', $year . '_' . $sem);
                    ?>
                        <div class="flex justify-between items-end mb-2 border-b border-slate-300 print:border-gray-400 pb-1 min-h-[28px]">
                            <div class="flex items-center gap-3">
                                <h4 class="font-bold text-slate-700 print:text-black uppercase text-[12px] tracking-wider"><?= $sem ?></h4>
                                
                                <?php if ($can_edit): ?>
                                <div id="actions_<?= $semester_id ?>" class="hidden items-center gap-1.5 animate-up no-print">
                                    <button type="button" onclick="promptEditCredit('<?= $semester_id ?>')" class="text-[9px] bg-indigo-50 text-indigo-700 border border-indigo-200 hover:bg-indigo-600 hover:text-white px-2 py-0.5 rounded font-bold tracking-widest uppercase transition-colors shadow-sm whitespace-nowrap">Edit Credit</button>
                                    <?php if ($show_status === 'archived'): ?>
                                        <button type="button" onclick="promptSemesterBulkAction('<?= $semester_id ?>', 'restore')" class="text-[9px] bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-600 hover:text-white px-2 py-0.5 rounded font-bold tracking-widest uppercase transition-colors shadow-sm whitespace-nowrap">Restore</button>
                                    <?php else: ?>
                                        <button type="button" onclick="promptSemesterBulkAction('<?= $semester_id ?>', 'archive')" class="text-[9px] bg-rose-50 text-rose-700 border border-rose-200 hover:bg-rose-600 hover:text-white px-2 py-0.5 rounded font-bold tracking-widest uppercase transition-colors shadow-sm whitespace-nowrap">Move to Archive</button>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <span class="font-bold text-[10px] bg-slate-100 text-slate-600 print:text-black px-2 py-0.5 border border-slate-200 print:border-gray-300 rounded shrink-0">Units: <?= $term_units ?></span>
                        </div>
                        
                        <div class="overflow-x-auto custom-scrollbar w-full pb-2">
                            <table id="table_<?= $semester_id ?>" class="w-full text-left border-collapse border border-slate-300 print:border-black bg-white min-w-[400px]">
                                <thead class="bg-slate-100 print:bg-gray-100 text-slate-600 print:text-black uppercase text-[9px] tracking-wider">
                                    <tr>
                                        <?php if ($can_edit): ?>
                                            <th class="border border-slate-300 print:border-black p-1.5 w-[5%] text-center no-print align-middle"></th>
                                        <?php endif; ?>
                                        <th class="border border-slate-300 print:border-black p-1.5 <?= $can_edit ? 'w-[15%]' : 'w-[20%]' ?> font-bold">Code</th>
                                        <th class="border border-slate-300 print:border-black p-1.5 <?= $can_edit ? 'w-[45%]' : 'w-[50%]' ?> font-bold">Descriptive Title</th>
                                        <th class="border border-slate-300 print:border-black p-1.5 text-center w-[10%] font-bold">Units</th>
                                        <th class="border border-slate-300 print:border-black p-1.5 <?= $can_edit ? 'w-[15%]' : 'w-[20%]' ?> font-bold">Pre-Req</th>
                                        <?php if ($can_edit): ?>
                                            <th class="border border-slate-300 print:border-black p-1.5 w-[10%] text-center no-print font-bold">Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200 print:divide-black">
                                    <?php foreach ($term_subjects as $subj): ?>
                                        <tr class="transition-colors duration-150 group">
                                            <?php if ($can_edit): ?>
                                                <td class="border border-slate-300 print:border-black p-1.5 text-center align-middle no-print">
                                                    <input type="checkbox" value="<?= $subj['id'] ?>" class="subject-cb w-3 h-3 cursor-pointer text-indigo-600 border-slate-300 rounded focus:ring-indigo-500 block mx-auto" onchange="updateSemesterActions('<?= $semester_id ?>', this)">
                                                </td>
                                            <?php endif; ?>
                                            
                                            <td class="border border-slate-300 print:border-black p-1.5 font-mono font-bold text-[10px] <?= ($show_status === 'archived') ? 'text-slate-400 line-through' : 'text-[#00205b]' ?> align-middle">
                                                <?= htmlspecialchars($subj['course_code']) ?>
                                            </td>
                                            <td class="border border-slate-300 print:border-black p-1.5 text-[10px] leading-snug <?= ($show_status === 'archived') ? 'text-slate-400' : 'text-slate-700 font-medium' ?> align-middle">
                                                <?= htmlspecialchars($subj['descriptive_title']) ?>
                                            </td>
                                            <td class="border border-slate-300 print:border-black p-1.5 text-center font-bold text-[10px] <?= ($show_status === 'archived') ? 'text-slate-400' : 'text-[#c5a02c]' ?> align-middle">
                                                <?= htmlspecialchars($subj['units']) ?>
                                            </td>
                                            <td class="border border-slate-300 print:border-black p-1.5 text-[10px] font-medium leading-snug align-middle text-slate-600 print:text-black">
                                                <?= htmlspecialchars($subj['prerequisite'] ?? 'None') ?>
                                            </td>
                                            
                                            <?php if ($can_edit): ?>
                                                <td class="border border-slate-300 print:border-black p-1 text-center align-middle no-print">
                                                    <div class="flex items-center justify-center gap-1.5 opacity-30 group-hover:opacity-100 transition-opacity">
                                                        <?php if ($is_active_mode): ?>
                                                            <!-- SECURE DATA ATTRIBUTE BINDING -->
                                                            <button type="button" 
                                                                    data-id="<?= $subj['id'] ?>" 
                                                                    data-code="<?= htmlspecialchars($subj['course_code'], ENT_QUOTES, 'UTF-8') ?>" 
                                                                    data-title="<?= htmlspecialchars($subj['descriptive_title'], ENT_QUOTES, 'UTF-8') ?>" 
                                                                    data-units="<?= $subj['units'] ?>" 
                                                                    data-prereq="<?= htmlspecialchars($subj['prerequisite'] ?? '', ENT_QUOTES, 'UTF-8') ?>" 
                                                                    data-instructor="<?= htmlspecialchars($subj['instructor'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                                    onclick="openEditSubjectModal(event.currentTarget)" 
                                                                    class="text-blue-500 hover:text-blue-700 transition" title="Edit">
                                                                <svg class="w-3.5 h-3.5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                                            </button>
                                                            <button type="button" onclick="showCustomConfirm('Are you sure you want to move this subject to the archive?', 'archive', 'manage_prospectus.php?program_id=<?= urlencode($active_program_id) ?>&year=<?= urlencode($active_year) ?>&archive=<?= $subj['id'] ?>&status=active')" class="text-rose-400 hover:text-rose-600 transition" title="Archive">
                                                                <svg class="w-3.5 h-3.5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                                            </button>
                                                        <?php else: ?>
                                                            <button type="button" onclick="showCustomConfirm('Are you sure you want to restore this subject?', 'restore', 'manage_prospectus.php?program_id=<?= urlencode($active_program_id) ?>&year=<?= urlencode($active_year) ?>&restore=<?= $subj['id'] ?>&status=archived')" class="text-emerald-500 hover:text-emerald-700 transition" title="Restore"><svg class="w-3.5 h-3.5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" /></svg></button>
                                                            <?php if ($_SESSION['role'] === 'admin'): ?>
                                                            <button type="button" onclick="showCustomConfirm('CRITICAL: Are you sure you want to permanently delete this subject from the database?', 'delete', 'manage_prospectus.php?program_id=<?= urlencode($active_program_id) ?>&year=<?= urlencode($active_year) ?>&delete=<?= $subj['id'] ?>&status=archived')" class="text-rose-500 hover:text-rose-700 transition" title="Permanent Delete"><svg class="w-3.5 h-3.5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            <?php endif; ?>
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
        $semester_id = preg_replace('/[^a-zA-Z0-9]/', '_', $year . '_Summer');
    ?>
    <div class="mt-8 w-full md:w-1/2 mx-auto page-break-inside-avoid">
        <div class="flex justify-between items-end mb-2 border-b border-slate-400 print:border-gray-400 pb-1 min-h-[28px]">
            <div class="flex items-center gap-3">
                <h4 class="font-bold text-slate-700 print:text-black uppercase text-[12px] tracking-wider">Summer</h4>
                <?php if ($can_edit): ?>
                <div id="actions_<?= $semester_id ?>" class="hidden items-center gap-1.5 animate-up no-print">
                    <button type="button" onclick="promptEditCredit('<?= $semester_id ?>')" class="text-[9px] bg-indigo-50 text-indigo-700 border border-indigo-200 hover:bg-indigo-600 hover:text-white px-2 py-0.5 rounded font-bold tracking-widest uppercase transition-colors shadow-sm whitespace-nowrap">Edit Credit</button>
                    <?php if ($show_status === 'archived'): ?>
                        <button type="button" onclick="promptSemesterBulkAction('<?= $semester_id ?>', 'restore')" class="text-[9px] bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-600 hover:text-white px-2 py-0.5 rounded font-bold tracking-widest uppercase transition-colors shadow-sm whitespace-nowrap">Restore</button>
                    <?php else: ?>
                        <button type="button" onclick="promptSemesterBulkAction('<?= $semester_id ?>', 'archive')" class="text-[9px] bg-rose-50 text-rose-700 border border-rose-200 hover:bg-rose-600 hover:text-white px-2 py-0.5 rounded font-bold tracking-widest uppercase transition-colors shadow-sm whitespace-nowrap">Move to Archive</button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <span class="font-bold text-[10px] bg-slate-100 text-slate-600 print:text-black px-2 py-0.5 border border-slate-200 print:border-gray-300 rounded-sm shrink-0">Units: <?= $term_units ?></span>
        </div>
        
        <div class="overflow-x-auto custom-scrollbar w-full pb-2">
            <table id="table_<?= $semester_id ?>" class="w-full text-left border-collapse border border-slate-300 print:border-black min-w-[400px] bg-white">
                <thead class="bg-slate-100 print:bg-gray-100 text-slate-600 print:text-black uppercase text-[9px] tracking-wider">
                    <tr>
                        <?php if ($can_edit): ?>
                            <th class="border border-slate-300 print:border-black p-1.5 w-[5%] text-center no-print align-middle"></th>
                        <?php endif; ?>
                        <th class="border border-slate-300 print:border-black p-1.5 <?= $can_edit ? 'w-[15%]' : 'w-[20%]' ?> font-bold">Code</th>
                        <th class="border border-slate-300 print:border-black p-1.5 <?= $can_edit ? 'w-[45%]' : 'w-[50%]' ?> font-bold">Descriptive Title</th>
                        <th class="border border-slate-300 print:border-black p-1.5 text-center w-[10%] font-bold">Units</th>
                        <th class="border border-slate-300 print:border-black p-1.5 <?= $can_edit ? 'w-[15%]' : 'w-[20%]' ?> font-bold">Pre-Req</th>
                        <?php if ($can_edit): ?>
                            <th class="border border-slate-300 print:border-black p-1.5 w-[10%] text-center no-print font-bold">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 print:divide-black">
                    <?php foreach ($term_subjects as $subj): ?>
                        <tr class="transition-colors duration-150 group">
                            <?php if ($can_edit): ?>
                                <td class="border border-slate-300 print:border-black p-1.5 text-center align-middle no-print">
                                    <input type="checkbox" value="<?= $subj['id'] ?>" class="subject-cb w-3 h-3 cursor-pointer text-indigo-600 border-slate-300 rounded focus:ring-indigo-500 block mx-auto" onchange="updateSemesterActions('<?= $semester_id ?>', this)">
                                </td>
                            <?php endif; ?>
                            
                            <td class="border border-slate-300 print:border-black p-1.5 font-mono font-bold text-[10px] <?= ($show_status === 'archived') ? 'text-slate-400 line-through' : 'text-[#00205b]' ?> align-middle">
                                <?= htmlspecialchars($subj['course_code']) ?>
                            </td>
                            <td class="border border-slate-300 print:border-black p-1.5 text-[10px] leading-snug <?= ($show_status === 'archived') ? 'text-slate-400' : 'text-slate-700 font-medium' ?> align-middle">
                                <?= htmlspecialchars($subj['descriptive_title']) ?>
                            </td>
                            <td class="border border-slate-300 print:border-black p-1.5 text-center font-bold text-[10px] <?= ($show_status === 'archived') ? 'text-slate-400' : 'text-[#c5a02c]' ?> align-middle">
                                <?= htmlspecialchars($subj['units']) ?>
                            </td>
                            <td class="border border-slate-300 print:border-black p-1.5 text-[10px] font-medium leading-snug align-middle text-slate-600 print:text-black">
                                <?= htmlspecialchars($subj['prerequisite'] ?? 'None') ?>
                            </td>
                            
                            <?php if ($can_edit): ?>
                                <td class="border border-slate-300 print:border-black p-1 text-center align-middle no-print">
                                    <div class="flex items-center justify-center gap-1.5 opacity-30 group-hover:opacity-100 transition-opacity">
                                        <?php if ($is_active_mode): ?>
                                            <!-- SECURE DATA ATTRIBUTE BINDING -->
                                            <button type="button" 
                                                    data-id="<?= $subj['id'] ?>" 
                                                    data-code="<?= htmlspecialchars($subj['course_code'], ENT_QUOTES, 'UTF-8') ?>" 
                                                    data-title="<?= htmlspecialchars($subj['descriptive_title'], ENT_QUOTES, 'UTF-8') ?>" 
                                                    data-units="<?= $subj['units'] ?>" 
                                                    data-prereq="<?= htmlspecialchars($subj['prerequisite'] ?? '', ENT_QUOTES, 'UTF-8') ?>" 
                                                    data-instructor="<?= htmlspecialchars($subj['instructor'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                    onclick="openEditSubjectModal(event.currentTarget)" 
                                                    class="text-blue-500 hover:text-blue-700 transition" title="Edit">
                                                <svg class="w-3.5 h-3.5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                            </button>
                                            <button type="button" onclick="showCustomConfirm('Are you sure you want to move this subject to the archive?', 'archive', 'manage_prospectus.php?program_id=<?= urlencode($active_program_id) ?>&year=<?= urlencode($active_year) ?>&archive=<?= $subj['id'] ?>&status=active')" class="text-rose-400 hover:text-rose-600 transition" title="Archive">
                                                <svg class="w-3.5 h-3.5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" onclick="showCustomConfirm('Are you sure you want to restore this subject?', 'restore', 'manage_prospectus.php?program_id=<?= urlencode($active_program_id) ?>&year=<?= urlencode($active_year) ?>&restore=<?= $subj['id'] ?>&status=archived')" class="text-emerald-500 hover:text-emerald-700 transition" title="Restore"><svg class="w-3.5 h-3.5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" /></svg></button>
                                            <?php if ($_SESSION['role'] === 'admin'): ?>
                                            <button type="button" onclick="showCustomConfirm('CRITICAL: Are you sure you want to permanently delete this subject from the database?', 'delete', 'manage_prospectus.php?program_id=<?= urlencode($active_program_id) ?>&year=<?= urlencode($active_year) ?>&delete=<?= $subj['id'] ?>&status=archived')" class="text-rose-500 hover:text-rose-700 transition" title="Permanent Delete"><svg class="w-3.5 h-3.5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endif; ?>
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
    <title>Curriculum Administration - LDSP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css?v=<?php echo time(); ?>">
</head>
<body class="flex h-screen antialiased overflow-hidden bg-[#f8fafc]">
    <div class="ambient-orb-1 no-print"></div>
    <div class="ambient-orb-2 no-print"></div>

    <?php include 'sidebar.php'; ?>

    <main class="flex-1 min-w-0 overflow-y-auto overflow-x-hidden h-full pt-20 md:pt-0 relative z-10 custom-scrollbar">
        <div class="p-4 md:p-8 lg:p-10 max-w-[1200px] mx-auto relative z-10">
            
            <!-- HEADER SECTION -->
            <header class="mb-6 flex flex-col xl:flex-row justify-between xl:items-end gap-6 no-print animate-up">
                <div>
                    <div class="flex items-center flex-wrap gap-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1">
                        <span>Curriculum Settings</span>
                        <?php if (!$can_edit): ?>
                            <span class="bg-rose-100 text-rose-600 px-2 py-0.5 rounded border border-rose-200">READ ONLY</span>
                        <?php endif; ?>
                        <?php if ($is_currently_published): ?>
                            <span class="bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded border border-emerald-200 flex items-center gap-1 shadow-sm"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> PUBLISHED DEFAULT</span>
                        <?php else: ?>
                            <span class="bg-amber-100 text-amber-700 px-2 py-0.5 rounded border border-amber-200 flex items-center gap-1 shadow-sm"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg> DRAFT MODE</span>
                        <?php endif; ?>
                    </div>
                    <h1 class="text-2xl md:text-3xl font-black text-[#00205b] tracking-tight font-academic uppercase">Manage Curriculum</h1>
                    <p class="text-slate-500 mt-1 text-xs">Formal administration of academic curricula across designated programs and academic years.</p>
                </div>
                
                <form method="GET" class="bg-white p-4 rounded-xl shadow-sm border border-slate-200 flex flex-col sm:flex-row items-end gap-4 w-full xl:w-auto">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($show_status) ?>">
                    
                    <div class="w-full sm:w-auto">
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 pl-1">Target Program</label>
                        <select name="program_id" onchange="document.getElementById('year_selector').disabled=true; this.form.submit()" class="input-glossy-smooth select-formal w-full sm:w-64 !py-1.5 !px-3 !text-xs font-semibold text-[#00205b]">
                            <?php if (empty($programs)): ?>
                                <option value="">No Programs Found</option>
                            <?php else: ?>
                                <?php foreach($programs as $id => $name): ?>
                                    <option value="<?= htmlspecialchars($id) ?>" <?= $active_program_id == $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="w-full sm:w-auto flex flex-col sm:flex-row items-end gap-2">
                        <div class="flex-1 w-full sm:w-auto">
                            <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 pl-1">Curriculum Year</label>
                            <select id="year_selector" name="year" onchange="this.form.submit()" class="input-glossy-smooth select-formal w-full sm:w-40 !py-1.5 !px-3 !text-xs font-bold text-[#00205b]">
                                <?php foreach($curriculum_years as $y): ?>
                                    <option value="<?= htmlspecialchars($y) ?>" <?= $active_year === $y ? 'selected' : '' ?>>S.Y. <?= htmlspecialchars($y) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($can_edit && $active_program_id > 0): ?>
                        <div class="flex gap-2 w-full sm:w-auto justify-end mt-2 sm:mt-0">
                            <button type="button" onclick="openModal('renameYearModal')" title="Edit Year Name" class="btn-secondary !p-2 !rounded-lg"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg></button>
                            <button type="button" onclick="openModal('addYearModal')" title="Create New Year (Blank Slate)" class="btn-secondary !p-2 !rounded-lg !text-emerald-600 !border-emerald-200 hover:!bg-emerald-50"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg></button>
                        </div>
                        <?php endif; ?>
                    </div>
                </form>
            </header>

            <!-- ACTION TOOLBAR -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6 border-b border-slate-200 pb-4 no-print animate-up delay-1">
                <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
                    <!-- SPA Navigation Buttons -->
                    <button id="tab_btn_active" onclick="window.switchTab('active')" class="px-3 md:px-4 py-1.5 rounded-md text-[10px] font-bold uppercase tracking-widest transition-colors cursor-pointer <?= ($show_status !== 'archived') ? 'bg-[#00205b] text-white shadow-sm' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
                        Active
                    </button>
                    <button id="tab_btn_archived" onclick="window.switchTab('archived')" class="px-3 md:px-4 py-1.5 rounded-md text-[10px] font-bold uppercase tracking-widest transition-colors flex items-center gap-1.5 cursor-pointer <?= ($show_status === 'archived') ? 'bg-slate-700 text-white shadow-sm' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>
                        Archive
                    </button>
                    
                    <?php if ($can_edit && $show_status !== 'archived'): ?>
                        <div class="h-4 w-px bg-slate-300 mx-1 hidden sm:block"></div>
                        <button onclick="window.openReferenceModal()" class="btn-primary !py-1.5 !px-3 !text-[9px] flex items-center gap-1.5 shadow-md">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg> <span class="hidden sm:inline">Builder Workspace</span>
                        </button>
                    <?php endif; ?>
                </div>

                <div class="flex flex-wrap items-center gap-2 w-full md:w-auto mt-2 md:mt-0 justify-end">
                    <?php if ($can_edit && !$is_currently_published && $show_status !== 'archived'): ?>
                    <form method="POST" class="inline" onsubmit="window.handleAjaxSubmit(event, null, 'Published curriculum successfully updated!');">
                        <input type="hidden" name="set_default_year" value="1">
                        <input type="hidden" name="year_to_set" value="<?= htmlspecialchars($active_year) ?>">
                        <button type="submit" class="btn-primary !py-1.5 !px-3 !text-[9px] !bg-emerald-600 hover:!bg-emerald-700 !border-emerald-700 flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg> <span class="hidden sm:inline">Publish as Default</span><span class="sm:hidden">Publish</span>
                        </button>
                    </form>
                    <?php endif; ?>
                    <button onclick="window.print()" class="btn-secondary !py-1.5 !px-3 !text-[9px] flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2-2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" /></svg> Print
                    </button>
                </div>
            </div>

            <!-- STRICT A4 RESPONSIVE CONTAINER -->
            <div class="animate-up delay-2 relative transition-opacity duration-300 overflow-x-auto pb-8" id="documentCanvasContainer">
                <div id="documentCanvas" class="paper-container mx-auto max-w-full" style="background: white; box-shadow: 0 15px 35px rgba(0,0,0,0.1), 0 5px 15px rgba(0,0,0,0.05); position: relative; border: 1px solid #e5e7eb; width: 210mm; min-height: 297mm; box-sizing: border-box; border-radius: 4px;">
                    <div class="paper-content" style="padding: 10mm; width: 100%; height: 100%; box-sizing: border-box;">
                        
                        <div class="text-center mb-6 border-b-2 border-black pb-4 shrink-0">
                            <h1 class="text-[16px] md:text-[20px] font-black uppercase font-academic tracking-wider m-0 leading-tight">LDSP Academic Curriculum</h1>
                            <h2 class="text-[12px] md:text-[14px] font-bold uppercase m-0 mt-1 leading-tight"><?= htmlspecialchars($active_program_name) ?></h2>
                            <p class="text-[10px] md:text-[11px] font-semibold m-0 mt-1 leading-tight text-black">Curriculum Year: <?= htmlspecialchars($active_year ?: 'N/A') ?></p>
                            <p class="text-[9px] md:text-[10px] m-0 leading-tight mt-1 text-black">Total Program Credit Units: <strong id="print_total_units"><?= $total_program_units ?></strong></p>
                            <?php if(!$is_currently_published): ?>
                                <p class="text-[9px] md:text-[10px] m-0 leading-tight mt-1 text-amber-600 font-bold italic no-print">(Draft Preview - Not Set as Official Default)</p>
                            <?php endif; ?>
                        </div>

                        <div id="curriculum_container" class="w-full flex flex-col gap-6">
                            <?= renderCurriculumTable($curriculum, $show_status, $can_edit, $active_program_id, $active_year) ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- FORM USED FOR DYNAMIC ACTION BAR SUBMISSIONS -->
    <form id="semesterActionForm" method="POST" class="hidden"></form>

    <!-- FLOATING TOAST CONTAINER -->
    <div id="toastContainer" class="fixed top-5 right-5 z-[10000] flex flex-col gap-2 pointer-events-none"></div>

    <!-- MASSIVE BUILDER WORKSPACE MODAL (BOOK VIEW) -->
    <div id="referenceCurriculumModal" class="fixed inset-0 z-[9990] hidden items-center justify-center p-2 sm:p-4 bg-slate-900/80 backdrop-blur-sm no-print">
        <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-[98vw] h-[95vh] flex flex-col overflow-hidden">
            
            <!-- Modal Header -->
            <div class="p-3 md:p-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center shrink-0">
                <h3 class="font-black text-[#00205b] uppercase tracking-[0.1em] flex items-center gap-2 text-xs md:text-sm">
                    <div class="p-1.5 bg-blue-100 rounded-md text-blue-600 hidden sm:block"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg></div>
                    Curriculum Builder Workspace
                </h3>
                <button type="button" onclick="window.closeModal('referenceCurriculumModal')" class="text-slate-400 hover:text-rose-500 bg-white hover:bg-rose-50 p-1.5 rounded-lg transition-colors border border-slate-200 focus:outline-none"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
            </div>
            
            <!-- Two-Page Body (The Book) -->
            <div class="flex-1 flex flex-col lg:flex-row overflow-hidden bg-slate-200 p-2 gap-2">
                
                <!-- LEFT PAGE: Reference & Import -->
                <div class="w-full lg:w-1/2 flex flex-col bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
                    <div class="p-3 md:p-4 bg-slate-50 border-b border-slate-200 shrink-0">
                        <h4 class="text-[10px] md:text-xs font-black text-slate-700 uppercase tracking-widest mb-3">Left Page: Reference & Select Subjects</h4>
                        
                        <div class="flex flex-col sm:flex-row gap-2">
                            <select id="import_program_selector" onchange="window.loadImportYears()" class="input-glossy-smooth select-formal w-full sm:w-1/2 !py-2 !px-3 !text-[10px] font-bold text-[#00205b]">
                                <option value="">-- View Another Program --</option>
                                <?php foreach($programs as $id => $name): ?>
                                    <option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="import_year_selector" onchange="window.loadPastCurriculum()" disabled class="input-glossy-smooth select-formal w-full sm:w-1/2 !py-2 !px-3 !text-[10px] font-bold text-[#00205b] disabled:opacity-50">
                                <option value="">-- Select Year --</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- View Panel -->
                    <div class="flex-1 overflow-y-auto custom-scrollbar relative bg-white" id="past_curriculum_container">
                        <div class="text-center py-10 md:py-24 text-slate-400 text-[10px] md:text-xs font-bold uppercase tracking-widest border border-dashed border-slate-300 rounded-xl bg-slate-50 mx-4 mt-4">
                            Select a program and year above to view subjects.
                        </div>
                    </div>

                    <!-- Import Action -->
                    <div class="p-3 border-t border-slate-200 bg-slate-50 shrink-0">
                        <form id="importForm" method="POST" onsubmit="window.submitImport(event)">
                            <input type="hidden" name="import_subjects" value="1">
                            <input type="hidden" name="target_curriculum_year" value="<?= htmlspecialchars($active_year) ?>">
                            <div id="hiddenImportInputs"></div>
                            <button type="submit" id="importSubmitBtn" class="btn-primary w-full !bg-emerald-600 hover:!bg-emerald-700 !px-4 !py-3 flex items-center justify-center gap-2 !text-[10px]">
                                <svg class="w-4 h-4 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                                Import Selected to Draft
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- RIGHT PAGE: Current Draft & Form -->
                <div class="w-full lg:w-1/2 flex flex-col bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
                    <div class="p-3 md:p-4 bg-blue-50 border-b border-blue-200 shrink-0 relative">
                        <div class="flex justify-between items-start mb-3">
                            <div>
                                <h4 class="text-[10px] md:text-xs font-black text-blue-900 uppercase tracking-widest">Right Page: Active Draft Builder</h4>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="text-[10px] font-bold bg-white text-blue-700 px-2 py-0.5 rounded shadow-sm border border-blue-200">S.Y. <?= htmlspecialchars($active_year) ?></span>
                                    <?php if ($total_program_units > 0): ?>
                                        <span class="text-[9px] font-bold text-amber-600 bg-amber-100 px-2 py-0.5 rounded border border-amber-200">Not Blank (<?= $total_program_units ?> Units)</span>
                                    <?php else: ?>
                                        <span class="text-[9px] font-bold text-emerald-600 bg-emerald-100 px-2 py-0.5 rounded border border-emerald-200">Blank Slate</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <button type="button" onclick="window.closeModal('referenceCurriculumModal'); document.getElementById('auto_open_builder').value='1'; window.openModal('addYearModal');" class="bg-white hover:bg-emerald-50 text-emerald-700 border border-emerald-300 px-3 py-1.5 rounded-lg text-[9px] font-bold uppercase tracking-widest transition-colors shadow-sm flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>
                                Start Blank Year
                            </button>
                        </div>
                        
                        <?php if ($total_program_units > 0): ?>
                            <div class="bg-amber-50/80 border border-amber-200 p-2 rounded mb-3">
                                <p class="text-[9px] text-amber-700 font-medium leading-tight"><strong>Careful:</strong> You are currently editing a curriculum that already has subjects. If you want to create a brand new curriculum for a future year, click "Start Blank Year" first.</p>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Quick Add Form Embedded in Modal -->
                        <form id="modalAddSubjectForm" onsubmit="window.submitModalAddSubject(event)" class="bg-white p-3 border border-blue-200 rounded-lg shadow-sm">
                            <input type="hidden" name="target_program_id" value="<?= htmlspecialchars($active_program_id) ?>">
                            <input type="hidden" name="add_subject" value="1">
                            <input type="hidden" name="curriculum_year" value="<?= htmlspecialchars($active_year) ?>">
                            <input type="hidden" name="instructor" value="TBA">
                            
                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-2">
                                <div class="col-span-12 sm:col-span-3">
                                    <select name="year_level" class="w-full text-[9px] md:text-[10px] p-2 border border-slate-300 rounded font-bold text-[#00205b] h-full focus:outline-none focus:border-blue-500">
                                        <option value="1st Year">1st Year</option>
                                        <option value="2nd Year">2nd Year</option>
                                        <option value="3rd Year">3rd Year</option>
                                        <option value="4th Year">4th Year</option>
                                    </select>
                                </div>
                                <div class="col-span-12 sm:col-span-3">
                                    <select name="semester" class="w-full text-[9px] md:text-[10px] p-2 border border-slate-300 rounded font-bold text-[#00205b] h-full focus:outline-none focus:border-blue-500">
                                        <option value="1st Semester">1st Sem</option>
                                        <option value="2nd Semester">2nd Sem</option>
                                        <option value="Summer">Summer</option>
                                    </select>
                                </div>
                                <div class="col-span-12 sm:col-span-2">
                                    <input type="text" name="course_code" required placeholder="Code" oninput="this.value=this.value.toUpperCase()" class="w-full text-[9px] md:text-[10px] p-2 border border-slate-300 rounded font-mono font-bold text-indigo-700 h-full focus:outline-none focus:border-blue-500">
                                </div>
                                <div class="col-span-12 sm:col-span-2">
                                    <input type="number" name="units" required min="1" max="20" value="3" placeholder="Units" class="w-full text-[9px] md:text-[10px] p-2 border border-slate-300 rounded text-center font-bold text-emerald-700 h-full focus:outline-none focus:border-blue-500">
                                </div>
                                <div class="col-span-12 sm:col-span-2">
                                    <button type="submit" class="w-full bg-[#00205b] hover:bg-[#001233] text-white text-[9px] md:text-[10px] font-bold uppercase tracking-widest p-2 rounded shadow-sm transition-colors h-full">
                                        + Add
                                    </button>
                                </div>
                                
                                <div class="col-span-12 sm:col-span-7">
                                    <input type="text" name="descriptive_title" required placeholder="Descriptive Subject Title" class="w-full text-[9px] md:text-[10px] p-2 border border-slate-300 rounded font-semibold text-slate-700 focus:outline-none focus:border-blue-500">
                                </div>
                                <div class="col-span-12 sm:col-span-5">
                                    <input type="text" name="prerequisite" placeholder="Pre-requisite (Optional)" class="w-full text-[9px] md:text-[10px] p-2 border border-slate-300 rounded text-slate-600 focus:outline-none focus:border-blue-500">
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Live Draft View -->
                    <div class="flex-1 overflow-y-auto custom-scrollbar p-2 bg-slate-50 relative" id="live_draft_container">
                        <div class="flex flex-col items-center justify-center py-20 text-slate-400">
                            <svg class="animate-spin h-6 w-6 mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            <span class="text-[10px] font-bold tracking-widest uppercase">Loading Live Draft...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- EDIT SUBJECT MODAL -->
    <div id="editSubjectModal" class="fixed inset-0 z-[9995] hidden items-center justify-center p-4 modal-overlay bg-slate-900/60 backdrop-blur-sm no-print">
        <div class="bg-white rounded-xl shadow-2xl border border-slate-200 w-full max-w-2xl modal-content overflow-hidden">
            <div class="p-4 md:p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50">
                <h3 class="font-black text-[#00205b] uppercase tracking-[0.1em] flex items-center gap-2 text-xs md:text-sm">
                    <div class="p-1.5 bg-blue-100 rounded-md text-blue-600 hidden sm:block"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg></div>
                    Edit Subject
                </h3>
                <button type="button" onclick="window.closeModal('editSubjectModal')" class="text-slate-400 hover:text-rose-500 bg-white hover:bg-rose-50 p-1.5 rounded-lg transition-colors border border-slate-200 focus:outline-none"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
            </div>
            <form onsubmit="window.handleAjaxSubmit(event, 'editSubjectModal', 'Subject updated successfully!');" action="manage_prospectus.php?program_id=<?= urlencode($active_program_id) ?>&year=<?= urlencode($active_year) ?>" method="POST" class="p-4 md:p-6">
                <input type="hidden" name="subject_id" id="edit_sub_id" value="">
                <input type="hidden" name="edit_subject" value="1">
                
                <div class="grid grid-cols-1 md:grid-cols-12 gap-4 mb-4 md:mb-6">
                    <div class="col-span-12 md:col-span-4">
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 pl-1">Course Code</label>
                        <input type="text" name="course_code" id="edit_sub_code" required class="input-glossy-smooth !py-2 !px-3 text-xs font-mono font-bold uppercase text-[#00205b]" oninput="this.value = this.value.toUpperCase()">
                    </div>
                    <div class="col-span-12 md:col-span-8">
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 pl-1">Descriptive Subject Title</label>
                        <input type="text" name="descriptive_title" id="edit_sub_title" required class="input-glossy-smooth !py-2 !px-3 text-xs font-semibold">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-12 gap-4 mb-6 md:mb-8">
                    <div class="col-span-12 md:col-span-3">
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 pl-1">Units</label>
                        <input type="number" name="units" id="edit_sub_units" required min="1" max="20" class="input-glossy-smooth !py-2 !px-3 text-xs text-center font-mono font-black text-emerald-700">
                    </div>
                    <div class="col-span-12 md:col-span-5">
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 pl-1">Pre-requisite</label>
                        <input type="text" name="prerequisite" id="edit_sub_prereq" class="input-glossy-smooth !py-2 !px-3 text-xs font-semibold">
                    </div>
                    <div class="col-span-12 md:col-span-4">
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 pl-1">Instructor</label>
                        <input type="text" name="instructor" id="edit_sub_instructor" class="input-glossy-smooth !py-2 !px-3 text-xs font-semibold">
                    </div>
                </div>
                
                <div class="flex justify-end pt-4 border-t border-slate-100 gap-3">
                    <button type="button" onclick="window.closeModal('editSubjectModal')" class="btn-secondary !px-6 !py-2.5">Cancel</button>
                    <button type="submit" class="btn-primary !px-6 !py-2.5">Save Updates</button>
                </div>
            </form>
        </div>
    </div>

    <!-- TINY EDIT CREDIT MODAL -->
    <div id="editCreditModal" class="fixed inset-0 z-[9995] hidden items-center justify-center p-4 modal-overlay bg-slate-900/60 backdrop-blur-sm no-print">
        <div class="bg-white rounded-xl shadow-2xl border border-slate-200 w-full max-w-sm modal-content overflow-hidden">
            <div class="p-4 border-b border-slate-100 flex justify-between items-center bg-slate-50">
                <h3 class="font-black text-indigo-800 uppercase tracking-widest flex items-center gap-2 text-[11px]">
                    Edit Credit Units
                </h3>
                <button type="button" onclick="window.closeModal('editCreditModal')" class="text-slate-400 hover:text-rose-500 focus:outline-none"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
            </div>
            <div class="p-6 text-center">
                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-3">Set New Credit Value</label>
                <input type="number" id="bulk_new_units" class="input-glossy-smooth text-center font-black text-2xl text-emerald-600 !py-3 w-1/2 mx-auto block" value="3" min="1" max="20">
                
                <div class="mt-6 flex gap-3 justify-center">
                    <button type="button" onclick="window.closeModal('editCreditModal')" class="btn-secondary !px-4 !py-2.5 !text-[10px]">Cancel</button>
                    <button type="button" id="btnApplyCredit" onclick="window.submitEditCredit()" class="btn-primary !bg-indigo-600 hover:!bg-indigo-700 !px-4 !py-2.5 !text-[10px]">Apply</button>
                </div>
            </div>
        </div>
    </div>

    <!-- RENAME YEAR MODAL -->
    <div id="renameYearModal" class="fixed inset-0 z-[9995] hidden items-center justify-center p-4 modal-overlay bg-slate-900/60 backdrop-blur-sm">
        <div class="bg-white rounded-xl shadow-2xl border border-slate-200 w-full max-w-md modal-content overflow-hidden">
            <div class="p-4 md:p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50">
                <h3 class="font-black text-[#00205b] uppercase tracking-[0.1em] flex items-center gap-2 text-xs md:text-sm">
                    Edit Curriculum Year
                </h3>
                <button type="button" onclick="window.closeModal('renameYearModal')" class="text-slate-400 hover:text-rose-500 bg-white hover:bg-rose-50 p-1.5 rounded-lg transition-colors border border-slate-200 focus:outline-none"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
            </div>
            <form method="POST" class="p-4 md:p-6">
                <input type="hidden" name="rename_year" value="1">
                <input type="hidden" name="old_year" value="<?= htmlspecialchars($active_year) ?>">
                <input type="hidden" name="active_program_id" value="<?= htmlspecialchars($active_program_id) ?>">
                <input type="hidden" name="active_program_name" value="<?= htmlspecialchars($active_program_name) ?>">
                
                <div class="bg-blue-50 border border-blue-100 p-3 rounded-lg mb-5">
                    <p class="text-[9px] md:text-[10px] text-blue-800 font-medium leading-relaxed">Editing this will globally update the database for all subjects attached to this curriculum year.</p>
                </div>
                
                <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 pl-1">Curriculum Year Name</label>
                <input type="text" name="new_year" value="<?= htmlspecialchars($active_year) ?>" class="input-glossy-smooth !py-2 !px-3 w-full text-sm font-bold text-[#00205b]" required>
                
                <div class="mt-6 flex gap-3 justify-end pt-4 border-t border-slate-100">
                    <button type="button" onclick="window.closeModal('renameYearModal')" class="btn-secondary !px-4 !py-2">Cancel</button>
                    <button type="submit" class="btn-primary !px-4 !py-2">Save Update</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ADD YEAR MODAL -->
    <div id="addYearModal" class="fixed inset-0 z-[9995] hidden items-center justify-center p-4 modal-overlay bg-slate-900/60 backdrop-blur-sm">
        <div class="bg-white rounded-xl shadow-2xl border border-slate-200 w-full max-w-md modal-content overflow-hidden">
            <div class="p-4 md:p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50">
                <h3 class="font-black text-emerald-800 uppercase tracking-[0.1em] flex items-center gap-2 text-xs md:text-sm">
                    Add Curriculum Year
                </h3>
                <button type="button" onclick="window.closeModal('addYearModal')" class="text-slate-400 hover:text-rose-500 bg-white hover:bg-rose-50 p-1.5 rounded-lg transition-colors border border-slate-200 focus:outline-none"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
            </div>
            <form method="POST" class="p-4 md:p-6">
                <input type="hidden" name="add_new_year" value="1">
                <input type="hidden" name="active_program_id" value="<?= htmlspecialchars($active_program_id) ?>">
                <input type="hidden" name="active_program_name" value="<?= htmlspecialchars($active_program_name) ?>">
                <input type="hidden" name="auto_open_builder" id="auto_open_builder" value="0">
                
                <div class="bg-emerald-50 border border-emerald-100 p-3 rounded-lg mb-5">
                    <p class="text-[9px] md:text-[10px] text-emerald-800 font-medium leading-relaxed">This will create a new, empty curriculum block in the database for <strong class="font-black"><?= htmlspecialchars($active_program_name) ?></strong>.</p>
                </div>
                
                <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 pl-1">New Year Identifier</label>
                <input type="text" name="new_year" placeholder="e.g. 2026-2027" class="input-glossy-smooth !py-2 !px-3 w-full text-sm font-bold text-[#00205b] focus:!border-emerald-500" required>
                
                <div class="mt-6 flex gap-3 justify-end pt-4 border-t border-slate-100">
                    <button type="button" onclick="window.closeModal('addYearModal')" class="btn-secondary !px-4 !py-2">Cancel</button>
                    <button type="submit" class="btn-primary !bg-emerald-600 hover:!bg-emerald-700 !px-4 !py-2">Create & Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- CUSTOM GLOBAL CONFIRMATION MODAL -->
    <div id="customConfirmModal" class="fixed inset-0 z-[9999] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-opacity duration-300 opacity-0 no-print">
        <div id="customConfirmCard" class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-sm p-6 text-center transform scale-90 transition-all duration-300">
            
            <div id="confirmIconContainer" class="mx-auto w-12 h-12 mb-4 rounded-full flex items-center justify-center"></div>
            
            <h3 id="confirmTitle" class="text-lg font-black text-[#00205b] uppercase tracking-wide mb-2">Confirm Action</h3>
            <p id="confirmMessage" class="text-xs text-slate-500 mb-6 font-medium leading-relaxed"></p>
            
            <div id="confirmButtons" class="flex justify-center gap-3">
                <button id="btnConfirmCancel" class="px-5 py-2.5 rounded-xl text-[10px] font-bold uppercase tracking-widest text-slate-600 bg-slate-100 hover:bg-slate-200 transition-colors">Cancel</button>
                <button id="btnConfirmProceed" class="px-5 py-2.5 rounded-xl text-[10px] font-bold uppercase tracking-widest text-white shadow-md transition-colors"></button>
            </div>
            
            <div id="confirmLoading" class="hidden flex-col items-center justify-center py-2 animate-up">
                <svg class="animate-spin h-8 w-8 text-emerald-500 mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span id="confirmSuccessText" class="text-[10px] font-black text-emerald-600 uppercase tracking-widest">Processing...</span>
            </div>
        </div>
    </div>

    <!-- JAVASCRIPT LOGIC CONFIGURATION -->
    <script>
        window.PROSPECTUS_INIT_DATA = {
            activeProgramId: '<?= addslashes($active_program_id) ?>',
            activeYear: '<?= addslashes($active_year) ?>',
            currentTab: '<?= addslashes($show_status) ?>'
        };
    </script>
    
    <script src="admin.js?v=<?php echo time(); ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="manage_prospectus.js?v=<?php echo time(); ?>"></script>
</body>
</html>