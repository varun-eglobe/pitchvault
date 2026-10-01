<?php
// watch.php - Public/Private Video Detail Page & Player with Email Gate & Event Tracker

require_once __DIR__ . '/includes/auth.php';

$accessKey = sanitize($_GET['v'] ?? $_GET['access_key'] ?? '');
$videoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$db = getDBConnection();

// Fetch Video and associated Project
if (!empty($accessKey)) {
    $stmt = $db->prepare("SELECT v.*, (SELECT COUNT(*) FROM video_sessions WHERE video_id = v.id) as session_count, p.title as project_title, p.access_key as project_access_key, p.access_type as project_access_type 
                          FROM videos v 
                          JOIN projects p ON v.project_id = p.id 
                          WHERE v.access_key = :key LIMIT 1");
    $stmt->execute(['key' => $accessKey]);
} else {
    $stmt = $db->prepare("SELECT v.*, (SELECT COUNT(*) FROM video_sessions WHERE video_id = v.id) as session_count, p.title as project_title, p.access_key as project_access_key, p.access_type as project_access_type 
                          FROM videos v 
                          JOIN projects p ON v.project_id = p.id 
                          WHERE v.id = :id LIMIT 1");
    $stmt->execute(['id' => $videoId]);
}

$video = $stmt->fetch();

if (!$video) {
    $pageTitle = "Video Not Found";
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="max-w-3xl mx-auto my-16 px-4 text-center">
        <div class="w-16 h-16 bg-rose-100 text-rose-600 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
        </div>
        <h1 class="text-2xl font-bold text-slate-900">Video Not Found</h1>
        <p class="text-slate-600 mt-2">The requested private video link is invalid or may have been deleted.</p>
        <a href="<?= getBaseUrl() ?>/admin/index" class="inline-block mt-6 px-4 py-2 bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 transition">Return to Dashboard</a>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$vId = (int)$video['id'];
$vKey = $video['access_key'];
$pageTitle = $video['title'] . " - PitchVault";

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
            if (isAdminLoggedIn() || hasVideoAccess($vId, $submittedEmail) || isEmailVerifiedGlobal($submittedEmail)) {
                autoGrantVideoAccess($vId, $submittedEmail);
                setViewerEmailForVideo($vId, $submittedEmail);
                header("Location: watch?v=" . urlencode($vKey));
                exit;
            } else {
                // Generate 6-digit OTP for new unverified viewer email
                $genResult = generateVideoOTP($vId, $submittedEmail, $video['title']);
                $_SESSION['pending_otp'][$vId] = [
                    'email' => $submittedEmail,
                    'sent_at' => time()
                ];
                $otpSuccess = "A 6-digit verification code was sent to " . htmlspecialchars($submittedEmail) . ".";
            }
        }
    } elseif ($action === 'verify_otp') {
        $pendingEmail = $_SESSION['pending_otp'][$vId]['email'] ?? '';
        $inputOtp = trim($_POST['otp_code'] ?? '');
        if (empty($pendingEmail)) {
            $otpError = 'Session expired. Please re-enter your email address.';
            unset($_SESSION['pending_otp'][$vId]);
        } elseif (empty($inputOtp) || strlen($inputOtp) !== 6) {
            $otpError = 'Please enter a valid 6-digit verification code.';
        } else {
            if (verifyVideoOTP($vId, $pendingEmail, $inputOtp)) {
                autoGrantVideoAccess($vId, $pendingEmail);
                setViewerEmailForVideo($vId, $pendingEmail);
                unset($_SESSION['pending_otp'][$vId]);
                header("Location: watch?v=" . urlencode($vKey));
                exit;
            } else {
                $otpError = 'Invalid or expired 6-digit verification code. Please check your inbox or resend code.';
            }
        }
    } elseif ($action === 'resend_otp') {
        $pendingEmail = $_SESSION['pending_otp'][$vId]['email'] ?? '';
        if (!empty($pendingEmail)) {
            generateVideoOTP($vId, $pendingEmail, $video['title']);
            $_SESSION['pending_otp'][$vId]['sent_at'] = time();
            $otpSuccess = "A new 6-digit verification code has been sent to " . htmlspecialchars($pendingEmail) . ".";
        } else {
            $gateError = 'Session expired. Please re-enter your email address.';
        }
    } elseif ($action === 'cancel_otp') {
        unset($_SESSION['pending_otp'][$vId]);
        header("Location: watch?v=" . urlencode($vKey));
        exit;
    } elseif ($action === 'request_access') {
        $submittedEmail = strtolower(trim($_POST['email'] ?? ''));
        if (!empty($submittedEmail) && filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
            $pId = $video['project_id'] ?? 0;
            submitAccessRequest($pId, $vId, $submittedEmail);
            $gateSuccess = "Access Request Sent! An admin or editor has been notified to approve access for '$submittedEmail'.";
            $ungrantedEmail = $submittedEmail;
        } else {
            $gateError = 'Please enter a valid email address to request access.';
        }
    }
}

$pendingOtpSession = $_SESSION['pending_otp'][$vId] ?? null;

// Check if viewer has authorized session or persistent cookie
$viewerEmail = getViewerEmailForVideo($vId);
$isAdmin = isAdminLoggedIn();

// If non-admin viewer email is present, check if globally verified to auto-grant or invalidate if revoked
if (!$isAdmin && !empty($viewerEmail)) {
    if (isEmailVerifiedGlobal($viewerEmail)) {
        autoGrantVideoAccess($vId, $viewerEmail);
    } elseif (!hasVideoAccess($vId, $viewerEmail)) {
        unset($_SESSION['viewer_access'][$vId]);
        if (isset($_SESSION['global_viewer_email']) && strtolower($_SESSION['global_viewer_email']) === strtolower($viewerEmail)) {
            unset($_SESSION['global_viewer_email']);
        }
        $viewerEmail = null;
    }
}

$isAuthorized = $isAdmin || (!empty($viewerEmail) && hasVideoAccess($vId, $viewerEmail));

// Fetch access list for share modal with Admin Share Tracking
if (isSalesAdmin()) {
    $stmtAccess = $db->prepare("
        SELECT va.email, va.granted_at, va.granted_by_admin_id, 
               a.name as granted_by_name, 
               a.email as granted_by_email
        FROM video_access va 
        LEFT JOIN admins a ON va.granted_by_admin_id = a.id 
        WHERE va.video_id = :vid AND va.granted_by_admin_id = :aid
        ORDER BY va.granted_at DESC
    ");
    $stmtAccess->execute(['vid' => $vId, 'aid' => $_SESSION['admin_id'] ?? 0]);
} else {
    $stmtAccess = $db->prepare("
        SELECT va.email, va.granted_at, va.granted_by_admin_id, 
               a.name as granted_by_name, 
               a.email as granted_by_email
        FROM video_access va 
        LEFT JOIN admins a ON va.granted_by_admin_id = a.id 
        WHERE va.video_id = :vid 
        ORDER BY va.granted_at DESC
    ");
    $stmtAccess->execute(['vid' => $vId]);
}
$accessList = $stmtAccess->fetchAll();

// Fetch attached related documents for admin
$relatedDocs = [];
if ($isAdmin) {
    $stmtDocs = $db->prepare("SELECT id, doc_name, doc_link FROM video_documents WHERE video_id = :vid ORDER BY id ASC");
    $stmtDocs->execute(['vid' => $vId]);
    $relatedDocs = $stmtDocs->fetchAll();
}

// Fetch other videos in the same project that this viewer has access to
if ($isAdmin) {
    $stmtOther = $db->prepare("SELECT id, access_key, title, description, thumbnail_filename, duration, status, created_at 
                               FROM videos 
                               WHERE project_id = :pid AND id != :current_id 
                               ORDER BY id ASC");
    $stmtOther->execute(['pid' => $video['project_id'], 'current_id' => $vId]);
    $otherVideos = $stmtOther->fetchAll();
} else {
    $hasFullProject = !empty($viewerEmail) && hasProjectAccess($video['project_id'], $viewerEmail);
    if ($hasFullProject) {
        $stmtOther = $db->prepare("SELECT id, access_key, title, description, thumbnail_filename, duration, status, created_at 
                                   FROM videos 
                                   WHERE project_id = :pid AND id != :current_id AND status = 'published' 
                                   ORDER BY id ASC");
        $stmtOther->execute(['pid' => $video['project_id'], 'current_id' => $vId]);
        $otherVideos = $stmtOther->fetchAll();
    } elseif (!empty($viewerEmail)) {
        // Only other videos the user was explicitly granted access to in video_access
        $stmtOther = $db->prepare("SELECT v.id, v.access_key, v.title, v.description, v.thumbnail_filename, v.duration, v.status, v.created_at 
                                   FROM videos v
                                   JOIN video_access va ON va.video_id = v.id
                                   WHERE v.project_id = :pid 
                                     AND v.id != :current_id 
                                     AND v.status = 'published'
                                     AND LOWER(va.email) = LOWER(:email)
                                   ORDER BY v.id ASC");
        $stmtOther->execute([
            'pid' => $video['project_id'],
            'current_id' => $vId,
            'email' => $viewerEmail
        ]);
        $otherVideos = $stmtOther->fetchAll();
    } else {
        $otherVideos = [];
    }
}

$hideHeaderEmail = true;
include __DIR__ . '/includes/header.php';
?>

<div class="max-w-6xl mx-auto px-3 sm:px-6 lg:px-8 py-4 sm:py-8 w-full">

    <?php if (!$isAuthorized): ?>
        <!-- Access Gate Modal Card -->
        <div class="max-w-md mx-auto my-6 sm:my-16 px-2">
            <div class="bg-white rounded-3xl border border-slate-200/80 shadow-2xl overflow-hidden relative">
                <!-- Glowing Ambient Accent -->
                <div class="absolute -top-24 -left-24 w-48 h-48 bg-brand-500/20 rounded-full blur-3xl pointer-events-none"></div>
                <div class="absolute -top-24 -right-24 w-48 h-48 bg-indigo-500/20 rounded-full blur-3xl pointer-events-none"></div>

                <?php if (!empty($pendingOtpSession)): ?>
                    <!-- 6-Digit OTP Verification Card -->
                    <div class="bg-gradient-to-b from-slate-900 via-indigo-950 to-slate-950 px-6 sm:px-8 py-8 sm:py-10 text-center text-white relative">
                        <div class="w-14 h-14 sm:w-16 sm:h-16 bg-gradient-to-tr from-emerald-500 to-teal-500 rounded-2xl flex items-center justify-center mx-auto mb-3.5 shadow-xl shadow-emerald-500/25 ring-4 ring-white/10">
                            <svg class="w-7 h-7 sm:w-8 sm:h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <span class="inline-block px-3 py-1 bg-emerald-500/20 border border-emerald-400/30 text-emerald-300 text-[11px] font-semibold rounded-full uppercase tracking-wider mb-2">Security Verification</span>
                        <h2 class="text-lg sm:text-xl font-bold tracking-tight text-white px-2">Enter 6-Digit Code</h2>
                        <p class="text-xs text-slate-300 mt-1 font-medium">We sent a 6-digit code to <strong class="text-white font-mono"><?= htmlspecialchars($pendingOtpSession['email']) ?></strong></p>
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

                        <form method="POST" action="watch?v=<?= urlencode($vKey) ?>" class="space-y-4">
                            <input type="hidden" name="action" value="verify_otp">
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2 text-center">6-Digit Verification Code</label>
                                <input type="text" name="otp_code" required maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" placeholder="123456" 
                                       autofocus
                                       class="w-full px-4 py-3.5 bg-slate-50 border border-slate-300 rounded-2xl text-2xl font-bold font-mono tracking-[0.5em] text-center text-brand-600 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition shadow-inner">
                            </div>
                            <button type="submit" class="w-full py-3 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-semibold rounded-xl text-sm transition shadow-lg shadow-emerald-600/25 flex items-center justify-center space-x-2 active:scale-[0.99]">
                                <span>Verify & Unlock Video</span>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                            </button>
                        </form>

                        <div class="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between text-xs">
                            <form method="POST" action="watch?v=<?= urlencode($vKey) ?>" class="inline">
                                <input type="hidden" name="action" value="resend_otp">
                                <button type="submit" class="text-brand-600 hover:text-brand-700 font-semibold underline transition">Resend Code</button>
                            </form>
                            <form method="POST" action="watch?v=<?= urlencode($vKey) ?>" class="inline">
                                <input type="hidden" name="action" value="cancel_otp">
                                <button type="submit" class="text-slate-500 hover:text-slate-700 transition">Change Email Address</button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-gradient-to-b from-slate-900 via-indigo-950 to-slate-950 px-6 sm:px-8 py-8 sm:py-10 text-center text-white relative">
                        <div class="w-14 h-14 sm:w-16 sm:h-16 bg-gradient-to-tr from-brand-600 to-indigo-500 rounded-2xl flex items-center justify-center mx-auto mb-3.5 shadow-xl shadow-brand-500/25 ring-4 ring-white/10">
                            <svg class="w-7 h-7 sm:w-8 sm:h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <span class="inline-block px-3 py-1 bg-brand-500/20 border border-brand-400/30 text-brand-300 text-[11px] font-semibold rounded-full uppercase tracking-wider mb-2">Private Presentation</span>
                        <h2 class="text-lg sm:text-xl font-bold tracking-tight text-white px-2"><?= htmlspecialchars($video['title']) ?></h2>
                        <p class="text-xs text-slate-300 mt-1 font-medium">Enter your authorized email to watch this video pitch</p>
                    </div>
                    
                    <div class="p-6 sm:p-8">
                        <form method="POST" action="watch?v=<?= urlencode($vKey) ?>" class="space-y-4">
                            <input type="hidden" name="action" value="verify_email">
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Authorized Email Address</label>
                                <input type="email" name="email" required placeholder="john@domain.com" 
                                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                       class="w-full px-4 py-3 bg-slate-50 border border-slate-300/80 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition shadow-xs">
                            </div>
                            <button type="submit" class="w-full py-3 bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-700 hover:to-indigo-700 text-white font-semibold rounded-xl text-sm transition shadow-lg shadow-brand-600/25 flex items-center justify-center space-x-2 active:scale-[0.99]">
                                <span>Continue to Video</span>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                            </button>
                        </form>

                        <?php if ($gateSuccess): ?>
                            <div class="mt-5 p-4 bg-emerald-50 border border-emerald-200/90 text-emerald-800 text-xs rounded-2xl flex items-start space-x-2 shadow-2xs">
                                <svg class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <span class="font-medium"><?= htmlspecialchars($gateSuccess) ?></span>
                            </div>
                        <?php elseif ($gateError): ?>
                            <div class="mt-5 p-4 bg-rose-50 border border-rose-200/90 text-rose-800 text-xs rounded-2xl space-y-3 shadow-2xs">
                                <div class="flex items-start space-x-2">
                                    <svg class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    <span class="font-medium"><?= htmlspecialchars($gateError) ?></span>
                                </div>
                                <?php if (!empty($ungrantedEmail)): ?>
                                    <?php 
                                    $reqStatus = getAccessRequestStatus($video['project_id'], $vId, $ungrantedEmail); 
                                    ?>
                                    <?php if ($reqStatus === 'pending'): ?>
                                        <div class="p-2.5 bg-amber-100/70 border border-amber-200 text-amber-800 rounded-xl text-xs font-semibold flex items-center justify-center space-x-2">
                                            <svg class="w-4 h-4 text-amber-600 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                            <span>Access Request Pending Approval</span>
                                        </div>
                                    <?php else: ?>
                                        <form method="POST" action="watch?v=<?= urlencode($vKey) ?>" class="pt-1">
                                            <input type="hidden" name="action" value="request_access">
                                            <input type="hidden" name="email" value="<?= htmlspecialchars($ungrantedEmail) ?>">
                                            <button type="submit" class="w-full py-2.5 px-3 bg-rose-600 hover:bg-rose-700 text-white font-semibold rounded-xl text-xs transition shadow-xs flex items-center justify-center space-x-1.5 active:scale-[0.99]">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                                                <span>Request Permission to Access</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="mt-6 pt-5 border-t border-slate-100 text-center">
                            <p class="text-xs text-slate-500 leading-relaxed">This video pitch is protected by PitchVault. Only emails explicitly invited by the owner can gain access.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php else: ?>

        <!-- Video Player Section (Cinema Container) -->
        <div class="relative mb-5 group">
            <!-- Subtle Ambient Backdrop Glow -->
            <div class="absolute -inset-1 bg-gradient-to-r from-brand-600/30 to-indigo-600/30 rounded-2xl sm:rounded-3xl blur-xl opacity-40 group-hover:opacity-60 transition duration-500"></div>
            
            <div class="relative bg-slate-950 rounded-2xl sm:rounded-3xl overflow-hidden shadow-2xl border border-slate-800/80">
                <video id="mainVideoPlayer" class="w-full aspect-video outline-none" controls controlsList="nodownload nopictureinpicture" disablePictureInPicture
                       poster="<?= !empty($video['thumbnail_filename']) ? getBaseUrl() . '/uploads/thumbnails/' . htmlspecialchars($video['thumbnail_filename']) : '' ?>">
                    <source src="<?= getBaseUrl() ?>/video-stream.php?v=<?= urlencode($vKey) ?>" type="video/mp4">
                    Your browser does not support HTML5 video playback.
                </video>
            </div>
        </div>

        <!-- Video Details Header Bar -->
        <div class="bg-white rounded-2xl border border-slate-200/90 p-4 sm:p-6 shadow-xs mb-6">
            
            <!-- Top Row: Project Badge + Date + Actions -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3.5 border-b border-slate-100">
                
                <!-- Project Breadcrumb & Status -->
                <div class="flex items-center flex-wrap gap-2 text-xs">
                    <a href="<?= getProjectUrl(['id' => $video['project_id'], 'access_key' => $video['project_access_key'] ?? '']) ?>" class="inline-flex items-center px-2.5 py-1 rounded-lg font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200/80 hover:bg-indigo-100 transition space-x-1.5 shadow-2xs">
                        <svg class="w-3.5 h-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
                        <span><?= htmlspecialchars($video['project_title']) ?></span>
                    </a>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg font-medium bg-emerald-50 text-emerald-700 border border-emerald-200/80 shadow-2xs">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 mr-1.5 animate-pulse"></span>
                        Verified Access
                    </span>
                    <?php $wViews = max((int)($video['total_views'] ?? 0), (int)($video['session_count'] ?? 0)); ?>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg font-medium bg-slate-100 text-slate-700 border border-slate-200/80 shadow-2xs">
                        <svg class="w-3.5 h-3.5 text-slate-500 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                        <span><?= number_format($wViews) ?> View<?= $wViews === 1 ? '' : 's' ?></span>
                    </span>
                    <?php if ($isAdmin): ?>
                        <span class="text-slate-400 text-xs hidden sm:inline">&bull;</span>
                        <span class="text-slate-500 text-xs font-medium">Uploaded: <?= date('M d, Y', strtotime($video['created_at'])) ?></span>
                        <?php if (!empty($video['updated_at']) && strtotime($video['updated_at']) > (strtotime($video['created_at']) + 60)): ?>
                            <span class="text-slate-400 text-xs hidden sm:inline">&bull;</span>
                            <span class="text-amber-700 bg-amber-50 px-2 py-0.5 rounded-md border border-amber-200/80 text-xs font-semibold inline-flex items-center" title="Reuploaded / Last Updated on <?= date('M d, Y h:i A', strtotime($video['updated_at'])) ?>">
                                Updated: <?= date('M d, Y, g:i A', strtotime($video['updated_at'])) ?>
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>

                </div>

                <!-- Action Toolbar (Responsive Flex Row) -->
                <?php if ($isAdmin): ?>
                    <div class="flex items-center gap-1.5 flex-wrap w-full sm:w-auto justify-between sm:justify-end">
                        <button id="copyLinkBtn" class="flex-1 sm:flex-initial inline-flex items-center justify-center space-x-1.5 px-3 py-2 bg-slate-50 border border-slate-200/80 hover:bg-slate-100 text-slate-700 font-semibold rounded-xl text-xs transition shadow-2xs">
                            <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                            <span>Copy Link</span>
                        </button>
                        <button id="openShareModalBtn" class="flex-1 sm:flex-initial inline-flex items-center justify-center space-x-1.5 px-3 py-2 bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-xl text-xs transition shadow-xs">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path></svg>
                            <span>Share (<?= count($accessList) ?>)</span>
                        </button>

                        <button id="openDocsModalBtn" class="flex-1 sm:flex-initial inline-flex items-center justify-center space-x-1.5 px-3 py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200/80 font-semibold rounded-xl text-xs transition shadow-2xs">
                            <svg class="w-3.5 h-3.5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                            <span>Docs</span>
                            <span id="docsHeaderBadge" class="ml-1 px-1.5 py-0.2 bg-white border border-indigo-200 text-indigo-700 rounded-md text-[10px] font-bold leading-none"><?= count($relatedDocs) ?></span>
                        </button>

                        <a href="<?= getBaseUrl() ?>/admin/video-upload?id=<?= $vId ?>" class="flex-1 sm:flex-initial inline-flex items-center justify-center space-x-1 px-3 py-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 rounded-xl text-xs font-semibold transition shadow-2xs">
                            <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                            <span>Edit</span>
                        </a>

                        <a href="<?= getBaseUrl() ?>/admin/analytics?id=<?= $vId ?>" class="flex-1 sm:flex-initial inline-flex items-center justify-center space-x-1 px-3 py-2 bg-slate-900 text-white hover:bg-slate-800 rounded-xl text-xs font-semibold transition shadow-xs">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                            <span>Stats</span>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Video Title & Description -->
            <div class="pt-3">
                <h1 class="text-lg sm:text-2xl font-bold text-slate-900 tracking-tight leading-snug break-words">
                    <?= htmlspecialchars($video['title']) ?>
                </h1>
                <?php if (!empty($video['description'])): ?>
                    <p class="text-xs sm:text-sm text-slate-600 mt-2 leading-relaxed whitespace-pre-line">
                        <?= htmlspecialchars($video['description']) ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <!-- Admin-Only Attached Documents Section (Below Player) -->
            <div class="bg-white rounded-2xl border border-slate-200/80 p-4 sm:p-6 shadow-xs mb-6">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center space-x-2">
                        <span class="p-1.5 rounded-lg bg-indigo-50 text-indigo-600">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                        </span>
                        <div>
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-800">Related Documents & Assets</h3>
                            <p class="text-[11px] text-slate-400">Attached files & links for this video presentation</p>
                        </div>
                        <span class="text-[10px] font-bold text-indigo-700 bg-indigo-50 border border-indigo-200/80 px-2 py-0.5 rounded-full uppercase tracking-wider hidden sm:inline-block">Admin Only</span>
                    </div>
                    <button type="button" id="cardManageDocsBtn" class="inline-flex items-center space-x-1.5 text-xs font-semibold text-brand-600 hover:text-brand-700 bg-brand-50 hover:bg-brand-100 px-3 py-1.5 rounded-xl border border-brand-200/80 transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                        <span>Manage Docs</span>
                    </button>
                </div>

                <div id="attachedDocsDisplay" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2.5 mt-3">
                    <!-- Populated dynamically by JavaScript -->
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($otherVideos)): ?>
            <!-- Other Videos in Same Project -->
            <div class="mt-8 pt-6 border-t border-slate-200/80">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-5">
                    <div>
                        <h2 class="text-base sm:text-lg font-bold text-slate-900 tracking-tight flex items-center space-x-2">
                            <span>More Videos in this Project</span>
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600"><?= count($otherVideos) ?></span>
                        </h2>
                        <p class="text-xs text-slate-500 mt-0.5">Other presentations and pitches in <?= htmlspecialchars($video['project_title']) ?></p>
                    </div>
                    <a href="<?= getProjectUrl(['id' => $video['project_id'], 'access_key' => $video['project_access_key'] ?? '']) ?>" class="inline-flex items-center space-x-1 text-xs font-semibold text-brand-600 hover:text-brand-700 transition group self-start sm:self-auto">
                        <span>View Full Project</span>
                        <svg class="w-3.5 h-3.5 text-brand-500 group-hover:translate-x-0.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                    </a>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
                    <?php foreach ($otherVideos as $ov): ?>
                        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-md hover:border-slate-300 transition-all duration-300 overflow-hidden flex flex-col group">
                            <!-- Thumbnail Preview with Touch Play Button -->
                            <a href="<?= getBaseUrl() ?>/watch?v=<?= urlencode($ov['access_key']) ?>" 
                               class="w-full aspect-video rounded-t-2xl overflow-hidden relative bg-slate-950 shrink-0 block group/thumb">
                                <?php if (!empty($ov['thumbnail_filename'])): ?>
                                    <img src="<?= getBaseUrl() ?>/uploads/thumbnails/<?= htmlspecialchars($ov['thumbnail_filename']) ?>" 
                                         alt="<?= htmlspecialchars($ov['title']) ?>"
                                         class="w-full h-full object-cover group-hover/thumb:scale-105 transition-transform duration-500">
                                <?php else: ?>
                                    <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-slate-900 to-indigo-950 text-slate-600">
                                        <svg class="w-10 h-10 text-slate-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </div>
                                <?php endif; ?>

                                <!-- Touch/Play Overlay -->
                                <div class="absolute inset-0 bg-slate-950/20 sm:bg-slate-950/30 group-hover/thumb:bg-slate-950/40 transition-colors flex items-center justify-center">
                                    <div class="w-11 h-11 rounded-full bg-brand-600/90 sm:bg-brand-600 text-white flex items-center justify-center shadow-xl transform sm:group-hover/thumb:scale-110 transition border border-white/20">
                                        <svg class="w-5 h-5 ml-0.5" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M8 5v14l11-7z"/>
                                        </svg>
                                    </div>
                                </div>

                                <?php if ($isAdmin && $ov['status'] === 'draft'): ?>
                                    <div class="absolute top-2 left-2">
                                        <span class="px-2 py-0.5 text-[9px] font-bold rounded-md uppercase tracking-wider bg-slate-800 text-slate-200 border border-slate-700">
                                            Draft
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </a>

                            <!-- Card Body -->
                            <div class="p-4 flex-1 flex flex-col justify-between">
                                <div>
                                    <h3 class="font-bold text-slate-900 text-sm sm:text-base group-hover:text-brand-600 transition leading-snug line-clamp-2 mb-1.5 break-words">
                                        <a href="<?= getBaseUrl() ?>/watch?v=<?= urlencode($ov['access_key']) ?>">
                                            <?= htmlspecialchars($ov['title']) ?>
                                        </a>
                                    </h3>
                                    <?php if (!empty($ov['description'])): ?>
                                        <p class="text-xs text-slate-500 line-clamp-2 leading-relaxed">
                                            <?= htmlspecialchars($ov['description']) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                                    <?php if ($isAdmin): ?>
                                        <span class="text-slate-400 text-[11px]">
                                            Uploaded: <?= date('M d, Y', strtotime($ov['created_at'])) ?>
                                            <?php if (!empty($ov['updated_at']) && strtotime($ov['updated_at']) > (strtotime($ov['created_at']) + 60)): ?>
                                                &bull; <span class="text-amber-700 font-semibold" title="Reuploaded / Last Updated">Updated: <?= date('M d, Y, g:i A', strtotime($ov['updated_at'])) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <div></div>
                                    <?php endif; ?>
                                    <a href="<?= getBaseUrl() ?>/watch?v=<?= urlencode($ov['access_key']) ?>" 
                                       class="inline-flex items-center space-x-1 font-semibold text-brand-600 group-hover:text-brand-700 transition">
                                        <span>Watch</span>
                                        <svg class="w-3.5 h-3.5 text-brand-500 group-hover:translate-x-0.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</div>

<!-- Google Drive Style Share Modal -->
<div id="shareModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl max-w-3xl sm:max-w-4xl w-full border border-slate-200 overflow-hidden my-auto max-h-[90vh] flex flex-col transform transition-all">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between shrink-0 bg-slate-50/50">
            <div class="flex items-center space-x-2.5">
                <div class="w-8 h-8 bg-brand-50 text-brand-600 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                </div>
                <h3 class="font-bold text-slate-900 text-base sm:text-lg">Share "<?= htmlspecialchars($video['title']) ?>"</h3>
            </div>
            <button id="closeShareModalBtn" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-xl hover:bg-slate-100 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <!-- 2-Column Grid Body -->
        <div class="p-6 grid grid-cols-1 md:grid-cols-12 gap-6 overflow-y-auto">
            <!-- Left Column: Grant Access & Share Link -->
            <div class="md:col-span-5 space-y-6 flex flex-col justify-between">
                <div>
                    <?php if ($isAdmin): ?>
                        <div class="mb-5">
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Grant Access to Email</label>
                            <div class="space-y-2">
                                <input type="email" id="newAccessEmail" placeholder="john@domain.com" 
                                       class="w-full px-3.5 py-2.5 bg-slate-50/50 border border-slate-300 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition">
                                <button id="addAccessBtn" class="w-full px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold rounded-xl transition shadow-xs flex items-center justify-center space-x-1.5">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                                    <span>Grant Access</span>
                                </button>
                            </div>
                            <p class="text-[11px] text-slate-400 mt-1.5 leading-normal">Granted viewers will receive direct authorization to view this video asset.</p>
                        </div>
                    <?php endif; ?>

                    <div class="pt-4 border-t border-slate-100">
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Private Video Share Link</label>
                        <div class="flex items-center space-x-2 p-1.5 bg-slate-50 border border-slate-200 rounded-xl">
                            <input type="text" id="shareUrlInput" readonly value="<?= getBaseUrl() ?>/watch?v=<?= urlencode($vKey) ?>" 
                                   class="flex-1 bg-transparent border-0 text-xs text-slate-600 focus:ring-0 px-2 font-mono truncate">
                            <button id="modalCopyBtn" class="px-3.5 py-2 bg-white border border-slate-200 hover:bg-slate-100 text-xs font-semibold text-slate-700 rounded-lg transition shadow-2xs shrink-0">
                                Copy Link
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Allowed Emails List -->
            <div class="md:col-span-7 flex flex-col min-h-0 md:border-l md:border-slate-100 md:pl-6">
                <div class="flex items-center justify-between mb-3">
                    <h4 class="text-xs font-bold text-slate-700 uppercase tracking-wider">People with access</h4>
                </div>
                <div id="accessListContainer" class="space-y-2 max-h-80 sm:max-h-[380px] overflow-y-auto pr-1 flex-1">
                    <?php foreach ($accessList as $acc): ?>
                        <div class="flex items-center justify-between p-2.5 rounded-xl hover:bg-slate-50 border border-slate-100">
                            <div class="flex items-center space-x-3 min-w-0">
                                <div class="w-8 h-8 rounded-full bg-brand-50 text-brand-700 font-bold text-xs flex items-center justify-center uppercase shrink-0">
                                    <?= substr($acc['email'], 0, 2) ?>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold text-slate-800 truncate"><?= htmlspecialchars($acc['email']) ?></p>
                                    <p class="text-[10px] text-slate-500">
                                        <?php if (!empty($acc['granted_by_admin_id'])): ?>
                                            Shared by <strong class="text-slate-700 font-semibold"><?= htmlspecialchars($acc['granted_by_name'] ?? 'Admin') ?></strong> &bull; <?= date('M d, Y', strtotime($acc['granted_at'])) ?>
                                        <?php else: ?>
                                            <span class="inline-flex items-center text-amber-700 font-medium">OTP Verification</span> &bull; <?= date('M d, Y', strtotime($acc['granted_at'])) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            <?php if ($isAdmin): ?>
                                <button onclick="removeAccess('<?= htmlspecialchars($acc['email']) ?>')" class="text-slate-400 hover:text-rose-600 p-1 text-xs shrink-0 ml-2">
                                    Remove
                                </button>
                            <?php else: ?>
                                <span class="text-[10px] bg-slate-100 text-slate-500 px-2 py-0.5 rounded font-medium shrink-0 ml-2">Has access</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- Admin Related Documents Repeater Modal -->
<div id="docsModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full border border-slate-200 overflow-hidden transform transition-all">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center space-x-2.5">
                <div class="w-9 h-9 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                </div>
                <div>
                    <h3 class="font-bold text-slate-900 text-base sm:text-lg">Attach Related Documents</h3>
                    <p class="text-xs text-slate-400">Keep pitch decks, spreadsheets, or briefs reachable here (Admin only)</p>
                </div>
            </div>
            <button type="button" id="closeDocsModalBtn" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <!-- Modal Body: Repeater Rows -->
        <div class="p-6">
            <div class="hidden sm:grid grid-cols-12 gap-3 mb-2 text-[11px] font-bold text-slate-400 uppercase tracking-wider px-1">
                <div class="col-span-5">Document Name</div>
                <div class="col-span-6">Link URL (e.g. Google Drive, Notion, PDF)</div>
                <div class="col-span-1 text-center">Action</div>
            </div>

            <!-- Repeater Container -->
            <div id="docsRepeaterContainer" class="space-y-2.5 max-h-[320px] overflow-y-auto pr-1">
                <!-- Injected dynamically by JavaScript -->
            </div>

            <!-- Add Row Button -->
            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                <button type="button" id="addDocRowBtn" class="inline-flex items-center space-x-1.5 text-xs font-semibold text-brand-600 hover:text-brand-700 bg-brand-50 hover:bg-brand-100 px-3 py-2 rounded-xl transition border border-brand-200/60">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    <span>Add Another Document</span>
                </button>
                <span class="text-[11px] text-slate-400 hidden sm:inline">Empty rows will be ignored</span>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex items-center justify-between">
            <span class="text-[11px] text-slate-400">Visible only to logged-in admins</span>
            <div class="flex items-center space-x-2">
                <button type="button" id="cancelDocsModalBtn" class="px-4 py-2 bg-white border border-slate-200 hover:bg-slate-100 text-slate-700 rounded-xl text-xs sm:text-sm font-medium transition shadow-xs">
                    Cancel
                </button>
                <button type="button" id="saveDocsBtn" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-xs sm:text-sm font-semibold transition shadow-sm shadow-brand-600/20 flex items-center space-x-1.5">
                    <span id="saveDocsBtnText">Save Documents</span>
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Copy Toast Notification -->
<div id="toast" class="fixed bottom-6 right-6 z-50 hidden bg-slate-900 text-white px-4 py-3 rounded-xl shadow-2xl border border-slate-800 text-sm flex items-center space-x-2">
    <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
    <span id="toastMsg">Link copied to clipboard!</span>
</div>

<script>
// JS Logic for Share Modal & Copy Link
const openShareModalBtn = document.getElementById('openShareModalBtn');
const closeShareModalBtn = document.getElementById('closeShareModalBtn');
const shareModal = document.getElementById('shareModal');
const copyLinkBtn = document.getElementById('copyLinkBtn');
const modalCopyBtn = document.getElementById('modalCopyBtn');
const shareUrlInput = document.getElementById('shareUrlInput');
const toast = document.getElementById('toast');
const toastMsg = document.getElementById('toastMsg');

function showToast(msg) {
    if (toastMsg) toastMsg.textContent = msg;
    if (toast) {
        toast.classList.remove('hidden');
        setTimeout(() => toast.classList.add('hidden'), 3000);
    }
}

if (openShareModalBtn) {
    openShareModalBtn.addEventListener('click', () => shareModal.classList.remove('hidden'));
}
if (closeShareModalBtn) {
    closeShareModalBtn.addEventListener('click', () => shareModal.classList.add('hidden'));
}
if (copyLinkBtn) {
    copyLinkBtn.addEventListener('click', () => {
        copyToClipboard(window.location.href);
        showToast('Video link copied to clipboard!');
    });
}
if (modalCopyBtn && shareUrlInput) {
    modalCopyBtn.addEventListener('click', () => {
        copyToClipboard(shareUrlInput.value);
        showToast('Private link copied!');
    });
}

// AJAX Access Management for Admin
const addAccessBtn = document.getElementById('addAccessBtn');
const newAccessEmail = document.getElementById('newAccessEmail');
const accessListContainer = document.getElementById('accessListContainer');

if (addAccessBtn) {
    addAccessBtn.addEventListener('click', () => {
        const email = newAccessEmail.value.trim();
        if (!email) return;

        fetch('<?= getBaseUrl() ?>/ajax/share_access.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'add',
                video_id: <?= $vId ?>,
                email: email
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                newAccessEmail.value = '';
                renderAccessList(data.access_list);
                showToast('Access granted to ' + email);
            } else {
                alert(data.error || 'Failed to grant access');
            }
        });
    });
}

function removeAccess(email) {
    if (!confirm('Remove access for ' + email + '?')) return;
    fetch('<?= getBaseUrl() ?>/ajax/share_access.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'remove',
            video_id: <?= $vId ?>,
            email: email
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            renderAccessList(data.access_list);
            showToast('Access removed for ' + email);
        }
    });
}

function renderAccessList(list) {
    const openShareModalBtn = document.getElementById('openShareModalBtn');
    if (openShareModalBtn) {
        const span = openShareModalBtn.querySelector('span');
        if (span) span.textContent = `Share (${list ? list.length : 0})`;
    }
    if (!accessListContainer) return;
    if (list.length === 0) {
        accessListContainer.innerHTML = '<p class="text-xs text-slate-400 py-2">No emails granted access yet.</p>';
        return;
    }
    accessListContainer.innerHTML = list.map(item => {
        const isGrantedByAdmin = item.granted_by_admin_id !== null && item.granted_by_admin_id !== undefined && item.granted_by_admin_id !== '';
        const adminName = item.granted_by_name || 'Admin';
        const dateStr = item.granted_at ? ' &bull; ' + item.granted_at.substring(0, 10) : '';
        const accessText = isGrantedByAdmin
            ? `Shared by <strong class="text-slate-700 font-semibold">${escapeHtml(adminName)}</strong>`
            : `<span class="inline-flex items-center text-amber-700 font-medium">OTP Verification</span>`;

        return `
            <div class="flex items-center justify-between p-2.5 rounded-xl hover:bg-slate-50 border border-slate-100">
                <div class="flex items-center space-x-3 min-w-0">
                    <div class="w-8 h-8 rounded-full bg-brand-50 text-brand-700 font-bold text-xs flex items-center justify-center uppercase shrink-0">
                        ${escapeHtml(item.email.substring(0, 2))}
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-slate-800 truncate">${escapeHtml(item.email)}</p>
                        <p class="text-[10px] text-slate-500">
                            ${accessText}${dateStr}
                        </p>
                    </div>
                </div>
                <button onclick="removeAccess('${escapeHtml(item.email)}')" class="text-slate-400 hover:text-rose-600 p-1 text-xs font-medium shrink-0 ml-2">
                    Remove
                </button>
            </div>
        `;
    }).join('');
}

<?php if ($isAdmin): ?>
// Related Documents Management for Admin
let attachedDocs = <?= json_encode($relatedDocs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> || [];
const docsModal = document.getElementById('docsModal');
const openDocsModalBtn = document.getElementById('openDocsModalBtn');
const cardManageDocsBtn = document.getElementById('cardManageDocsBtn');
const closeDocsModalBtn = document.getElementById('closeDocsModalBtn');
const cancelDocsModalBtn = document.getElementById('cancelDocsModalBtn');
const docsRepeaterContainer = document.getElementById('docsRepeaterContainer');
const addDocRowBtn = document.getElementById('addDocRowBtn');
const saveDocsBtn = document.getElementById('saveDocsBtn');
const saveDocsBtnText = document.getElementById('saveDocsBtnText');
const attachedDocsDisplay = document.getElementById('attachedDocsDisplay');
const docsHeaderBadge = document.getElementById('docsHeaderBadge');

function openDocsModal() {
    renderRepeater();
    if (docsModal) docsModal.classList.remove('hidden');
}

function closeDocsModal() {
    if (docsModal) docsModal.classList.add('hidden');
}

if (openDocsModalBtn) openDocsModalBtn.addEventListener('click', openDocsModal);
if (cardManageDocsBtn) cardManageDocsBtn.addEventListener('click', openDocsModal);
if (closeDocsModalBtn) closeDocsModalBtn.addEventListener('click', closeDocsModal);
if (cancelDocsModalBtn) cancelDocsModalBtn.addEventListener('click', closeDocsModal);

function addDocRow(name = '', link = '') {
    if (!docsRepeaterContainer) return;
    const row = document.createElement('div');
    row.className = 'doc-repeater-row grid grid-cols-1 sm:grid-cols-12 gap-2 sm:gap-3 items-center p-2.5 bg-slate-50 border border-slate-200/80 rounded-xl transition hover:border-slate-300';
    row.innerHTML = `
        <div class="sm:col-span-5">
            <input type="text" class="doc-name-input w-full px-3 py-2 bg-white border border-slate-300 rounded-lg text-xs font-medium text-slate-800 placeholder-slate-400 focus:ring-2 focus:ring-brand-500 focus:border-brand-500" 
                   placeholder="e.g. Pitch Deck PDF" value="${escapeHtml(name)}">
        </div>
        <div class="sm:col-span-6">
            <input type="url" class="doc-link-input w-full px-3 py-2 bg-white border border-slate-300 rounded-lg text-xs font-medium text-slate-800 placeholder-slate-400 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 font-mono" 
                   placeholder="https://drive.google.com/..." value="${escapeHtml(link)}">
        </div>
        <div class="sm:col-span-1 flex justify-end sm:justify-center">
            <button type="button" class="remove-doc-row-btn p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Remove Document">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
            </button>
        </div>
    `;
    row.querySelector('.remove-doc-row-btn').addEventListener('click', () => {
        row.remove();
        if (docsRepeaterContainer.children.length === 0) {
            addDocRow();
        }
    });
    docsRepeaterContainer.appendChild(row);
}

function renderRepeater() {
    if (!docsRepeaterContainer) return;
    docsRepeaterContainer.innerHTML = '';
    if (attachedDocs && attachedDocs.length > 0) {
        attachedDocs.forEach(doc => addDocRow(doc.doc_name, doc.doc_link));
    } else {
        addDocRow();
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderDocsDisplay() {
    if (!attachedDocsDisplay) return;
    if (docsHeaderBadge) docsHeaderBadge.textContent = attachedDocs.length;

    if (!attachedDocs || attachedDocs.length === 0) {
        attachedDocsDisplay.innerHTML = `
            <div class="col-span-1 sm:col-span-2 p-4 bg-slate-50 border border-dashed border-slate-200 rounded-xl text-center">
                <p class="text-xs text-slate-500 mb-2">No related documents attached yet.</p>
                <button type="button" onclick="openDocsModal()" class="inline-flex items-center space-x-1 text-xs font-semibold text-brand-600 hover:text-brand-700 bg-white px-3 py-1.5 rounded-lg border border-slate-200 shadow-2xs hover:bg-slate-50 transition">
                    <svg class="w-3.5 h-3.5 text-brand-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    <span>Attach First Document</span>
                </button>
            </div>
        `;
        return;
    }

    let html = '';
    attachedDocs.forEach(doc => {
        const link = doc.doc_link || '#';
        const name = doc.doc_name || 'Document';
        let hostname = '';
        try {
            hostname = new URL(link).hostname.replace('www.', '');
        } catch(e) {
            hostname = 'Resource Link';
        }

        html += `
            <a href="${escapeHtml(link)}" target="_blank" rel="noopener noreferrer" 
               class="group p-3 bg-slate-50 hover:bg-white border border-slate-200/80 hover:border-brand-300 rounded-xl transition-all duration-200 shadow-2xs hover:shadow-xs flex items-center justify-between gap-3">
                <div class="flex items-center space-x-3 min-w-0">
                    <div class="w-8 h-8 rounded-lg bg-indigo-100/70 text-indigo-700 flex items-center justify-center shrink-0 group-hover:bg-brand-600 group-hover:text-white transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-slate-800 truncate group-hover:text-brand-600 transition-colors">${escapeHtml(name)}</p>
                        <p class="text-[10px] text-slate-400 truncate font-mono">${escapeHtml(hostname)}</p>
                    </div>
                </div>
                <svg class="w-4 h-4 text-slate-400 group-hover:text-brand-600 shrink-0 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
            </a>
        `;
    });
    attachedDocsDisplay.innerHTML = html;
}

if (addDocRowBtn) {
    addDocRowBtn.addEventListener('click', () => addDocRow());
}

if (saveDocsBtn) {
    saveDocsBtn.addEventListener('click', () => {
        const rows = docsRepeaterContainer.querySelectorAll('.doc-repeater-row');
        const docsData = [];
        rows.forEach(r => {
            const name = r.querySelector('.doc-name-input').value.trim();
            const link = r.querySelector('.doc-link-input').value.trim();
            if (name || link) {
                docsData.push({ doc_name: name, doc_link: link });
            }
        });

        saveDocsBtn.disabled = true;
        saveDocsBtnText.textContent = 'Saving...';

        fetch('<?= getBaseUrl() ?>/ajax/manage_docs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save',
                video_id: <?= $vId ?>,
                documents: docsData
            })
        })
        .then(res => res.json())
        .then(data => {
            saveDocsBtn.disabled = false;
            saveDocsBtnText.textContent = 'Save Documents';
            if (data.success) {
                attachedDocs = data.documents;
                renderDocsDisplay();
                closeDocsModal();
                showToast('Related documents saved successfully!');
            } else {
                alert(data.error || 'Failed to save documents');
            }
        })
        .catch(err => {
            saveDocsBtn.disabled = false;
            saveDocsBtnText.textContent = 'Save Documents';
            alert('Error saving documents: ' + err.message);
        });
    });
}

// Initial render of docs
renderDocsDisplay();
<?php endif; ?>

</script>

<?php if ($isAuthorized): ?>
<!-- HTML5 Video Player Tracking Script -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const video = document.getElementById('mainVideoPlayer');
    if (!video) return;
    video.disablePictureInPicture = true;

    <?php $thumbUrl = !empty($video['thumbnail_filename']) ? getBaseUrl() . '/uploads/thumbnails/' . htmlspecialchars($video['thumbnail_filename']) : getBaseUrl() . '/favicon.png'; ?>
    // Set Mobile Lock Screen Media Artwork & Metadata via Web MediaSession API
    if ('mediaSession' in navigator) {
        const thumbUrl = <?= json_encode($thumbUrl) ?>;
        navigator.mediaSession.metadata = new MediaMetadata({
            title: <?= json_encode($video['title']) ?>,
            artist: <?= json_encode($video['project_title'] ?? 'PitchVault') ?>,
            album: 'PitchVault Presentation',
            artwork: [
                { src: thumbUrl, sizes: '512x512', type: 'image/jpeg' },
                { src: thumbUrl, sizes: '384x384', type: 'image/jpeg' },
                { src: thumbUrl, sizes: '256x256', type: 'image/jpeg' },
                { src: thumbUrl, sizes: '192x192', type: 'image/png' },
                { src: thumbUrl, sizes: '96x96', type: 'image/png' }
            ]
        });

        try {
            navigator.mediaSession.setActionHandler('play', () => video.play());
            navigator.mediaSession.setActionHandler('pause', () => video.pause());
            navigator.mediaSession.setActionHandler('seekbackward', (details) => {
                const skipTime = details.seekOffset || 10;
                video.currentTime = Math.max(video.currentTime - skipTime, 0);
            });
            navigator.mediaSession.setActionHandler('seekforward', (details) => {
                const skipTime = details.seekOffset || 10;
                video.currentTime = Math.min(video.currentTime + skipTime, video.duration);
            });
            navigator.mediaSession.setActionHandler('seekto', (details) => {
                if (details.fastSeek && ('fastSeek' in video)) {
                    video.fastSeek(details.seekTime);
                } else {
                    video.currentTime = details.seekTime;
                }
            });
        } catch (e) {
            console.log('MediaSession action handler error:', e);
        }
    }

    // Generate unique session token for this viewing session
    const sessionToken = 'sess_' + Date.now() + '_' + Math.random().toString(36).substring(2, 9);
    const videoId = <?= $vId ?>;
    const accessKey = "<?= $vKey ?>";

    let lastPosition = 0;
    let isSeeking = false;
    let seekFromPosition = 0;
    let currentPlayPosition = 0;
    let lastTimeUpdate = Date.now();
    let accumulatedWatchTime = 0;

    function sendEvent(eventType, watchDelta = 0, explicitPrev = null, explicitCurrent = null) {
        const currentPos = explicitCurrent !== null ? explicitCurrent : video.currentTime;
        const prevPos = explicitPrev !== null ? explicitPrev : lastPosition;
        
        const payload = {
            session_token: sessionToken,
            video_id: videoId,
            access_key: accessKey,
            event_type: eventType,
            prev_position: prevPos,
            current_position: currentPos,
            watch_time_delta: watchDelta,
            video_duration: (video && !isNaN(video.duration) && video.duration > 0) ? Math.round(video.duration) : 0
        };

        fetch('<?= getBaseUrl() ?>/ajax/track_event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).catch(err => console.error('Tracking ping error:', err));

        lastPosition = currentPos;
    }

    video.addEventListener('timeupdate', () => {
        if (!isSeeking) {
            const nowTime = video.currentTime;
            if (Math.abs(nowTime - currentPlayPosition) < 2.0) {
                currentPlayPosition = nowTime;
            }
        }
    });

    video.addEventListener('play', () => {
        sendEvent('play', 0, currentPlayPosition, currentPlayPosition);
        lastTimeUpdate = Date.now();
    });

    video.addEventListener('pause', () => {
        if (!video.ended && !isSeeking) {
            const now = Date.now();
            const deltaSec = (now - lastTimeUpdate) / 1000;
            sendEvent('pause', deltaSec, currentPlayPosition, currentPlayPosition);
        }
    });

    video.addEventListener('seeking', () => {
        if (!isSeeking) {
            seekFromPosition = currentPlayPosition;
        }
        isSeeking = true;
    });

    video.addEventListener('seeked', () => {
        const seekTo = video.currentTime;
        const seekFrom = seekFromPosition;
        
        // Send seek event explicitly defining prev and current positions
        if (Math.abs(seekTo - seekFrom) >= 0.5) {
            sendEvent('seek', 0, seekFrom, seekTo);
        }

        isSeeking = false;
        currentPlayPosition = seekTo;
        lastTimeUpdate = Date.now();
    });

    video.addEventListener('ended', () => {
        const now = Date.now();
        const deltaSec = (now - lastTimeUpdate) / 1000;
        sendEvent('ended', deltaSec, currentPlayPosition, currentPlayPosition);
    });

    // Periodic watch time ping every 5 seconds while playing
    setInterval(() => {
        if (!video.paused && !video.ended && !isSeeking) {
            const now = Date.now();
            const deltaSec = (now - lastTimeUpdate) / 1000;
            lastTimeUpdate = now;
            sendEvent('progress', deltaSec, currentPlayPosition, currentPlayPosition);
        }
    }, 5000);
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
