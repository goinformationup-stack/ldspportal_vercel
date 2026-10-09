<?php
if (session_status() === PHP_SESSION_NONE) {
    $session_lifetime = 60 * 60 * 24 * 30; ini_set('session.gc_maxlifetime', $session_lifetime);
    session_set_cookie_params($session_lifetime, '/'); session_name('LDSP_STAFF_SESSION'); session_start();
}
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false); header("Pragma: no-cache");

include "../dbconn.php";
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header("Location: ../index.php"); exit(); }

// =========================================================
// AUTO-CREATE TABLES IF MISSING
// =========================================================
try {
    $conn->query("ALTER TABLE prospectus ADD COLUMN IF NOT EXISTS teacher_id INT NULL AFTER instructor");
    
    $conn->query("CREATE TABLE IF NOT EXISTS teaching_applications (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        teacher_id INT NOT NULL, 
        subject_id INT NOT NULL, 
        semester VARCHAR(50) NOT NULL, 
        status VARCHAR(20) DEFAULT 'Pending', 
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

// =========================================================
// FETCH ACTIVE SEMESTER FROM PORTAL SETTINGS
// =========================================================
$active_semester = '1st Semester'; // Fallback
try {
    $sem_query = $conn->query("SELECT setting_value FROM portal_settings WHERE setting_key = 'active_semester'");
    if ($sem_query && $sem_query->num_rows > 0) {
        $active_semester = $sem_query->fetch_assoc()['setting_value'];
    }
} catch (Exception $e) {}

$year_levels_order = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
$semesters_order = ['1st Semester', '2nd Semester', 'Summer'];

// =========================================================
// REUSABLE UI RENDER FUNCTIONS
// =========================================================
function renderTeachingRoster($grouped_subjects, $active_program_id, $year_levels_order, $semesters_order) {
    ob_start();
    if (empty($grouped_subjects) && $active_program_id > 0): ?>
        <div class="bg-white rounded-lg shadow-sm border border-slate-200 p-8 text-center">
            <svg class="w-10 h-10 text-slate-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
            <p class="text-slate-400 text-xs font-bold uppercase tracking-[0.2em]">No subjects available for this program</p>
        </div>
    <?php elseif ($active_program_id == 0): ?>
        <div class="bg-white rounded-lg shadow-sm border border-slate-200 p-8 text-center">
            <svg class="w-10 h-10 text-slate-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <p class="text-slate-400 text-xs font-bold uppercase tracking-[0.2em]">Please select a program</p>
        </div>
    <?php else: ?>
        <div class="space-y-6">
            <?php foreach ($year_levels_order as $yl): ?>
                <?php if (!isset($grouped_subjects[$yl])) continue; ?>
                
                <div class="ml-0">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="h-6 w-1.5 bg-[#00205b] rounded-full shrink-0 shadow-sm"></div>
                        <h3 class="text-lg font-black text-[#00205b] uppercase tracking-[0.15em] shrink-0 font-academic"><?= htmlspecialchars($yl) ?></h3>
                        <div class="flex-1 h-[2px] bg-slate-200 rounded-full"></div>
                    </div>
                    
                    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                        <?php foreach ($semesters_order as $sem): ?>
                            <?php if (!isset($grouped_subjects[$yl][$sem])) continue; ?>
                            
                            <div class="bg-white border border-slate-200 rounded-lg overflow-hidden shadow-sm flex flex-col term-block transition-all hover:shadow-md relative">
                                <div class="h-1 w-full bg-[#00205b]"></div>
                                
                                <div class="bg-slate-50 border-b border-slate-200 px-4 py-2.5 flex justify-between items-center shrink-0">
                                    <h4 class="font-black text-[#00205b] text-[0.7rem] uppercase tracking-[0.15em]"><?= htmlspecialchars($sem) ?></h4>
                                    <span class="text-[0.6rem] font-bold bg-white text-slate-500 px-2 py-0.5 rounded border border-slate-200 shadow-sm uppercase tracking-widest">
                                        <?= count($grouped_subjects[$yl][$sem]) ?> Subjects
                                    </span>
                                </div>
                                <div class="overflow-x-auto custom-scrollbar flex-1 bg-white">
                                    <table class="w-full text-left border-collapse">
                                        <thead class="bg-slate-50 border-b-2 border-slate-100 hidden sm:table-header-group">
                                            <tr>
                                                <th class="px-4 py-2 text-[0.6rem] font-bold text-slate-400 uppercase tracking-widest w-20">Code</th>
                                                <th class="px-4 py-2 text-[0.6rem] font-bold text-slate-400 uppercase tracking-widest">Subject</th>
                                                <th class="px-4 py-2 text-[0.6rem] font-bold text-slate-400 uppercase tracking-widest text-right">Faculty</th>
                                                <th class="px-4 py-2 w-12 text-center"></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100/80">
                                            <?php foreach ($grouped_subjects[$yl][$sem] as $sub): ?>
                                                <tr class="hover:bg-slate-50 transition-colors duration-200 subject-row group">
                                                    <td class="px-4 py-2 text-[0.65rem] font-black text-[#00205b] align-middle whitespace-nowrap">
                                                        <?= htmlspecialchars($sub['subject_code'] ?? 'N/A') ?>
                                                    </td>
                                                    <td class="px-4 py-2 text-[0.7rem] font-bold text-slate-700 align-middle leading-tight whitespace-normal">
                                                        <?= htmlspecialchars($sub['description'] ?? 'N/A') ?>
                                                    </td>
                                                    <td class="px-4 py-2 text-right align-middle whitespace-nowrap">
                                                        <?php 
                                                        if (!empty($sub['teacher_id'])): 
                                                            if (!empty($sub['t_last'])): ?>
                                                                <span class="text-[0.7rem] font-black text-[#00205b]">
                                                                    <?= htmlspecialchars($sub['t_last'] . ', ' . substr($sub['t_first'] ?? 'T', 0, 1) . '.') ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="text-[0.65rem] font-bold text-rose-500 uppercase tracking-widest border border-rose-200 bg-rose-50 px-2 py-0.5 rounded shadow-sm" title="ID: <?= $sub['teacher_id'] ?>">
                                                                    Deleted Acc
                                                                </span>
                                                            <?php endif; 
                                                        else: ?>
                                                            <span class="text-[0.65rem] font-bold text-slate-400 uppercase tracking-widest border border-slate-200 bg-slate-50 px-2 py-0.5 rounded shadow-sm">
                                                                Unassigned
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-4 py-2 text-center align-middle">
                                                        <button type="button" 
                                                                onclick="window.openAssignModal(<?= $sub['id'] ?>, '<?= htmlspecialchars(addslashes($sub['subject_code'])) ?>', '<?= htmlspecialchars(addslashes($sub['description'])) ?>', '<?= htmlspecialchars(addslashes($sub['program'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($sub['year_level'])) ?>', '<?= htmlspecialchars(addslashes($sub['semester'])) ?>', '<?= $sub['teacher_id'] ?? '' ?>')"
                                                                class="p-1.5 text-[#00205b] bg-slate-100 rounded-md hover:bg-[#00205b] hover:text-white transition-all inline-flex items-center justify-center border border-slate-200 shadow-sm hover:shadow" title="Designate Faculty">
                                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; 
    
    $count = count($grouped_subjects);
    return ob_get_clean();
}

function renderTeacherOptions($conn) {
    ob_start();
    $teachers = [];
    try {
        $t_query = $conn->query("
            SELECT u.id, p.first_name, p.last_name 
            FROM users u 
            JOIN user_profiles p ON u.id = p.user_id
            WHERE u.role = 'teacher' AND (u.is_archived = 0 OR u.is_archived IS NULL) 
            ORDER BY p.last_name ASC, p.first_name ASC
        ");
        if ($t_query) {
            while ($row = $t_query->fetch_assoc()) {
                $teachers[] = $row;
            }
        }
    } catch (Exception $e) {}
    ?>
    <label class="teacher-list-item flex items-center gap-3 px-3 py-2 border-b border-slate-100 cursor-pointer hover:bg-slate-50 transition-colors has-[:checked]:bg-slate-50">
        <input type="radio" name="teacher_id" value="" class="hidden peer" id="teacher_radio_unassigned">
        <div class="w-7 h-7 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center border border-slate-200 peer-checked:bg-slate-600 peer-checked:text-white transition-colors shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
        </div>
        <div class="flex flex-col flex-1">
            <span class="font-bold text-slate-700 text-xs">Leave Unassigned</span>
            <span class="text-[0.6rem] font-medium text-slate-400 uppercase tracking-widest mt-0.5">Clear current faculty</span>
        </div>
        <div class="w-4 h-4 rounded-full border-2 border-slate-300 peer-checked:border-[#00205b] peer-checked:bg-[#00205b] flex items-center justify-center transition-colors shrink-0">
            <svg class="w-2.5 h-2.5 text-white opacity-0 peer-checked:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
        </div>
    </label>
    <?php foreach ($teachers as $t): 
        $f_initial = !empty($t['first_name']) ? substr($t['first_name'], 0, 1) : 'T';
        $l_initial = !empty($t['last_name']) ? substr($t['last_name'], 0, 1) : 'C';
    ?>
        <label class="teacher-list-item flex items-center gap-3 px-3 py-2 border-b border-slate-100 cursor-pointer hover:bg-slate-50 transition-colors has-[:checked]:bg-slate-50">
            <input type="radio" name="teacher_id" value="<?= $t['id'] ?>" class="hidden peer" id="teacher_radio_<?= $t['id'] ?>">
            <div class="w-7 h-7 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center text-[0.6rem] font-black border border-slate-200 peer-checked:bg-[#00205b] peer-checked:text-white peer-checked:border-[#00205b] transition-all shrink-0">
                <?= strtoupper($f_initial . $l_initial) ?>
            </div>
            <div class="flex flex-col flex-1">
                <span class="font-bold text-[#00205b] text-xs">Prof. <?= htmlspecialchars(($t['last_name'] ?? 'Unknown') . ', ' . ($t['first_name'] ?? '')) ?></span>
                <span class="text-[0.6rem] font-mono text-slate-500 uppercase tracking-widest mt-0.5">ID: UID-<?= str_pad((int)($t['id'] ?? 0), 5, '0', STR_PAD_LEFT) ?></span>
            </div>
            <div class="w-4 h-4 rounded-full border-2 border-slate-300 peer-checked:border-[#00205b] peer-checked:bg-[#00205b] flex items-center justify-center transition-colors shrink-0">
                <svg class="w-2.5 h-2.5 text-white opacity-0 peer-checked:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
            </div>
        </label>
    <?php endforeach; 
    return ob_get_clean();
}

function renderApplicationsTable($conn, $active_semester) {
    ob_start();
    $teaching_applications = [];
    try {
        $app_query = $conn->query("
            SELECT ta.*, u.id as u_id, 
                   up.first_name, up.last_name, 
                   p.course_code, p.descriptive_title, pr.program_name
            FROM teaching_applications ta 
            JOIN users u ON ta.teacher_id = u.id 
            JOIN user_profiles up ON u.id = up.user_id
            JOIN prospectus p ON ta.subject_id = p.id 
            LEFT JOIN programs pr ON p.program_id = pr.program_id
            WHERE ta.semester = '{$conn->real_escape_string($active_semester)}' AND ta.status = 'Pending'
            ORDER BY ta.created_at ASC
        ");
        if ($app_query) {
            while($row = $app_query->fetch_assoc()) {
                $teaching_applications[] = $row;
            }
        }
    } catch (Exception $e) {}

    if (empty($teaching_applications)): ?>
        <tr>
            <td colspan="5" class="px-6 py-12 text-center text-slate-400 text-xs font-bold uppercase tracking-[0.2em] bg-white">
                No pending requests for <?= htmlspecialchars($active_semester) ?>.
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($teaching_applications as $app): ?>
            <tr class="hover:bg-slate-50 transition-colors border-b border-slate-100 last:border-0">
                <td class="px-4 py-4 text-[0.65rem] font-bold text-slate-500 align-middle whitespace-nowrap">
                    <?= date('M j, Y', strtotime($app['created_at'])) ?><br>
                    <span class="text-[0.55rem] font-mono text-slate-400"><?= date('h:i A', strtotime($app['created_at'])) ?></span>
                </td>
                <td class="px-4 py-4 align-middle whitespace-normal break-words">
                    <div class="font-bold text-[#00205b] text-sm">Prof. <?= htmlspecialchars($app['last_name'] . ', ' . $app['first_name']) ?></div>
                    <div class="text-[0.6rem] font-mono text-slate-400 uppercase tracking-widest mt-0.5">ID: UID-<?= str_pad($app['u_id'], 5, '0', STR_PAD_LEFT) ?></div>
                </td>
                <td class="px-4 py-4 text-[0.7rem] font-bold text-slate-600 align-middle whitespace-normal break-words leading-snug">
                    <?= htmlspecialchars($app['program_name'] ?? 'General') ?>
                </td>
                <td class="px-4 py-4 align-middle whitespace-normal break-words">
                    <div class="font-black text-[#00205b] text-[0.75rem] leading-tight"><?= htmlspecialchars($app['course_code']) ?></div>
                    <div class="text-[0.65rem] font-medium text-slate-500 mt-0.5 leading-snug"><?= htmlspecialchars($app['descriptive_title']) ?></div>
                </td>
                <td class="px-4 py-4 text-center align-middle">
                    <div class="flex flex-col gap-1.5 w-full max-w-[100px] mx-auto">
                        <button type="button" 
                                onclick="window.openAssignModal(<?= $app['subject_id'] ?>, '<?= htmlspecialchars(addslashes($app['course_code'])) ?>', '<?= htmlspecialchars(addslashes($app['descriptive_title'])) ?>', '<?= htmlspecialchars(addslashes($app['program_name'] ?? '')) ?>', 'TBD', '<?= htmlspecialchars(addslashes($active_semester)) ?>', '<?= $app['teacher_id'] ?>')"
                                class="w-full px-2 py-1.5 bg-[#00205b] hover:bg-[#001233] text-white rounded text-[0.6rem] font-black uppercase tracking-widest shadow-sm transition-colors text-center">
                            Assign
                        </button>
                        <button type="button" 
                                onclick="window.denyApplication(<?= $app['id'] ?>)"
                                class="w-full px-2 py-1.5 bg-slate-100 text-slate-500 hover:bg-slate-200 hover:text-slate-800 border border-slate-200 rounded text-[0.6rem] font-black uppercase tracking-widest shadow-sm transition-colors text-center">
                            Deny
                        </button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; 
    
    $count = count($teaching_applications);
    return ['html' => ob_get_clean(), 'count' => $count];
}
?>

<?php
// =========================================================
// AJAX ENDPOINT: HANDLE ASSIGNMENTS IN REAL-TIME
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_teacher_ajax') {
    header('Content-Type: application/json');
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $teacher_id = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : NULL;
    $current_semester = $_POST['active_semester_flag'] ?? $active_semester;

    try {
        if ($teacher_id === NULL) {
            $stmt = $conn->prepare("UPDATE prospectus SET teacher_id = NULL WHERE id = ?");
            $stmt->bind_param("i", $subject_id);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("UPDATE prospectus SET teacher_id = ? WHERE id = ?");
            $stmt->bind_param("ii", $teacher_id, $subject_id);
            $stmt->execute();

            if(!empty($current_semester)) {
                $appr = $conn->prepare("UPDATE teaching_applications SET status = 'Approved' WHERE subject_id = ? AND teacher_id = ? AND semester = ?");
                $appr->bind_param("iis", $subject_id, $teacher_id, $current_semester);
                $appr->execute();

                $rej = $conn->prepare("UPDATE teaching_applications SET status = 'Rejected' WHERE subject_id = ? AND teacher_id != ? AND semester = ? AND status = 'Pending'");
                $rej->bind_param("iis", $subject_id, $teacher_id, $current_semester);
                $rej->execute();
            }
        }
        echo json_encode(['status' => 'success', 'message' => 'Teacher designation updated.']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to assign teacher.']);
    }
    exit();
}

// =========================================================
// AJAX ENDPOINT: MANUAL DENY APPLICATION
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deny_application_ajax') {
    header('Content-Type: application/json');
    $app_id = (int)($_POST['application_id'] ?? 0);
    try {
        $stmt = $conn->prepare("UPDATE teaching_applications SET status = 'Rejected' WHERE id = ?");
        $stmt->bind_param("i", $app_id);
        $stmt->execute();
        echo json_encode(['status' => 'success', 'message' => 'Application denied successfully.']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to deny application.']);
    }
    exit();
}

// =========================================================
// AJAX ENDPOINT: REAL-TIME DATA REFRESH
// =========================================================
if (isset($_GET['api_refresh'])) {
    header('Content-Type: application/json');
    $prog_id = (int)($_GET['program_id'] ?? 0);
    
    $grouped_subjects = [];
    $total_assigned = 0;
    $total_unassigned = 0;
    
    if ($prog_id > 0) {
        $s_query = $conn->query("
            SELECT p.id, p.course_code as subject_code, p.descriptive_title as description, p.year_level, p.semester, pr.program_name as program, p.teacher_id, t_p.first_name as t_first, t_p.last_name as t_last 
            FROM prospectus p 
            LEFT JOIN programs pr ON p.program_id = pr.program_id
            LEFT JOIN users t_u ON p.teacher_id = t_u.id 
            LEFT JOIN user_profiles t_p ON t_u.id = t_p.user_id
            WHERE IFNULL(p.is_archived, 0) = 0 AND p.program_id = $prog_id
            ORDER BY p.year_level ASC, p.semester ASC, p.course_code ASC
        ");
        if ($s_query) {
            while ($row = $s_query->fetch_assoc()) {
                $grouped_subjects[$row['year_level']][$row['semester']][] = $row;
                if (!empty($row['teacher_id']) && !empty($row['t_last'])) $total_assigned++;
                else $total_unassigned++;
            }
        }
    }
    
    $app_data = renderApplicationsTable($conn, $active_semester);
    $teachers_html = renderTeacherOptions($conn);
    
    echo json_encode([
        'html' => renderTeachingRoster($grouped_subjects, $prog_id, $year_levels_order, $semesters_order),
        'assigned' => $total_assigned,
        'unassigned' => $total_unassigned,
        'applications_html' => $app_data['html'],
        'applications_count' => $app_data['count'],
        'teachers_html' => $teachers_html
    ]);
    exit();
}

// Initial renders for direct page load
$app_data_initial = renderApplicationsTable($conn, $active_semester);
$teachers_initial = renderTeacherOptions($conn);

$programs = [];
try {
    $p_query = $conn->query("SELECT program_id, program_name FROM programs WHERE is_archived = 0 OR is_archived IS NULL ORDER BY program_name ASC");
    if (!$p_query) $p_query = $conn->query("SELECT program_id, program_name FROM programs ORDER BY program_name ASC");
    if ($p_query && $p_query->num_rows > 0) {
        while ($row = $p_query->fetch_assoc()) $programs[$row['program_id']] = $row['program_name'];
    }
} catch (Exception $e) {}

$active_program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : (empty($programs) ? 0 : array_key_first($programs));
$active_program_name = $programs[$active_program_id] ?? 'Unassigned Program';

$grouped_subjects = [];
$total_assigned = 0;
$total_unassigned = 0;

if ($active_program_id > 0) {
    try {
        $s_query = $conn->query("
            SELECT p.id, p.course_code as subject_code, p.descriptive_title as description, p.year_level, p.semester, pr.program_name as program, p.teacher_id, t_p.first_name as t_first, t_p.last_name as t_last 
            FROM prospectus p 
            LEFT JOIN programs pr ON p.program_id = pr.program_id
            LEFT JOIN users t_u ON p.teacher_id = t_u.id 
            LEFT JOIN user_profiles t_p ON t_u.id = t_p.user_id
            WHERE IFNULL(p.is_archived, 0) = 0 AND p.program_id = $active_program_id
            ORDER BY p.year_level ASC, p.semester ASC, p.course_code ASC
        ");
        if ($s_query) {
            while ($row = $s_query->fetch_assoc()) {
                $grouped_subjects[$row['year_level']][$row['semester']][] = $row;
                if (!empty($row['teacher_id']) && !empty($row['t_last'])) $total_assigned++;
                else $total_unassigned++;
            }
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Teaching Load - LDSP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800;900&family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css?v=<?php echo time(); ?>">
</head>
<body class="flex h-screen overflow-hidden antialiased bg-slate-50">
    <div class="ambient-orb-1"></div>
    <div class="ambient-orb-2"></div>
    
    <!-- TOAST CONTAINER -->
    <div id="toastContainer" class="fixed top-5 right-5 z-[100] flex flex-col gap-3 pointer-events-none"></div>

    <?php include 'sidebar.php'; ?>

    <main class="flex-1 min-w-0 overflow-y-auto h-full w-full pt-20 md:pt-0 relative z-10 custom-scrollbar">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1400px] mx-auto">
            
            <header class="mb-5 animate-up">
                <div class="flex items-center gap-2 text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">
                    <span>Administrator</span> <span class="text-slate-400">/</span> <span class="text-[#00205b]">Curriculum & Faculty</span>
                </div>
                <h1 class="text-2xl md:text-3xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">Assign Teaching Load</h1>
            </header>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6 animate-up delay-1">
                
                <div class="bg-white rounded-lg p-4 md:p-5 border border-slate-200 border-t-[3px] border-t-slate-400 cursor-pointer group hover:-translate-y-1 transition-all duration-300 relative overflow-hidden shadow-sm" onclick="window.location.href='manage_prospectus.php'">
                    <div class="flex justify-between items-start relative z-10">
                        <div>
                            <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest mb-1 group-hover:text-[#00205b] transition-colors">Curriculum Origin</p>
                            <p class="text-lg font-black text-[#00205b] drop-shadow-sm leading-tight">Manage<br>Prospectus</p>
                        </div>
                        <div class="bg-slate-100 p-2 rounded-lg text-slate-500 shadow-sm border border-slate-200 group-hover:bg-[#00205b] group-hover:text-white transition-all duration-300">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2-2v8a2 2 0 002 2z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg p-4 md:p-5 border border-slate-200 border-t-[3px] border-t-slate-400 shadow-sm">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest mb-1">Assigned Subjects</p>
                            <p class="text-3xl font-black text-[#00205b] drop-shadow-sm" id="stat_assigned"><?= $total_assigned ?></p>
                        </div>
                        <div class="bg-slate-100 p-2 rounded-lg text-slate-600 shadow-sm border border-slate-200">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg p-4 md:p-5 border border-slate-200 border-t-[3px] border-t-slate-400 shadow-sm">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest mb-1">Unassigned Subjects</p>
                            <p class="text-3xl font-black text-[#00205b] drop-shadow-sm" id="stat_unassigned"><?= $total_unassigned ?></p>
                        </div>
                        <div class="bg-slate-100 p-2 rounded-lg text-slate-600 shadow-sm border border-slate-200">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg p-4 md:p-5 border border-slate-200 border-t-[3px] border-t-[#00205b] cursor-pointer group hover:-translate-y-1 transition-all duration-300 relative overflow-hidden shadow-sm" onclick="window.openModal('applicationsModal')">
                    <div class="flex justify-between items-start relative z-10">
                        <div>
                            <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest mb-1 group-hover:text-[#00205b] transition-colors">Faculty Requests</p>
                            <div class="flex items-center gap-2">
                                <p class="text-lg font-black text-[#00205b] drop-shadow-sm leading-tight">Teaching<br>Applications</p>
                                <span id="applications_card_count" class="<?= $app_data_initial['count'] > 0 ? '' : 'hidden' ?> bg-[#00205b] text-white text-[10px] font-black px-2 py-0.5 rounded shadow-sm"><?= $app_data_initial['count'] ?></span>
                            </div>
                        </div>
                        <div class="bg-slate-100 p-2 rounded-lg text-[#00205b] shadow-sm border border-slate-200 group-hover:bg-[#00205b] group-hover:text-white transition-all duration-300">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                            </svg>
                        </div>
                    </div>
                </div>

            </div>

            <!-- MASTER ALLOCATION PANEL -->
            <div class="bg-white rounded-xl flex flex-col z-20 shadow-sm border border-slate-200 border-t-[4px] !border-t-[#00205b] mb-6 animate-up delay-2">
                
                <div class="p-4 md:p-5 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-3 border-b border-slate-200 bg-slate-50 relative z-20 rounded-t-lg">
                    <div>
                        <h2 class="text-lg font-black text-[#1e293b] uppercase tracking-[0.1em] font-academic">Curriculum Allocations</h2>
                        <p class="text-[0.65rem] font-bold text-slate-500 uppercase tracking-[0.15em] mt-0.5">Viewing: <strong class="text-[#00205b]" id="active_program_label"><?= htmlspecialchars($active_program_name) ?></strong></p>
                    </div>
                    
                    <form method="GET" class="flex flex-col sm:flex-row items-center gap-2.5 w-full lg:w-auto">
                        <div class="w-full lg:w-72">
                            <select id="programSelector" onchange="window.changeProgram(this)" class="w-full bg-white border border-slate-300 text-[#00205b] text-xs font-bold rounded-lg pl-3 pr-8 py-2 shadow-sm focus:outline-none focus:border-[#00205b] focus:ring-1 focus:ring-[#00205b] transition-all cursor-pointer">
                                <?php if (empty($programs)): ?>
                                    <option value="0">No Programs Found</option>
                                <?php else: ?>
                                    <?php foreach($programs as $id => $name): ?>
                                        <option value="<?= htmlspecialchars($id) ?>" data-name="<?= htmlspecialchars($name) ?>" <?= $active_program_id == $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="relative w-full lg:w-60">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                            </div>
                            <input type="text" id="subjectSearch" onkeyup="window.filterSubjects()" placeholder="Search Subject or Code..." class="w-full pl-9 pr-3 py-2 bg-white border border-slate-300 rounded-lg text-[0.75rem] font-semibold text-slate-700 focus:outline-none focus:border-[#00205b] focus:ring-1 focus:ring-[#00205b] transition-all shadow-sm placeholder:text-slate-400">
                        </div>
                    </form>
                </div>

                <div id="master_roster_container" class="p-4 md:p-5 bg-white transition-opacity duration-300 rounded-b-xl">
                    <?= renderTeachingRoster($grouped_subjects, $active_program_id, $year_levels_order, $semesters_order) ?>
                </div>

            </div>
        </div>
    </main>

    <!-- FORMAL ASSIGN TEACHER MODAL -->
    <div id="assignTeacherModal" class="fixed inset-0 z-[70] hidden items-center justify-center p-4 modal-overlay bg-slate-900/70 backdrop-blur-md">
        <div class="bg-white rounded-xl shadow-2xl border border-slate-200 w-full max-w-md modal-content overflow-hidden relative flex flex-col max-h-[90vh]">
            
            <div class="bg-slate-50 border-b border-slate-200 px-5 py-4 relative shrink-0 flex justify-between items-start">
                <div class="flex flex-col">
                    <h3 class="text-sm font-black text-[#00205b] uppercase tracking-wide mb-1">Designate Faculty</h3>
                    <span id="mod_sub_code" class="text-[0.65rem] font-bold text-slate-500 uppercase tracking-widest">CODE</span>
                    <span id="mod_sub_desc" class="text-xs font-semibold text-slate-800 leading-tight">Descriptive Title</span>
                </div>
                <button type="button" onclick="window.closeModal('assignTeacherModal')" class="text-slate-400 hover:text-slate-600 focus:outline-none bg-white border border-slate-200 p-1.5 rounded-md hover:bg-slate-100 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
            
            <form id="ajaxAssignForm" onsubmit="window.submitTeacherAssignment(event)" class="flex flex-col flex-1 overflow-hidden bg-white">
                <input type="hidden" name="action" value="assign_teacher_ajax">
                <input type="hidden" name="subject_id" id="mod_sub_id" value="">
                <input type="hidden" name="current_program_id" value="<?= htmlspecialchars($active_program_id) ?>">
                <input type="hidden" name="active_semester_flag" value="<?= htmlspecialchars($active_semester) ?>">

                <div class="p-4 flex flex-col flex-1 overflow-hidden">
                    <div class="relative w-full mb-3">
                        <div class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none">
                            <svg class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                        </div>
                        <input type="text" id="teacherSearch" onkeyup="window.filterTeachers()" placeholder="Search faculty name..." class="w-full pl-8 pr-3 py-2 bg-slate-50 border border-slate-200 rounded-md text-xs font-semibold text-slate-700 focus:outline-none focus:border-[#00205b] transition-all shadow-sm placeholder:text-slate-400">
                    </div>
                    
                    <div class="bg-white border border-slate-200 rounded-md overflow-hidden flex flex-col flex-1">
                        <div id="teacher_options_container" class="overflow-y-auto custom-scrollbar" style="max-height: 250px;"> 
                            <?= $teachers_initial ?>
                        </div>
                    </div>
                </div>

                <div class="p-4 bg-slate-50 border-t border-slate-200 flex flex-col sm:flex-row justify-end gap-2.5 shrink-0">
                    <button type="button" onclick="window.closeModal('assignTeacherModal')" class="w-full sm:w-auto px-5 py-2 rounded-md text-[0.65rem] font-bold uppercase tracking-widest text-slate-600 bg-white border border-slate-300 hover:bg-slate-100 transition-colors text-center focus:outline-none">Cancel</button>
                    <button type="submit" class="w-full sm:w-auto justify-center bg-[#00205b] hover:bg-[#001233] text-white font-bold rounded-md px-6 py-2 text-[0.65rem] uppercase tracking-widest flex items-center gap-1.5 shadow-sm transition-colors focus:outline-none">
                        Confirm Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- TEACHING APPLICATIONS MODAL (RESPONSIVE FIX) -->
    <div id="applicationsModal" class="fixed inset-0 z-[60] hidden items-center justify-center p-4 modal-overlay bg-slate-900/70 backdrop-blur-md">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-5xl modal-content overflow-hidden relative flex flex-col max-h-[90vh]">
            
            <div class="bg-slate-50 px-5 py-4 border-b border-slate-200 flex justify-between items-center shrink-0">
                <div class="flex items-center gap-3">
                    <div class="bg-[#00205b]/10 text-[#00205b] p-1.5 rounded-md">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-[#00205b] leading-tight">Faculty Requests</h3>
                        <p class="text-[0.65rem] font-bold text-slate-500 uppercase tracking-widest mt-0.5">Term: <?= htmlspecialchars($active_semester) ?></p>
                    </div>
                </div>
                <button type="button" onclick="window.closeModal('applicationsModal')" class="text-slate-400 hover:text-slate-600 bg-white hover:bg-slate-200 border border-slate-200 p-1.5 rounded-md transition-colors focus:outline-none">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <div class="p-0 flex flex-col flex-1 overflow-hidden bg-white">
                <div class="overflow-x-auto overflow-y-auto custom-scrollbar flex-1">
                    <table class="w-full text-left border-collapse min-w-[800px]">
                        <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-20">
                            <tr>
                                <th class="px-4 py-3 text-[0.6rem] font-black text-slate-500 uppercase tracking-widest w-[12%]">Date</th>
                                <th class="px-4 py-3 text-[0.6rem] font-black text-slate-500 uppercase tracking-widest w-[20%]">Faculty Name</th>
                                <th class="px-4 py-3 text-[0.6rem] font-black text-slate-500 uppercase tracking-widest w-[25%]">Target Program</th>
                                <th class="px-4 py-3 text-[0.6rem] font-black text-slate-500 uppercase tracking-widest w-[25%]">Subject Requested</th>
                                <th class="px-4 py-3 text-[0.6rem] font-black text-slate-500 uppercase tracking-widest text-center w-[18%]">Action</th>
                            </tr>
                        </thead>
                        <tbody id="applications_tbody" class="bg-white">
                            <?= $app_data_initial['html'] ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="p-3 bg-slate-50 border-t border-slate-200 flex justify-end shrink-0">
                <button type="button" onclick="window.closeModal('applicationsModal')" class="px-5 py-2 rounded-md text-[0.65rem] font-bold uppercase tracking-widest text-slate-600 bg-white border border-slate-300 hover:bg-slate-100 transition-colors focus:outline-none">Close Window</button>
            </div>
        </div>
    </div>

    <!-- DATA BRIDGE -->
    <script>
        window.TEACHING_INIT_DATA = {
            activeProgramId: '<?= addslashes($active_program_id) ?>',
            activeSemester: '<?= addslashes($active_semester) ?>'
        };
    </script>
    
    <!-- EXTERNAL JS -->
    <script src="admin.js?v=<?php echo time(); ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="manage_teaching.js?v=<?php echo time(); ?>"></script>
</body>
</html>