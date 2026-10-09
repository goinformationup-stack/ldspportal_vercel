<?php
// 1. UNIFIED STAFF SESSION CONFIGURATION
$session_lifetime = 60 * 60 * 24 * 30; // 30 days
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params($session_lifetime, '/');
session_name('LDSP_STAFF_SESSION'); 
session_start();

// 2. CACHE BUSTING
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

include "../dbconn.php";

// =========================================================
// STRICT ADMIN-ONLY ACCESS
// =========================================================
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// =========================================================
// AUTO-CREATE ARCHIVE COLUMN FAILSAFE (SOFT DELETE)
// =========================================================
try {
    $conn->query("ALTER TABLE programs ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) DEFAULT 0 AFTER program_name");
} catch (Exception $e) {}

// =========================================================
// HTML ROW RENDERING TEMPLATE (USED BY PHP & API)
// =========================================================
function renderProgramRow($row, $count, $show_status) {
    ob_start();
    $is_archived = ($show_status === 'archived');
    ?>
    <tr class="hover:bg-slate-50 transition-colors group">
        <td class="px-4 py-3 text-center text-slate-400 font-mono text-[10px] font-bold"><?= str_pad($count, 2, '0', STR_PAD_LEFT) ?></td>
        
        <td class="px-4 py-3">
            <div id="display-prog-<?= $row['program_id'] ?>" class="font-bold text-xs drop-shadow-sm <?= $is_archived ? 'text-slate-400 line-through' : 'text-[#00205b]' ?>">
                <?= htmlspecialchars($row['program_name']) ?>
            </div>
            
            <?php if (!$is_archived): ?>
            <form method="POST" id="edit-prog-<?= $row['program_id'] ?>" class="hidden flex gap-2 items-center">
                <input type="hidden" name="program_id" value="<?= $row['program_id'] ?>">
                <input type="text" name="program_name" value="<?= htmlspecialchars($row['program_name']) ?>" required class="input-glossy-smooth !py-1 !px-2 text-[11px] w-full max-w-[250px] border-blue-200 focus:!border-[#00205b] font-bold text-[#00205b]">
                <button type="submit" name="edit_program" class="bg-emerald-50 text-emerald-700 hover:bg-emerald-600 hover:text-white px-2.5 py-1 rounded text-[9px] uppercase tracking-widest font-black transition-colors border border-emerald-200 hover:border-emerald-600 shadow-sm">Save</button>
                <button type="button" onclick="toggleEdit(<?= $row['program_id'] ?>, false)" class="bg-slate-100 text-slate-600 hover:bg-slate-200 px-2.5 py-1 rounded text-[9px] uppercase tracking-widest font-bold transition-colors border border-slate-200 shadow-sm">Cancel</button>
            </form>
            <?php endif; ?>
        </td>

        <td class="px-4 py-3 text-[11px] text-slate-500 font-medium"><?= date('M j, Y', strtotime($row['created_at'])) ?></td>
        
        <td class="px-4 py-3 text-right relative z-30 opacity-90 group-hover:opacity-100 transition-opacity">
            <div id="actions-<?= $row['program_id'] ?>" class="flex items-center justify-end gap-1.5">
                
                <?php if ($is_archived): ?>
                    <a href="?restore=<?= $row['program_id'] ?>" title="Restore Program" class="text-emerald-600 hover:text-white bg-white hover:bg-emerald-500 border border-emerald-200 hover:border-emerald-600 p-1.5 rounded-lg inline-flex items-center transition-all shadow-sm" onclick="return confirm('Restore this program to active status?')">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" /></svg>
                    </a>
                <?php else: ?>
                    <button type="button" onclick="toggleEdit(<?= $row['program_id'] ?>, true)" title="Edit Program" class="text-blue-600 hover:text-white bg-white hover:bg-blue-600 border border-blue-200 hover:border-blue-600 p-1.5 rounded-lg inline-flex items-center transition-all shadow-sm">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.89 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.89l12.673-12.673z" /></svg>
                    </button>
                    
                    <a href="?archive=<?= $row['program_id'] ?>" title="Temporarily Delete Program" class="text-amber-600 hover:text-white bg-white hover:bg-amber-500 border border-amber-200 hover:border-amber-600 p-1.5 rounded-lg inline-flex items-center transition-all shadow-sm" onclick="return confirm('Temporarily delete this program? It will be removed from all active dropdown lists and sent to the archive.')">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>
                    </a>
                <?php endif; ?>
                
            </div>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

// =========================================================
// ASYNC JSON DATA ENDPOINT (FOR ZERO-LAG LIVE REFRESH)
// =========================================================
if (isset($_GET['api_refresh'])) {
    header('Content-Type: application/json');
    $show_status = $_GET['status'] ?? 'active';
    $html = '';
    
    if ($show_status === 'archived') {
        $query = "SELECT * FROM programs WHERE is_archived = 1 ORDER BY program_name ASC";
    } else {
        $query = "SELECT * FROM programs WHERE is_archived = 0 OR is_archived IS NULL ORDER BY program_name ASC";
    }
    
    $res = $conn->query($query);
    $count = 1;
    
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $html .= renderProgramRow($row, $count++, $show_status);
        }
        $count_text = $res->num_rows . ' Total';
    } else {
        $empty_msg = ($show_status === 'archived') ? 'No temporarily deleted programs found.' : 'No active programs found in the database. Add one above.';
        $html = '<tr><td colspan="4" class="px-4 py-8 text-center text-slate-400 text-[10px] font-bold uppercase tracking-[0.2em] bg-white/50"><div class="flex flex-col items-center justify-center gap-2"><svg class="w-8 h-8 text-slate-300 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0l-3-3m3 3l3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>' . $empty_msg . '</div></td></tr>';
        $count_text = '0 Total';
    }

    echo json_encode([
        'html' => $html,
        'count_text' => $count_text
    ]);
    exit();
}

$success_msg = "";
$error_msg = "";

// ---------------------------------------------------------
// HANDLE ADDING A NEW PROGRAM
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_program'])) {
    $new_program = trim($conn->real_escape_string($_POST['program_name']));
    
    if (!empty($new_program)) {
        try {
            $stmt = $conn->prepare("INSERT INTO programs (program_name, is_archived) VALUES (?, 0)");
            $stmt->bind_param("s", $new_program);
            if ($stmt->execute()) {
                $success_msg = "<strong>$new_program</strong> has been successfully added to the system.";
            }
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1062) {
                $error_msg = "That program already exists in the system.";
            } else {
                $error_msg = "Database Error: " . $e->getMessage();
            }
        }
    } else {
        $error_msg = "Program name cannot be empty.";
    }
}

// ---------------------------------------------------------
// HANDLE EDITING/RENAMING A PROGRAM (V2 SECURE SYNC)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_program'])) {
    $edit_id = (int)$_POST['program_id'];
    $updated_name = trim($conn->real_escape_string($_POST['program_name']));
    
    if (!empty($updated_name)) {
        try {
            // First, get the old name so we can sync the students/staff
            $old_name_query = $conn->query("SELECT program_name FROM programs WHERE program_id = $edit_id");
            if ($old_name_query && $old_name_query->num_rows > 0) {
                $old_name = $old_name_query->fetch_assoc()['program_name'];

                // Update the master program list
                $stmt = $conn->prepare("UPDATE programs SET program_name = ? WHERE program_id = ?");
                $stmt->bind_param("si", $updated_name, $edit_id);
                
                if ($stmt->execute()) {
                    // Auto-Sync: Update all users and enrollment records tied to the old name
                    $safe_old = $conn->real_escape_string($old_name);
                    
                    // Core V2 Table Syncs
                    try { $conn->query("UPDATE user_profiles SET program = '$updated_name' WHERE program = '$safe_old'"); } catch(Exception $e) {}
                    try { $conn->query("UPDATE enrollment_requests SET program = '$updated_name' WHERE program = '$safe_old'"); } catch(Exception $e) {}
                    // Failsafe in case users table still holds legacy data
                    try { $conn->query("UPDATE users SET program = '$updated_name' WHERE program = '$safe_old'"); } catch(Exception $e) {}
                    
                    $success_msg = "Program successfully renamed to <strong>$updated_name</strong>. All associated records were synced.";
                }
            }
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1062) {
                $error_msg = "Cannot rename. A program with that name already exists.";
            } else {
                $error_msg = "Database Error: " . $e->getMessage();
            }
        }
    } else {
        $error_msg = "Program name cannot be empty.";
    }
}

// ---------------------------------------------------------
// HANDLE ARCHIVING A PROGRAM (TEMPORARY DELETE)
// ---------------------------------------------------------
if (isset($_GET['archive'])) {
    $target_id = (int)$_GET['archive'];
    
    // Set is_archived to 1, removing it from active queries system-wide
    $stmt = $conn->prepare("UPDATE programs SET is_archived = 1 WHERE program_id = ?");
    $stmt->bind_param("i", $target_id);
    if ($stmt->execute()) {
        header("Location: manage_programs.php?msg=archived");
        exit();
    } else {
        $error_msg = "Failed to archive the program.";
    }
}

// ---------------------------------------------------------
// HANDLE RESTORING A PROGRAM (REVERT DELETION)
// ---------------------------------------------------------
if (isset($_GET['restore'])) {
    $target_id = (int)$_GET['restore'];
    
    // Set is_archived to 0, returning it to active status
    $stmt = $conn->prepare("UPDATE programs SET is_archived = 0 WHERE program_id = ?");
    $stmt->bind_param("i", $target_id);
    if ($stmt->execute()) {
        header("Location: manage_programs.php?msg=restored&status=archived");
        exit();
    } else {
        $error_msg = "Failed to restore the program.";
    }
}

// Check for URL messages
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'archived') {
        $success_msg = "Program temporarily deleted. It has been moved to the Archive and is hidden from standard system access.";
    } elseif ($_GET['msg'] === 'restored') {
        $success_msg = "Program successfully restored. It is now active and accessible in the system again.";
    }
}

// ---------------------------------------------------------
// FETCH PROGRAMS BASED ON ACTIVE TAB
// ---------------------------------------------------------
$show_status = $_GET['status'] ?? 'active';

if ($show_status === 'archived') {
    $programs_query = $conn->query("SELECT * FROM programs WHERE is_archived = 1 ORDER BY program_name ASC");
} else {
    $programs_query = $conn->query("SELECT * FROM programs WHERE is_archived = 0 OR is_archived IS NULL ORDER BY program_name ASC");
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Programs - LDSP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css?v=<?php echo time(); ?>">
</head>

<body class="flex h-screen overflow-hidden antialiased">
    <div class="ambient-orb-1"></div>
    <div class="ambient-orb-2"></div>
    
    <div id="toastContainer" class="fixed top-5 right-5 z-[100] flex flex-col gap-3 pointer-events-none"></div>

    <?php include 'sidebar.php'; ?>

    <main class="flex-1 min-w-0 overflow-y-auto h-full pt-20 md:pt-0 relative custom-scrollbar z-10">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1200px] mx-auto">
            
            <header class="mb-6 animate-up">
                <div class="flex items-center gap-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">
                    <span>Administrator</span> <span class="text-slate-400">/</span> <span class="text-[#00205b]">Curriculum Settings</span>
                </div>
                <h1 class="text-2xl md:text-3xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">Academic Programs</h1>
            </header>

            <?php if ($success_msg): ?>
                <div class="bg-emerald-50/90 backdrop-blur-sm border border-emerald-200 text-emerald-800 p-3.5 rounded-xl mb-6 flex items-center gap-3 shadow-sm animate-up delay-1">
                    <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span class="text-sm font-semibold"><?= $success_msg ?></span>
                </div>
            <?php elseif ($error_msg): ?>
                <div class="bg-rose-50/90 backdrop-blur-sm border border-rose-200 text-rose-800 p-3.5 rounded-xl mb-6 flex items-center gap-3 shadow-sm animate-up delay-1">
                    <svg class="w-5 h-5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span class="text-sm font-semibold"><?= htmlspecialchars($error_msg) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($show_status !== 'archived'): ?>
            <div class="glossy-panel p-4 md:p-5 mb-6 animate-up delay-1 shadow-[0_10px_30px_rgba(0,32,91,0.05)]">
                <!-- Applied solid indigo background directly via style tag to ensure it works flawlessly -->
                <div class="glossy-panel-header" style="background-color: #00205b;"></div>
                <h2 class="text-base font-black text-[#00205b] mb-4 flex items-center gap-2 border-b border-slate-200/60 pb-3 z-20 relative drop-shadow-sm uppercase tracking-[0.1em]">
                    <div class="p-1.5 bg-[#00205b]/10 rounded-md"><svg class="w-4 h-4 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg></div>
                    Create New Program
                </h2>
                <form method="POST" class="flex flex-col md:flex-row gap-4 items-end z-20 relative">
                    <div class="w-full">
                        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1.5">Official Program Title</label>
                        <input type="text" name="program_name" required class="input-glossy-smooth w-full" placeholder="e.g. BS Computer Science">
                    </div>
                    <button type="submit" name="add_program" class="btn-glossy-animated font-black px-6 py-2 rounded-lg uppercase tracking-widest text-[10px] w-full md:w-auto shrink-0 h-[36px] flex items-center justify-center gap-2">
                        Add Program
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <div class="flex items-center gap-2 mb-5 pb-2 border-b border-slate-300/50 overflow-x-auto whitespace-nowrap drop-shadow-sm animate-up delay-2 custom-scrollbar">
                <a href="manage_programs.php?status=active" class="px-4 py-2 rounded-lg text-[10px] font-bold uppercase tracking-widest transition-all <?= ($show_status !== 'archived') ? 'bg-gradient-to-b from-[#003882] to-[#00205b] text-white shadow-[0_4px_10px_-2px_rgba(0,32,91,0.4),inset_0_1px_0_rgba(255,255,255,0.3)] border border-[#001233]' : 'bg-white/80 backdrop-blur-sm border border-slate-200 text-slate-500 hover:bg-white hover:text-[#00205b] shadow-sm hover:shadow-md' ?>">
                    Active Programs
                </a>
                <a href="manage_programs.php?status=archived" class="px-4 py-2 rounded-lg text-[10px] font-bold uppercase tracking-widest transition-all flex items-center gap-1.5 <?= ($show_status === 'archived') ? 'bg-gradient-to-b from-slate-600 to-slate-700 text-white shadow-[0_4px_10px_-2px_rgba(71,85,105,0.4),inset_0_1px_0_rgba(255,255,255,0.3)] border border-slate-800' : 'bg-white/80 backdrop-blur-sm border border-slate-200 text-slate-500 hover:bg-white hover:text-slate-700 shadow-sm hover:shadow-md' ?>">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>
                    Archived
                </a>
            </div>

            <div class="glossy-panel mb-8 animate-up delay-3 shadow-[0_10px_30px_rgba(0,0,0,0.03)]">
                <!-- Condition to keep the archive styling slate, and change active state to solid indigo -->
                <?php if ($show_status === 'archived'): ?>
                    <div class="glossy-panel-header bg-gradient-to-r from-slate-400 to-slate-600"></div>
                <?php else: ?>
                    <div class="glossy-panel-header" style="background-color: #00205b;"></div>
                <?php endif; ?>
                
                <div class="px-4 py-3 bg-white/60 border-b border-slate-200/60 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 z-20 relative">
                    <h3 class="font-bold <?= ($show_status === 'archived') ? 'text-slate-700' : 'text-[#00205b]' ?> text-[11px] uppercase tracking-[0.1em] flex items-center gap-2 drop-shadow-sm">
                        <?= ($show_status === 'archived') ? 'Temporarily Deleted Programs' : 'Active Academic Programs' ?>
                    </h3>
                    <span id="programs_count" class="bg-white border <?= ($show_status === 'archived') ? 'border-slate-300 text-slate-600' : 'border-blue-200 text-[#00205b] shadow-[0_2px_10px_rgba(0,32,91,0.1)]' ?> px-3 py-1 rounded-md text-[9px] uppercase tracking-widest font-black">
                        <?= $programs_query ? $programs_query->num_rows : 0 ?> Total
                    </span>
                </div>
                
                <div class="overflow-x-auto z-20 bg-white/40">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-white/80 backdrop-blur-md text-slate-500 uppercase text-[9px] font-bold tracking-widest border-b border-slate-200">
                            <tr>
                                <th class="px-4 py-3 w-12 text-center font-bold">No.</th>
                                <th class="px-4 py-3 font-bold">Program Name</th>
                                <th class="px-4 py-3 font-bold">Date Added</th>
                                <th class="px-4 py-3 font-bold text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody id="programs_tbody" class="divide-y divide-slate-100/80">
                            <?php 
                            if ($programs_query && $programs_query->num_rows > 0):
                                $count = 1;
                                while ($row = $programs_query->fetch_assoc()): 
                                    echo renderProgramRow($row, $count++, $show_status);
                                endwhile;
                            else: ?>
                                <tr>
                                    <td colspan="4" class="px-4 py-8 text-center text-slate-400 text-[10px] font-bold uppercase tracking-[0.2em] bg-white/50">
                                        <div class="flex flex-col items-center justify-center gap-2">
                                            <svg class="w-8 h-8 text-slate-300 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0l-3-3m3 3l3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>
                                            <?= ($show_status === 'archived') ? 'No temporarily deleted programs found.' : 'No active programs found in the database. Add one above.' ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <!-- Data passing for JS -->
    <script>
        const currentTab = '<?= $show_status ?>';
    </script>
    
    <!-- External Scripts -->
    <script src="admin.js?v=<?php echo time(); ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="manage_programs.js?v=<?php echo time(); ?>"></script>
</body>
</html>