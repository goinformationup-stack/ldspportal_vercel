<?php
session_start();
// Include the database connection from the same root folder
include "dbconn.php"; 

$error = '';
$success = '';
$valid_token = false;
$user_id = null;
$user_email = '';

// 1. VERIFY TOKEN
if (isset($_GET['token']) || isset($_POST['token'])) {
    $token = $_GET['token'] ?? $_POST['token'];
    
    // Check if token exists and is not expired
    $stmt = $conn->prepare("SELECT id, email, first_name FROM users u LEFT JOIN user_profiles p ON u.id = p.user_id WHERE activation_token = ? AND token_expiry > NOW() LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $valid_token = true;
        $row = $result->fetch_assoc();
        $user_id = $row['id'];
        $user_email = $row['email'];
        $first_name = $row['first_name'] ?? 'Staff';
    } else {
        $error = "This setup link is invalid or has expired (exceeded 48 hours). Please contact the system administrator to request a new invite.";
    }
} else {
    $error = "No setup token provided. Please use the exact link sent to your email.";
}

// 2. PROCESS PASSWORD SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];
    
    if (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match. Please try again.";
    } else {
        // Hash the new password securely
        $hashed_pass = password_hash($password, PASSWORD_DEFAULT);
        
        // Update user: Set password, activate account, and wipe the token
        $update_stmt = $conn->prepare("UPDATE users SET password = ?, status = 'Active', activation_token = NULL, token_expiry = NULL WHERE id = ?");
        $update_stmt->bind_param("si", $hashed_pass, $user_id);
        
        if ($update_stmt->execute()) {
            $success = "Your password has been successfully set, and your account is now active!";
            $valid_token = false; // Hide the form
        } else {
            $error = "Database error. Failed to activate account.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Setup - LDSP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .font-academic { font-family: 'Crimson Pro', serif; }
        .ambient-orb {
            position: absolute; width: 600px; height: 600px; border-radius: 50%;
            background: radial-gradient(circle, rgba(0,32,91,0.08) 0%, rgba(255,255,255,0) 70%);
            z-index: 0; filter: blur(40px); pointer-events: none;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center relative overflow-hidden">
    <div class="ambient-orb" style="top: -100px; left: -100px;"></div>
    <div class="ambient-orb" style="bottom: -200px; right: -100px; background: radial-gradient(circle, rgba(197,160,44,0.08) 0%, rgba(255,255,255,0) 70%);"></div>

    <div class="w-full max-w-md p-6 relative z-10">
        
        <!-- Logo Header -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">Lyceum de San Pablo</h1>
            <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mt-1">Staff Account Activation</p>
        </div>

        <!-- Main Card -->
        <div class="bg-white/80 backdrop-blur-xl border border-slate-200/60 rounded-2xl shadow-xl overflow-hidden">
            <div class="h-2 w-full bg-gradient-to-r from-[#00205b] to-indigo-500"></div>
            
            <div class="p-6 sm:p-8">
                <?php if ($success): ?>
                    <!-- SUCCESS STATE -->
                    <div class="text-center">
                        <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-4">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <h2 class="text-lg font-bold text-slate-800 mb-2">Setup Complete!</h2>
                        <p class="text-sm text-slate-500 mb-6"><?= $success ?></p>
                        <a href="login.php" class="inline-flex items-center justify-center w-full px-4 py-2.5 bg-[#00205b] hover:bg-[#001233] text-white text-sm font-bold rounded-lg transition-colors shadow-md">
                            Proceed to Login
                        </a>
                    </div>
                
                <?php elseif ($error && !$valid_token): ?>
                    <!-- ERROR STATE (Invalid/Expired Token) -->
                    <div class="text-center">
                        <div class="w-16 h-16 bg-rose-100 text-rose-600 rounded-full flex items-center justify-center mx-auto mb-4">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        </div>
                        <h2 class="text-lg font-bold text-slate-800 mb-2">Link Invalid</h2>
                        <p class="text-sm text-slate-500 mb-6"><?= $error ?></p>
                        <a href="index.php" class="inline-flex items-center justify-center w-full px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-sm font-bold rounded-lg transition-colors border border-slate-300">
                            Return to Homepage
                        </a>
                    </div>

                <?php else: ?>
                    <!-- FORM STATE -->
                    <div class="mb-6">
                        <h2 class="text-xl font-bold text-slate-800">Welcome, <?= htmlspecialchars($first_name) ?>!</h2>
                        <p class="text-xs text-slate-500 mt-1">Set a secure password for <span class="font-bold text-slate-700"><?= htmlspecialchars($user_email) ?></span> to activate your account.</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="bg-rose-50 border-l-4 border-rose-500 p-3 mb-5 rounded-r">
                            <p class="text-xs font-bold text-rose-700"><?= $error ?></p>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                        
                        <div class="space-y-4">
                            <div>
                                <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 block">New Password</label>
                                <input type="password" name="password" required minlength="6" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all font-mono" placeholder="Minimum 6 characters">
                            </div>
                            
                            <div>
                                <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 block">Confirm Password</label>
                                <input type="password" name="confirm_password" required minlength="6" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all font-mono" placeholder="Re-type your password">
                            </div>
                        </div>

                        <button type="submit" class="mt-6 w-full px-4 py-2.5 bg-gradient-to-b from-[#003882] to-[#00205b] hover:from-[#00205b] hover:to-[#001233] text-white text-sm font-bold uppercase tracking-widest rounded-lg transition-all shadow-[0_4px_14px_0_rgba(0,32,91,0.39)] hover:shadow-[0_6px_20px_rgba(0,32,91,0.23)] hover:-translate-y-0.5 flex items-center justify-center gap-2">
                            Activate Account
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
    </div>
</body>
</html>