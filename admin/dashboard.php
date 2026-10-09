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
// STRICT ADMIN-ONLY ACCESS 
// =========================================================
$allowed_roles = ['admin'];

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../index.php");
    exit();
}

// ---------------------------------------------------------
// SAFE DATABASE HELPER FUNCTION
// ---------------------------------------------------------
function getSafeCount($conn, $query) {
    try {
        $result = $conn->query($query);
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return (int)($row['total'] ?? 0);
        }
    } catch (Exception $e) {}
    return 0;
}

$error_msg = "";
$success_msg = "";

// =========================================================
// POST ACTION PROCESSOR (ZERO-REFRESH SPA READY)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['ajax_post'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => $error_msg ? 'error' : 'success',
            'message' => $error_msg ?: ($success_msg ?: 'Action completed successfully.'),
            'updates' => []
        ]);
        exit();
    }
}

// =========================================================
// ASYNC JSON DATA ENDPOINT (FOR ZERO-LAG LIVE REFRESH)
// =========================================================
if (isset($_GET['api_refresh'])) {
    header('Content-Type: application/json');

    $sort_program = $_GET['sort_program'] ?? 'All';
    $sort_year    = $_GET['sort_year'] ?? 'All';

    $filter_query = "WHERE final_status='Enrolled'";
    if ($sort_program !== 'All') {
        $filter_query .= " AND program = '" . $conn->real_escape_string($sort_program) . "'";
    }
    if ($sort_year !== 'All') {
        $filter_query .= " AND year_level = '" . $conn->real_escape_string($sort_year) . "'";
    }

    // 1. Top Quick Stats
    $total_enrolled     = getSafeCount($conn, "SELECT COUNT(*) as total FROM enrollment_requests $filter_query");
    $active_staff       = getSafeCount($conn, "SELECT COUNT(*) as total FROM users WHERE role != 'student'");
    $total_enroll_reqs  = getSafeCount($conn, "SELECT COUNT(*) as total FROM enrollment_requests");
    $pending_admissions = getSafeCount($conn, "SELECT COUNT(*) as total FROM admissions WHERE status = 'Pending'");

    // 2. Bottom Stats
    $freshmen_count  = getSafeCount($conn, "SELECT COUNT(*) as total FROM admissions WHERE student_type='Freshman'");
    $regular_count   = getSafeCount($conn, "SELECT COUNT(*) as total FROM admissions WHERE student_type='Regular'");
    $irregular_count = getSafeCount($conn, "SELECT COUNT(*) as total FROM admissions WHERE student_type='Irregular'");

    // 3. Analytics Data
    $gender_data = ['Male' => 0, 'Female' => 0, 'Unspecified' => 0];
    
    $g_query = $conn->query("SELECT p.gender, COUNT(*) as count FROM enrollment_requests e JOIN user_profiles p ON e.user_id = p.user_id WHERE e.final_status='Enrolled' GROUP BY p.gender");
    if ($g_query) { 
        while ($row = $g_query->fetch_assoc()) { 
            $gender_data[$row['gender'] ?: 'Unspecified'] = (int)$row['count']; 
        } 
    }

    $mode_data = ['Hybrid' => 0, 'Online' => 0, 'Modular' => 0];
    $m_query = $conn->query("SELECT learning_mode, COUNT(*) as count FROM enrollment_requests $filter_query GROUP BY learning_mode");
    if ($m_query) { 
        while ($row = $m_query->fetch_assoc()) { 
            $mode_data[$row['learning_mode'] ?: 'Online'] = (int)$row['count']; 
        } 
    }

    $prog_labels = [];
    $prog_counts = [];
    $p_query = $conn->query("SELECT program, COUNT(*) as count FROM enrollment_requests $filter_query GROUP BY program");
    if ($p_query) { 
        while ($row = $p_query->fetch_assoc()) { 
            $prog_labels[] = $row['program'] ?: 'Unassigned'; 
            $prog_counts[] = (int)$row['count']; 
        } 
    }

    echo json_encode([
        'stats' => [
            'total_enrolled'     => $total_enrolled,
            'active_staff'       => $active_staff,
            'total_enroll_reqs'  => $total_enroll_reqs,
            'pending_admissions' => $pending_admissions,
            'freshmen_count'     => $freshmen_count,
            'regular_count'      => $regular_count,
            'irregular_count'    => $irregular_count
        ],
        'charts' => [
            'program' => [
                'labels' => !empty($prog_labels) ? $prog_labels : ['No Records'],
                'data'   => !empty($prog_counts) ? $prog_counts : [0]
            ],
            'gender' => [
                'data' => [$gender_data['Male'] ?? 0, $gender_data['Female'] ?? 0, $gender_data['Unspecified'] ?? 0]
            ],
            'modality' => [
                'data' => [$mode_data['Hybrid'] ?? 0, $mode_data['Online'] ?? 0, $mode_data['Modular'] ?? 0]
            ]
        ]
    ]);
    exit();
}

// ---------------------------------------------------------
// Initial Load Data Preparation
// ---------------------------------------------------------
$sort_program = isset($_GET['sort_program']) ? $_GET['sort_program'] : 'All';
$sort_year    = isset($_GET['sort_year']) ? $_GET['sort_year'] : 'All';

$filter_query = "WHERE final_status='Enrolled'";
if ($sort_program !== 'All') {
    $filter_query .= " AND program = '" . $conn->real_escape_string($sort_program) . "'";
}
if ($sort_year !== 'All') {
    $filter_query .= " AND year_level = '" . $conn->real_escape_string($sort_year) . "'";
}

$total_enrolled     = getSafeCount($conn, "SELECT COUNT(*) as total FROM enrollment_requests $filter_query");
$active_staff       = getSafeCount($conn, "SELECT COUNT(*) as total FROM users WHERE role != 'student'");
$total_enroll_reqs  = getSafeCount($conn, "SELECT COUNT(*) as total FROM enrollment_requests");
$pending_admissions = getSafeCount($conn, "SELECT COUNT(*) as total FROM admissions WHERE status='Pending'");

$freshmen_count     = getSafeCount($conn, "SELECT COUNT(*) as total FROM admissions WHERE student_type='Freshman'");
$regular_count      = getSafeCount($conn, "SELECT COUNT(*) as total FROM admissions WHERE student_type='Regular'");
$irregular_count    = getSafeCount($conn, "SELECT COUNT(*) as total FROM admissions WHERE student_type='Irregular'");

$gender_data = ['Male' => 0, 'Female' => 0, 'Unspecified' => 0];
$mode_data   = ['Hybrid' => 0, 'Online' => 0, 'Modular' => 0];
$prog_labels = []; 
$prog_counts = [];

try {
    $g_query = $conn->query("SELECT p.gender, COUNT(*) as count FROM enrollment_requests e JOIN user_profiles p ON e.user_id = p.user_id WHERE e.final_status='Enrolled' GROUP BY p.gender");
    if ($g_query) { while ($row = $g_query->fetch_assoc()) { $gender_data[$row['gender'] ?: 'Unspecified'] = (int)$row['count']; } }

    $m_query = $conn->query("SELECT learning_mode, COUNT(*) as count FROM enrollment_requests $filter_query GROUP BY learning_mode");
    if ($m_query) { while ($row = $m_query->fetch_assoc()) { $mode_data[$row['learning_mode'] ?: 'Online'] = (int)$row['count']; } }

    $p_query = $conn->query("SELECT program, COUNT(*) as count FROM enrollment_requests $filter_query GROUP BY program");
    if ($p_query) { while ($row = $p_query->fetch_assoc()) { $prog_labels[] = $row['program'] ?: 'Unassigned'; $prog_counts[] = (int)$row['count']; } }
} catch (Exception $e) {}

$all_programs_list = [];
try {
    $prog_q = $conn->query("SELECT program_name FROM programs ORDER BY program_name ASC");
    if ($prog_q) { while ($r = $prog_q->fetch_assoc()) { $all_programs_list[] = $r['program_name']; } }
} catch (Exception $e) {}

$all_years_list = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator Dashboard - Lyceum de San Pablo</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css?v=<?php echo time(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="flex h-screen overflow-hidden antialiased bg-slate-50">
    <div id="toastContainer" class="fixed top-5 right-5 z-[100] flex flex-col gap-3 pointer-events-none"></div>

    <?php include 'sidebar.php'; ?>

    <main class="flex-1 min-w-0 overflow-y-auto h-full w-full pt-20 md:pt-0 relative custom-scrollbar z-10">
        <div class="p-5 md:p-8 lg:p-10 max-w-[1600px] mx-auto relative z-10">
            
            <header class="mb-8 animate-up flex flex-col md:flex-row md:justify-between md:items-end gap-4">
                <div>
                    <div class="flex items-center gap-2 text-xs font-semibold text-slate-500 uppercase tracking-widest mb-1.5">
                        <span>Administrator</span> 
                        <span class="text-slate-300">/</span> 
                        <span class="text-indigo-600">System Overview</span>
                    </div>
                    <h1 class="text-3xl md:text-4xl font-black text-slate-900 tracking-tight">Admin Dashboard</h1>
                </div>
            </header>

            <!-- Top Stats -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                
                <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm animate-up group hover:-translate-y-1 transition-all duration-300 hover:shadow-md">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.15em] mb-2">Enrolled Population</p>
                            <h3 id="stat_total_enrolled" data-val="<?= $total_enrolled ?>" class="text-3xl font-black text-slate-800 transition-colors"><?= number_format($total_enrolled) ?></h3>
                        </div>
                        <div class="p-3 bg-indigo-50 text-indigo-600 rounded-xl group-hover:bg-indigo-600 group-hover:text-white transition-colors duration-300">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm animate-up delay-1 group hover:-translate-y-1 transition-all duration-300 hover:shadow-md">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.15em] mb-2">Active Personnel</p>
                            <h3 id="stat_active_staff" data-val="<?= $active_staff ?>" class="text-3xl font-black text-slate-800 transition-colors"><?= number_format($active_staff) ?></h3>
                        </div>
                        <div class="p-3 bg-amber-50 text-amber-600 rounded-xl group-hover:bg-amber-500 group-hover:text-white transition-colors duration-300">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2-2v10a2 2 0 002 2z" /></svg>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm animate-up delay-2 group hover:-translate-y-1 transition-all duration-300 hover:shadow-md">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.15em] mb-2">Enrollment Volume</p>
                            <h3 id="stat_total_enroll_reqs" data-val="<?= $total_enroll_reqs ?>" class="text-3xl font-black text-slate-800 transition-colors"><?= number_format($total_enroll_reqs) ?></h3>
                        </div>
                        <div class="p-3 bg-emerald-50 text-emerald-600 rounded-xl group-hover:bg-emerald-500 group-hover:text-white transition-colors duration-300">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2-2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm animate-up delay-3 group hover:-translate-y-1 transition-all duration-300 hover:shadow-md">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.15em] mb-2">Pending Clearance</p>
                            <h3 id="stat_pending_admissions" data-val="<?= $pending_admissions ?>" class="text-3xl font-black text-slate-800 transition-colors"><?= number_format($pending_admissions) ?></h3>
                        </div>
                        <div class="p-3 bg-sky-50 text-sky-600 rounded-xl group-hover:bg-sky-500 group-hover:text-white transition-colors duration-300">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Charts Row 1 -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                
                <div class="bg-white rounded-2xl p-6 lg:col-span-2 border border-slate-200 shadow-sm animate-up delay-2">
                    <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-6 gap-4">
                        <div>
                            <h3 class="text-sm font-black text-slate-800 tracking-tight">Program Distribution</h3>
                            <p class="text-xs text-slate-500 mt-1">Enrolled students across academic programs</p>
                        </div>
                        <div class="w-full lg:w-auto z-20"> 
                            <form id="filterForm" class="flex flex-wrap md:flex-nowrap items-center justify-end gap-2 w-full md:w-auto" onsubmit="event.preventDefault(); window.triggerDynamicFetch();">
                                <select id="sort_program" name="sort_program" onchange="window.triggerDynamicFetch()" class="bg-slate-50 border border-slate-200 text-slate-700 text-xs rounded-lg focus:ring-indigo-500 focus:border-indigo-500 block p-2">
                                    <option value="All">All Programs</option>
                                    <?php foreach($all_programs_list as $prog): ?>
                                        <option value="<?= htmlspecialchars($prog) ?>" <?= ($sort_program === $prog) ? 'selected' : '' ?>><?= htmlspecialchars($prog) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select id="sort_year" name="sort_year" onchange="window.triggerDynamicFetch()" class="bg-slate-50 border border-slate-200 text-slate-700 text-xs rounded-lg focus:ring-indigo-500 focus:border-indigo-500 block p-2">
                                    <option value="All">All Years</option>
                                    <?php foreach($all_years_list as $year): ?>
                                        <option value="<?= htmlspecialchars($year) ?>" <?= ($sort_year === $year) ? 'selected' : '' ?>><?= htmlspecialchars($year) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                    </div>
                    <div class="relative w-full h-[280px] flex-1 z-20">
                        <canvas id="programChart"></canvas>
                    </div>
                </div>

                <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm animate-up delay-3">
                    <div>
                        <h3 class="text-sm font-black text-slate-800 tracking-tight">Demographics</h3>
                        <p class="text-xs text-slate-500 mt-1">Gender distribution of enrolled students</p>
                    </div>
                    <div class="relative w-full h-[260px] flex items-center justify-center flex-1 z-20 mt-4">
                        <canvas id="genderChart"></canvas>
                    </div>
                </div>

            </div>

            <!-- Charts Row 2 & Bottom Stats -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                
                <div class="bg-white rounded-2xl p-6 lg:col-span-2 border border-slate-200 shadow-sm animate-up delay-1">
                    <div>
                        <h3 class="text-sm font-black text-slate-800 tracking-tight">Learning Modality</h3>
                        <p class="text-xs text-slate-500 mt-1">Student preferences for course delivery</p>
                    </div>
                    <div class="relative w-full h-[200px] flex-1 z-20 mt-4">
                        <canvas id="modalityChart"></canvas>
                    </div>
                </div>

                <div class="grid grid-rows-3 gap-4 lg:col-span-1">
                    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm flex items-center justify-between animate-up delay-2">
                        <p class="text-xs font-bold text-slate-500 uppercase tracking-widest">Freshmen</p>
                        <span id="stat_freshmen_count" data-val="<?= $freshmen_count ?>" class="text-2xl font-black text-slate-800"><?= number_format($freshmen_count) ?></span>
                    </div>
                    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm flex items-center justify-between animate-up delay-3">
                        <p class="text-xs font-bold text-slate-500 uppercase tracking-widest">Regular</p>
                        <span id="stat_regular_count" data-val="<?= $regular_count ?>" class="text-2xl font-black text-slate-800"><?= number_format($regular_count) ?></span>
                    </div>
                    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm flex items-center justify-between animate-up delay-4">
                        <p class="text-xs font-bold text-slate-500 uppercase tracking-widest">Irregular</p>
                        <span id="stat_irregular_count" data-val="<?= $irregular_count ?>" class="text-2xl font-black text-slate-800"><?= number_format($irregular_count) ?></span>
                    </div>
                </div>

            </div>

        </div>
    </main>

    <!-- Securely pass initial PHP data to JS for Charts -->
    <script>
        window.DASHBOARD_INIT_DATA = {
            progLabels: <?= json_encode(!empty($prog_labels) ? $prog_labels : ['No Records']) ?>,
            progCounts: <?= json_encode(!empty($prog_counts) ? $prog_counts : [0]) ?>,
            genderData: [<?= $gender_data['Male'] ?? 0 ?>, <?= $gender_data['Female'] ?? 0 ?>, <?= $gender_data['Unspecified'] ?? 0 ?>],
            modeData: [<?= $mode_data['Hybrid'] ?? 0 ?>, <?= $mode_data['Online'] ?? 0 ?>, <?= $mode_data['Modular'] ?? 0 ?>]
        };
    </script>
    
    <!-- External Scripts -->
    <script src="admin.js?v=<?php echo time(); ?>"></script>
    <script src="manage_sidebar.js?v=<?php echo time(); ?>"></script>
    <script src="dashboard.js?v=<?php echo time(); ?>"></script>
</body>
</html>