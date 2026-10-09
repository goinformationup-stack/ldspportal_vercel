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
$allowed_roles = ['admin'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../index.php");
    exit();
}

$success_msg = "";
$error_msg = "";

// Ensure bank_accounts table exists
$conn->query("CREATE TABLE IF NOT EXISTS bank_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_name VARCHAR(100) NOT NULL,
    account_name VARCHAR(100) NOT NULL,
    account_number VARCHAR(50) NOT NULL,
    payment_link VARCHAR(255) NULL
)");

// Failsafe: Add payment_link column if the table was created under the old schema
try { $conn->query("ALTER TABLE bank_accounts ADD COLUMN payment_link VARCHAR(255) NULL"); } catch (Exception $e) {}

// =========================================================
// HTML TEMPLATE FUNCTION (USED BY PHP & FETCH API)
// =========================================================
function renderBankCard($bank) {
    ob_start();
    $is_link_only = ($bank['account_name'] === 'N/A' && $bank['account_number'] === 'N/A');
    ?>
    <div class="glossy-panel flex flex-col group hover:-translate-y-1 transition-all duration-300 shadow-[0_10px_30px_rgba(0,32,91,0.05)] hover:shadow-[0_15px_35px_rgba(0,32,91,0.15)] z-20">
        <!-- FIXED: Bypassing Tailwind compiler with an inline style for solid indigo -->
        <div class="glossy-panel-header" style="background-color: #00205b;"></div>
        
        <div class="px-5 py-4 border-b border-slate-200/60 bg-white/50 flex justify-between items-center relative z-20">
            <div class="font-black text-[#00205b] uppercase tracking-[0.1em] text-xs flex items-center gap-2 drop-shadow-sm">
                <div class="p-1.5 rounded-md bg-[#00205b]/10"><svg class="w-4 h-4 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg></div> 
                <?= htmlspecialchars($bank['bank_name']) ?>
            </div>
            <div class="flex items-center gap-2 opacity-90 group-hover:opacity-100 transition-opacity">
                <button type="button" onclick="openEditModal(<?= $bank['id'] ?>, '<?= addslashes(htmlspecialchars($bank['bank_name'])) ?>', '<?= addslashes(htmlspecialchars($bank['account_name'])) ?>', '<?= addslashes(htmlspecialchars($bank['account_number'])) ?>', '<?= addslashes(htmlspecialchars($bank['payment_link'] ?? '')) ?>')" class="text-blue-600 hover:text-white bg-white hover:bg-blue-600 border border-slate-200 hover:border-blue-600 p-2 rounded-lg inline-flex items-center transition-colors shadow-sm" title="Edit Bank">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                </button>
                <a href="?delete_id=<?= $bank['id'] ?>" onclick="return confirm('Are you sure you want to completely remove this bank account?');" class="text-rose-500 hover:text-white bg-white hover:bg-rose-600 border border-slate-200 hover:border-rose-600 p-2 rounded-lg inline-flex items-center transition-colors shadow-sm" title="Delete Bank">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                </a>
            </div>
        </div>
        
        <div class="p-6 flex-1 flex flex-col justify-between bg-white/30 relative z-20">
            <div class="glass-section p-4 border border-slate-200/60 shadow-[inset_0_1px_4px_rgba(0,0,0,0.01)]">
                <?php if (!$is_link_only): ?>
                    <div class="mb-4">
                        <div class="text-[9px] text-slate-400 uppercase font-bold tracking-widest drop-shadow-sm mb-1">Account Name</div>
                        <div class="text-sm font-bold text-slate-800 drop-shadow-sm leading-tight"><?= htmlspecialchars($bank['account_name']) ?></div>
                    </div>
                    <div>
                        <div class="text-[9px] text-slate-400 uppercase font-bold tracking-widest drop-shadow-sm mb-1">Account Number</div>
                        <div class="text-lg font-mono font-black text-[#00205b] drop-shadow-sm tracking-wider"><?= htmlspecialchars($bank['account_number']) ?></div>
                    </div>
                <?php else: ?>
                    <div class="py-2">
                        <div class="text-[9px] text-slate-400 uppercase font-bold tracking-widest drop-shadow-sm mb-1">Payment Method Type</div>
                        <div class="text-sm font-bold text-[#00205b] drop-shadow-sm leading-tight">Direct Payment Link Only</div>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($bank['payment_link'])): ?>
                <div class="mt-5">
                    <a href="<?= htmlspecialchars($bank['payment_link']) ?>" target="_blank" class="text-[10px] uppercase tracking-[0.15em] font-black text-indigo-700 bg-indigo-50/80 hover:bg-indigo-600 hover:text-white border border-indigo-200 hover:border-indigo-600 px-4 py-2.5 rounded-xl inline-flex items-center justify-center gap-2 transition-all w-full shadow-sm hover:shadow-[0_4px_10px_-2px_rgba(79,70,229,0.4)]">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
                        Open Payment Link
                    </a>
                </div>
            <?php endif; ?>
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
    $html = '';
    
    $banks_q = $conn->query("SELECT * FROM bank_accounts ORDER BY id ASC");
    if ($banks_q && $banks_q->num_rows > 0) {
        while ($row = $banks_q->fetch_assoc()) {
            $html .= renderBankCard($row);
        }
    } else {
        $html = '
        <div class="md:col-span-2 glossy-panel p-12 text-center border-dashed border-2 border-slate-300 z-20">
            <svg class="w-14 h-14 mx-auto text-slate-300 mb-4 drop-shadow-sm" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            <p class="text-slate-500 text-sm font-black uppercase tracking-[0.15em] drop-shadow-sm">No Bank Accounts Configured</p>
            <p class="text-xs text-slate-400 mt-2 font-medium">Students will not see any payment channels until you add one.</p>
        </div>';
    }

    echo json_encode(['html' => $html]);
    exit();
}

// ---------------------------------------------------------
// CRUD LOGIC
// ---------------------------------------------------------

// ADD NEW BANK
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_bank'])) {
    $channel_type = $_POST['channel_type'] ?? 'standard';
    $bank_name = $conn->real_escape_string($_POST['bank_name']);
    $payment_link = !empty($_POST['payment_link']) ? "'" . $conn->real_escape_string($_POST['payment_link']) . "'" : "NULL";

    if ($channel_type === 'link') {
        $account_name = 'N/A';
        $account_number = 'N/A';
    } else {
        $account_name = $conn->real_escape_string($_POST['account_name'] ?? '');
        $account_number = $conn->real_escape_string($_POST['account_number'] ?? '');
    }

    $sql = "INSERT INTO bank_accounts (bank_name, account_name, account_number, payment_link) VALUES ('$bank_name', '$account_name', '$account_number', $payment_link)";
    if ($conn->query($sql)) {
        $success_msg = "Bank account successfully added.";
    } else {
        $error_msg = "Error adding bank account: " . $conn->error;
    }
}

// EDIT EXISTING BANK
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_bank'])) {
    $id = (int)$_POST['edit_id'];
    $channel_type = $_POST['edit_channel_type'] ?? 'standard';
    $bank_name = $conn->real_escape_string($_POST['bank_name']);
    $payment_link = !empty($_POST['payment_link']) ? "'" . $conn->real_escape_string($_POST['payment_link']) . "'" : "NULL";

    if ($channel_type === 'link') {
        $account_name = 'N/A';
        $account_number = 'N/A';
    } else {
        $account_name = $conn->real_escape_string($_POST['account_name'] ?? '');
        $account_number = $conn->real_escape_string($_POST['account_number'] ?? '');
    }

    $sql = "UPDATE bank_accounts SET bank_name = '$bank_name', account_name = '$account_name', account_number = '$account_number', payment_link = $payment_link WHERE id = $id";
    if ($conn->query($sql)) {
        $success_msg = "Bank account successfully updated.";
    } else {
        $error_msg = "Error updating bank account: " . $conn->error;
    }
}

// DELETE BANK
if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    if ($conn->query("DELETE FROM bank_accounts WHERE id = $id")) {
        $success_msg = "Bank account removed successfully.";
    } else {
        $error_msg = "Error deleting bank account: " . $conn->error;
    }
}

// Fetch Banks for initial render
$banks_q = $conn->query("SELECT * FROM bank_accounts ORDER BY id ASC");
$banks = [];
if ($banks_q && $banks_q->num_rows > 0) {
    while ($row = $banks_q->fetch_assoc()) {
        $banks[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Bank Accounts - LDSP Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Style.css?v=<?php echo time(); ?>">
</head>
<body class="flex h-screen overflow-hidden antialiased">
    <div class="ambient-orb-1"></div>
    <div class="ambient-orb-2"></div>
    <div id="toastContainer" class="fixed top-5 right-5 z-[100] flex flex-col gap-3 pointer-events-none"></div>

    <!-- MAIN SIDEBAR -->
    <?php include 'sidebar.php'; ?>

    <main class="flex-1 min-w-0 overflow-y-auto h-full pt-20 md:pt-0 relative custom-scrollbar z-10">
        <div class="p-6 md:p-10 lg:p-12 max-w-[1400px] mx-auto relative z-10">
            
            <header class="mb-10 animate-up">
                <div class="flex items-center gap-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1 drop-shadow-sm">
                    <span>Administrator</span> <span class="text-slate-400">/</span> <span class="text-[#00205b]">Financial Settings</span>
                </div>
                <h1 class="text-3xl md:text-4xl font-black text-[#00205b] tracking-tight font-academic uppercase drop-shadow-sm">Manage Authorized Banks</h1>
            </header>

            <?php if ($success_msg): ?>
                <div class="bg-emerald-50/90 backdrop-blur-sm border border-emerald-200 text-emerald-800 p-4 rounded-xl mb-8 flex items-center gap-3 shadow-sm animate-up delay-1">
                    <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span class="text-sm font-semibold"><?= htmlspecialchars($success_msg) ?></span>
                </div>
            <?php elseif ($error_msg): ?>
                <div class="bg-rose-50/90 backdrop-blur-sm border border-rose-200 text-rose-800 p-4 rounded-xl mb-8 flex items-center gap-3 shadow-sm animate-up delay-1">
                    <svg class="w-5 h-5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                    <span class="text-sm font-semibold"><?= htmlspecialchars($error_msg) ?></span>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <!-- LEFT COLUMN: Register Bank -->
                <div class="lg:col-span-1">
                    <div class="glossy-panel p-6 shadow-[0_10px_30px_rgba(0,32,91,0.05)] sticky top-8 z-20 animate-up delay-1">
                        <!-- FIXED: Bypassing Tailwind compiler for the form panel too -->
                        <div class="glossy-panel-header" style="background-color: #00205b;"></div>
                        <h3 class="font-black text-[#00205b] text-sm uppercase tracking-[0.15em] border-b border-slate-200/60 pb-4 mb-6 drop-shadow-sm flex items-center gap-2">
                            <div class="p-1.5 bg-[#00205b]/10 rounded-md"><svg class="w-4 h-4 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg></div>
                            Add New Bank
                        </h3>
                        <form method="POST" class="space-y-5 relative z-20">
                            
                            <div class="mb-2">
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2 tracking-widest drop-shadow-sm">Channel Type</label>
                                <div class="flex flex-col gap-3">
                                    <label class="flex items-center gap-2 text-xs text-[#00205b] font-bold cursor-pointer">
                                        <input type="radio" name="channel_type" value="standard" checked onchange="toggleAddFields(this.value)" class="accent-[#00205b] w-4 h-4">
                                        Standard Bank/E-Wallet
                                    </label>
                                    <label class="flex items-center gap-2 text-xs text-[#00205b] font-bold cursor-pointer">
                                        <input type="radio" name="channel_type" value="link" onchange="toggleAddFields(this.value)" class="accent-[#00205b] w-4 h-4">
                                        Payment Link Only
                                    </label>
                                </div>
                            </div>

                            <div>
                                <label id="add_bank_name_label" class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-widest drop-shadow-sm">Bank Name</label>
                                <input type="text" name="bank_name" required placeholder="e.g. Land Bank, BPI, GCash" class="input-glossy-smooth font-semibold text-[#00205b]">
                            </div>
                            
                            <div id="add_standard_fields" class="space-y-5">
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-widest drop-shadow-sm">Account Name</label>
                                    <input type="text" name="account_name" id="add_account_name" required placeholder="e.g. LYCEUM DE SAN PABLO CITY INC" class="input-glossy-smooth font-semibold">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-widest drop-shadow-sm">Account Number / Mobile No.</label>
                                    <input type="text" name="account_number" id="add_account_number" required placeholder="e.g. 0123-4567-89" class="input-glossy-smooth font-mono font-bold text-[#00205b]">
                                </div>
                            </div>

                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-widest drop-shadow-sm">Payment Link <span id="add_payment_link_opt">(Optional)</span></label>
                                <input type="url" name="payment_link" id="add_payment_link" placeholder="https://..." class="input-glossy-smooth text-[#00205b]">
                                <p class="text-[9px] text-slate-400 mt-1.5 font-medium drop-shadow-sm leading-relaxed">Add a direct URL for online payment portals (e.g. Maya, GCash Express, or Bank Transfer pages).</p>
                            </div>
                            
                            <div class="pt-5 border-t border-slate-200/60 mt-4">
                                <button type="submit" name="add_bank" class="w-full btn-glossy-animated py-3.5 rounded-xl text-[11px] uppercase tracking-widest flex items-center justify-center gap-2">
                                    Add Bank Account
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- RIGHT COLUMN: Bank Cards -->
                <div class="lg:col-span-2">
                    <div id="bank_cards_container" class="grid grid-cols-1 md:grid-cols-2 gap-6 animate-up delay-2">
                        <?php if (count($banks) > 0): ?>
                            <?php foreach ($banks as $index => $bank): ?>
                                <?= renderBankCard($bank) ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="md:col-span-2 glossy-panel p-12 text-center border-dashed border-2 border-slate-300 z-20">
                                <svg class="w-14 h-14 mx-auto text-slate-300 mb-4 drop-shadow-sm" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                                <p class="text-slate-500 text-sm font-black uppercase tracking-[0.15em] drop-shadow-sm">No Bank Accounts Configured</p>
                                <p class="text-xs text-slate-400 mt-2 font-medium">Students will not see any payment channels until you add one.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <!-- EDIT MODAL -->
    <div id="editModal" class="fixed inset-0 hidden flex items-center justify-center p-4 modal-overlay bg-slate-900/60 backdrop-blur-sm z-[100]">
        <div class="glossy-panel w-full max-w-md modal-content border-t-4 border-t-[#00205b]">
            <div class="px-6 py-5 border-b border-slate-200 flex justify-between items-center bg-white/90 relative z-20">
                <h3 class="font-black text-[#00205b] uppercase tracking-[0.1em] flex items-center gap-2 drop-shadow-sm">
                    <div class="p-1.5 bg-[#00205b]/10 rounded-md"><svg class="w-4 h-4 text-[#00205b]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg></div>
                    Edit Bank Account
                </h3>
                <button type="button" onclick="closeModal('editModal')" class="text-slate-400 hover:text-rose-500 transition-colors bg-slate-50 hover:bg-rose-50 p-2 rounded-xl focus:outline-none">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
            <div class="p-8 bg-slate-50/90 relative z-20">
                <form method="POST" class="space-y-5">
                    <input type="hidden" name="edit_id" id="edit_id">
                    
                    <div class="mb-2">
                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2 tracking-widest drop-shadow-sm">Channel Type</label>
                        <div class="flex gap-4">
                            <label class="flex items-center gap-2 text-xs text-[#00205b] font-bold cursor-pointer">
                                <input type="radio" name="edit_channel_type" id="edit_type_std" value="standard" onchange="toggleEditFields(this.value)" class="accent-[#00205b] w-4 h-4">
                                Standard
                            </label>
                            <label class="flex items-center gap-2 text-xs text-[#00205b] font-bold cursor-pointer">
                                <input type="radio" name="edit_channel_type" id="edit_type_link" value="link" onchange="toggleEditFields(this.value)" class="accent-[#00205b] w-4 h-4">
                                Link Only
                            </label>
                        </div>
                    </div>

                    <div>
                        <label id="edit_bank_name_label" class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-widest drop-shadow-sm">Bank Name</label>
                        <input type="text" name="bank_name" id="edit_bank_name" required class="input-glossy-smooth font-semibold text-[#00205b]">
                    </div>
                    
                    <div id="edit_standard_fields" class="space-y-5">
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-widest drop-shadow-sm">Account Name</label>
                            <input type="text" name="account_name" id="edit_account_name" required class="input-glossy-smooth font-semibold">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-widest drop-shadow-sm">Account Number / Mobile No.</label>
                            <input type="text" name="account_number" id="edit_account_number" required class="input-glossy-smooth font-mono font-bold text-[#00205b]">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-widest drop-shadow-sm">Payment Link <span id="edit_payment_link_opt">(Optional)</span></label>
                        <input type="url" name="payment_link" id="edit_payment_link" placeholder="https://..." class="input-glossy-smooth text-[#00205b]">
                    </div>
                    
                    <div class="pt-6 mt-8 border-t border-slate-200/80 flex gap-4">
                        <button type="button" onclick="closeModal('editModal')" class="flex-1 px-6 py-3 rounded-xl text-[11px] font-bold uppercase tracking-widest text-slate-500 bg-white border border-slate-300 hover:bg-slate-50 transition-colors shadow-sm">Cancel</button>
                        <button type="submit" name="edit_bank" class="flex-1 btn-glossy-animated !px-6 !py-3 !text-[11px] !tracking-widest flex items-center justify-center gap-2">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- EXTERNAL JS -->
    <script src="admin.js?v=<?php echo time(); ?>"></script>
    <script src="../sidebar/sidebar.js?v=<?= time() ?>"></script>
    <script src="manage_banks.js?v=<?php echo time(); ?>"></script>
</body>
</html>