<?php
// CRITICAL FIX: Change session name to be isolated for Teachers
if (session_status() === PHP_SESSION_NONE) {
    $session_lifetime = 60 * 60 * 24 * 30; 
    ini_set('session.gc_maxlifetime', $session_lifetime);
    session_set_cookie_params($session_lifetime, '/'); 
    session_name('LDSP_TEACHER_SESSION');
    session_start();
}

// Redirect if already logged in as teacher
if (isset($_SESSION['role']) && $_SESSION['role'] === 'teacher') {
    header("Location: home_page.php");
    exit();
}

$error_msg = '';
$login_success = false;
$redirect_url = 'home_page.php';

// ---------------------------------------------------------
// TEACHER LOGIN PROCESSING (BOTH STANDARD & GOOGLE OAUTH)
// ---------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    include "../dbconn.php";
    
    // --- METHOD 1: GOOGLE OAUTH LOGIN ---
    if (isset($_POST['credential'])) {
        $jwt = $_POST['credential'];
        $verify_url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . $jwt;
        $response = @file_get_contents($verify_url);
        
        if ($response) {
            $google_data = json_decode($response, true);
            if (isset($google_data['email'])) {
                $email = trim($google_data['email']);
                processTeacherLogin($conn, $email, null, true, $error_msg, $login_success);
            } else {
                $error_msg = "Failed to retrieve email from Google.";
            }
        } else {
            $error_msg = "Google Authentication Failed.";
        }
    } 
    // --- METHOD 2: TRADITIONAL LOGIN ---
    elseif (isset($_POST['email']) && isset($_POST['password'])) {
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        
        if (!empty($email) && !empty($password)) {
            processTeacherLogin($conn, $email, $password, false, $error_msg, $login_success);
        } else {
            $error_msg = "Please fill in all fields.";
        }
    }
}

function processTeacherLogin($conn, $email, $password, $is_google, &$error_msg, &$login_success) {
    $stmt = $conn->prepare("
        SELECT u.id, u.password, p.first_name, p.last_name 
        FROM users u 
        LEFT JOIN user_profiles p ON u.id = p.user_id 
        WHERE u.email = ? 
        AND u.role = 'teacher' 
        AND (u.status = 'Active' OR u.status IS NULL)
        AND (u.is_archived = 0 OR u.is_archived IS NULL)
    ");
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($user = $res->fetch_assoc()) {
        // Verify Password (Bypass this check if they logged in securely via Google)
        if ($is_google || password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = 'teacher';
            $_SESSION['first_name'] = $user['first_name'] ?? 'Faculty';
            $_SESSION['last_name'] = $user['last_name'] ?? '';
            
            $login_success = true;
        } else {
            $error_msg = "Invalid password.";
        }
    } else {
        $error_msg = $is_google ? "This Google account is not registered as an active teacher." : "Teacher account not found or access has been restricted.";
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Portal - LDSP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Identity Services -->
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ldsp-blue: #00205b;
            --ldsp-blue-light: #003882;
            --ldsp-accent: #4f46e5;
            --ldsp-accent-light: #818cf8;
            --ldsp-gold: #c5a02c;
        }
        body { 
            font-family: 'Inter', sans-serif; 
            margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh;
            background: radial-gradient(circle at 50% -20%, #e5e7eb 0%, #cbd5e1 50%, #94a3b8 100%);
            background-attachment: fixed;
        }
        .font-academic { font-family: 'Crimson Pro', serif; }
        
        .fade-in-up { animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        @keyframes fadeInUp { 0% { opacity: 0; transform: translateY(30px); } 100% { opacity: 1; transform: translateY(0); } }

        .glossy-card {
            background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid rgba(255, 255, 255, 0.9);
            border-top: 5px solid var(--ldsp-blue);
            box-shadow: 0 25px 50px -12px rgba(0, 32, 91, 0.2), 0 0 0 1px rgba(226, 232, 240, 0.5), inset 0 2px 4px rgba(255, 255, 255, 1);
            position: relative; overflow: hidden; border-radius: 0.75rem;
        }
        .glossy-card::before {
            content: ''; position: absolute; top: 0; left: -150%; width: 50%; height: 100%;
            background: linear-gradient(to right, rgba(255,255,255,0) 0%, rgba(255,255,255,0.4) 50%, rgba(255,255,255,0) 100%);
            transform: skewX(-25deg); animation: shine 7s infinite; pointer-events: none;
        }
        @keyframes shine { 0% { left: -150%; } 20% { left: 200%; } 100% { left: 200%; } }

        .input-glossy {
            background: #fdfdfd; border: 1px solid #cbd5e1; color: #111827; outline: none;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.02), 0 1px 0 rgba(255,255,255,1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .input-glossy:focus { background: #ffffff; border-color: var(--ldsp-accent); box-shadow: inset 0 1px 2px rgba(0,0,0,0.02), 0 0 0 4px rgba(79, 70, 229, 0.1); }

        .btn-glossy {
            background: linear-gradient(180deg, var(--ldsp-blue-light) 0%, var(--ldsp-blue) 100%);
            border: 1px solid #001233;
            box-shadow: 0 4px 6px -1px rgba(0, 32, 91, 0.3), 0 2px 4px -1px rgba(0, 32, 91, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.2);
            text-shadow: 0 1px 2px rgba(0,0,0,0.6); transition: all 0.2s ease;
        }
        .btn-glossy:hover { background: linear-gradient(180deg, #0044a3 0%, var(--ldsp-blue-light) 100%); transform: translateY(-1px); box-shadow: 0 6px 10px -1px rgba(0, 32, 91, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.3); }
        .btn-glossy:active { transform: translateY(1px); box-shadow: 0 1px 2px rgba(0, 32, 91, 0.4), inset 0 2px 4px rgba(0, 0, 0, 0.3); }

        .accent-gradient-text { background: linear-gradient(135deg, var(--ldsp-accent) 0%, #3730a3 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .accent-divider { background: linear-gradient(90deg, transparent, var(--ldsp-accent), transparent); }

        /* =========================================
           ACADEMIC PROFESSIONAL LOADING CSS 
           ========================================= */
        .academic-bg {
            background-color: #f8fafc;
            background-image: radial-gradient(circle at 50% 0%, #ffffff 0%, #e2e8f0 100%);
        }
        
        .elegant-pulse {
            animation: elegantPulse 2.5s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        
        @keyframes elegantPulse {
            0%, 100% { transform: scale(1); box-shadow: 0 4px 20px rgba(0, 32, 91, 0.08); }
            50% { transform: scale(1.03); box-shadow: 0 10px 30px rgba(0, 32, 91, 0.18); }
        }

        .academic-progress-container {
            width: 100%; max-width: 260px; height: 3px;
            background: #cbd5e1; border-radius: 4px; overflow: hidden;
            margin: 1.5rem auto;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
        }

        .academic-progress-fill {
            height: 100%; background: var(--ldsp-blue);
            width: 0%; border-radius: 4px;
        }

        .fade-text { animation: fadeInOut 0.6s ease-in-out forwards; }
        
        @keyframes fadeInOut {
            0% { opacity: 0; transform: translateY(4px); }
            100% { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="relative overflow-hidden w-full h-full min-h-screen">

    <?php if ($login_success): ?>
        <div class="fixed inset-0 z-50 flex flex-col items-center justify-center academic-bg fade-in-up">
            <div class="z-10 flex flex-col items-center max-w-sm w-full px-6 text-center">
                
                <!-- Elegant Pulsing Logo -->
                <div class="w-28 h-28 rounded-full border border-slate-200 bg-white p-1.5 mb-6 elegant-pulse flex items-center justify-center">
                    <img src="../logo.jpg" alt="LDSP Logo" class="w-full h-full object-contain rounded-full">
                </div>

                <!-- Academic Heading -->
                <h1 class="text-[#00205b] text-2xl font-bold font-academic uppercase tracking-widest mb-1 drop-shadow-sm">
                    Authenticating
                </h1>
                <p class="text-slate-400 text-[10px] uppercase tracking-[0.2em] font-bold">Please wait</p>
                
                <!-- Elegant Progress Bar -->
                <div class="academic-progress-container">
                    <div class="academic-progress-fill" id="academic-bar"></div>
                </div>

                <!-- Clean Status Text -->
                <div class="h-6 flex items-center justify-center mt-2">
                    <p id="sys-status" class="text-[11px] text-slate-500 font-medium tracking-wide fade-text">Verifying credentials...</p>
                </div>

            </div>
        </div>

        <script>
            const statusText = document.getElementById('sys-status');
            const progressBar = document.getElementById('academic-bar');

            // Start smooth progress bar fill
            setTimeout(() => { 
                progressBar.style.transition = 'width 2.8s cubic-bezier(0.4, 0, 0.2, 1)'; 
                progressBar.style.width = '100%'; 
            }, 100);

            // Transition text messages elegantly
            setTimeout(() => { 
                statusText.style.animation = 'none'; 
                void statusText.offsetWidth; // trigger reflow
                statusText.style.animation = 'fadeInOut 0.5s ease-in-out forwards';
                statusText.innerText = 'Securing session...'; 
            }, 1200);

            setTimeout(() => { 
                statusText.style.animation = 'none'; 
                void statusText.offsetWidth; // trigger reflow
                statusText.style.animation = 'fadeInOut 0.5s ease-in-out forwards';
                statusText.innerText = 'Loading dashboard...'; 
                statusText.classList.remove('text-slate-500');
                statusText.classList.add('text-[#00205b]', 'font-bold');
            }, 2200);

            // Final Redirect
            setTimeout(() => {
                window.location.href = '<?= $redirect_url ?>';
            }, 3000);
        </script>

    <?php else: ?>
        <main class="glossy-card p-8 md:p-12 w-full max-w-md mx-4 z-10 fade-in-up">
            <div class="text-center mb-10">
                <div class="bg-white p-2 rounded-full shadow-[0_4px_10px_rgba(0,0,0,0.05)] inline-block mb-6 border border-gray-100">
                    <img src="../logo.jpg" alt="LDSP Logo" class="w-28 h-28 object-contain rounded-full">
                </div>
                <h1 class="text-2xl font-bold text-[#00205b] tracking-tight mb-2 font-academic uppercase drop-shadow-sm">Teacher <span class="accent-gradient-text">Portal</span></h1>
                <div class="w-20 h-1 accent-divider mx-auto mb-4 rounded-full"></div>
                <p class="text-gray-500 text-[10px] font-bold tracking-[0.2em] uppercase">Faculty Dashboard Access</p>
            </div>

            <?php if (!empty($error_msg)): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 text-xs px-4 py-3 rounded-md mb-6 flex items-center gap-3 shadow-inner">
                <svg class="w-4 h-4 flex-shrink-0 text-red-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                <span class="font-semibold"><?= $error_msg ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="mb-5">
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-2">Institutional Email</label>
                    <input type="email" name="email" required placeholder="teacher@ldsp.edu.ph" class="input-glossy w-full px-4 py-3.5 rounded-md text-sm" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                </div>
                <div class="mb-8">
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-2">Password</label>
                    <input type="password" name="password" required placeholder="••••••••" class="input-glossy w-full px-4 py-3.5 rounded-md text-sm">
                </div>
                <button type="submit" class="btn-glossy w-full text-white font-bold text-xs uppercase tracking-[0.15em] py-4 rounded-md flex items-center justify-center gap-2">Login</button>
            </form>

            <!-- DIVIDER -->
            <div class="my-6 flex items-center before:mt-0.5 before:flex-1 before:border-t before:border-gray-200 after:mt-0.5 after:flex-1 after:border-t after:border-gray-200">
                <p class="mx-4 mb-0 text-center font-bold text-gray-400 text-[10px] tracking-widest uppercase">Or</p>
            </div>

            <!-- GOOGLE LOGIN BUTTON CONTAINER -->
            <div class="flex justify-center w-full">
                <div id="g_id_onload"
                     data-client_id="730165365033-kbggtd9222hbkc210lsg7q4b1c8tinr8.apps.googleusercontent.com"
                     data-context="signin"
                     data-ux_mode="popup"
                     data-login_uri="http://localhost/LDSP_enrollment_system/index.php"
                     data-auto_prompt="false">
                </div>

                <div class="g_id_signin flex justify-center"
                     data-type="standard"
                     data-shape="rectangular"
                     data-theme="outline"
                     data-text="signin_with"
                     data-size="large"
                     data-logo_alignment="center">
                </div>
            </div>

            <footer class="mt-8 text-center border-t border-slate-100 pt-6">
                <p class="text-[10px] text-gray-400 uppercase tracking-widest font-semibold">&copy; <?= date('Y') ?> LDSP Faculty </p>
            </footer>
        </main>
    <?php endif; ?>
</body>
</html>