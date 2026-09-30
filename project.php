<?php
// project.php - Public/Private Project View Page & Share Manager with Email Gate

require_once __DIR__ . '/includes/auth.php';

// Prevent browser caching so access changes take effect immediately
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$param = trim(sanitize($_GET['id'] ?? $_GET['key'] ?? $_GET['p'] ?? ''));
$db = getDBConnection();

// Fetch Project Details by access_key or numeric ID fallback
if (is_numeric($param)) {
    $stmt = $db->prepare("SELECT * FROM projects WHERE id = :id OR access_key = :key LIMIT 1");
    $stmt->execute(['id' => (int)$param, 'key' => $param]);
} else {
    $stmt = $db->prepare("SELECT * FROM projects WHERE access_key = :key LIMIT 1");
    $stmt->execute(['key' => $param]);
}
$project = $stmt->fetch();

if (!$project) {
    $pageTitle = "Project Not Found";
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="max-w-3xl mx-auto my-16 px-4 text-center">
        <div class="w-16 h-16 bg-rose-100 text-rose-600 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-md">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-slate-900">Project Not Found</h1>
        <p class="text-slate-600 mt-2">The requested project link is invalid, inactive, or may have been deleted.</p>
        <a href="<?= getBaseUrl() ?>/index" class="inline-block mt-6 px-5 py-2.5 bg-brand-600 text-white rounded-xl font-medium hover:bg-brand-700 transition shadow-md shadow-brand-600/20">Return to Portal Home</a>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$projectId = (int)$project['id'];
$projectAccessKey = !empty($project['access_key']) ? $project['access_key'] : $project['id'];
$isAdmin = isAdminLoggedIn();
$projectUrl = getBaseUrl() . "/project?id=" . urlencode($projectAccessKey);
$pageTitle = htmlspecialchars($project['title']) . " - Project Videos";

// Handle Email Verification & Access Request Gate
$gateError = '';
$gateSuccess = '';
$ungrantedEmail = '';
$otpError = '';
$otpSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'verify_email') {
        $submittedEmail = strtolower(trim($_POST['email'] ?? ''));
        if (empty($submittedEmail) || !filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
            $gateError = 'Please enter a valid email address.';
        } else {
            if ($isAdmin || hasProjectAccess($projectId, $submittedEmail) || isEmailVerifiedGlobal($submittedEmail)) {
                autoGrantProjectAccess($projectId, $submittedEmail);
                setViewerEmailForProject($projectId, $submittedEmail);
                header("Location: project?id=" . urlencode($projectAccessKey));
                exit;
            } else {
                // Generate 6-digit OTP for new unverified viewer email
                $genResult = generateProjectOTP($projectId, $submittedEmail, $project['title']);
                $_SESSION['pending_project_otp'][$projectId] = [
                    'email' => $submittedEmail,
                    'sent_at' => time()
                ];
                $otpSuccess = "A 6-digit verification code was sent to " . htmlspecialchars($submittedEmail) . ".";
            }
        }
    } elseif ($action === 'verify_otp') {
        $pendingEmail = $_SESSION['pending_project_otp'][$projectId]['email'] ?? '';
        $inputOtp = trim($_POST['otp_code'] ?? '');
        if (empty($pendingEmail)) {
            $otpError = 'Session expired. Please re-enter your email address.';
            unset($_SESSION['pending_project_otp'][$projectId]);
        } elseif (empty($inputOtp) || strlen($inputOtp) !== 6) {
            $otpError = 'Please enter a valid 6-digit verification code.';
        } else {
            if (verifyProjectOTP($projectId, $pendingEmail, $inputOtp)) {
                autoGrantProjectAccess($projectId, $pendingEmail);
                setViewerEmailForProject($projectId, $pendingEmail);
                unset($_SESSION['pending_project_otp'][$projectId]);
                header("Location: project?id=" . urlencode($projectAccessKey));
                exit;
            } else {
                $otpError = 'Invalid or expired 6-digit verification code. Please check your inbox or resend code.';
            }
        }
    } elseif ($action === 'resend_otp') {
        $pendingEmail = $_SESSION['pending_project_otp'][$projectId]['email'] ?? '';
        if (!empty($pendingEmail)) {
            generateProjectOTP($projectId, $pendingEmail, $project['title']);
            $_SESSION['pending_project_otp'][$projectId]['sent_at'] = time();
            $otpSuccess = "A new 6-digit verification code has been sent to " . htmlspecialchars($pendingEmail) . ".";
        } else {
            $gateError = 'Session expired. Please re-enter your email address.';
        }
    } elseif ($action === 'cancel_otp') {
        unset($_SESSION['pending_project_otp'][$projectId]);
        header("Location: project?id=" . urlencode($projectAccessKey));
        exit;
    } elseif ($action === 'request_access') {
        $submittedEmail = strtolower(trim($_POST['email'] ?? ''));
        if (!empty($submittedEmail) && filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
            submitAccessRequest($projectId, 0, $submittedEmail);
            $gateSuccess = "Access Request Sent! An admin or editor has been notified to approve access for '$submittedEmail'.";
            $ungrantedEmail = $submittedEmail;
        } else {
            $gateError = 'Please enter a valid email address to request access.';
        }
    }
}

$pendingProjectOtpSession = $_SESSION['pending_project_otp'][$projectId] ?? null;

// Handle Video Deletion by Admin/Editor
$videoMessage = '';
$videoError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_video') {
    requireAdminLogin();
    if (isSalesAdmin()) {
        $videoError = 'Access denied: Sales role cannot delete videos.';
    } else {
        $delVidId = (int)($_POST['video_id'] ?? 0);
        if ($delVidId > 0) {
            $checkStmt = $db->prepare("SELECT * FROM videos WHERE id = :vid AND project_id = :pid LIMIT 1");
            $checkStmt->execute(['vid' => $delVidId, 'pid' => $projectId]);
            $vRow = $checkStmt->fetch();

            if (!$vRow) {
                $videoError = 'Video not found in this project.';
            } elseif (!canAdminManageProject($projectId)) {
                $videoError = 'Access denied: You do not have permission to manage this project.';
            } else {
                if (!empty($vRow['video_filename'])) {
                    @unlink(__DIR__ . '/uploads/videos/' . $vRow['video_filename']);
                }
                if (!empty($vRow['thumbnail_filename'])) {
                    @unlink(__DIR__ . '/uploads/thumbnails/' . $vRow['thumbnail_filename']);
                }
                $delStmt = $db->prepare("DELETE FROM videos WHERE id = :vid");
                $delStmt->execute(['vid' => $delVidId]);
                $videoMessage = 'Video deleted successfully.';
            }
        }
    }
}

// Check viewer authorization status
$viewerEmail = getViewerEmailForProject($projectId);

// If non-admin viewer email is present, check if globally verified to auto-grant or invalidate if revoked
if (!$isAdmin && !empty($viewerEmail)) {
    if (isEmailVerifiedGlobal($viewerEmail)) {
        autoGrantProjectAccess($projectId, $viewerEmail);
    } elseif (!hasProjectAccess($projectId, $viewerEmail)) {
        unset($_SESSION['project_viewer_access'][$projectId]);
        if (isset($_SESSION['global_viewer_email']) && strtolower($_SESSION['global_viewer_email']) === strtolower($viewerEmail)) {
            unset($_SESSION['global_viewer_email']);
        }
        $viewerEmail = null;
    }
}

$isAuthorized = $isAdmin || (!empty($viewerEmail) && hasProjectAccess($projectId, $viewerEmail));

// Fetch Videos in this project
if ($isAdmin) {
    if (isSalesAdmin()) {
        $stmtV = $db->prepare("
            SELECT v.*, 
                   (SELECT COUNT(*) FROM video_access WHERE video_id = v.id AND granted_by_admin_id = :aid) as access_count,
                   (SELECT COUNT(*) FROM video_sessions WHERE video_id = v.id) as session_count
            FROM videos v 
            WHERE v.project_id = :pid 
            ORDER BY v.created_at DESC
        ");
        $stmtV->execute(['pid' => $projectId, 'aid' => $_SESSION['admin_id'] ?? 0]);
    } else {
        $stmtV = $db->prepare("
            SELECT v.*, 
                   (SELECT COUNT(*) FROM video_access WHERE video_id = v.id) as access_count,
                   (SELECT COUNT(*) FROM video_sessions WHERE video_id = v.id) as session_count
            FROM videos v 
            WHERE v.project_id = :pid 
            ORDER BY v.created_at DESC
        ");
        $stmtV->execute(['pid' => $projectId]);
    }
} else {
    $stmtV = $db->prepare("
        SELECT v.* 
        FROM videos v 
        WHERE v.project_id = :pid AND v.status = 'published' 
        ORDER BY v.created_at DESC
    ");
    $stmtV->execute(['pid' => $projectId]);
}
$videos = $stmtV->fetchAll();

// Fetch Project Access List for share modal with Admin Share Tracking
if (isSalesAdmin()) {
    $stmtAccess = $db->prepare("
        SELECT pa.email, pa.granted_at, pa.granted_by_admin_id, 
               COALESCE(a.name, 'Admin') as granted_by_name, 
               a.email as granted_by_email
        FROM project_access pa 
        LEFT JOIN admins a ON pa.granted_by_admin_id = a.id 
        WHERE pa.project_id = :pid AND pa.granted_by_admin_id = :aid
        ORDER BY pa.granted_at DESC
    ");
    $stmtAccess->execute(['pid' => $projectId, 'aid' => $_SESSION['admin_id'] ?? 0]);
} else {
    $stmtAccess = $db->prepare("
        SELECT pa.email, pa.granted_at, pa.granted_by_admin_id, 
               COALESCE(a.name, 'Admin') as granted_by_name, 
               a.email as granted_by_email
        FROM project_access pa 
        LEFT JOIN admins a ON pa.granted_by_admin_id = a.id 
        WHERE pa.project_id = :pid 
        ORDER BY pa.granted_at DESC
    ");
    $stmtAccess->execute(['pid' => $projectId]);
}
$accessList = $stmtAccess->fetchAll();

// Calculate aggregate stats
$totalDuration = array_sum(array_column($videos, 'duration'));

include __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-4 sm:py-8 w-full">

    <?php if (!$isAuthorized): ?>
        <!-- Access Gate Card -->
        <div class="max-w-md mx-auto bg-white rounded-3xl border border-slate-200/80 shadow-2xl overflow-hidden my-6 sm:my-12 relative">
            <!-- Glowing Ambient Accent -->
            <div class="absolute -top-24 -left-24 w-48 h-48 bg-brand-500/20 rounded-full blur-3xl pointer-events-none"></div>
            <div class="absolute -top-24 -right-24 w-48 h-48 bg-indigo-500/20 rounded-full blur-3xl pointer-events-none"></div>

            <?php if (!empty($pendingProjectOtpSession)): ?>
                <!-- 6-Digit OTP Verification Card -->
                <div class="bg-gradient-to-b from-slate-900 via-indigo-950 to-slate-950 px-6 sm:px-8 py-8 sm:py-10 text-center text-white relative">
                    <div class="w-14 h-14 sm:w-16 sm:h-16 bg-gradient-to-tr from-emerald-500 to-teal-500 rounded-2xl flex items-center justify-center mx-auto mb-3.5 shadow-xl shadow-emerald-500/25 ring-4 ring-white/10">
                        <svg class="w-7 h-7 sm:w-8 sm:h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <span class="inline-block px-3 py-1 bg-emerald-500/20 border border-emerald-400/30 text-emerald-300 text-[11px] font-semibold rounded-full uppercase tracking-wider mb-2">Security Verification</span>
                    <h2 class="text-lg sm:text-xl font-bold tracking-tight text-white px-2">Enter 6-Digit Code</h2>
                    <p class="text-xs text-slate-300 mt-1 font-medium">We sent a 6-digit code to <strong class="text-white font-mono"><?= htmlspecialchars($pendingProjectOtpSession['email']) ?></strong></p>
                </div>
                
                <div class="p-6 sm:p-8">
                    <?php if (!empty($otpSuccess)): ?>
                        <div class="mb-5 p-3.5 bg-emerald-50 border border-emerald-200/90 text-emerald-800 text-xs rounded-xl flex items-start space-x-2 shadow-2xs">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <span class="font-medium"><?= htmlspecialchars($otpSuccess) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($otpError)): ?>
                        <div class="mb-5 p-3.5 bg-rose-50 border border-rose-200/90 text-rose-800 text-xs rounded-xl flex items-start space-x-2 shadow-2xs">
                            <svg class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <span class="font-medium"><?= htmlspecialchars($otpError) ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="project?id=<?= urlencode($projectAccessKey) ?>" class="space-y-4">
                        <input type="hidden" name="action" value="verify_otp">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2 text-center">6-Digit Verification Code</label>
                            <input type="text" name="otp_code" required maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" placeholder="123456" 
                                   autofocus
                                   class="w-full px-4 py-3.5 bg-slate-50 border border-slate-300 rounded-2xl text-2xl font-bold font-mono tracking-[0.5em] text-center text-brand-600 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition shadow-inner">
                        </div>
                        <button type="submit" class="w-full py-3 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-semibold rounded-xl text-sm transition shadow-lg shadow-emerald-600/25 flex items-center justify-center space-x-2 active:scale-[0.99]">
                            <span>Verify & Unlock Project</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                        </button>
                    </form>

                    <div class="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between text-xs">
                        <form method="POST" action="project?id=<?= urlencode($projectAccessKey) ?>" class="inline">
                            <input type="hidden" name="action" value="resend_otp">
                            <button type="submit" class="text-brand-600 hover:text-brand-700 font-semibold underline transition">Resend Code</button>
                        </form>
                        <form method="POST" action="project?id=<?= urlencode($projectAccessKey) ?>" class="inline">
                            <input type="hidden" name="action" value="cancel_otp">
                            <button type="submit" class="text-slate-500 hover:text-slate-700 transition">Change Email Address</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- Standard Email Access Card -->
                <div class="bg-gradient-to-b from-slate-900 via-indigo-950 to-slate-950 px-6 sm:px-8 py-8 sm:py-10 text-center text-white relative">
                    <div class="w-14 h-14 sm:w-16 sm:h-16 bg-gradient-to-tr from-brand-600 to-indigo-500 rounded-2xl flex items-center justify-center mx-auto mb-3.5 shadow-xl shadow-brand-500/25 ring-4 ring-white/10">
                        <svg class="w-7 h-7 sm:w-8 sm:h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                    </div>
                    <span class="inline-block px-3 py-1 bg-brand-500/20 border border-brand-400/30 text-brand-300 text-[11px] font-semibold rounded-full uppercase tracking-wider mb-2">Private Project Access</span>
                    <h2 class="text-lg sm:text-xl font-bold tracking-tight text-white px-2"><?= htmlspecialchars($project['title']) ?></h2>
                    <p class="text-xs text-slate-300 mt-1 font-medium">Enter your email address to access this project presentation</p>
                </div>
                
                <div class="p-6 sm:p-8">
                    <form method="POST" action="project?id=<?= urlencode($projectAccessKey) ?>" class="space-y-4">
                        <input type="hidden" name="action" value="verify_email">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Your Email Address</label>
                            <input type="email" name="email" required placeholder="john@domain.com" 
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                   class="w-full px-4 py-3 bg-slate-50 border border-slate-300/80 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition shadow-xs">
                        </div>
                        <button type="submit" class="w-full py-3 bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-700 hover:to-indigo-700 text-white font-semibold rounded-xl text-sm transition shadow-lg shadow-brand-600/25 flex items-center justify-center space-x-2 active:scale-[0.99]">
                            <span>Continue to Project Videos</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                        </button>
                    </form>

                    <?php 
                    $reqState = !empty($ungrantedEmail) ? getAccessRequestStatus($projectId, 0, $ungrantedEmail) : null;
                    ?>

                    <?php if ($gateSuccess || ($reqState && $reqState['status'] === 'pending')): ?>
                        <div class="mt-5 p-4 bg-emerald-50 border border-emerald-200/90 text-emerald-800 text-xs sm:text-sm rounded-2xl flex items-start space-x-3 shadow-2xs">
                            <svg class="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <div>
                                <strong class="font-bold block text-emerald-900 mb-0.5">Access Request Pending Approval</strong>
                                <span>Your access request for <strong class="underline"><?= htmlspecialchars($ungrantedEmail) ?></strong> has been submitted. An admin or editor will review and approve your request.</span>
                            </div>
                        </div>
                    <?php elseif ($gateError): ?>
                        <div class="mt-5 p-4 bg-rose-50 border border-rose-200/90 text-rose-800 text-xs rounded-2xl space-y-3 shadow-2xs">
                            <div class="flex items-start space-x-2">
                                <svg class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <span class="font-medium"><?= htmlspecialchars($gateError) ?></span>
                            </div>
                            <?php if (!empty($ungrantedEmail)): ?>
                                <form method="POST" action="project?id=<?= urlencode($projectAccessKey) ?>" class="pt-1">
                                    <input type="hidden" name="action" value="request_access">
                                    <input type="hidden" name="email" value="<?= htmlspecialchars($ungrantedEmail) ?>">
                                    <button type="submit" class="w-full py-2.5 px-3 bg-rose-600 hover:bg-rose-700 text-white font-semibold rounded-xl text-xs transition shadow-xs flex items-center justify-center space-x-1.5 active:scale-[0.99]">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                                        <span>Request Permission to Access</span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="mt-6 pt-5 border-t border-slate-100 text-center">
                        <p class="text-xs text-slate-500 leading-relaxed">This project is protected by PitchVault. Email verification is required to view presentation content.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>

        <!-- Responsive Project Header Card -->
        <div class="bg-white border border-slate-200/90 rounded-2xl p-4 sm:p-5 shadow-xs mb-6 transition-all">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                
                <!-- Left: Title & Meta Info -->
                <div class="flex items-start sm:items-center gap-3.5 min-w-0">
                    <div class="w-10 h-10 sm:w-11 sm:h-11 shrink-0 rounded-2xl bg-gradient-to-tr from-brand-600 via-indigo-600 to-brand-500 flex items-center justify-center shadow-md shadow-brand-500/20 text-white">
                        <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h1 class="text-lg sm:text-xl font-bold text-slate-900 leading-tight break-words sm:truncate"><?= htmlspecialchars($project['title']) ?></h1>
                        
                        <!-- Mobile & Desktop Badges -->
                        <div class="flex flex-wrap items-center gap-1.5 sm:gap-2 mt-1.5 text-xs text-slate-600">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-medium bg-indigo-50 text-indigo-700 border border-indigo-200/60">
                                <span class="w-1.5 h-1.5 rounded-full bg-indigo-500 mr-1.5 animate-pulse"></span>Verified Invited Access
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-medium bg-slate-100 text-slate-700 border border-slate-200/60 font-mono">
                                <?= count($videos) ?> video<?= count($videos) != 1 ? 's' : '' ?>
                            </span>
                            <span class="text-[11px] text-slate-500 w-full sm:w-auto mt-0.5 sm:mt-0">
                                Viewing as <strong class="text-slate-800 font-semibold"><?= htmlspecialchars($viewerEmail ?? 'Admin') ?></strong>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Right: Admin Action Bar (Responsive) -->
                <?php if ($isAdmin): ?>
                <div class="flex flex-wrap items-center gap-2 pt-3 lg:pt-0 border-t lg:border-t-0 border-slate-100 shrink-0 w-full lg:w-auto justify-start sm:justify-end">
                    <div class="hidden sm:flex items-center space-x-1.5 bg-slate-50 border border-slate-200/80 p-1 rounded-xl flex-1 sm:flex-initial min-w-0">
                        <input type="text" id="projectUrlInput" readonly value="<?= $projectUrl ?>"
                               class="bg-transparent border-0 text-[11px] text-slate-600 font-mono w-36 sm:w-48 focus:ring-0 px-2 truncate">
                        <button id="copyProjectUrlBtn" class="px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-xs font-semibold transition shrink-0 shadow-xs">
                            Copy Link
                        </button>
                    </div>

                    <button id="copyProjectUrlBtnMobile" class="sm:hidden flex-1 px-3 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-xs font-semibold transition flex items-center justify-center space-x-1.5 shadow-xs">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                        <span>Copy Link</span>
                    </button>

                    <button id="openProjectShareModalBtn" class="flex-1 sm:flex-initial px-3.5 py-2 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-semibold transition flex items-center justify-center space-x-1.5 whitespace-nowrap shadow-xs">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path></svg>
                        <span>Share</span>
                    </button>
                    
                    <a href="<?= getBaseUrl() ?>/admin/projects" class="px-3.5 py-2 border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 rounded-xl text-xs font-semibold transition whitespace-nowrap text-center">
                        Manage →
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($videoMessage): ?>
            <div class="mb-4 p-3.5 bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold rounded-xl flex items-center justify-between shadow-2xs">
                <div class="flex items-center space-x-2">
                    <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    <span><?= htmlspecialchars($videoMessage) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($videoError): ?>
            <div class="mb-4 p-3.5 bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold rounded-xl flex items-center justify-between shadow-2xs">
                <div class="flex items-center space-x-2">
                    <svg class="w-4 h-4 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span><?= htmlspecialchars($videoError) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Videos Section Header -->
        <div class="flex items-center justify-between mb-4 px-1">
            <div class="flex items-center space-x-2">
                <h2 class="text-xs font-bold text-slate-500 uppercase tracking-wider">Project Videos</h2>
                <span class="text-xs font-semibold px-2.5 py-1 bg-slate-200/70 text-slate-700 rounded-lg"><?= count($videos) ?> item<?= count($videos) != 1 ? 's' : '' ?></span>
            </div>

            <!-- Grid / List View Toggle Switcher -->
            <div class="inline-flex items-center bg-slate-200/60 p-1 rounded-xl border border-slate-200/80 shadow-2xs">
                <button type="button" id="toggleGridBtn" onclick="setUserViewMode('grid')" 
                        class="px-2.5 py-1 rounded-lg text-xs font-semibold flex items-center space-x-1.5 transition text-slate-600 hover:text-slate-900" 
                        title="Grid View">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    <span class="hidden sm:inline">Grid</span>
                </button>
                <button type="button" id="toggleListBtn" onclick="setUserViewMode('list')" 
                        class="px-2.5 py-1 rounded-lg text-xs font-semibold flex items-center space-x-1.5 transition text-slate-600 hover:text-slate-900" 
                        title="Listing View">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                    <span class="hidden sm:inline">List</span>
                </button>
            </div>
        </div>

        <!-- Videos List -->
        <?php if (empty($videos)): ?>
            <div class="bg-white rounded-3xl p-8 sm:p-12 border border-slate-200 text-center shadow-xs">
                <div class="w-16 h-16 bg-slate-100 text-slate-400 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <h3 class="text-base sm:text-lg font-bold text-slate-900">No Published Videos</h3>
                <p class="text-xs sm:text-sm text-slate-500 mt-1 max-w-sm mx-auto">There are no videos uploaded or published in this project yet.</p>
                <?php if ($isAdmin): ?>
                    <a href="<?= getBaseUrl() ?>/admin/video-upload?project_id=<?= $project['id'] ?>" class="inline-block mt-5 px-5 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-xs font-semibold shadow-md shadow-brand-600/20 transition">
                        + Upload Video to Project
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>            <div id="userVideosContainer" class="user-videos-container space-y-4">
                <?php foreach ($videos as $video): ?>
                    <div class="user-video-card bg-white rounded-2xl border border-slate-200/90 shadow-xs hover:shadow-md transition-all duration-200 p-3 sm:p-3.5 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 sm:gap-4 group">
                        
                        <!-- Thumbnail Box -->
                        <a href="<?= getBaseUrl() ?>/watch?v=<?= urlencode($video['access_key']) ?>" 
                           class="video-thumb-box w-full sm:w-44 h-36 sm:h-24 aspect-video rounded-xl overflow-hidden relative shrink-0 bg-slate-950 group/thumb block shadow-xs border border-slate-100">
                            
                            <?php if (!empty($video['thumbnail_filename'])): ?>
                                <img src="<?= getBaseUrl() ?>/uploads/thumbnails/<?= htmlspecialchars($video['thumbnail_filename']) ?>" 
                                     alt="<?= htmlspecialchars($video['title']) ?>"
                                     class="w-full h-full object-cover group-hover/thumb:scale-105 transition-transform duration-500">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-slate-900 to-indigo-950 text-slate-600">
                                    <svg class="w-8 h-8 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                            <?php endif; ?>

                            <!-- Always-Visible Touch/Play Badge -->
                            <div class="absolute inset-0 bg-slate-950/20 sm:bg-slate-950/30 group-hover/thumb:bg-slate-950/40 transition-colors flex items-center justify-center">
                                <div class="w-9 h-9 sm:w-8 sm:h-8 rounded-full bg-brand-600/90 sm:bg-brand-600 text-white flex items-center justify-center shadow-md transform sm:group-hover/thumb:scale-110 transition border border-white/20">
                                    <svg class="w-4 h-4 ml-0.5" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M8 5v14l11-7z"/>
                                    </svg>
                                </div>
                            </div>

                            <!-- Status Badge (Admin only) -->
                            <?php if ($isAdmin): ?>
                                <div class="absolute top-1.5 left-1.5">
                                    <span class="px-1.5 py-0.5 text-[9px] font-bold rounded-md uppercase tracking-wider <?= $video['status'] === 'published' ? 'bg-emerald-500 text-white' : 'bg-slate-700 text-slate-200' ?>">
                                        <?= $video['status'] ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </a>

                        <!-- Title & Description Info Box -->
                        <div class="video-info-box flex-1 min-w-0 flex flex-col justify-between w-full">
                            <div>
                                <?php if ($isAdmin): ?>
                                    <div class="flex items-center flex-wrap gap-1.5 text-[11px] text-slate-400 font-medium mb-1">
                                        <span>Uploaded: <?= date('M d, Y', strtotime($video['created_at'])) ?></span>
                                        <?php if (!empty($video['updated_at']) && strtotime($video['updated_at']) > (strtotime($video['created_at']) + 60)): ?>
                                            <span>&bull;</span>
                                            <span class="text-amber-700 font-semibold" title="Reuploaded / Last Updated on <?= date('M d, Y h:i A', strtotime($video['updated_at'])) ?>">
                                                Updated: <?= date('M d, Y', strtotime($video['updated_at'])) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <h3 class="font-bold text-slate-900 text-sm sm:text-base group-hover:text-brand-600 transition leading-snug break-words">
                                    <a href="<?= getBaseUrl() ?>/watch?v=<?= urlencode($video['access_key']) ?>">
                                        <?= htmlspecialchars($video['title']) ?>
                                    </a>
                                </h3>

                                <p class="text-xs text-slate-500 mt-1.5 line-clamp-2 leading-relaxed">
                                    <?= htmlspecialchars($video['description'] ?: 'No description provided.') ?>
                                </p>
                            </div>

                            <!-- Card Footer / Actions -->
                            <div class="video-card-footer mt-4 pt-3 border-t border-slate-100 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2 w-full">
                                
                                <!-- Watch Button -->
                                <a href="<?= getBaseUrl() ?>/watch?v=<?= urlencode($video['access_key']) ?>" 
                                   class="inline-flex items-center justify-center space-x-1.5 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-xl text-xs transition shadow-2xs w-full sm:w-auto shrink-0 order-1 sm:order-2">
                                    <span>Watch Video</span>
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                                    </svg>
                                </a>

                                <!-- Admin Tool Buttons -->
                                <?php if ($isAdmin): ?>
                                <div class="flex items-center gap-1 w-full sm:w-auto justify-between sm:justify-start order-2 sm:order-1 flex-wrap">
                                    <button onclick="copyVideoLink('<?= getBaseUrl() ?>/watch?v=<?= urlencode($video['access_key']) ?>')" 
                                            class="flex-1 sm:flex-initial inline-flex items-center justify-center space-x-1 px-2.5 py-1.5 bg-slate-50 border border-slate-200/80 hover:bg-slate-100 text-slate-700 font-semibold rounded-lg text-[11px] transition shadow-2xs" title="Copy Video Access Link">
                                        <svg class="w-3 h-3 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                        </svg>
                                        <span>Copy</span>
                                    </button>
                                    <a href="<?= getBaseUrl() ?>/admin/video-upload?id=<?= $video['id'] ?>" 
                                       class="flex-1 sm:flex-initial inline-flex items-center justify-center space-x-1 px-2.5 py-1.5 bg-white border border-slate-200/80 hover:bg-slate-100 text-slate-700 font-semibold rounded-lg text-[11px] transition shadow-2xs">
                                        <svg class="w-3 h-3 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                        <span>Edit</span>
                                    </a>
                                    <a href="<?= getBaseUrl() ?>/admin/analytics?id=<?= $video['id'] ?>" 
                                       class="flex-1 sm:flex-initial inline-flex items-center justify-center space-x-1 px-2.5 py-1.5 bg-white border border-slate-200/80 hover:bg-slate-100 text-slate-700 font-semibold rounded-lg text-[11px] transition shadow-2xs">
                                        <svg class="w-3 h-3 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 00-2 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                                        <span>Stats</span>
                                    </a>
                                    <?php if (!isSalesAdmin()): ?>
                                        <form method="POST" action="project?id=<?= urlencode($projectAccessKey) ?>" onsubmit="return confirm('Are you sure you want to delete this video asset?');" class="flex-1 sm:flex-initial inline">
                                            <input type="hidden" name="action" value="delete_video">
                                            <input type="hidden" name="video_id" value="<?= $video['id'] ?>">
                                            <button type="submit" 
                                                    class="w-full sm:w-auto inline-flex items-center justify-center space-x-1 px-2.5 py-1.5 bg-rose-50 border border-rose-200/80 hover:bg-rose-100 text-rose-700 font-semibold rounded-lg text-[11px] transition shadow-2xs" 
                                                    title="Delete Video">
                                                <svg class="w-3 h-3 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                                <span>Delete</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>

<!-- Google Drive Style Share Project Modal -->
<div id="projectShareModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full border border-slate-200 overflow-hidden transform transition-all">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center space-x-2">
                <div class="w-8 h-8 bg-brand-50 text-brand-600 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                </div>
                <h3 class="font-bold text-slate-900 text-lg">Share "<?= htmlspecialchars($project['title']) ?>"</h3>
            </div>
            <button id="closeProjectShareModalBtn" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <div class="p-6 space-y-6">
            <!-- Add Email Section (Admin Mode) -->
            <?php if ($isAdmin): ?>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Grant Project Access to Email</label>
                    <div class="flex space-x-2">
                        <input type="email" id="newProjectAccessEmail" placeholder="investor@firm.com" 
                               class="flex-1 px-3.5 py-2 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                        <button id="addProjectAccessBtn" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium rounded-xl transition shrink-0">
                            Grant Access
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Copy Link Box -->
            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Private Project Share Link</label>
                <div class="flex items-center space-x-2 p-1.5 bg-slate-50 border border-slate-200 rounded-xl">
                    <input type="text" id="modalProjectShareUrl" readonly value="<?= $projectUrl ?>" 
                           class="flex-1 bg-transparent border-0 text-xs text-slate-600 focus:ring-0 px-2 font-mono">
                    <button id="modalProjectCopyBtn" class="px-3 py-1.5 bg-white border border-slate-200 hover:bg-slate-100 text-xs font-semibold text-slate-700 rounded-lg transition shadow-xs">
                        Copy Link
                    </button>
                </div>
            </div>

            <!-- List of People with Access -->
            <div>
                <h4 class="text-xs font-semibold text-slate-700 uppercase tracking-wider mb-3">People with project access</h4>
                <div id="projectAccessListContainer" class="space-y-2 max-h-48 overflow-y-auto pr-1">
                    <?php if (empty($accessList)): ?>
                        <p class="text-xs text-slate-400 py-2">No emails granted project access yet.</p>
                    <?php else: ?>
                        <?php foreach ($accessList as $acc): ?>
                            <div class="flex items-center justify-between p-2.5 rounded-xl hover:bg-slate-50 border border-slate-100">
                                <div class="flex items-center space-x-3 min-w-0">
                                    <div class="w-8 h-8 rounded-full bg-brand-50 text-brand-700 font-bold text-xs flex items-center justify-center uppercase shrink-0">
                                        <?= substr($acc['email'], 0, 2) ?>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs font-semibold text-slate-800 truncate"><?= htmlspecialchars($acc['email']) ?></p>
                                        <p class="text-[10px] text-slate-500">
                                            Shared by <strong class="text-slate-700 font-semibold"><?= htmlspecialchars($acc['granted_by_name'] ?? 'Admin') ?></strong> &bull; <?= date('M d, Y', strtotime($acc['granted_at'])) ?>
                                        </p>
                                    </div>
                                </div>
                                <?php if ($isAdmin): ?>
                                    <button onclick="removeProjectAccess('<?= htmlspecialchars($acc['email']) ?>')" class="text-slate-400 hover:text-rose-600 p-1 text-xs font-medium shrink-0 ml-2">
                                        Remove
                                    </button>
                                <?php else: ?>
                                    <span class="text-[10px] bg-slate-100 text-slate-500 px-2 py-0.5 rounded font-medium shrink-0 ml-2">Has access</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Copy Toast Notification -->
<div id="toast" class="fixed bottom-6 right-6 z-50 hidden bg-slate-900 text-white px-4 py-3 rounded-xl shadow-2xl border border-slate-800 text-sm flex items-center space-x-2">
    <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
    </svg>
    <span id="toastMsg">Link copied to clipboard!</span>
</div>

<script>
const projectUrlInput = document.getElementById('projectUrlInput');
const copyProjectUrlBtn = document.getElementById('copyProjectUrlBtn');
const openProjectShareModalBtn = document.getElementById('openProjectShareModalBtn');
const closeProjectShareModalBtn = document.getElementById('closeProjectShareModalBtn');
const projectShareModal = document.getElementById('projectShareModal');
const modalProjectCopyBtn = document.getElementById('modalProjectCopyBtn');
const modalProjectShareUrl = document.getElementById('modalProjectShareUrl');
const toast = document.getElementById('toast');
const toastMsg = document.getElementById('toastMsg');

function showToast(msg) {
    if (toastMsg) toastMsg.textContent = msg;
    if (toast) {
        toast.classList.remove('hidden');
        setTimeout(() => toast.classList.add('hidden'), 3000);
    }
}

const copyProjectUrlBtnMobile = document.getElementById('copyProjectUrlBtnMobile');

if (copyProjectUrlBtn && projectUrlInput) {
    copyProjectUrlBtn.addEventListener('click', () => {
        copyToClipboard(projectUrlInput.value);
        showToast('Project link copied to clipboard!');
    });
}

if (copyProjectUrlBtnMobile && projectUrlInput) {
    copyProjectUrlBtnMobile.addEventListener('click', () => {
        copyToClipboard(projectUrlInput.value);
        showToast('Project link copied to clipboard!');
    });
}

if (modalProjectCopyBtn && modalProjectShareUrl) {
    modalProjectCopyBtn.addEventListener('click', () => {
        copyToClipboard(modalProjectShareUrl.value);
        showToast('Project share link copied!');
    });
}

if (openProjectShareModalBtn) {
    openProjectShareModalBtn.addEventListener('click', () => projectShareModal.classList.remove('hidden'));
}
if (closeProjectShareModalBtn) {
    closeProjectShareModalBtn.addEventListener('click', () => projectShareModal.classList.add('hidden'));
}

function copyVideoLink(url) {
    copyToClipboard(url);
    showToast('Video link copied to clipboard!');
}

// AJAX Project Access Management for Admin
const addProjectAccessBtn = document.getElementById('addProjectAccessBtn');
const newProjectAccessEmail = document.getElementById('newProjectAccessEmail');
const projectAccessListContainer = document.getElementById('projectAccessListContainer');

if (addProjectAccessBtn) {
    addProjectAccessBtn.addEventListener('click', () => {
        const email = newProjectAccessEmail.value.trim();
        if (!email) return;

        fetch('<?= getBaseUrl() ?>/ajax/share_project_access.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'add',
                project_id: <?= $projectId ?>,
                email: email
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                newProjectAccessEmail.value = '';
                renderProjectAccessList(data.access_list);
                showToast('Project access granted to ' + email);
            } else {
                alert(data.error || 'Failed to grant project access');
            }
        });
    });
}

function removeProjectAccess(email) {
    if (!confirm('Remove project access for ' + email + '?')) return;
    fetch('<?= getBaseUrl() ?>/ajax/share_project_access.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'remove',
            project_id: <?= $projectId ?>,
            email: email
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            renderProjectAccessList(data.access_list);
            showToast('Project access removed for ' + email);
        }
    });
}

function renderProjectAccessList(list) {
    if (!projectAccessListContainer) return;
    if (!list || list.length === 0) {
        projectAccessListContainer.innerHTML = '<p class="text-xs text-slate-400 py-3 text-center">No emails granted project access yet.</p>';
        return;
    }
    projectAccessListContainer.innerHTML = list.map(item => {
        const adminName = item.granted_by_name || 'Admin';
        const dateStr = item.granted_at ? ' &bull; ' + item.granted_at.substring(0, 10) : '';
        return `
            <div class="flex items-center justify-between p-2.5 rounded-xl hover:bg-slate-50 border border-slate-100">
                <div class="flex items-center space-x-3 min-w-0">
                    <div class="w-8 h-8 rounded-full bg-brand-50 text-brand-700 font-bold text-xs flex items-center justify-center uppercase shrink-0">
                        ${escapeHtml(item.email.substring(0, 2))}
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-slate-800 truncate">${escapeHtml(item.email)}</p>
                        <p class="text-[10px] text-slate-500">
                            Shared by <strong class="text-slate-700 font-semibold">${escapeHtml(adminName)}</strong>${dateStr}
                        </p>
                    </div>
                </div>
                <button onclick="removeProjectAccess('${escapeHtml(item.email)}')" class="text-slate-400 hover:text-rose-600 p-1 text-xs font-medium shrink-0 ml-2">
                    Remove
                </button>
            </div>
        `;
    }).join('');
}

function setUserViewMode(mode) {
    localStorage.setItem('pitchvault_view_pref', mode);

    const isGrid = mode === 'grid';
    const btnGrid = document.getElementById('toggleGridBtn');
    const btnList = document.getElementById('toggleListBtn');

    if (btnGrid && btnList) {
        if (isGrid) {
            btnGrid.className = 'px-2.5 py-1 rounded-lg text-xs font-semibold flex items-center space-x-1.5 transition bg-white text-brand-600 shadow-xs border border-slate-200/60';
            btnList.className = 'px-2.5 py-1 rounded-lg text-xs font-semibold flex items-center space-x-1.5 transition text-slate-600 hover:text-slate-900 bg-transparent';
        } else {
            btnList.className = 'px-2.5 py-1 rounded-lg text-xs font-semibold flex items-center space-x-1.5 transition bg-white text-brand-600 shadow-xs border border-slate-200/60';
            btnGrid.className = 'px-2.5 py-1 rounded-lg text-xs font-semibold flex items-center space-x-1.5 transition text-slate-600 hover:text-slate-900 bg-transparent';
        }
    }

    const vidContainers = document.querySelectorAll('.user-videos-container');
    vidContainers.forEach(vidContainer => {
        if (isGrid) {
            vidContainer.className = 'user-videos-container grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5';
            vidContainer.querySelectorAll('.user-video-card').forEach(card => {
                card.className = 'user-video-card bg-white rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-md hover:border-slate-300 transition-all duration-300 overflow-hidden flex flex-col justify-between group';
                const thumb = card.querySelector('.video-thumb-box');
                if (thumb) thumb.className = 'video-thumb-box w-full aspect-video rounded-t-2xl overflow-hidden relative bg-slate-950 shrink-0 block border-b border-slate-100';
                const info = card.querySelector('.video-info-box');
                if (info) info.className = 'video-info-box p-4 flex-1 min-w-0 flex flex-col justify-between w-full';
                const footer = card.querySelector('.video-card-footer');
                if (footer) footer.className = 'video-card-footer mt-4 pt-3 border-t border-slate-100 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2 text-xs w-full';
            });
        } else {
            vidContainer.className = 'user-videos-container flex flex-col space-y-3.5';
            vidContainer.querySelectorAll('.user-video-card').forEach(card => {
                card.className = 'user-video-card bg-white rounded-2xl border border-slate-200/80 p-3.5 sm:p-4 shadow-xs hover:shadow-md hover:border-slate-300 transition-all duration-300 overflow-hidden flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 sm:gap-4 group';
                const thumb = card.querySelector('.video-thumb-box');
                if (thumb) thumb.className = 'video-thumb-box w-full sm:w-44 h-36 sm:h-24 aspect-video rounded-xl overflow-hidden relative bg-slate-950 shrink-0 block border border-slate-100';
                const info = card.querySelector('.video-info-box');
                if (info) info.className = 'video-info-box p-0 flex-1 min-w-0 flex flex-col sm:flex-row sm:items-center justify-between gap-3 w-full';
                const footer = card.querySelector('.video-card-footer');
                if (footer) footer.className = 'video-card-footer mt-0 pt-0 border-t-0 flex flex-col sm:flex-row items-stretch sm:items-center justify-end gap-2 shrink-0 w-full sm:w-auto';
            });
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const savedPref = localStorage.getItem('pitchvault_view_pref') || 'grid';
    setUserViewMode(savedPref);
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
