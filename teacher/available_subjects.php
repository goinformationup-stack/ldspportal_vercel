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

// CRITICAL FIX: Schema integrity check
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

$teacher_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;

$active_semester = '1st Semester';
try {
    $sem_query = $conn->query("SELECT setting_value FROM portal_settings WHERE setting_key = 'active_semester'");
    if ($sem_query && $sem_query->num_rows > 0) $active_semester = $sem_query->fetch_assoc()['setting_value'];
} catch (Exception $e) {}

$programs = [];
try {
    $p_query = $conn->query("SELECT program_name FROM programs WHERE is_archived = 0 OR is_archived IS NULL ORDER BY program_name ASC");
    if ($p_query && $p_query->num_rows > 0) {
        while ($row = $p_query->fetch_assoc()) $programs[] = $row['program_name'];
    }
} catch (Exception $e) {}

// AJAX POST HANDLERS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $subject_id = (int)($_POST['subject_id'] ?? 0);

    if ($action === 'apply') {
        try {
            $avail_check = $conn->prepare("SELECT teacher_id FROM prospectus WHERE id = ?");
            $avail_check->bind_param("i", $subject_id);
            $avail_check->execute();
            $avail_res = $avail_check->get_result()->fetch_assoc();
            
            if (!empty($avail_res['teacher_id'])) { echo json_encode(['status' => 'error', 'message' => 'Subject is already assigned.']); exit; }

            $check = $conn->prepare("SELECT id, status FROM teaching_applications WHERE teacher_id = ? AND subject_id = ? AND semester = ?");
            $check->bind_param("iis", $teacher_id, $subject_id, $active_semester);
            $check->execute();
            $check_res = $check->get_result();

            if ($check_res->num_rows === 0) {
                $insert = $conn->prepare("INSERT INTO teaching_applications (teacher_id, subject_id, semester, status) VALUES (?, ?, ?, 'Pending')");
                $insert->bind_param("iis", $teacher_id, $subject_id, $active_semester);
                $insert->execute();
                echo json_encode(['status' => 'success', 'message' => 'Application submitted.']);
            } else {
                $existing = $check_res->fetch_assoc();
                if ($existing['status'] === 'Rejected' || $existing['status'] === 'Approved') {
                    $update = $conn->prepare("UPDATE teaching_applications SET status = 'Pending', created_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $update->bind_param("i", $existing['id']);
                    $update->execute();
                    echo json_encode(['status' => 'success', 'message' => 'Application submitted.']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Active application exists.']);
                }
            }
        } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => 'Action failed.']); }
        exit;
    }

    if ($action === 'unapply') {
        try {
            $del = $conn->prepare("DELETE FROM teaching_applications WHERE teacher_id = ? AND subject_id = ? AND semester = ? AND status = 'Pending'");
            $del->bind_param("iis", $teacher_id, $subject_id, $active_semester);
            $del->execute();
            echo json_encode(['status' => 'success', 'message' => 'Application revoked.']);
        } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => 'Failed to revoke.']); }
        exit;
    }

    if ($action === 'remove_obsolete') {
        try {
            $del = $conn->prepare("DELETE FROM teaching_applications WHERE teacher_id = ? AND subject_id = ? AND semester = ? AND status IN ('Rejected', 'Approved')");
            $del->bind_param("iis", $teacher_id, $subject_id, $active_semester);
            $del->execute();
            echo json_encode(['status' => 'success', 'message' => 'Application removed.']);
        } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => 'Failed to remove.']); }
        exit;
    }
}

// GET FETCH HANDLERS
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_fetch'])) {
    header('Content-Type: application/json');
    $fetch_type = $_GET['ajax_fetch'];

    if ($fetch_type === 'applications') {
        $html = '';
        $app_q = $conn->prepare("
            SELECT ta.subject_id, ta.status, p.course_code, p.descriptive_title, p.teacher_id as assigned_teacher
            FROM teaching_applications ta
            JOIN prospectus p ON ta.subject_id = p.id
            WHERE ta.teacher_id = ? AND ta.semester = ?
            ORDER BY ta.created_at DESC
        ");
        $app_q->bind_param("is", $teacher_id, $active_semester);
        $app_q->execute();
        $res = $app_q->get_result();
        $count = $res->num_rows;

        if ($count > 0) {
            while ($row = $res->fetch_assoc()) {
                $is_taken = (!empty($row['assigned_teacher']) && $row['assigned_teacher'] != $teacher_id);
                $is_mine_currently = ($row['assigned_teacher'] == $teacher_id);
                $is_obsolete = ($row['status'] === 'Approved' && !$is_mine_currently);

                if ($is_obsolete) {
                    $statusColor = 'bg-slate-100 text-slate-600 border-slate-300';
                    $displayStatus = 'Unassigned';
                } else {
                    $statusColor = $row['status'] === 'Approved' ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : 
                                  ($row['status'] === 'Rejected' ? 'bg-slate-100 text-slate-600 border-slate-300' : 'bg-slate-100 text-[#00205b] border-slate-300');
                    $displayStatus = $row['status'] === 'Rejected' ? 'Denied' : $row['status'];
                }

                $html .= '
                <div class="bg-white border border-slate-300 rounded p-3 shadow-sm flex flex-col mb-2">
                    <div class="flex justify-between items-start gap-2">
                        <div class="flex flex-col flex-1">
                            <span class="font-mono text-[#00205b] font-bold text-xs">'.htmlspecialchars($row['course_code']).'</span>
                            <h4 class="font-semibold text-slate-800 text-xs mt-0.5">'.htmlspecialchars($row['descriptive_title']).'</h4>
                        </div>
                        <span class="text-[9px] font-bold uppercase px-2 py-0.5 rounded-sm border '.$statusColor.' shrink-0">'.htmlspecialchars($displayStatus).'</span>
                    </div>
                    '.($row['status'] === 'Pending' ? '
                    <div class="mt-2 pt-2 border-t border-slate-100 flex justify-end">
                        <button onclick="handleApplication(\'unapply\', '.$row['subject_id'].')" class="text-[10px] font-bold text-slate-500 hover:text-rose-600 transition-colors focus:outline-none">Revoke Application</button>
                    </div>' : '').'
                    '.(($row['status'] === 'Rejected' || $is_obsolete) ? '
                    <div class="mt-2 pt-2 border-t border-slate-100 flex justify-end gap-3">
                        <button onclick="handleApplication(\'remove_obsolete\', '.$row['subject_id'].')" class="text-[10px] font-bold text-slate-500 hover:text-slate-700 transition-colors focus:outline-none">Dismiss</button>
                        '.(!$is_taken ? '
                        <button onclick="handleApplication(\'apply\', '.$row['subject_id'].')" class="text-[10px] font-bold text-[#00205b] hover:text-[#001233] transition-colors focus:outline-none">Re-Apply</button>' : '').'
                    </div>' : '').'
                </div>';
            }
        } else {
            $html = '<div class="w-full text-center py-4 text-slate-500 text-xs">No active applications.</div>';
        }
        echo json_encode(['html' => $html, 'count' => $count]);
        exit;
    }

    if ($fetch_type === 'available_subjects') {
        $html = '';
        $my_apps = [];
        $app_q = $conn->prepare("SELECT subject_id, status FROM teaching_applications WHERE teacher_id = ? AND semester = ?");
        $app_q->bind_param("is", $teacher_id, $active_semester);
        $app_q->execute();
        $app_res = $app_q->get_result();
        while ($r = $app_res->fetch_assoc()) $my_apps[$r['subject_id']] = $r['status'];

        $avail_q = $conn->prepare("
            SELECT p.id, p.course_code, p.descriptive_title, pr.program_name, p.year_level, p.semester, p.teacher_id,
                   up.first_name as t_first, up.last_name as t_last
            FROM prospectus p
            LEFT JOIN programs pr ON p.program_id = pr.program_id
            LEFT JOIN users t ON p.teacher_id = t.id
            LEFT JOIN user_profiles up ON t.id = up.user_id
            WHERE p.semester = ? AND (p.is_archived = 0 OR p.is_archived IS NULL)
            ORDER BY p.program_id ASC, p.year_level ASC, p.course_code ASC
        ");
        $avail_q->bind_param("s", $active_semester);
        $avail_q->execute();
        $res = $avail_q->get_result();

        if ($res->num_rows > 0) {
            while ($sub = $res->fetch_assoc()) {
                $is_mine = ($sub['teacher_id'] == $teacher_id);
                $is_assigned = (!empty($sub['teacher_id']) && !$is_mine);
                $applied_status = $my_apps[$sub['id']] ?? null;
                $is_obsolete_app = ($applied_status === 'Approved' && empty($sub['teacher_id']));

                $action_html = ''; $status_html = '';

                $btn_base = "w-full text-[10px] font-bold uppercase tracking-wider px-2 py-1.5 rounded-sm border transition-colors focus:outline-none";

                if ($is_mine) {
                    $status_html = '<span class="text-[#00205b] font-bold">Your Class</span>';
                    $action_html = '<button disabled class="'.$btn_base.' bg-slate-100 text-slate-400 border-slate-200 cursor-not-allowed">Assigned</button>';
                } elseif ($is_assigned) {
                    $prof_name = !empty($sub['t_last']) ? htmlspecialchars($sub['t_last']) : 'Unknown';
                    $status_html = '<div class="flex flex-col"><span class="text-slate-700 font-bold mb-0.5">Taken</span><span class="text-[9px] text-slate-500">Prof. '.$prof_name.'</span></div>';
                    $action_html = '<button disabled class="'.$btn_base.' bg-slate-100 text-slate-400 border-slate-200 cursor-not-allowed">Unavailable</button>';
                } elseif ($applied_status === 'Rejected' || $is_obsolete_app) {
                    $status_html = $applied_status === 'Rejected' ? '<span class="text-slate-500 font-bold">Denied</span>' : '<span class="text-emerald-600 font-bold">Available</span>';
                    $action_html = '<button onclick="handleApplication(\'apply\', '.$sub['id'].')" class="'.$btn_base.' bg-white border-slate-300 text-[#00205b] hover:bg-[#00205b] hover:text-white hover:border-[#00205b] shadow-sm">Apply</button>';
                } elseif ($applied_status) {
                    $status_html = '<span class="text-[#00205b] font-bold">Pending</span>';
                    $action_html = '<button disabled class="'.$btn_base.' bg-slate-100 text-slate-500 border-slate-300 cursor-not-allowed">Waiting</button>';
                } else {
                    $status_html = '<span class="text-emerald-600 font-bold">Available</span>';
                    $action_html = '<button onclick="handleApplication(\'apply\', '.$sub['id'].')" class="'.$btn_base.' bg-white border-slate-300 text-[#00205b] hover:bg-[#00205b] hover:text-white hover:border-[#00205b] shadow-sm">Apply</button>';
                }

                $html .= '
                <tr class="hover:bg-slate-50 transition-colors apply-subject-row">
                    <td class="border-b border-slate-200 px-3 py-3 align-middle font-mono text-xs font-bold text-[#00205b] w-[12%] text-center">'.htmlspecialchars($sub['course_code']).'</td>
                    <td class="border-b border-slate-200 px-3 py-3 align-middle text-xs font-semibold text-slate-800 w-[35%] whitespace-normal break-words">'.htmlspecialchars($sub['descriptive_title']).'</td>
                    <td class="border-b border-slate-200 px-3 py-3 align-middle text-[11px] text-slate-600 w-[23%] whitespace-normal break-words">'.htmlspecialchars($sub['program_name'] ?? 'General').'</td>
                    <td class="border-b border-slate-200 px-3 py-3 align-middle text-center text-[11px] text-slate-700 font-medium w-[10%]">'.htmlspecialchars($sub['year_level']).'</td>
                    <td class="border-b border-slate-200 px-3 py-3 align-middle text-center text-[10px] uppercase w-[10%]">'.$status_html.'</td>
                    <td class="border-b border-slate-200 px-2 py-2 align-middle text-center w-[10%]">'.$action_html.'</td>
                </tr>';
            }
        } else {
            $html = '<tr><td colspan="6" class="border-b border-slate-200 p-8 text-center text-slate-500 text-xs font-medium">No subjects are available for application at this time.</td></tr>';
        }
        echo json_encode(['html' => $html]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Subjects - LDSP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css?v=<?php echo time(); ?>">
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 8px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .dropdown-open { opacity: 1; transform: scaleY(1); visibility: visible; }
        .dropdown-closed { opacity: 0; transform: scaleY(0.95); visibility: hidden; }
        .animate-up { opacity: 0; animation: fadeUpSmooth 0.5s ease-out forwards; }
        @keyframes fadeUpSmooth { 0% { opacity: 0; transform: translateY(15px); } 100% { opacity: 1; transform: translateY(0); } }
        
        .excel-wall { border-collapse: collapse; width: 100%; }
        .excel-wall th { background-color: #f8fafc; color: #475569; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #e2e8f0; padding: 0.75rem 0.5rem; position: sticky; top: 0; z-index: 10; }
        .excel-wall td { border-bottom: 1px solid #e2e8f0; }
    </style>
</head>
<body class="flex h-screen overflow-hidden antialiased bg-slate-50 text-slate-800 font-sans">
    
    <div id="toastContainer" class="fixed top-5 right-5 z-[100] flex flex-col gap-3 pointer-events-none"></div>

    <?php include 'sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto h-full w-full pt-20 md:pt-0 relative custom-scrollbar z-10 p-4 md:p-6">
        <div class="max-w-[1600px] mx-auto relative z-20 h-full flex flex-col">
            
            <header class="mb-4 flex flex-col md:flex-row md:justify-between md:items-end gap-4 animate-up shrink-0">
                <div>
                    <h1 class="text-2xl font-black text-slate-900 tracking-tight">Subject Offerings</h1>
                    <p class="text-xs text-slate-500 mt-1 font-medium">Curriculum Database connected to Admission active parameters.</p>
                </div>
                
                <div>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-white text-[#00205b] rounded text-[10px] font-bold border border-slate-200 shadow-sm uppercase tracking-widest">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2-2v12a2 2 0 002 2z"/></svg>
                        Term: <?= htmlspecialchars($active_semester) ?>
                    </span>
                </div>
            </header>

            <div class="flex-1 flex flex-col bg-white border border-slate-200 rounded-lg shadow-sm animate-up delay-1 overflow-hidden">
                
                <!-- Toolbar Area -->
                <div class="p-3 border-b border-slate-200 bg-slate-50 flex flex-col xl:flex-row justify-between items-start xl:items-center gap-3 shrink-0">
                    <div class="flex flex-col sm:flex-row items-center gap-2 w-full xl:w-auto">
                        <select id="filterProgram" onchange="filterApplySubjects()" class="bg-white border border-slate-300 text-slate-700 text-xs rounded-md focus:outline-none focus:border-[#00205b] block w-full sm:w-48 p-2 cursor-pointer shadow-sm">
                            <option value="">All Programs</option>
                            <?php foreach($programs as $p): ?><option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option><?php endforeach; ?>
                        </select>
                        
                        <select id="filterYear" onchange="filterApplySubjects()" class="bg-white border border-slate-300 text-slate-700 text-xs rounded-md focus:outline-none focus:border-[#00205b] block w-full sm:w-32 p-2 cursor-pointer shadow-sm">
                            <option value="">All Years</option>
                            <option value="1st Year">1st Year</option><option value="2nd Year">2nd Year</option><option value="3rd Year">3rd Year</option><option value="4th Year">4th Year</option>
                        </select>
                        
                        <div class="relative w-full sm:w-48">
                            <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none">
                                <svg class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                            </div>
                            <input type="text" id="applySearch" onkeyup="filterApplySubjects()" placeholder="Search Subjects..." class="bg-white border border-slate-300 text-slate-700 text-xs rounded-md focus:outline-none focus:border-[#00205b] block w-full pl-8 p-2 shadow-sm">
                        </div>
                    </div>

                    <div class="relative w-full sm:w-auto" id="dropdown_wrapper">
                        <button onclick="toggleApplicationsCard()" class="w-full sm:w-auto flex items-center justify-center gap-1.5 bg-[#00205b] border border-[#001233] text-white px-4 py-2 rounded-md text-xs font-bold transition-colors focus:outline-none hover:bg-[#003882] shadow-sm">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg> 
                            My Requests
                            <span id="my_apps_count" class="bg-white text-[#00205b] px-1.5 py-0.5 rounded text-[9px] ml-1">0</span>
                        </button>
                        
                        <div id="floating_apps_card" class="dropdown-closed absolute right-0 top-full mt-1 w-full sm:w-[350px] bg-white border border-slate-300 shadow-xl z-50 overflow-hidden transform origin-top-right transition-all duration-200 rounded-lg">
                            <div class="bg-slate-50 border-b border-slate-200 px-3 py-2 flex justify-between items-center">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-[#00205b] flex items-center gap-1.5">Application Status</span>
                                <button onclick="toggleApplicationsCard()" class="text-slate-400 hover:text-slate-600 focus:outline-none"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
                            </div>
                            <div id="my_applications_container" class="max-h-[300px] overflow-y-auto custom-scrollbar p-2 bg-white space-y-2"></div>
                        </div>
                    </div>
                </div>
                
                <!-- EXCEL WALL DATA GRID -->
                <div class="overflow-x-auto overflow-y-auto flex-1 custom-scrollbar relative">
                    <table class="excel-wall" id="applyTable">
                        <thead>
                            <tr>
                                <th class="text-center w-[12%]">Course Code</th>
                                <th class="w-[35%]">Descriptive Title</th>
                                <th class="w-[23%]">Program / Course</th>
                                <th class="text-center w-[10%]">Year Level</th>
                                <th class="text-center w-[10%]">Status</th>
                                <th class="text-center w-[10%]">Action</th>
                            </tr>
                        </thead>
                        <tbody id="available_subjects_container"></tbody>
                    </table>
                </div>

            </div>
        </div>
    </main>

    <script src="sidebar.js?v=<?php echo time(); ?>"></script>
    <script src="available_subjects.js?v=<?php echo time(); ?>"></script>
</body>
</html>