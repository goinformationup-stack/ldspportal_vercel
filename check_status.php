<?php include "dbconn.php"; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Admission Status - LDSP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            overflow-x: hidden; 
            min-height: 100vh;
        }
        .font-academic { font-family: 'Crimson Pro', serif; }

        /* Ambient Background Orbs */
        .ambient-orb-1 {
            position: fixed; top: -10%; left: -10%; width: 60vw; height: 60vw;
            background: radial-gradient(circle, rgba(0,32,91,0.06) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%; pointer-events: none; z-index: 0;
        }
        .ambient-orb-2 {
            position: fixed; bottom: -10%; right: -10%; width: 70vw; height: 70vw;
            background: radial-gradient(circle, rgba(197,160,44,0.04) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%; pointer-events: none; z-index: 0;
        }

        /* Premium Glossy Panel */
        .glossy-panel { 
            background: linear-gradient(145deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.85) 100%);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 1);
            border-radius: 1.5rem; 
            box-shadow: 0 20px 40px -15px rgba(0, 32, 91, 0.15), inset 0 2px 4px rgba(255,255,255,1);
            position: relative;
            z-index: 10;
            overflow: hidden;
            transition: transform 0.5s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.5s ease;
        }

        .glossy-panel-header {
            height: 5px; width: 100%; position: absolute; top: 0; left: 0; opacity: 0.95;
            background: linear-gradient(90deg, var(--ldsp-blue) 0%, var(--ldsp-blue-light) 50%, var(--ldsp-gold) 100%);
        }

        /* Smooth Inputs */
        .input-glossy-smooth { 
            background: rgba(249, 250, 251, 0.7);
            border: 1px solid rgba(0, 32, 91, 0.12); 
            border-radius: 0.75rem; 
            padding: 0.75rem 1rem; 
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
            box-shadow: 0 0 0 4px rgba(197, 160, 44, 0.15), inset 0 1px 2px rgba(0,0,0,0.01); 
        }

        /* Glossy Buttons */
        .btn-glossy-animated {
            background: linear-gradient(135deg, var(--ldsp-blue-light) 0%, var(--ldsp-blue) 100%);
            color: white; font-weight: 700; border-radius: 0.75rem; padding: 0.75rem 1.5rem;
            display: inline-flex; justify-content: center; align-items: center; gap: 8px;
            border: 1px solid rgba(0, 20, 60, 0.8);
            box-shadow: 0 8px 20px -6px rgba(0, 32, 91, 0.5), inset 0 2px 0 rgba(255, 255, 255, 0.2);
            position: relative; overflow: hidden; cursor: pointer;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .btn-glossy-animated::after {
            content: ''; position: absolute; top: 0; left: -100%; width: 50%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
            transform: skewX(-20deg); animation: shineSweep 5s infinite cubic-bezier(0.4, 0, 0.2, 1);
        }
        @keyframes shineSweep { 0% { left: -100%; } 20% { left: 200%; } 100% { left: 200%; } }
        
        .btn-glossy-animated:hover { transform: translateY(-2px); box-shadow: 0 12px 25px -8px rgba(0, 32, 91, 0.6), inset 0 2px 0 rgba(255, 255, 255, 0.3); }
        .btn-glossy-animated:active { transform: translateY(1px); box-shadow: 0 2px 8px rgba(0, 32, 91, 0.4), inset 0 3px 5px rgba(0,0,0,0.2); }

        /* Staggered Animations */
        .animate-up { opacity: 0; animation: fadeUpSmooth 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
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

    <div class="max-w-lg w-full glossy-panel p-8 sm:p-12 text-center shadow-[0_15px_50px_rgba(0,32,91,0.15)] relative z-10">
        <div class="glossy-panel-header"></div>
        
        <div class="animate-up">
            <div class="inline-block mb-6 relative group">
                <div class="absolute inset-0 bg-gradient-to-r from-[#00205b] to-[#c5a02c] rounded-full blur-lg opacity-20 transition-opacity duration-500"></div>
                <img src="logo.jpg" alt="LDSP Logo" onerror="this.src='../logo.jpg'" class="relative w-20 h-20 sm:w-24 sm:h-24 object-contain mx-auto drop-shadow-lg rounded-full bg-white p-1.5 border border-white">
            </div>
        </div>

        <div class="animate-up delay-1">
            <h2 class="text-3xl font-black text-[#00205b] tracking-tight mb-2 font-academic uppercase drop-shadow-sm">Track Application</h2>
            <p class="text-slate-500 mb-8 text-sm font-medium">Enter the ADM Number given to you during your application.</p>
        </div>

        <form method="GET" class="mb-8 animate-up delay-2 relative z-20">
            <input type="text" name="adm_no" value="<?= htmlspecialchars($_GET['adm_no'] ?? '') ?>" placeholder="e.g. ADM-2026-0001" required class="input-glossy-smooth w-full !py-4 !text-lg !font-bold font-mono text-center mb-5 uppercase tracking-widest text-[#00205b] shadow-inner placeholder:font-sans placeholder:normal-case placeholder:font-normal placeholder:tracking-normal">
            <button type="submit" class="btn-glossy-animated w-full !py-4 shadow-[0_8px_20px_-6px_rgba(0,32,91,0.5)] flex items-center justify-center gap-2 !text-xs">
                Check Status
                <svg class="w-4 h-4 opacity-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
            </button>
        </form>

        <?php
        if (isset($_GET['adm_no'])) {
            $adm_no = trim($_GET['adm_no']);
            
            // Corrected query: Fetching the separate name columns instead of 'fullname'
            $stmt = $conn->prepare("SELECT last_name, first_name, middle_name, status FROM admissions WHERE admission_number = ?");
            $stmt->bind_param("s", $adm_no);
            $stmt->execute();
            $result = $stmt->get_result();

            echo "<div class='border-t border-slate-200/80 pt-8 mt-4 text-left animate-up delay-3 relative z-20'>";

            if ($row = $result->fetch_assoc()) {
                
                // Combine the separate columns for display purposes only
                $first = !empty($row['first_name']) ? $row['first_name'] : '';
                $middle = !empty($row['middle_name']) ? ' ' . $row['middle_name'] : '';
                $last = !empty($row['last_name']) ? $row['last_name'] . ', ' : '';
                
                $display_name = htmlspecialchars(trim($last . $first . $middle));

                echo "<p class='text-slate-400 text-[10px] uppercase font-bold tracking-widest mb-1 drop-shadow-sm'>Applicant Name</p>";
                echo "<p class='text-lg font-black text-[#00205b] mb-6 drop-shadow-sm'>" . $display_name . "</p>";
                
                echo "<p class='text-slate-400 text-[10px] uppercase font-bold tracking-widest mb-2 drop-shadow-sm'>Current Status</p>";
                
                if ($row['status'] === 'Accepted') {
                    echo "
                    <div class='bg-emerald-50/90 backdrop-blur-sm border border-emerald-200 p-6 rounded-2xl shadow-[inset_0_2px_4px_rgba(255,255,255,1),0_4px_10px_rgba(16,185,129,0.1)] text-center relative overflow-hidden'>
                        <div class=" . '"' . "absolute top-0 right-0 w-24 h-24 bg-emerald-400 rounded-full blur-3xl opacity-20" . '"' . "></div>
                        <div class=" . '"' . "w-12 h-12 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-3 shadow-inner" . '"' . ">
                            <svg class=" . '"' . "w-6 h-6" . '"' . " fill=" . '"' . "none" . '"' . " stroke=" . '"' . "currentColor" . '"' . " viewBox=" . '"' . "0 0 24 24" . '"' . "><path stroke-linecap=" . '"' . "round" . '"' . " stroke-linejoin=" . '"' . "round" . '"' . " stroke-width=" . '"' . "3" . '"' . " d=" . '"' . "M5 13l4 4L19 7" . '"' . "/></svg>
                        </div>
                        <p class='text-2xl font-black text-emerald-600 uppercase tracking-widest mb-2 drop-shadow-sm'>ACCEPTED</p>
                        <p class='text-xs text-emerald-800 font-medium leading-relaxed'>Congratulations! Check your email for your Student ID and Portal Login credentials.</p>
                    </div>";
                } else if ($row['status'] === 'Denied') {
                    echo "
                    <div class='bg-rose-50/90 backdrop-blur-sm border border-rose-200 p-6 rounded-2xl shadow-[inset_0_2px_4px_rgba(255,255,255,1),0_4px_10px_rgba(244,63,94,0.1)] text-center relative overflow-hidden'>
                        <div class=" . '"' . "absolute top-0 right-0 w-24 h-24 bg-rose-400 rounded-full blur-3xl opacity-20" . '"' . "></div>
                        <div class=" . '"' . "w-12 h-12 bg-rose-100 text-rose-600 rounded-full flex items-center justify-center mx-auto mb-3 shadow-inner" . '"' . ">
                            <svg class=" . '"' . "w-6 h-6" . '"' . " fill=" . '"' . "none" . '"' . " stroke=" . '"' . "currentColor" . '"' . " viewBox=" . '"' . "0 0 24 24" . '"' . "><path stroke-linecap=" . '"' . "round" . '"' . " stroke-linejoin=" . '"' . "round" . '"' . " stroke-width=" . '"' . "3" . '"' . " d=" . '"' . "M6 18L18 6M6 6l12 12" . '"' . "/></svg>
                        </div>
                        <p class='text-2xl font-black text-rose-600 uppercase tracking-widest drop-shadow-sm'>DENIED</p>
                    </div>";
                } else {
                    echo "
                    <div class='bg-amber-50/90 backdrop-blur-sm border border-amber-200 p-6 rounded-2xl shadow-[inset_0_2px_4px_rgba(255,255,255,1),0_4px_10px_rgba(245,158,11,0.1)] text-center relative overflow-hidden'>
                        <div class=" . '"' . "absolute top-0 right-0 w-24 h-24 bg-amber-400 rounded-full blur-3xl opacity-20" . '"' . "></div>
                        <div class=" . '"' . "w-12 h-12 bg-amber-100 text-amber-500 rounded-full flex items-center justify-center mx-auto mb-3 shadow-inner" . '"' . ">
                            <svg class=" . '"' . "w-6 h-6 animate-pulse" . '"' . " fill=" . '"' . "none" . '"' . " stroke=" . '"' . "currentColor" . '"' . " viewBox=" . '"' . "0 0 24 24" . '"' . "><path stroke-linecap=" . '"' . "round" . '"' . " stroke-linejoin=" . '"' . "round" . '"' . " stroke-width=" . '"' . "2" . '"' . " d=" . '"' . "M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" . '"' . "/></svg>
                        </div>
                        <p class='text-xl font-black text-amber-600 uppercase tracking-widest mb-2 drop-shadow-sm'>PENDING REVIEW</p>
                        <p class='text-xs text-amber-800 font-medium leading-relaxed'>Your application is currently being evaluated by the admissions office.</p>
                    </div>";
                }
            } else {
                echo "
                <div class='bg-rose-50/90 backdrop-blur-sm text-rose-700 p-5 rounded-xl border border-rose-200 font-bold text-sm text-center shadow-sm flex flex-col items-center gap-2'>
                    <svg class=" . '"' . "w-6 h-6 text-rose-500" . '"' . " fill=" . '"' . "none" . '"' . " stroke=" . '"' . "currentColor" . '"' . " viewBox=" . '"' . "0 0 24 24" . '"' . "><path stroke-linecap=" . '"' . "round" . '"' . " stroke-linejoin=" . '"' . "round" . '"' . " stroke-width=" . '"' . "2" . '"' . " d=" . '"' . "M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" . '"' . "/></svg>
                    Admission Number not found. Please check your spelling.
                </div>";
            }
            
            echo "</div>";
        }
        ?>
        
        <div class="mt-8 animate-up delay-3 relative z-20">
            <a href="admission_portal.php" class="text-slate-400 font-bold text-xs uppercase tracking-widest hover:text-[#00205b] transition-colors flex items-center justify-center gap-2 w-max mx-auto border-b-2 border-transparent hover:border-[#00205b] pb-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                Back to Application Form
            </a>
        </div>
    </div>
</body>
</html>