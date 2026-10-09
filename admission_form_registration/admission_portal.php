<?php 
include "../dbconn.php"; 

// --- 1. FETCH DYNAMIC SETTINGS ---
$settings = [];
try {
    $check = $conn->query("SHOW TABLES LIKE 'portal_settings'");
    if ($check && $check->num_rows > 0) {
        $res = $conn->query("SELECT * FROM portal_settings");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
    }
} catch (Exception $e) {}

$admission_status = $settings['admission_status'] ?? 'Open';
$academic_year = $settings['academic_year'] ?? '2026-2027';
$semester = $settings['semester'] ?? '1st Semester';
$portal_title = $settings['portal_title'] ?? 'Admission Portal';
$announcement = $settings['announcement'] ?? 'Welcome to the Lyceum de San Pablo digital gateway. Online enrollment is now officially ongoing.';
$requirements = $settings['requirements'] ?? "1. Form 138 (Original Report Card)\n2. PSA Birth Certificate\n3. Certificate of Good Moral\n4. 2x2 ID Picture\n5. Long brown envelope & colored folder";

// Get dynamic step count
$process_steps_count = isset($settings['process_steps_count']) ? (int)$settings['process_steps_count'] : 3;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($portal_title) ?> - Lyceum de San Pablo</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ldsp-blue: #00205b;
            --ldsp-blue-light: #003882;
            --ldsp-gold: #c5a02c;
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f4f6f9;
            color: #1f2937; 
            min-height: 100vh;
            overflow-x: hidden;
        }

        .ambient-orb-1 {
            position: fixed; top: -10%; left: -10%; width: 50vw; height: 50vw;
            background: radial-gradient(circle, rgba(0,32,91,0.06) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%; pointer-events: none; z-index: 0;
        }
        .ambient-orb-2 {
            position: fixed; bottom: -10%; right: -10%; width: 60vw; height: 60vw;
            background: radial-gradient(circle, rgba(197,160,44,0.06) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%; pointer-events: none; z-index: 0;
        }

        .font-academic { font-family: 'Crimson Pro', serif; }
        
        .glossy-panel { 
            background: linear-gradient(145deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.85) 100%);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 1);
            border-radius: 1rem; 
            box-shadow: 0 10px 30px -10px rgba(0, 32, 91, 0.1), inset 0 2px 4px rgba(255,255,255,1);
            position: relative;
            z-index: 10;
            overflow: hidden;
        }

        .glossy-panel::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
            background: linear-gradient(90deg, var(--ldsp-blue) 0%, var(--ldsp-blue-light) 50%, var(--ldsp-gold) 100%);
            opacity: 0.9;
        }

        .input-glossy-smooth { 
            background: rgba(249, 250, 251, 0.7);
            border: 1px solid rgba(0, 32, 91, 0.12); 
            border-radius: 0.5rem; 
            padding: 0.625rem 0.875rem; 
            font-size: 0.875rem; 
            color: #111827; 
            width: 100%;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            outline: none;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.015), 0 1px 0 rgba(255,255,255,1);
        }
        .input-glossy-smooth:focus { 
            background: #ffffff;
            border-color: var(--ldsp-gold); 
            box-shadow: 0 0 0 3px rgba(197, 160, 44, 0.15), inset 0 1px 2px rgba(0,0,0,0.01); 
            transform: translateY(-1px);
        }

        .btn-glossy-animated {
            background: linear-gradient(135deg, var(--ldsp-blue-light) 0%, var(--ldsp-blue) 100%);
            color: white; 
            font-weight: 600; 
            border-radius: 0.5rem; 
            padding: 0.75rem 1.5rem;
            font-size: 0.9rem;
            display: inline-flex; justify-content: center; align-items: center; gap: 8px;
            border: 1px solid rgba(0, 20, 60, 0.8);
            box-shadow: 0 6px 15px -4px rgba(0, 32, 91, 0.5), inset 0 2px 0 rgba(255, 255, 255, 0.2);
            position: relative; overflow: hidden; cursor: pointer;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .btn-glossy-animated::after {
            content: ''; position: absolute; top: 0; left: -100%; width: 50%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
            transform: skewX(-20deg);
            animation: shineSweep 5s infinite cubic-bezier(0.4, 0, 0.2, 1);
        }
        @keyframes shineSweep { 0% { left: -100%; } 20% { left: 200%; } 100% { left: 200%; } }
        
        .btn-glossy-animated:hover:not(:disabled) { 
            transform: translateY(-2px) scale(1.01);
            box-shadow: 0 8px 20px -6px rgba(0, 32, 91, 0.6), inset 0 2px 0 rgba(255, 255, 255, 0.3);
        }
        
        .btn-gold {
            background: linear-gradient(135deg, #d4b242 0%, var(--ldsp-gold) 100%);
            color: white; border: 1px solid #a3821f;
            box-shadow: 0 4px 10px -3px rgba(197, 160, 44, 0.4), inset 0 2px 0 rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
        }
        .btn-gold:hover { transform: translateY(-1px); box-shadow: 0 6px 15px -3px rgba(197, 160, 44, 0.5), inset 0 2px 0 rgba(255, 255, 255, 0.4); }

        .glass-section {
            background: rgba(255, 255, 255, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.8);
            border-radius: 0.75rem;
            box-shadow: inset 0 1px 4px rgba(0,0,0,0.02);
        }

        .section-header {
            color: var(--ldsp-blue); font-weight: 700; font-size: 1rem;
            display: flex; align-items: center; gap: 0.5rem;
            margin-bottom: 1rem; border-bottom: 2px solid rgba(0,32,91,0.05); padding-bottom: 0.5rem;
        }
        
        .animate-up { opacity: 0; animation: fadeUpSmooth 0.7s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; } .delay-4 { animation-delay: 0.4s; }
        
        @keyframes fadeUpSmooth { 
            0% { opacity: 0; transform: translateY(20px) scale(0.99); filter: blur(2px); } 
            100% { opacity: 1; transform: translateY(0) scale(1); filter: blur(0); } 
        }
    </style>
</head>
<body class="flex items-center justify-center p-4 sm:p-6 md:p-8">

    <div class="ambient-orb-1"></div>
    <div class="ambient-orb-2"></div>

    <div class="max-w-3xl w-full glossy-panel p-6 sm:p-8 md:p-10 relative z-10 animate-up">
        
        <div class="text-center mb-8 pb-6 border-b border-gray-200/60">
            <div class="inline-block mb-4 relative group">
                <div class="absolute inset-0 bg-gradient-to-r from-[var(--ldsp-blue)] to-[var(--ldsp-gold)] rounded-full blur-lg opacity-20 group-hover:opacity-40 transition-opacity duration-500"></div>
                <img src="../logo.jpg" alt="LDSP Logo" class="relative w-20 h-20 sm:w-24 sm:h-24 object-contain mx-auto drop-shadow-md rounded-full bg-white p-1.5 border border-white">
            </div>
            
            <?php 
                $title_parts = explode(' ', htmlspecialchars($portal_title));
                $last_word = array_pop($title_parts);
                $first_part = implode(' ', $title_parts);
            ?>
            <h1 class="text-3xl sm:text-4xl font-black text-[#00205b] font-academic tracking-tight mb-3 drop-shadow-sm">
                <?= $first_part ?> <span class="text-transparent bg-clip-text bg-gradient-to-r from-[#c5a02c] to-[#d4b242]"><?= $last_word ?></span>
            </h1>
            
            <span class="inline-block bg-gradient-to-r from-[#003882] to-[#00205b] text-white font-bold text-[10px] uppercase tracking-widest px-4 py-1.5 rounded-full shadow-[inset_0_2px_0_rgba(255,255,255,0.2),0_2px_5px_rgba(0,32,91,0.2)]">
                <?= htmlspecialchars($semester) ?> &bull; A.Y. <?= htmlspecialchars($academic_year) ?>
            </span>
        </div>
        
        <div class="mb-8 bg-[#00205b]/5 border border-[#00205b]/10 rounded-xl p-4 sm:p-5 hover:bg-[#00205b]/[0.07] transition-colors duration-300">
            <div class="flex flex-col sm:flex-row gap-4 items-start sm:items-center">
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-[#003882] to-[#00205b] text-white flex items-center justify-center shrink-0 shadow-md border border-white">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
                </div>
                <div class="text-gray-700 leading-relaxed text-sm font-medium">
                    <?= nl2br(htmlspecialchars($announcement)) ?>
                </div>
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
            <div class="glass-section p-5 hover:-translate-y-1 transition-transform duration-300">
                <h3 class="section-header">
                    <svg class="w-4 h-4 text-[#c5a02c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Required Documents
                </h3>
                <div class="text-xs text-gray-700 font-medium leading-relaxed drop-shadow-sm">
                    <?= nl2br(htmlspecialchars($requirements)) ?>
                </div>
            </div>

            <div class="glass-section p-5 hover:-translate-y-1 transition-transform duration-300">
                <h3 class="section-header">
                    <svg class="w-4 h-4 text-[#c5a02c]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    Process Overview
                </h3>
                <ul class="text-xs text-gray-700 font-medium space-y-4">
                    <?php 
                    for ($i = 1; $i <= $process_steps_count; $i++) {
                        $step_title = $settings["step_{$i}_title"] ?? "Step {$i}";
                        $isActive = ($i < $process_steps_count);
                        $circle_bg = $isActive ? 'bg-gradient-to-br from-[#003882] to-[#00205b] text-white border-none shadow-[inset_0_2px_0_rgba(255,255,255,0.3),0_2px_4px_rgba(0,32,91,0.3)]' : 'bg-gray-100/50 text-gray-400 border border-gray-300';
                        $text_color = $isActive ? 'text-[#00205b]' : 'text-gray-400';

                        echo '
                        <li class="flex items-center gap-3 group">
                            <span class="w-6 h-6 ' . $circle_bg . ' flex items-center justify-center text-[10px] font-black rounded-full shrink-0 transition-transform group-hover:scale-110">' . $i . '</span> 
                            <span class="font-bold ' . $text_color . '">' . htmlspecialchars($step_title) . '</span>
                        </li>';
                    }
                    ?>
                </ul>
            </div>
        </div>

        <div class="flex flex-col items-center gap-6 pt-6 border-t border-gray-200/60">
            <?php if ($admission_status === 'Open'): ?>
                <a href="admission_form.php" class="btn-glossy-animated w-full sm:w-auto text-sm sm:text-base text-center">
                    Proceed to Application Form &rarr;
                </a>
            <?php else: ?>
                <button disabled class="btn-glossy-animated w-full sm:w-auto text-sm sm:text-base opacity-60">
                    Enrollment Currently Closed
                </button>
            <?php endif; ?>

            <div class="w-full max-w-sm mt-2 glass-section p-5 text-center shadow-md">
                <h4 class="text-[9px] font-black text-[#c5a02c] uppercase tracking-[0.15em] mb-3 drop-shadow-sm">Track Application Status</h4>
                <form action="../track_status.php" method="GET" class="flex flex-col sm:flex-row gap-2">
                    <input type="text" name="adm_no" required placeholder="ADM-2026-XXXX" 
                           class="input-glossy-smooth font-mono text-center sm:text-left font-bold uppercase tracking-widest flex-1 text-xs">
                    <button type="submit" class="btn-gold px-5 py-2.5 rounded-lg text-xs font-black uppercase tracking-wider">
                        Search
                    </button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>