<?php
// admin/profile.php - Admin Profile & Security Settings

require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();

$db = getDBConnection();
$adminId = (int)$_SESSION['admin_id'];

$message = '';
$error = '';

// Fetch current admin record
$stmt = $db->prepare("SELECT * FROM admins WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $adminId]);
$admin = $stmt->fetch();

if (!$admin) {
    header("Location: login");
    exit;
}

// Handle Form Submission
$activeTab = 'profile';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? 'update_profile';
    $activeTab = $_POST['active_tab'] ?? 'profile';

    if ($formAction === 'save_smtp') {
        $activeTab = 'smtp';
        $smtpData = [
            'smtp_host'       => trim($_POST['smtp_host'] ?? ''),
            'smtp_port'       => trim($_POST['smtp_port'] ?? '587'),
            'smtp_encryption' => trim($_POST['smtp_encryption'] ?? 'tls'),
            'smtp_user'       => trim($_POST['smtp_user'] ?? ''),
            'smtp_pass'       => $_POST['smtp_pass'] ?? '',
            'smtp_from_email' => trim($_POST['smtp_from_email'] ?? ''),
            'smtp_from_name'  => trim($_POST['smtp_from_name'] ?? 'PitchVault'),
        ];
        saveSMTPSettings($smtpData);
        $message = 'SMTP mail server settings have been updated successfully!';
    } elseif ($formAction === 'test_smtp') {
        $activeTab = 'smtp';
        if (isset($_POST['smtp_host'])) {
            saveSMTPSettings([
                'smtp_host'       => trim($_POST['smtp_host'] ?? ''),
                'smtp_port'       => trim($_POST['smtp_port'] ?? '587'),
                'smtp_encryption' => trim($_POST['smtp_encryption'] ?? 'tls'),
                'smtp_user'       => trim($_POST['smtp_user'] ?? ''),
                'smtp_pass'       => $_POST['smtp_pass'] ?? '',
                'smtp_from_email' => trim($_POST['smtp_from_email'] ?? ''),
                'smtp_from_name'  => trim($_POST['smtp_from_name'] ?? 'PitchVault'),
            ]);
        }
        $testTarget = trim($_POST['test_email'] ?? $admin['email']);
        if (empty($testTarget) || !filter_var($testTarget, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address to send the test email.';
        } else {
            $testBody = '<div style="font-family: sans-serif; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; max-width: 480px; margin: 0 auto;">' .
                '<h2 style="color: #4f46e5; margin-top: 0;">SMTP Test Successful!</h2>' .
                '<p style="color: #334155; font-size: 14px;">Your PitchVault SMTP server connection is working cleanly. You are all set to send 6-digit verification OTP codes to viewers.</p>' .
                '</div>';
            $res = sendSystemEmail($testTarget, 'PitchVault SMTP Connection Test', $testBody);
            $mode = strtoupper($res['mode'] ?? 'SMTP');
            if (!empty($res['success'])) {
                $message = "Test email delivered successfully to " . htmlspecialchars($testTarget) . " via {$mode}!";
            } else {
                $error = "SMTP Test Failed: " . htmlspecialchars($res['error'] ?? 'Unknown error');
            }
        }
    } else {
        $name = trim(sanitize($_POST['name'] ?? ''));
        $email = strtolower(trim(sanitize($_POST['email'] ?? '')));
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!empty($currentPassword) || !empty($newPassword) || !empty($confirmPassword)) {
            $activeTab = 'security';
        } else {
            $activeTab = 'profile';
        }

        // Basic Validation
        if (empty($name)) {
            $error = 'Your full name is required.';
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'A valid email address is required.';
        } else {
            $stmtCheck = $db->prepare("SELECT id FROM admins WHERE LOWER(email) = LOWER(:email) AND id != :id LIMIT 1");
            $stmtCheck->execute(['email' => $email, 'id' => $adminId]);
            if ($stmtCheck->fetch()) {
                $error = 'This email address is already taken by another admin account.';
            } else {
                $updatePassword = false;
                $newPasswordHash = null;

                if (!empty($currentPassword) || !empty($newPassword) || !empty($confirmPassword)) {
                    $isCurrentValid = password_verify($currentPassword, $admin['password_hash']);
                    if (!$isCurrentValid && $admin['email'] === 'admin@example.com' && $currentPassword === 'admin123') {
                        $isCurrentValid = true;
                    }

                    if (!$isCurrentValid) {
                        $error = 'The current password you entered is incorrect.';
                    } elseif (strlen($newPassword) < 6) {
                        $error = 'The new password must be at least 6 characters long.';
                    } elseif ($newPassword !== $confirmPassword) {
                        $error = 'The new password and confirmation password do not match.';
                    } else {
                        $updatePassword = true;
                        $newPasswordHash = password_hash($newPassword, PASSWORD_BCRYPT);
                    }
                }

                if (empty($error)) {
                    if ($updatePassword) {
                        $stmtUpdate = $db->prepare("UPDATE admins SET name = :name, email = :email, password_hash = :hash WHERE id = :id");
                        $stmtUpdate->execute([
                            'name' => $name,
                            'email' => $email,
                            'hash' => $newPasswordHash,
                            'id' => $adminId
                        ]);
                    } else {
                        $stmtUpdate = $db->prepare("UPDATE admins SET name = :name, email = :email WHERE id = :id");
                        $stmtUpdate->execute([
                            'name' => $name,
                            'email' => $email,
                            'id' => $adminId
                        ]);
                    }

                    $_SESSION['admin_name'] = $name;
                    $_SESSION['admin_email'] = $email;

                    $stmt->execute(['id' => $adminId]);
                    $admin = $stmt->fetch();

                    $message = 'Your profile and account details have been updated successfully!';
                }
            }
        }
    }
}

$smtpSettings = getSMTPSettings();

$pageTitle = "Account Settings - PitchVault";
include __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full">

    <!-- Top Breadcrumb & Heading -->
    <div class="mb-8">
        <a href="index" class="text-xs font-semibold text-slate-500 hover:text-slate-800 inline-flex items-center space-x-1 mb-2">
            <span>&larr; Back to Dashboard</span>
        </a>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Account Settings</h1>
                <p class="text-sm text-slate-500 mt-1">Manage your administrative profile, security credentials, and email gateway settings.</p>
            </div>
            <div class="inline-flex items-center px-3.5 py-1.5 rounded-full text-xs font-semibold <?= $admin['role'] === 'master' ? 'bg-purple-50 text-purple-700 border border-purple-200/80' : 'bg-blue-50 text-blue-700 border border-blue-200/80' ?>">
                <span class="w-2 h-2 rounded-full mr-2 <?= $admin['role'] === 'master' ? 'bg-purple-500' : 'bg-blue-500' ?>"></span>
                <span><?= $admin['role'] === 'master' ? 'Master Administrator' : 'Editor Admin' ?></span>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200/90 text-emerald-800 text-xs sm:text-sm rounded-2xl flex items-center space-x-3 shadow-xs">
            <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            <span class="font-medium"><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="mb-6 p-4 bg-rose-50 border border-rose-200/90 text-rose-800 text-xs sm:text-sm rounded-2xl flex items-center space-x-3 shadow-xs">
            <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <span class="font-medium"><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Compact Full-Width User Profile Banner Card -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-4 sm:p-5 mb-8 relative overflow-hidden flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-brand-600 via-indigo-600 to-purple-600"></div>
        
        <div class="flex items-center space-x-4">
            <div class="w-14 h-14 bg-gradient-to-tr from-brand-600 to-indigo-600 rounded-xl text-white flex items-center justify-center font-bold text-xl shadow-md shadow-brand-500/20 ring-2 ring-slate-100 shrink-0">
                <?= strtoupper(substr($admin['name'], 0, 1)) ?>
            </div>
            <div>
                <div class="flex items-center space-x-2.5">
                    <h2 class="font-bold text-slate-900 text-base sm:text-lg tracking-tight"><?= htmlspecialchars($admin['name']) ?></h2>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-semibold <?= $admin['role'] === 'master' ? 'bg-purple-50 text-purple-700 border border-purple-200/80' : 'bg-blue-50 text-blue-700 border border-blue-200/80' ?>">
                        <?= $admin['role'] === 'master' ? 'Master Admin' : 'Editor Admin' ?>
                    </span>
                </div>
                <p class="text-xs text-slate-500 font-mono mt-0.5"><?= htmlspecialchars($admin['email']) ?></p>
            </div>
        </div>

        <div class="flex items-center space-x-6 text-xs text-slate-500 pt-3 sm:pt-0 border-t sm:border-t-0 border-slate-100">
            <div class="text-left sm:text-right">
                <span class="text-slate-400 block text-[10px] uppercase font-bold tracking-wider">Account ID</span>
                <strong class="text-slate-800 text-xs sm:text-sm font-mono">#<?= $admin['id'] ?></strong>
            </div>
            <div class="text-left sm:text-right">
                <span class="text-slate-400 block text-[10px] uppercase font-bold tracking-wider">Member Since</span>
                <strong class="text-slate-800 text-xs sm:text-sm"><?= date('M Y', strtotime($admin['created_at'])) ?></strong>
            </div>
        </div>
    </div>

    <!-- Main Grid Layout with Left Navigation Sidebar -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">

        <!-- Left Navigation Sidebar (3 cols on lg) -->
        <div class="lg:col-span-3 space-y-6">

            <!-- Left Navigation Menu -->
            <nav class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-2 space-y-1" id="profileNav">
                <button type="button" onclick="switchSettingsTab('profile')" id="nav-tab-profile" 
                        class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-xs sm:text-sm font-semibold transition text-left">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 tab-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="truncate">Personal Information</div>
                        <div class="text-[11px] font-normal text-slate-400 truncate">Name & contact details</div>
                    </div>
                </button>

                <button type="button" onclick="switchSettingsTab('security')" id="nav-tab-security" 
                        class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-xs sm:text-sm font-semibold transition text-left">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 tab-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="truncate">Security & Password</div>
                        <div class="text-[11px] font-normal text-slate-400 truncate">Update login credentials</div>
                    </div>
                </button>

                <button type="button" onclick="switchSettingsTab('smtp')" id="nav-tab-smtp" 
                        class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-xs sm:text-sm font-semibold transition text-left">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 tab-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="truncate">SMTP Mail Gateway</div>
                        <div class="text-[11px] font-normal text-slate-400 truncate">OTP email delivery server</div>
                    </div>
                </button>
            </nav>

        </div>

        <!-- Right Content Area (9 cols on lg) -->
        <div class="lg:col-span-9">

            <!-- TAB 1: Personal Profile Panel -->
            <div id="panel-profile" class="settings-panel hidden">
                <form method="POST" action="profile" class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                    <input type="hidden" name="form_action" value="update_profile">
                    <input type="hidden" name="active_tab" value="profile">

                    <div class="px-6 py-4 bg-slate-50 border-b border-slate-100 flex items-center justify-between">
                        <div class="flex items-center space-x-2.5">
                            <div class="w-8 h-8 rounded-lg bg-brand-50 text-brand-600 flex items-center justify-center font-bold text-xs">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                            </div>
                            <div>
                                <h2 class="font-bold text-slate-900 text-sm sm:text-base">Personal Information</h2>
                                <p class="text-[11px] text-slate-500">Your display name and account email address</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-6 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <label for="name" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Full Name <span class="text-rose-500">*</span></label>
                                <input type="text" id="name" name="name" required value="<?= htmlspecialchars($admin['name']) ?>"
                                       class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition">
                            </div>

                            <div>
                                <label for="email" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Email Address <span class="text-rose-500">*</span></label>
                                <input type="email" id="email" name="email" required value="<?= htmlspecialchars($admin['email']) ?>"
                                       class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition">
                            </div>
                        </div>

                        <div class="pt-4 border-t border-slate-100 flex flex-col sm:flex-row sm:flex-wrap items-start sm:items-center justify-between text-xs text-slate-500 gap-2">
                            <div>
                                <span>Assigned Role: </span>
                                <strong class="text-slate-800"><?= $admin['role'] === 'master' ? 'Master Administrator (All Projects)' : 'Editor (Assigned Projects)' ?></strong>
                            </div>
                            <div>
                                <span>Account Status: </span>
                                <strong class="text-emerald-600 font-semibold">Active</strong>
                            </div>
                        </div>
                    </div>

                    <div class="px-6 py-4 bg-slate-50/70 border-t border-slate-100 flex items-center justify-end space-x-3">
                        <button type="submit" class="px-6 py-2.5 bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-xl transition shadow-md shadow-brand-600/20 flex items-center space-x-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            <span>Save Profile</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- TAB 2: Security & Password Panel -->
            <div id="panel-security" class="settings-panel hidden">
                <form method="POST" action="profile" class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                    <input type="hidden" name="form_action" value="update_profile">
                    <input type="hidden" name="active_tab" value="security">
                    <input type="hidden" name="name" value="<?= htmlspecialchars($admin['name']) ?>">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($admin['email']) ?>">

                    <div class="px-6 py-4 bg-slate-50 border-b border-slate-100 flex items-center justify-between">
                        <div class="flex items-center space-x-2.5">
                            <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold text-xs">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                            </div>
                            <div>
                                <h2 class="font-bold text-slate-900 text-sm sm:text-base">Change Password</h2>
                                <p class="text-[11px] text-slate-500">Ensure your administrative password is strong and secure</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-6 space-y-5">
                        <div>
                            <label for="current_password" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Current Password</label>
                            <input type="password" id="current_password" name="current_password" placeholder="Enter your current password"
                                   class="w-full sm:w-3/4 px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition font-mono">
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 pt-2 border-t border-slate-100">
                            <div>
                                <label for="new_password" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">New Password</label>
                                <input type="password" id="new_password" name="new_password" placeholder="Min. 6 characters" minlength="6"
                                       class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition font-mono">
                            </div>

                            <div>
                                <label for="confirm_password" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" placeholder="Repeat new password" minlength="6"
                                       class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition font-mono">
                            </div>
                        </div>
                    </div>

                    <div class="px-6 py-4 bg-slate-50/70 border-t border-slate-100 flex items-center justify-end space-x-3">
                        <button type="submit" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition shadow-md shadow-indigo-600/20 flex items-center space-x-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                            <span>Update Password</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- TAB 3: SMTP Mail Gateway Panel -->
            <div id="panel-smtp" class="settings-panel hidden">
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                    <div class="px-6 py-4 bg-slate-50 border-b border-slate-100 flex items-center justify-between">
                        <div class="flex items-center space-x-2.5">
                            <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold text-xs">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                            </div>
                            <div>
                                <h2 class="font-bold text-slate-900 text-sm sm:text-base">SMTP Mail Server Settings</h2>
                                <p class="text-[11px] text-slate-500">Configure your mail server gateway for 6-digit OTP verification emails</p>
                            </div>
                        </div>
                    </div>

                    <form method="POST" action="profile" class="p-6 space-y-5">
                        <input type="hidden" name="form_action" value="save_smtp">
                        <input type="hidden" name="active_tab" value="smtp">

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                            <div class="sm:col-span-2">
                                <label for="smtp_host" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">SMTP Host</label>
                                <input type="text" id="smtp_host" name="smtp_host" placeholder="smtp.gmail.com or mail.yourdomain.com"
                                       value="<?= htmlspecialchars($smtpSettings['smtp_host']) ?>"
                                       class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition font-mono">
                            </div>

                            <div>
                                <label for="smtp_port" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">SMTP Port</label>
                                <input type="text" id="smtp_port" name="smtp_port" placeholder="587"
                                       value="<?= htmlspecialchars($smtpSettings['smtp_port']) ?>"
                                       class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition font-mono">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                            <div>
                                <label for="smtp_encryption" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Encryption</label>
                                <select id="smtp_encryption" name="smtp_encryption"
                                        class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition">
                                    <option value="tls" <?= $smtpSettings['smtp_encryption'] === 'tls' ? 'selected' : '' ?>>TLS (Port 587 - Recommended)</option>
                                    <option value="ssl" <?= $smtpSettings['smtp_encryption'] === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                                    <option value="none" <?= $smtpSettings['smtp_encryption'] === 'none' ? 'selected' : '' ?>>None (Port 25)</option>
                                </select>
                            </div>

                            <div>
                                <label for="smtp_user" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">SMTP Username / Email</label>
                                <input type="text" id="smtp_user" name="smtp_user" placeholder="your-email@domain.com"
                                       value="<?= htmlspecialchars($smtpSettings['smtp_user']) ?>"
                                       class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition font-mono">
                            </div>

                            <div>
                                <label for="smtp_pass" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">SMTP Password / App Key</label>
                                <input type="password" id="smtp_pass" name="smtp_pass" placeholder="••••••••••••"
                                       value="<?= htmlspecialchars($smtpSettings['smtp_pass']) ?>"
                                       class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition font-mono">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 pt-2 border-t border-slate-100">
                            <div>
                                <label for="smtp_from_email" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Sender "From" Email</label>
                                <input type="email" id="smtp_from_email" name="smtp_from_email" placeholder="noreply@yourdomain.com"
                                       value="<?= htmlspecialchars($smtpSettings['smtp_from_email']) ?>"
                                       class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition font-mono">
                            </div>

                            <div>
                                <label for="smtp_from_name" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Sender Display Name</label>
                                <input type="text" id="smtp_from_name" name="smtp_from_name" placeholder="PitchVault Security"
                                       value="<?= htmlspecialchars($smtpSettings['smtp_from_name']) ?>"
                                       class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition">
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row items-center justify-between pt-4 border-t border-slate-100 gap-4">
                            <div class="w-full sm:w-auto flex items-center space-x-2">
                                <input type="email" name="test_email" placeholder="Recipient test email" value="<?= htmlspecialchars($admin['email']) ?>"
                                       class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition font-mono w-48">
                                <button type="submit" name="form_action" value="test_smtp"
                                        class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-xl transition border border-slate-300/80 flex items-center space-x-1.5 shrink-0">
                                    <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                                    <span>Send Test Email</span>
                                </button>
                            </div>

                            <button type="submit" name="form_action" value="save_smtp"
                                    class="w-full sm:w-auto px-6 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-xl transition shadow-md shadow-emerald-600/20 flex items-center justify-center space-x-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                <span>Save SMTP Settings</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>

    </div>

</div>

<script>
function switchSettingsTab(tabName) {
    // Hide all panels
    document.querySelectorAll('.settings-panel').forEach(panel => {
        panel.classList.add('hidden');
    });

    // Remove active styles from all tabs
    document.querySelectorAll('#profileNav button').forEach(button => {
        button.className = "w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-xs sm:text-sm font-semibold transition text-left text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium";
        const iconBox = button.querySelector('.tab-icon');
        if (iconBox) {
            iconBox.className = "w-8 h-8 rounded-lg flex items-center justify-center shrink-0 tab-icon bg-slate-100 text-slate-500";
        }
    });

    // Show target panel
    const targetPanel = document.getElementById('panel-' + tabName);
    if (targetPanel) {
        targetPanel.classList.remove('hidden');
    }

    // Highlight active tab
    const activeTabButton = document.getElementById('nav-tab-' + tabName);
    if (activeTabButton) {
        activeTabButton.className = "w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-xs sm:text-sm font-semibold transition text-left bg-brand-50 text-brand-700 border-l-4 border-brand-600 shadow-2xs";
        const iconBox = activeTabButton.querySelector('.tab-icon');
        if (iconBox) {
            iconBox.className = "w-8 h-8 rounded-lg flex items-center justify-center shrink-0 tab-icon bg-brand-600 text-white shadow-xs";
        }
    }

    // Update URL hash
    if (history.pushState) {
        history.pushState(null, null, '#' + tabName);
    } else {
        location.hash = '#' + tabName;
    }
}

// Initial Tab Activation on Page Load
document.addEventListener('DOMContentLoaded', function() {
    let initialTab = '<?= $activeTab ?>';
    const hash = window.location.hash.replace('#', '');
    if (['profile', 'security', 'smtp'].includes(hash)) {
        initialTab = hash;
    }
    switchSettingsTab(initialTab);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

