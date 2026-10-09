<?php
// 1. UNIFIED STAFF SESSION CONFIGURATION
$session_lifetime = 60 * 60 * 24 * 30; // 30 days
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params($session_lifetime, '/');
session_name('LDSP_STAFF_SESSION'); 
session_start();

include "../dbconn.php"; // Adjust path if necessary

// Ensure only admins can access this page
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

$message = "";

// =========================================================
// AUTO-DATABASE MIGRATION: Added Width & Height for Canva Resizing
// =========================================================
try {
    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) UNIQUE NOT NULL,
        setting_value TEXT NOT NULL
    )");

    $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('login_bg_type', 'none')");
    $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('login_bg_file', '')");
    $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('login_bg_x', '0')");
    $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('login_bg_y', '0')");
    
    // Width and Height coordinates for freeform stretching
    $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('login_bg_w', '100')");
    $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('login_bg_h', '100')");
    $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('login_bg_scale', '1')");
    
    $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('login_card_x', '50')"); 
    $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('login_card_y', '50')");
    $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('login_card_scale', '1')");
} catch (Exception $e) {}


// =========================================================
// TEMPLATE RENDERING FUNCTIONS (USED BY PHP & FETCH API)
// =========================================================
function renderEditorForm($settings) {
    ob_start();
    $current_bg_type = $settings['login_bg_type'] ?? 'none';
    $current_bg_scale = $settings['login_bg_scale'] ?? '1';
    $current_card_scale = $settings['login_card_scale'] ?? '1';
    
    $current_bg_x = $settings['login_bg_x'] ?? '0';
    $current_bg_y = $settings['login_bg_y'] ?? '0';
    $current_bg_w = $settings['login_bg_w'] ?? '100';
    $current_bg_h = $settings['login_bg_h'] ?? '100';
    
    $current_card_x = $settings['login_card_x'] ?? '50';
    $current_card_y = $settings['login_card_y'] ?? '50';
    ?>
    <input type="hidden" name="update_settings" value="1">
    <div>
        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Background Media</label>
        <select name="bg_type" id="bgType" class="w-full bg-white border border-slate-300 rounded-lg p-2 text-sm font-medium outline-none focus:border-[#00205b]">
            <option value="none" <?= $current_bg_type == 'none' ? 'selected' : '' ?>>No Background (Solid)</option>
            <option value="image" <?= $current_bg_type == 'image' ? 'selected' : '' ?>>Image (.png, .jpg)</option>
            <option value="video" <?= $current_bg_type == 'video' ? 'selected' : '' ?>>Video Loop (.mp4)</option>
        </select>
    </div>

    <!-- CANVA-STYLE MEDIA BOX -->
    <div id="uploadSection" class="<?= $current_bg_type == 'none' ? 'hidden' : '' ?>">
        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Upload File</label>
        <div class="border-2 border-dashed border-indigo-300 bg-indigo-50/50 rounded-xl p-5 text-center hover:bg-indigo-50 hover:border-indigo-500 transition-all duration-300 relative cursor-pointer group">
            <input type="file" name="bg_file" id="mediaUpload" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10" accept="image/*,video/*">
            <div class="transform group-hover:scale-105 transition-transform duration-300 flex flex-col items-center">
                <div class="w-10 h-10 bg-white rounded-full shadow-sm flex items-center justify-center mb-2 border border-indigo-100">
                    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                </div>
                <p class="text-[11px] font-bold text-[#00205b]">Drag & drop or click</p>
                <p class="text-[9px] text-slate-500 mt-1" id="fileNameDisplay">Supports JPG, PNG, MP4</p>
            </div>
        </div>
    </div>
    
    <div class="border-t border-slate-200 pt-5">
        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-3">Background Scale</label>
        <div class="flex items-center gap-3">
            <span class="text-xs font-bold text-slate-400">50%</span>
            <input type="range" id="zoomSlider" min="0.5" max="3" step="0.05" value="<?= htmlspecialchars($current_bg_scale) ?>">
            <span class="text-xs font-bold text-slate-400">300%</span>
        </div>
    </div>

    <div class="border-t border-slate-200 pt-5">
        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-3">Login Card Size</label>
        <div class="flex items-center gap-3">
            <span class="text-xs font-bold text-slate-400">50%</span>
            <input type="range" id="cardZoomSlider" min="0.5" max="1.5" step="0.05" value="<?= htmlspecialchars($current_card_scale) ?>">
            <span class="text-xs font-bold text-slate-400">150%</span>
        </div>
    </div>

    <!-- Hidden inputs for responsive % coordinates and scale -->
    <input type="hidden" name="bg_x" id="inputX" value="<?= htmlspecialchars($current_bg_x) ?>">
    <input type="hidden" name="bg_y" id="inputY" value="<?= htmlspecialchars($current_bg_y) ?>">
    <input type="hidden" name="bg_w" id="inputW" value="<?= htmlspecialchars($current_bg_w) ?>">
    <input type="hidden" name="bg_h" id="inputH" value="<?= htmlspecialchars($current_bg_h) ?>">
    <input type="hidden" name="bg_scale" id="inputScale" value="<?= htmlspecialchars($current_bg_scale) ?>">
    
    <input type="hidden" name="card_x" id="cardX" value="<?= htmlspecialchars($current_card_x) ?>">
    <input type="hidden" name="card_y" id="cardY" value="<?= htmlspecialchars($current_card_y) ?>">
    <input type="hidden" name="card_scale" id="inputCardScale" value="<?= htmlspecialchars($current_card_scale) ?>">
    <?php
    return ob_get_clean();
}

function renderWorkspace($settings) {
    ob_start();
    $current_bg_type = $settings['login_bg_type'] ?? 'none';
    $current_bg_file = $settings['login_bg_file'] ?? '';
    $current_bg_scale = $settings['login_bg_scale'] ?? '1';
    $current_bg_x = $settings['login_bg_x'] ?? '0';
    $current_bg_y = $settings['login_bg_y'] ?? '0';
    $current_bg_w = $settings['login_bg_w'] ?? '100';
    $current_bg_h = $settings['login_bg_h'] ?? '100';
    
    $current_card_x = $settings['login_card_x'] ?? '50';
    $current_card_y = $settings['login_card_y'] ?? '50';
    $current_card_scale = $settings['login_card_scale'] ?? '1';
    
    // Auto-migration failsafe for legacy pixel dimensions
    if (floatval($current_card_x) > 100) $current_card_x = number_format((floatval($current_card_x) / 1920) * 100, 2);
    if (floatval($current_card_y) > 100) $current_card_y = number_format((floatval($current_card_y) / 1080) * 100, 2);
    ?>
    <div class="preview-window" id="previewWindow">
        <!-- TRUE 1920x1080 Viewport Scale target -->
        <div class="canvas-area" id="canvasArea">
            
            <!-- 1. Background Layer (Draggable & Resizable) -->
            <div id="mediaContainer" class="draggable-element canva-outline overflow-hidden" style="top: <?= htmlspecialchars($current_bg_y) ?>%; left: <?= htmlspecialchars($current_bg_x) ?>%; width: <?= htmlspecialchars($current_bg_w) ?>%; height: <?= htmlspecialchars($current_bg_h) ?>%;">
                
                <?php if ($current_bg_type === 'image' && !empty($current_bg_file)): ?>
                    <img id="mediaElement" class="media-content" src="../uploads/settings/<?= htmlspecialchars($current_bg_file) ?>" draggable="false" style="transform: scale(<?= htmlspecialchars($current_bg_scale) ?>); transform-origin: center center;">
                <?php elseif ($current_bg_type === 'video' && !empty($current_bg_file)): ?>
                    <video id="mediaElement" class="media-content" src="../uploads/settings/<?= htmlspecialchars($current_bg_file) ?>" autoplay loop muted draggable="false" style="transform: scale(<?= htmlspecialchars($current_bg_scale) ?>); transform-origin: center center;"></video>
                <?php else: ?>
                    <!-- Placeholder if none -->
                    <div id="mediaElement" class="w-full h-full bg-slate-300 border-4 border-slate-400 flex items-center justify-center pointer-events-none" style="transform: scale(1);">
                        <span class="text-slate-500 font-bold uppercase tracking-widest text-2xl">New Media</span>
                    </div>
                <?php endif; ?>

                <!-- Canva Resize Handles -->
                <div class="canva-handle handle-nw" data-handle="nw"></div>
                <div class="canva-handle handle-ne" data-handle="ne"></div>
                <div class="canva-handle handle-sw" data-handle="sw"></div>
                <div class="canva-handle handle-se" data-handle="se"></div>
                
                <div class="canva-handle handle-n" data-handle="n"></div>
                <div class="canva-handle handle-s" data-handle="s"></div>
                <div class="canva-handle handle-e" data-handle="e"></div>
                <div class="canva-handle handle-w" data-handle="w"></div>
            </div>

            <!-- Gradient Overlay for readability -->
            <div class="absolute inset-0 bg-gradient-to-tr from-[#020617]/50 via-transparent to-transparent pointer-events-none z-10"></div>

            <!-- 2. The Floating Login Card (Draggable, Resizable, and visually identical) -->
            <div id="loginCard" class="draggable-element mock-login-card-container canva-outline" style="top: <?= htmlspecialchars($current_card_y) ?>%; left: <?= htmlspecialchars($current_card_x) ?>%; transform: scale(<?= htmlspecialchars($current_card_scale) ?>);">
                <div class="glossy-card border-t-4 border-t-[#00205b] p-8 w-full">
                    <div class="absolute top-0 right-0 bg-gradient-to-bl from-blue-50 to-white text-blue-700 text-[9px] font-black px-3 py-1.5 rounded-bl-lg border-b border-l border-blue-200 uppercase tracking-widest flex items-center gap-1.5 shadow-sm">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                        Student
                    </div>
                    <div class="text-center mb-6 mt-2">
                        <div class="w-24 h-24 mx-auto mb-4 rounded-full bg-white flex items-center justify-center shadow-inner overflow-hidden border border-slate-100 p-1">
                            <img src="../logo.jpg" onerror="this.src='logo.jpg'" alt="LDSP Logo" class="w-full h-full object-contain rounded-full">
                        </div>
                        <h1 class="text-3xl font-black text-[#00205b] font-academic tracking-tight mb-1 uppercase drop-shadow-sm">LDSP <span class="blue-gradient-text">PORTAL</span></h1>
                        <div class="w-16 h-1 bg-gradient-to-r from-[#003882] to-[#00205b] mx-auto mb-2 rounded-full shadow-sm"></div>
                        <p class="text-slate-500 text-[9px] font-bold tracking-[0.2em] uppercase drop-shadow-sm">Official Student Access</p>
                    </div>
                    <div class="mb-4 text-left">
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Student Identifier</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center">
                                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                            </div>
                            <div class="input-glossy !py-2.5 !pl-10 !text-sm text-slate-400">Email, Student ID, or Name</div>
                        </div>
                    </div>
                    <div class="mb-6 text-left">
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Password</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center">
                                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                            </div>
                            <div class="input-glossy !py-2.5 !pl-10 !text-sm text-slate-400">••••••••</div>
                        </div>
                    </div>
                    <div class="btn-glossy !py-3 mb-2">Login <svg class="w-4 h-4 ml-1 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg></div>
                    <div class="text-center mt-6">
                        <p class="text-[9px] text-slate-400 font-bold uppercase tracking-widest drop-shadow-sm">&copy; 2026 LDSP CAMPUS</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}


// =========================================================
// ASYNC JSON DATA ENDPOINT (FOR ZERO-LAG LIVE REFRESH)
// =========================================================
if (isset($_GET['api_refresh'])) {
    header('Content-Type: application/json');
    $settings = [];
    $res = $conn->query("SELECT * FROM system_settings");
    while ($row = $res->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    echo json_encode([
        'form_html' => renderEditorForm($settings),
        'workspace_html' => renderWorkspace($settings)
    ]);
    exit();
}


// =========================================================
// HANDLE FORM SUBMISSION & FILE UPLOAD
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_settings'])) {
    $bg_type = $_POST['bg_type'];
    $bg_x = $_POST['bg_x'] ?? '0';
    $bg_y = $_POST['bg_y'] ?? '0';
    $bg_w = $_POST['bg_w'] ?? '100';
    $bg_h = $_POST['bg_h'] ?? '100';
    $bg_scale = $_POST['bg_scale'] ?? '1';
    
    $card_x = $_POST['card_x'] ?? '50';
    $card_y = $_POST['card_y'] ?? '50';
    $card_scale = $_POST['card_scale'] ?? '1';

    // Update ALL coordinates to DB
    $stmtX = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'login_bg_x'");
    $stmtX->bind_param("s", $bg_x); $stmtX->execute();

    $stmtY = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'login_bg_y'");
    $stmtY->bind_param("s", $bg_y); $stmtY->execute();

    $stmtW = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'login_bg_w'");
    $stmtW->bind_param("s", $bg_w); $stmtW->execute();

    $stmtH = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'login_bg_h'");
    $stmtH->bind_param("s", $bg_h); $stmtH->execute();

    $stmtScale = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'login_bg_scale'");
    $stmtScale->bind_param("s", $bg_scale); $stmtScale->execute();

    $stmtCardX = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'login_card_x'");
    $stmtCardX->bind_param("s", $card_x); $stmtCardX->execute();

    $stmtCardY = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'login_card_y'");
    $stmtCardY->bind_param("s", $card_y); $stmtCardY->execute();
    
    $stmtCardScale = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'login_card_scale'");
    $stmtCardScale->bind_param("s", $card_scale); $stmtCardScale->execute();

    // Handle Upload
    if (isset($_FILES['bg_file']) && $_FILES['bg_file']['error'] == 0) {
        $upload_dir = "../uploads/settings/";
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $file_name = time() . '_' . basename($_FILES["bg_file"]["name"]);
        $target_file = $upload_dir . $file_name;
        $file_extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

        $allowed_image_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $allowed_video_ext = ['mp4', 'webm', 'ogg'];

        $is_valid = false;
        if ($bg_type == 'image' && in_array($file_extension, $allowed_image_ext)) $is_valid = true;
        elseif ($bg_type == 'video' && in_array($file_extension, $allowed_video_ext)) $is_valid = true;

        if ($is_valid && move_uploaded_file($_FILES["bg_file"]["tmp_name"], $target_file)) {
            $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'login_bg_type'");
            $stmt->bind_param("s", $bg_type); $stmt->execute();
            $stmt2 = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'login_bg_file'");
            $stmt2->bind_param("s", $file_name); $stmt2->execute();

            $message = "<div class='p-3 text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg shadow-sm flex items-center gap-2'><svg class='w-4 h-4 text-emerald-500' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M5 13l4 4L19 7'/></svg> Full layout published successfully!</div>";
        } else {
            $message = "<div class='p-3 text-xs font-bold text-rose-700 bg-rose-50 border border-rose-200 rounded-lg shadow-sm flex items-center gap-2'><svg class='w-4 h-4 text-rose-500' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M6 18L18 6M6 6l12 12'/></svg> Upload failed. Invalid format or permissions.</div>";
        }
    } elseif ($bg_type == 'none') {
        $conn->query("UPDATE system_settings SET setting_value = 'none' WHERE setting_key = 'login_bg_type'");
        $conn->query("UPDATE system_settings SET setting_value = '' WHERE setting_key = 'login_bg_file'");
        $message = "<div class='p-3 text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg shadow-sm flex items-center gap-2'><svg class='w-4 h-4 text-emerald-500' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M5 13l4 4L19 7'/></svg> Background cleared & Layout saved!</div>";
    } else {
        $message = "<div class='p-3 text-xs font-bold text-blue-700 bg-blue-50 border border-blue-200 rounded-lg shadow-sm flex items-center gap-2'><svg class='w-4 h-4 text-blue-500' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M5 13l4 4L19 7'/></svg> Elements Layout & Positioning saved!</div>";
    }
}

// Fetch current settings for initial page load
$settings = [];
$res = $conn->query("SELECT * FROM system_settings");
while ($row = $res->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Canva-Style Portal Editor - LDSP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ldsp-blue: #00205b;
            --ldsp-blue-light: #003882;
            --ldsp-gold: #c5a02c;
            --neon-blue: #3b82f6;
            --sidebar-bg: #001233;
        }
        
        body { font-family: 'Inter', sans-serif; background-color: #f4f6f9; color: #1f2937; overflow: hidden; }
        .font-academic { font-family: 'Crimson Pro', serif; }

        /* Ambient Background Orbs */
        .ambient-orb-1 { position: fixed; top: -10%; left: 10%; width: 50vw; height: 50vw; background: radial-gradient(circle, rgba(0,32,91,0.05) 0%, rgba(255,255,255,0) 70%); border-radius: 50%; pointer-events: none; z-index: 0; }
        .ambient-orb-2 { position: fixed; bottom: -10%; right: -5%; width: 60vw; height: 60vw; background: radial-gradient(circle, rgba(197,160,44,0.05) 0%, rgba(255,255,255,0) 70%); border-radius: 50%; pointer-events: none; z-index: 0; }

        /* Sidebar Styles */
        .sidebar { background: linear-gradient(145deg, var(--sidebar-bg) 0%, #000a1f 100%); border-right: 1px solid rgba(255, 255, 255, 0.08); box-shadow: 15px 0 30px rgba(0,0,0,0.4); }
        .nav-link { color: #94a3b8; transition: all 0.3s; border-left: 4px solid transparent; }
        .nav-link:hover { background: rgba(255,255,255,0.08); color: white; padding-left: 1.75rem; }
        .nav-link.active { background: rgba(197, 160, 44, 0.15); color: white; border-left-color: var(--ldsp-gold); }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(0,0,0,0.02); }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(0,32,91,0.2); border-radius: 4px; }

        .editor-workspace { background-image: radial-gradient(#d1d5db 1px, transparent 1px); background-size: 20px 20px; }
        
        /* TRUE MONITOR PREVIEW WINDOW */
        .preview-window {
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); 
            aspect-ratio: 16/9; 
            width: 100%; 
            max-width: 1100px;
            background: #0f172a; /* Dark monitor back panel */
            border-radius: 8px; 
            overflow: hidden; 
            border: 12px solid #1e293b; /* Realistic Monitor Bezel */
            position: relative;
        }

        /* TRUE 1080p CANVAS - Scales Down mathematically to fit screen perfectly */
        .canvas-area {
            width: 1920px; 
            height: 1080px; 
            position: absolute; 
            top: 0; left: 0; 
            transform-origin: top left;
            overflow: hidden; 
            background: repeating-conic-gradient(#f8fafc 0% 25%, #f1f5f9 0% 50%) 50% / 40px 40px;
        }
        
        /* DRAG AND RESIZE WRAPPERS */
        .draggable-element { position: absolute; cursor: grab; user-select: none; z-index: 5; transition: opacity 0.2s; }
        .draggable-element:active { cursor: grabbing; }

        /* CANVA STYLE OUTLINE & RESIZE HANDLES */
        .canva-outline::after {
            content: ''; position: absolute; inset: 0; border: 3px solid var(--neon-blue); opacity: 0; pointer-events: none; transition: opacity 0.2s; z-index: 10;
        }
        .draggable-element:hover .canva-outline::after { opacity: 1; }

        .canva-handle {
            position: absolute; background: #ffffff; border: 2px solid var(--neon-blue); box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            opacity: 0; transition: opacity 0.2s, transform 0.1s; z-index: 50;
        }
        .draggable-element:hover .canva-handle { opacity: 1; }
        .canva-handle:hover { transform: scale(1.3); background: var(--neon-blue); }

        /* Edge and Corner Handle Positions */
        .handle-nw { top: -6px; left: -6px; width: 14px; height: 14px; border-radius: 50%; cursor: nwse-resize; }
        .handle-ne { top: -6px; right: -6px; width: 14px; height: 14px; border-radius: 50%; cursor: nesw-resize; }
        .handle-sw { bottom: -6px; left: -6px; width: 14px; height: 14px; border-radius: 50%; cursor: nesw-resize; }
        .handle-se { bottom: -6px; right: -6px; width: 14px; height: 14px; border-radius: 50%; cursor: nwse-resize; }
        .handle-n { top: -6px; left: 50%; width: 24px; height: 10px; border-radius: 4px; transform: translateX(-50%); cursor: ns-resize; }
        .handle-s { bottom: -6px; left: 50%; width: 24px; height: 10px; border-radius: 4px; transform: translateX(-50%); cursor: ns-resize; }
        .handle-e { top: 50%; right: -6px; width: 10px; height: 24px; border-radius: 4px; transform: translateY(-50%); cursor: ew-resize; }
        .handle-w { top: 50%; left: -6px; width: 10px; height: 24px; border-radius: 4px; transform: translateY(-50%); cursor: ew-resize; }

        /* OBJECT FIT PRESERVES QUALITY WITHOUT SQUISHING */
        .media-content { width: 100%; height: 100%; object-fit: cover; pointer-events: none; display: block; }

        /* Login Card Mockup */
        .mock-login-card-container { width: 380px; z-index: 20; transform-origin: top left; }
        .glossy-card { 
            background: linear-gradient(145deg, rgba(255,255,255,0.95) 0%, rgba(248,250,252,0.9) 100%);
            backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.9); border-radius: 1rem; 
            box-shadow: 0 25px 50px -12px rgba(0, 32, 91, 0.3); pointer-events: none; 
        }

        /* Form elements styles */
        input[type="range"] { -webkit-appearance: none; width: 100%; background: transparent; }
        input[type="range"]::-webkit-slider-thumb { -webkit-appearance: none; height: 16px; width: 16px; border-radius: 50%; background: var(--ldsp-gold); cursor: pointer; margin-top: -6px; }
        input[type="range"]::-webkit-slider-runnable-track { width: 100%; height: 4px; cursor: pointer; background: #cbd5e1; border-radius: 2px; }
        .btn-publish { background: linear-gradient(135deg, var(--ldsp-blue-light) 0%, var(--ldsp-blue) 100%); box-shadow: 0 8px 20px -6px rgba(0, 32, 91, 0.5); }
        
        /* Custom Context Menu */
        .custom-context-menu {
            display: none; position: fixed; z-index: 100; width: 220px; background: white;
            border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1), 0 0 0 1px rgba(0,0,0,0.05);
            padding: 8px; font-family: 'Inter', sans-serif;
        }
        .custom-context-menu.active { display: block; }
        .context-item {
            padding: 10px 12px; border-radius: 8px; display: flex; align-items: center; gap: 10px;
            font-size: 12px; font-weight: 600; color: #334155; cursor: pointer; transition: background 0.2s;
        }
        .context-item:hover { background: #f1f5f9; color: var(--ldsp-blue); }
        
        /* Danger Context Item (Remove) */
        .context-item-danger {
            padding: 10px 12px; border-radius: 8px; display: flex; align-items: center; gap: 10px;
            font-size: 12px; font-weight: 600; color: #e11d48; cursor: pointer; transition: background 0.2s;
            border-top: 1px solid #f1f5f9; margin-top: 4px;
        }
        .context-item-danger:hover { background: #fff1f2; color: #be123c; }

        .dragging-out { opacity: 0.3 !important; filter: grayscale(1); }
    </style>
</head>
<body class="flex h-screen overflow-hidden antialiased">
    <div class="ambient-orb-1"></div>
    <div class="ambient-orb-2"></div>

    <!-- Mobile Header -->
    <div class="md:hidden fixed w-full top-0 left-0 bg-[#001233] z-30 px-6 py-4 flex justify-between items-center shadow-[0_5px_15px_rgba(0,0,0,0.4)] border-b border-white/10">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-white flex items-center justify-center shadow-inner overflow-hidden border border-white/20 p-0.5">
                <img src="../logo.jpg" onerror="this.src='logo.jpg'" alt="LDSP Logo" class="w-full h-full object-contain rounded-full">
            </div>
            <h2 class="text-lg font-bold text-white font-academic uppercase tracking-widest drop-shadow-md">LDSP <span class="gold-gradient-text">ADMIN</span></h2>
        </div>
        <button id="mobileMenuBtn" class="text-white focus:outline-none"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg></button>
    </div>
    
    <div id="sidebarOverlay" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-40 hidden md:hidden transition-opacity" onclick="toggleMenu()"></div>

    <!-- Sidebar -->
    <aside id="sidebar" class="fixed inset-y-0 left-0 transform -translate-x-full md:relative md:translate-x-0 transition-transform duration-300 ease-in-out w-72 sidebar flex flex-col z-50 h-full shrink-0">
        <div class="p-8 text-center border-b border-white/5 relative bg-white/5">
            <div class="w-24 h-24 mx-auto bg-white rounded-full flex items-center justify-center mb-5 shadow-[0_0_20px_rgba(0,0,0,0.5)] border-2 border-[#c5a02c]/50 p-1 relative overflow-hidden group">
                <div class="absolute inset-0 bg-gradient-to-tr from-[var(--ldsp-blue)] to-[var(--ldsp-gold)] opacity-0 group-hover:opacity-20 transition-opacity duration-500 z-10"></div>
                <img src="../logo.jpg" onerror="this.src='logo.jpg'" alt="LDSP Logo" class="w-full h-full object-contain rounded-full shadow-inner relative z-0">
            </div>
            <h2 class="text-xl font-bold text-white font-academic uppercase tracking-[0.2em] hidden md:block drop-shadow-[0_2px_4px_rgba(0,0,0,0.8)]">LDSP <span class="text-[#c5a02c]">ADMIN</span></h2>
            <button id="closeMenuBtn" class="md:hidden absolute top-6 right-6 text-white/50 hover:text-white transition-colors" onclick="toggleMenu()"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
        </div>
        <nav class="flex-1 p-4 space-y-1 mt-2 overflow-y-auto custom-scrollbar">
            <a href="dashboard.php" class="nav-link flex items-center gap-3 px-6 py-3.5 rounded-r-lg text-sm font-semibold uppercase tracking-wider">Dashboard</a>
            <a href="create_account.php" class="nav-link flex items-center gap-3 px-6 py-3.5 rounded-r-lg text-sm font-semibold uppercase tracking-wider">Accounts</a>
            <a href="manage_teaching.php" class="nav-link flex items-center gap-3 px-6 py-3.5 rounded-r-lg text-sm font-semibold uppercase tracking-wider">Staffs</a>
            <a href="manage_programs.php" class="nav-link flex items-center gap-3 px-6 py-3.5 rounded-r-lg text-sm font-semibold uppercase tracking-wider">Programs</a>
            <a href="manage_prospectus.php" class="nav-link flex items-center gap-3 px-6 py-3.5 rounded-r-lg text-sm font-semibold uppercase tracking-wider">Curriculum</a>
            <a href="manage_banks.php" class="nav-link flex items-center gap-3 px-6 py-3.5 rounded-r-lg text-sm font-semibold uppercase tracking-wider">Bank Accounts</a>
            <a href="system_settings.php" class="nav-link active flex items-center gap-3 px-6 py-3.5 rounded-r-lg text-sm font-semibold uppercase tracking-wider text-[#c5a02c]">System Settings</a>

            <div class="pt-8 px-6">
                <p class="text-[10px] font-bold text-white/30 uppercase tracking-[0.2em] mb-4">Operations</p>
                <div class="space-y-3">
                    <a href="../admission/dashboard.php" class="block text-xs text-slate-400 hover:text-white transition-colors uppercase tracking-widest font-semibold">Admission Dept</a>
                    <a href="../registrar/dashboard.php" class="block text-xs text-slate-400 hover:text-white transition-colors uppercase tracking-widest font-semibold">Registrar Dept</a>
                    <a href="../accounting/dashboard.php" class="block text-xs text-slate-400 hover:text-white transition-colors uppercase tracking-widest font-semibold">Accounting Dept</a>
                </div>
            </div>
        </nav>
        <div class="p-6 border-t border-white/5 bg-black/20">
            <a href="../logout.php" class="text-red-400 hover:text-red-300 text-xs font-black uppercase tracking-widest flex items-center gap-2 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg> Logout
            </a>
        </div>
    </aside>

    <main class="flex-1 flex flex-col h-full w-full bg-white relative z-10 pt-[72px] md:pt-0">
        <!-- Editor Topbar -->
        <header class="h-16 border-b border-slate-200 flex items-center justify-between px-6 bg-white shrink-0 shadow-sm z-20">
            <div class="flex items-center gap-3">
                <div class="p-2 bg-indigo-100 text-indigo-600 rounded-lg">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                </div>
                <div>
                    <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest flex items-center gap-2">
                        Portal Visual Editor
                        <span id="sync_indicator" class="ml-2 px-2 py-0.5 rounded-full bg-emerald-50 border border-emerald-100 text-emerald-600 text-[9px] font-black uppercase tracking-widest shadow-sm flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Live Syncing
                        </span>
                    </h1>
                    <p class="text-[10px] text-slate-400 font-bold tracking-wider">Drag out of bounds or Right-Click to remove media.</p>
                </div>
            </div>
            
            <div class="flex items-center gap-4">
                <div class="hidden md:block">
                    <?= $message ?>
                </div>
                <button type="button" onclick="document.getElementById('editorForm').submit();" class="btn-publish text-white font-bold text-xs uppercase tracking-widest px-6 py-2.5 rounded-lg flex items-center gap-2 hover:scale-105 transition-transform">
                    Publish Changes
                </button>
            </div>
        </header>

        <div class="md:hidden px-4 pt-4">
             <?= $message ?>
        </div>

        <!-- Editor Workspace (Split layout) -->
        <div class="flex-1 flex flex-col md:flex-row overflow-hidden">
            
            <!-- Left Properties Panel -->
            <div class="w-full md:w-72 bg-slate-50 border-r border-slate-200 flex flex-col shrink-0 overflow-y-auto">
                <form id="editorForm" action="" method="POST" enctype="multipart/form-data" class="p-5 space-y-6">
                    <!-- Extracted Form Template -->
                    <div id="editorFormContainer">
                        <?= renderEditorForm($settings) ?>
                    </div>
                </form>

                <div class="mt-auto p-5 border-t border-slate-200 bg-slate-100">
                    <p class="text-[10px] text-slate-500 font-bold uppercase tracking-wider mb-2">Editor Controls</p>
                    <ul class="text-xs text-slate-600 space-y-2 list-none">
                        <li class="flex items-start gap-2 mt-2">
                            <svg class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"/></svg>
                            <span><b>Right-click</b> the media for auto layout options and <b>Remove</b> option.</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Right Canvas Area -->
            <div class="flex-1 editor-workspace flex items-center justify-center p-4 md:p-8 overflow-hidden">
                <div id="workspaceContainer" class="w-full h-full flex items-center justify-center">
                    <?= renderWorkspace($settings) ?>
                </div>
            </div>
        </div>
    </main>

    <!-- CUSTOM RIGHT-CLICK CONTEXT MENU -->
    <div id="customContextMenu" class="custom-context-menu">
        <div class="context-item" onclick="applyQuickLayout('fit')">
            <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
            Perfectly Fit to Page
        </div>
        <div class="context-item" onclick="applyQuickLayout('left')">
            <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v18M4 3h5v18H4zM15 3h5v18h-5z"/></svg>
            Left Side Only
        </div>
        <div class="context-item" onclick="applyQuickLayout('right')">
            <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 3v18M4 3h5v18H4zM20 3h-5v18h5z"/></svg>
            Right Side Only
        </div>
        
        <!-- REMOVE MEDIA BUTTON -->
        <div class="context-item-danger" onclick="removeMedia()">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
            Remove Media
        </div>
    </div>

    <!-- Scripts -->
    <script>
        let isFormDirty = false;
        let lastDataString = '';

        // --- FORM PROTECTION LOGIC ---
        function markFormDirty() {
            if (!isFormDirty) {
                isFormDirty = true;
                const indicator = document.getElementById('sync_indicator');
                if (indicator) {
                    indicator.classList.replace('bg-emerald-50', 'bg-amber-50');
                    indicator.classList.replace('border-emerald-100', 'border-amber-100');
                    indicator.classList.replace('text-emerald-600', 'text-amber-600');
                    indicator.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> Unsaved Changes (Sync Paused)';
                }
            }
        }

        // ==========================================
        // DYNAMIC VARIABLES FOR EVENTS
        // ==========================================
        let previewWindow, canvasArea, mediaContainerObj, loginCardElement, contextMenu;

        function initializeEditorEvents() {
            // Setup References
            previewWindow = document.getElementById('previewWindow');
            canvasArea = document.getElementById('canvasArea');
            mediaContainerObj = document.getElementById('mediaContainer');
            loginCardElement = document.getElementById('loginCard');
            contextMenu = document.getElementById('customContextMenu');

            // 1. Setup Canvas Scaling
            function updateCanvasScale() {
                if(!previewWindow || !canvasArea) return;
                const canvasScale = previewWindow.clientWidth / 1920;
                canvasArea.style.transform = `scale(${canvasScale})`;
            }
            window.addEventListener('resize', updateCanvasScale);
            setTimeout(updateCanvasScale, 50);

            // 2. Setup Background Type Select
            const bgTypeInput = document.getElementById('bgType');
            if (bgTypeInput) {
                bgTypeInput.addEventListener('change', (e) => {
                    markFormDirty(); // Pause sync on interaction
                    const uploadSection = document.getElementById('uploadSection');
                    if(e.target.value === 'none') {
                        uploadSection.classList.add('hidden');
                        if (mediaContainerObj) mediaContainerObj.style.display = 'none';
                    } else {
                        uploadSection.classList.remove('hidden');
                        if (mediaContainerObj) mediaContainerObj.style.display = 'block';
                    }
                });
            }

            // 3. Setup Media Upload Preview
            const mediaUploadInput = document.getElementById('mediaUpload');
            if (mediaUploadInput) {
                mediaUploadInput.addEventListener('change', (e) => {
                    markFormDirty(); // Pause sync on interaction
                    const file = e.target.files[0];
                    if (!file) return;
                    
                    document.getElementById('fileNameDisplay').innerText = file.name;
                    const objectUrl = URL.createObjectURL(file);
                    const isVideo = file.type.startsWith('video');

                    if(mediaContainerObj.querySelector('#mediaElement')) {
                        mediaContainerObj.querySelector('#mediaElement').remove();
                    }

                    let newMedia;
                    if(isVideo) {
                        newMedia = document.createElement('video');
                        newMedia.autoplay = true; newMedia.loop = true; newMedia.muted = true;
                    } else {
                        newMedia = document.createElement('img');
                    }
                    
                    newMedia.id = "mediaElement";
                    newMedia.src = objectUrl;
                    newMedia.className = 'media-content formal-insert';
                    newMedia.draggable = false;
                    newMedia.style.transform = 'scale(1)';
                    newMedia.style.transformOrigin = 'center center';
                    
                    mediaContainerObj.style.top = '0%';
                    mediaContainerObj.style.left = '0%';
                    mediaContainerObj.style.width = '100%';
                    mediaContainerObj.style.height = '100%';
                    
                    document.getElementById('inputX').value = 0; 
                    document.getElementById('inputY').value = 0; 
                    document.getElementById('inputW').value = 100; 
                    document.getElementById('inputH').value = 100; 
                    document.getElementById('inputScale').value = 1;
                    document.getElementById('zoomSlider').value = 1;

                    mediaContainerObj.insertBefore(newMedia, mediaContainerObj.firstChild);
                });
            }

            // 4. Setup Sliders
            const cardZoom = document.getElementById('cardZoomSlider');
            if (cardZoom) {
                cardZoom.addEventListener('input', (e) => {
                    markFormDirty(); // Pause sync on interaction
                    const scale = e.target.value;
                    document.getElementById('inputCardScale').value = scale;
                    if(loginCardElement) loginCardElement.style.transform = `scale(${scale})`;
                });
            }

            const bgZoom = document.getElementById('zoomSlider');
            if (bgZoom) {
                bgZoom.addEventListener('input', (e) => {
                    markFormDirty(); // Pause sync on interaction
                    const scale = e.target.value;
                    document.getElementById('inputScale').value = scale;
                    const mediaEl = document.getElementById('mediaElement');
                    if(mediaEl) mediaEl.style.transform = `scale(${scale})`;
                });
            }

            // 5. Context Menu
            if (mediaContainerObj && contextMenu) {
                mediaContainerObj.addEventListener('contextmenu', (e) => {
                    e.preventDefault(); 
                    contextMenu.style.left = `${e.clientX}px`;
                    contextMenu.style.top = `${e.clientY}px`;
                    contextMenu.classList.add('active');
                });
            }

            document.addEventListener('click', (e) => {
                if(contextMenu && !contextMenu.contains(e.target)) {
                    contextMenu.classList.remove('active');
                }
            });

            // 6. Setup Drag & Drop
            makeDraggableAndResizable(mediaContainerObj, true, document.getElementById('inputX'), document.getElementById('inputY'), document.getElementById('inputW'), document.getElementById('inputH'));
            makeDraggableAndResizable(loginCardElement, false, document.getElementById('cardX'), document.getElementById('cardY'));
        }

        // ==========================================
        // TRUE RESPONSIVE DRAG, RESIZE, & DELETE ENGINE
        // ==========================================
        function makeDraggableAndResizable(element, isResizable, hiddenX, hiddenY, hiddenW = null, hiddenH = null) {
            if (!element) return;
            
            let isDragging = false;
            let isResizing = false;
            let currentHandle = '';
            
            let startX, startY;
            let initialLeftPct, initialTopPct, initialW, initialH;

            if(isResizable) {
                const handles = element.querySelectorAll('.canva-handle');
                handles.forEach(handle => {
                    handle.addEventListener('mousedown', (e) => {
                        if(e.button !== 0) return; // Only left click
                        e.stopPropagation(); 
                        markFormDirty(); // Form is dirty as soon as they grab a handle

                        isResizing = true;
                        currentHandle = handle.getAttribute('data-handle');
                        
                        startX = e.clientX;
                        startY = e.clientY;
                        
                        initialLeftPct = parseFloat(element.style.left) || 0;
                        initialTopPct = parseFloat(element.style.top) || 0;
                        initialW = parseFloat(element.style.width) || 100;
                        initialH = parseFloat(element.style.height) || 100;
                    });
                });
            }

            element.addEventListener('mousedown', (e) => {
                if (isResizing || e.button !== 0) return; // Only left click drag
                e.stopPropagation();
                markFormDirty(); // Form is dirty as soon as they click to drag
                
                isDragging = true;
                element.style.cursor = 'grabbing';
                
                startX = e.clientX;
                startY = e.clientY;
                initialLeftPct = parseFloat(element.style.left) || 0;
                initialTopPct = parseFloat(element.style.top) || 0;
            });

            document.addEventListener('mousemove', (e) => {
                const cScale = (previewWindow.clientWidth / 1920);

                if (isDragging) {
                    const dx = (e.clientX - startX) / cScale;
                    const dy = (e.clientY - startY) / cScale;
                    
                    let newLeft = initialLeftPct + ((dx / 1920) * 100);
                    let newTop = initialTopPct + ((dy / 1080) * 100);
                    
                    element.style.left = `${newLeft}%`;
                    element.style.top = `${newTop}%`;
                    
                    if(hiddenX) hiddenX.value = newLeft.toFixed(2);
                    if(hiddenY) hiddenY.value = newTop.toFixed(2);

                    if(element.id === 'mediaContainer') {
                        let currentW = parseFloat(element.style.width) || 100;
                        let currentH = parseFloat(element.style.height) || 100;

                        if (newLeft > 95 || (newLeft + currentW) < 5 || newTop > 95 || (newTop + currentH) < 5) {
                            element.classList.add('dragging-out');
                        } else {
                            element.classList.remove('dragging-out');
                        }
                    }
                }

                if (isResizing) {
                    const dx = (e.clientX - startX) / cScale;
                    const dy = (e.clientY - startY) / cScale;

                    const dxPct = (dx / 1920) * 100;
                    const dyPct = (dy / 1080) * 100;

                    let newW = initialW;
                    let newH = initialH;
                    let newLeft = initialLeftPct;
                    let newTop = initialTopPct;

                    if(currentHandle.includes('e')) newW = initialW + dxPct;
                    if(currentHandle.includes('w')) {
                        newW = initialW - dxPct;
                        newLeft = initialLeftPct + dxPct;
                    }

                    if(currentHandle.includes('s')) newH = initialH + dyPct;
                    if(currentHandle.includes('n')) {
                        newH = initialH - dyPct;
                        newTop = initialTopPct + dyPct;
                    }

                    if(newW > 5) {
                        element.style.width = `${newW}%`;
                        element.style.left = `${newLeft}%`;
                        if(hiddenW) hiddenW.value = newW.toFixed(2);
                        if(hiddenX) hiddenX.value = newLeft.toFixed(2);
                    }
                    if(newH > 5) {
                        element.style.height = `${newH}%`;
                        element.style.top = `${newTop}%`;
                        if(hiddenH) hiddenH.value = newH.toFixed(2);
                        if(hiddenY) hiddenY.value = newTop.toFixed(2);
                    }
                }
            });

            document.addEventListener('mouseup', () => {
                if(isDragging) {
                    isDragging = false;
                    element.style.cursor = 'grab';

                    // DELETE ON DRAG OUT EXECUTION
                    if(element.id === 'mediaContainer' && element.classList.contains('dragging-out')) {
                        element.classList.remove('dragging-out');
                        removeMedia(); // Call the unified delete function
                    }
                }
                if(isResizing) {
                    isResizing = false;
                }
            });
        }

        // ==========================================
        // QUICK ACTIONS
        // ==========================================
        function applyQuickLayout(layoutType) {
            markFormDirty();

            const hiddenX = document.getElementById('inputX');
            const hiddenY = document.getElementById('inputY');
            const hiddenW = document.getElementById('inputW');
            const hiddenH = document.getElementById('inputH');

            let newX = 0, newY = 0, newW = 100, newH = 100;

            if (layoutType === 'fit') {
                newX = 0; newY = 0; newW = 100; newH = 100;
            } else if (layoutType === 'left') {
                newX = 0; newY = 0; newW = 50; newH = 100;
            } else if (layoutType === 'right') {
                newX = 50; newY = 0; newW = 50; newH = 100;
            }

            if (mediaContainerObj) {
                mediaContainerObj.style.left = `${newX}%`;
                mediaContainerObj.style.top = `${newY}%`;
                mediaContainerObj.style.width = `${newW}%`;
                mediaContainerObj.style.height = `${newH}%`;
            }

            if (hiddenX) hiddenX.value = newX;
            if (hiddenY) hiddenY.value = newY;
            if (hiddenW) hiddenW.value = newW;
            if (hiddenH) hiddenH.value = newH;

            if (contextMenu) contextMenu.classList.remove('active');
        }

        function removeMedia() {
            markFormDirty();

            const bgTypeSelect = document.getElementById('bgType');
            if (bgTypeSelect) {
                bgTypeSelect.value = 'none';
                bgTypeSelect.dispatchEvent(new Event('change'));
            }

            if (mediaContainerObj) {
                mediaContainerObj.style.left = '0%';
                mediaContainerObj.style.top = '0%';
                mediaContainerObj.style.width = '100%';
                mediaContainerObj.style.height = '100%';
            }
            
            document.getElementById('inputX').value = 0;
            document.getElementById('inputY').value = 0;
            document.getElementById('inputW').value = 100;
            document.getElementById('inputH').value = 100;
            document.getElementById('inputScale').value = 1;
            document.getElementById('zoomSlider').value = 1;

            if (contextMenu) contextMenu.classList.remove('active');
        }

        // =========================================================
        // ZERO-LAG ASYNCHRONOUS LIVE DATA POLLING (FETCH API)
        // =========================================================
        async function triggerDynamicFetch() {
            // PROTECTION: DO NOT OVERWRITE IF USER IS ACTIVELY EDITING
            if (isFormDirty) return;

            try {
                const response = await fetch(`system_settings.php?api_refresh=1`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok) return;
                
                const data = await response.json();
                const newDataString = JSON.stringify(data);
                
                // Compare to prevent flickering if nothing changed
                if (newDataString === lastDataString) return; 
                lastDataString = newDataString;

                // Update the DOM
                const formContainer = document.getElementById('editorFormContainer');
                const workspaceContainer = document.getElementById('workspaceContainer');
                
                if (formContainer && data.form_html) formContainer.innerHTML = data.form_html;
                if (workspaceContainer && data.workspace_html) workspaceContainer.innerHTML = data.workspace_html;
                
                // RE-BIND ALL EVENTS AFTER HTML REPLACEMENT
                initializeEditorEvents();

            } catch (err) {
                console.error("Auto-sync error:", err);
            }
        }

        // INIT FIRST TIME ON PAGE LOAD
        document.addEventListener('DOMContentLoaded', initializeEditorEvents);
        
        // Poll every 5 seconds
        setInterval(triggerDynamicFetch, 5000);


        // --- SIDEBAR MENU LOGIC ---
        function toggleMenu() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            const overlay = document.getElementById('sidebarOverlay');
            if (overlay.classList.contains('hidden')) {
                overlay.classList.remove('hidden');
                setTimeout(() => overlay.classList.add('opacity-100'), 10);
            } else {
                overlay.classList.remove('opacity-100');
                setTimeout(() => overlay.classList.add('hidden'), 300);
            }
        }
        document.getElementById('mobileMenuBtn').addEventListener('click', toggleMenu);
        document.getElementById('sidebarOverlay').addEventListener('click', toggleMenu);
    </script>
</body>
</html>