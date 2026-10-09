<?php
// Cache busting
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Started session just for passing messages if needed, but NO LOGIN REQUIRED
session_start();
include "dbconn.php";

// 🔥 AUTHENTICATION COMPLETELY REMOVED 🔥 
// Anyone can access this page now.

$adm_no = isset($_GET['adm_no']) ? htmlspecialchars($_GET['adm_no']) : '';

if (empty($adm_no)) {
    // Redirect if accessed directly without an admission number
    header("Location: admission_portal.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Application Deployed - LDSP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --ldsp-blue: #00205b;
            --ldsp-gold: #c5a02c;
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f4f6f9;
            color: #1f2937; 
            overflow-x: hidden; 
            min-height: 100vh;
        }
        .font-academic { font-family: 'Crimson Pro', serif; }

        /* Ambient Background Orbs - Official Theme */
        .ambient-orb-1 {
            position: fixed; top: -10%; left: -10%; width: 60vw; height: 60vw;
            background: radial-gradient(circle, rgba(0,32,91,0.06) 0%, rgba(255,255,255,0) 70%); /* Navy Blue */
            border-radius: 50%; pointer-events: none; z-index: 0;
        }
        .ambient-orb-2 {
            position: fixed; bottom: -10%; right: -10%; width: 70vw; height: 70vw;
            background: radial-gradient(circle, rgba(197,160,44,0.04) 0%, rgba(255,255,255,0) 70%); /* Gold */
            border-radius: 50%; pointer-events: none; z-index: 0;
        }

        /* Premium Glossy Panel */
        .glossy-panel { 
            background: linear-gradient(145deg, rgba(255,255,255,1) 0%, rgba(249,250,251,1) 100%);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 1.5rem; 
            box-shadow: 0 20px 40px -15px rgba(0, 32, 91, 0.08), 0 0 0 1px rgba(0,32,91,0.02);
            position: relative;
            z-index: 10;
            overflow: hidden;
            transition: transform 0.5s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.5s ease;
        }

        .glossy-panel-header {
            height: 6px; width: 100%; position: absolute; top: 0; left: 0;
            background: var(--ldsp-blue); /* Solid LDSP Navy */
        }

        /* Smooth Inputs */
        .input-glossy-smooth { 
            background: #ffffff;
            border: 1px solid #e2e8f0; 
            border-radius: 0.75rem; 
            padding: 0.75rem 1rem; 
            font-size: 0.875rem; 
            color: #111827; 
            width: 100%;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            outline: none;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.01);
        }
        .input-glossy-smooth:focus { 
            border-color: var(--ldsp-blue); 
            box-shadow: 0 0 0 4px rgba(0, 32, 91, 0.1), inset 0 1px 2px rgba(0,0,0,0.01); 
        }

        /* Staggered Animations */
        .animate-up { opacity: 0; animation: fadeUpSmooth 0.7s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; } .delay-3 { animation-delay: 0.3s; }
        
        @keyframes fadeUpSmooth { 
            0% { opacity: 0; transform: translateY(20px) scale(0.99); filter: blur(2px); } 
            100% { opacity: 1; transform: translateY(0) scale(1); filter: blur(0); } 
        }

    </style>
</head>
<body class="min-h-screen flex flex-col items-center justify-center p-4 md:p-6 antialiased relative">

    <!-- Ambient Glossy Background -->
    <div class="ambient-orb-1"></div>
    <div class="ambient-orb-2"></div>

    <div class="max-w-2xl w-full glossy-panel p-8 sm:p-10 md:p-14 text-center relative z-10">
        <div class="glossy-panel-header"></div>
        
        <div class="animate-up">
            <!-- Success Icon (Emerald to signify success, but clean) -->
            <div class="w-20 h-20 bg-emerald-50 text-emerald-500 border border-emerald-100 rounded-full flex items-center justify-center mx-auto mb-6 shadow-sm">
                <svg class="w-10 h-10 drop-shadow-sm" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
            </div>
        </div>
        
        <div class="animate-up delay-1">
            <h1 class="text-3xl md:text-4xl font-black text-[#00205b] uppercase tracking-tight mb-3 font-academic drop-shadow-sm">
                Application <span class="text-[#c5a02c]">Deployed</span>
            </h1>
            <p class="text-slate-500 mb-8 sm:mb-10 text-sm font-medium leading-relaxed max-w-md mx-auto">
                Your data has been successfully transmitted to the admissions office. Copy your Admission Number below to track your status.
            </p>
        </div>

        <div class="relative group animate-up delay-2 w-full max-w-[480px] mx-auto">
            <!-- Tracking Number Box (LDSP Theme) -->
            <div id="copy-container" onclick="copyToClipboard()" class="cursor-pointer bg-white border border-slate-200 hover:border-[#00205b] p-6 sm:p-8 rounded-2xl mb-10 transition-all active:scale-[0.98] shadow-[0_2px_10px_rgba(0,0,0,0.02)] hover:shadow-[0_8px_20px_rgba(0,32,91,0.08)]">
                <p class="text-[9px] sm:text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 flex items-center justify-center gap-2 drop-shadow-sm transition-colors group-hover:text-[#00205b]">
                    Admission Tracking Number 
                    <span class="text-slate-300 font-bold hidden sm:inline">(Click to Copy)</span>
                </p>
                
                <div class="flex items-center justify-center gap-3">
                    <!-- The Tracking Number - Forced to never wrap, single-tap selection -->
                    <h2 id="adm-no" class="text-[22px] sm:text-3xl md:text-4xl font-mono font-black text-[#00205b] tracking-wider pb-1" style="-webkit-user-select: all; user-select: all; word-break: keep-all; white-space: nowrap;">
                        <?= $adm_no ?>
                    </h2>
                    
                    <div class="p-2 sm:p-2.5 rounded-lg bg-slate-50 border border-slate-100 text-slate-400 group-hover:bg-[#00205b] group-hover:border-[#00205b] group-hover:text-white transition-all duration-300 shadow-sm shrink-0">
                        <svg id="copy-icon" class="w-4 h-4 sm:w-5 sm:h-5 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                </div>
                
                <!-- Mobile Hint -->
                <p class="text-[9px] font-bold text-slate-300 uppercase tracking-widest mt-4 sm:hidden">Tap number to instantly copy</p>
                
                <!-- Floating Copied Toast -->
                <p id="copy-toast" class="absolute -bottom-10 left-1/2 -translate-x-1/2 text-[10px] font-black text-white uppercase tracking-[0.2em] opacity-0 transition-all duration-300 bg-[#00205b] px-4 py-1.5 rounded-lg shadow-md translate-y-2 pointer-events-none whitespace-nowrap">
                    Copied to Clipboard!
                </p>
            </div>
        </div>

        <div class="pt-8 sm:pt-10 border-t border-slate-100 animate-up delay-3 relative z-20">
            <p class="text-[10px] font-black text-slate-400 uppercase mb-4 tracking-widest drop-shadow-sm">Ready to check status?</p>
            <form action="track_status.php" method="GET" class="flex flex-col sm:flex-row gap-3 max-w-[480px] mx-auto">
                <input type="text" id="status_input" name="adm_no" placeholder="Paste ADM Number here" value="<?= $adm_no ?>" class="input-glossy-smooth flex-1 uppercase font-mono text-sm font-bold tracking-widest text-center sm:text-left !py-3.5 shadow-inner min-w-0" required>
                <button type="submit" class="bg-[#00205b] hover:bg-[#003882] text-white font-black text-[11px] sm:text-xs uppercase tracking-widest px-8 py-4 sm:py-3.5 rounded-xl shadow-[0_8px_15px_rgba(0,32,91,0.2)] hover:shadow-[0_10px_25px_rgba(0,32,91,0.3)] transition-all hover:-translate-y-0.5 whitespace-nowrap shrink-0">
                    Track Status
                </button>
            </form>
        </div>
        
    </div>

    <script>
        function copyToClipboard() {
            const admNoElement = document.getElementById('adm-no');
            const admText = admNoElement.innerText.trim();
            if (admText === 'N/A' || admText === '') return;

            const toast = document.getElementById('copy-toast');
            const icon = document.getElementById('copy-icon');
            const input = document.getElementById('status_input');
            
            navigator.clipboard.writeText(admText).then(() => {
                // Auto-fill input
                if (input) {
                    input.value = admText;
                    input.classList.add('ring-2', 'ring-[#00205b]', 'border-[#00205b]');
                    setTimeout(() => input.classList.remove('ring-2', 'ring-[#00205b]', 'border-[#00205b]'), 500);
                }

                // Show visual feedback
                toast.style.opacity = '1';
                toast.style.transform = 'translate(-50%, 0)';
                
                // Reset after 2 seconds
                setTimeout(() => {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translate(-50%, 8px)';
                }, 2000);
            });
        }
    </script>

</body>
</html>