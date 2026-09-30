<?php
// admin/index.php - Minimal Executive Admin Overview Dashboard

require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();

$db = getDBConnection();

$isMaster = isMasterAdmin();
$isSales = isSalesAdmin();
$currentAdminId = $_SESSION['admin_id'] ?? 0;
$assignedProjectIds = $isMaster ? [] : getEditorProjectIds($currentAdminId);
$projIdsList = empty($assignedProjectIds) ? '0' : implode(',', array_map('intval', $assignedProjectIds));

if ($isMaster) {
    $totalProjects = $db->query("SELECT COUNT(*) FROM projects")->fetchColumn();
    $totalVideos = $db->query("SELECT COUNT(*) FROM videos")->fetchColumn();
    $totalSessions = $db->query("SELECT COUNT(*) FROM video_sessions")->fetchColumn();
    $totalWatchSecs = $db->query("SELECT SUM(total_watch_time) FROM video_sessions")->fetchColumn() ?: 0;

    $topUsersStmt = $db->query("
        SELECT 
            s.user_email,
            COUNT(s.id) as session_count,
            SUM(s.total_watch_time) as total_watch_secs,
            COUNT(DISTINCT s.video_id) as video_count,
            MAX(s.last_activity) as last_activity
        FROM video_sessions s
        JOIN videos v ON s.video_id = v.id
        WHERE s.user_email IS NOT NULL AND TRIM(s.user_email) != ''
          AND LOWER(s.user_email) NOT IN (SELECT LOWER(email) FROM admins)
        GROUP BY LOWER(s.user_email)
        ORDER BY total_watch_secs DESC, session_count DESC
        LIMIT 5
    ");
    $topEngagedUsers = $topUsersStmt->fetchAll();
} elseif ($isSales) {
    $totalProjects = $db->query("SELECT COUNT(*) FROM projects")->fetchColumn();
    $totalVideos = $db->query("SELECT COUNT(*) FROM videos")->fetchColumn();

    $stmtS = $db->prepare("
        SELECT COUNT(s.id) FROM video_sessions s 
        WHERE LOWER(s.user_email) IN (
            SELECT LOWER(email) FROM project_access WHERE granted_by_admin_id = :aid1
            UNION
            SELECT LOWER(email) FROM video_access WHERE granted_by_admin_id = :aid2
        )
    ");
    $stmtS->execute(['aid1' => $currentAdminId, 'aid2' => $currentAdminId]);
    $totalSessions = $stmtS->fetchColumn();

    $stmtW = $db->prepare("
        SELECT SUM(s.total_watch_time) FROM video_sessions s 
        WHERE LOWER(s.user_email) IN (
            SELECT LOWER(email) FROM project_access WHERE granted_by_admin_id = :aid1
            UNION
            SELECT LOWER(email) FROM video_access WHERE granted_by_admin_id = :aid2
        )
    ");
    $stmtW->execute(['aid1' => $currentAdminId, 'aid2' => $currentAdminId]);
    $totalWatchSecs = $stmtW->fetchColumn() ?: 0;

    $topUsersStmt = $db->prepare("
        SELECT 
            s.user_email,
            COUNT(s.id) as session_count,
            SUM(s.total_watch_time) as total_watch_secs,
            COUNT(DISTINCT s.video_id) as video_count,
            MAX(s.last_activity) as last_activity
        FROM video_sessions s
        JOIN videos v ON s.video_id = v.id
        WHERE s.user_email IS NOT NULL AND TRIM(s.user_email) != ''
          AND LOWER(s.user_email) NOT IN (SELECT LOWER(email) FROM admins)
          AND LOWER(s.user_email) IN (
              SELECT LOWER(email) FROM project_access WHERE granted_by_admin_id = :aid1
              UNION
              SELECT LOWER(email) FROM video_access WHERE granted_by_admin_id = :aid2
          )
        GROUP BY LOWER(s.user_email)
        ORDER BY total_watch_secs DESC, session_count DESC
        LIMIT 5
    ");
    $topUsersStmt->execute(['aid1' => $currentAdminId, 'aid2' => $currentAdminId]);
    $topEngagedUsers = $topUsersStmt->fetchAll();
} else {
    $totalProjects = count($assignedProjectIds);
    $totalVideos = $db->query("SELECT COUNT(*) FROM videos WHERE project_id IN ($projIdsList)")->fetchColumn();
    $totalSessions = $db->query("SELECT COUNT(s.id) FROM video_sessions s JOIN videos v ON s.video_id = v.id WHERE v.project_id IN ($projIdsList)")->fetchColumn();
    $totalWatchSecs = $db->query("SELECT SUM(s.total_watch_time) FROM video_sessions s JOIN videos v ON s.video_id = v.id WHERE v.project_id IN ($projIdsList)")->fetchColumn() ?: 0;

    $topUsersStmt = $db->query("
        SELECT 
            s.user_email,
            COUNT(s.id) as session_count,
            SUM(s.total_watch_time) as total_watch_secs,
            COUNT(DISTINCT s.video_id) as video_count,
            MAX(s.last_activity) as last_activity
        FROM video_sessions s
        JOIN videos v ON s.video_id = v.id
        WHERE s.user_email IS NOT NULL AND TRIM(s.user_email) != ''
          AND LOWER(s.user_email) NOT IN (SELECT LOWER(email) FROM admins)
          AND v.project_id IN ($projIdsList)
        GROUP BY LOWER(s.user_email)
        ORDER BY total_watch_secs DESC, session_count DESC
        LIMIT 5
    ");
    $topEngagedUsers = $topUsersStmt->fetchAll();
}

// Fetch pending access requests
if ($isMaster || $isSales) {
    $pendingReqsStmt = $db->query("
        SELECT r.*, p.title as project_title, p.access_key as project_access_key, v.title as video_title, v.access_key as video_access_key
        FROM access_requests r
        JOIN projects p ON r.project_id = p.id
        LEFT JOIN videos v ON r.video_id = v.id
        WHERE r.status = 'pending'
        ORDER BY r.requested_at DESC
    ");
} else {
    $pendingReqsStmt = $db->query("
        SELECT r.*, p.title as project_title, p.access_key as project_access_key, v.title as video_title, v.access_key as video_access_key
        FROM access_requests r
        JOIN projects p ON r.project_id = p.id
        LEFT JOIN videos v ON r.video_id = v.id
        WHERE r.status = 'pending' AND r.project_id IN ($projIdsList)
        ORDER BY r.requested_at DESC
    ");
}
$pendingAccessRequests = $pendingReqsStmt ? $pendingReqsStmt->fetchAll() : [];

$pageTitle = "Admin Dashboard - PitchVault";
include __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 w-full">

    <!-- Top Action Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Executive Dashboard</h1>
            <p class="text-sm text-slate-500 mt-1">Overview of pitch projects, private video assets, and viewer engagement metrics.</p>
        </div>
        <div class="flex items-center space-x-3">
            <?php if (!isSalesAdmin()): ?>
                <a href="projects?action=new" class="inline-flex items-center space-x-2 px-4 py-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 font-medium rounded-xl text-sm transition shadow-xs">
                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    <span>New Project</span>
                </a>
                <a href="video-upload" class="inline-flex items-center space-x-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white font-medium rounded-xl text-sm transition shadow-md shadow-brand-600/20">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                    <span>Upload Video</span>
                </a>
            <?php else: ?>
                <a href="shares" class="inline-flex items-center space-x-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white font-medium rounded-xl text-sm transition shadow-md shadow-brand-600/20">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path></svg>
                    <span>Shares Audit</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($pendingAccessRequests)): ?>
        <!-- Pending Access Requests Notification Card -->
        <div class="mb-8 bg-white rounded-2xl border border-amber-200/90 shadow-xs overflow-hidden" id="pendingRequestsContainer">
            <!-- Header Bar -->
            <div class="p-4 sm:px-5 sm:py-4 bg-gradient-to-r from-amber-500/10 via-amber-500/5 to-transparent border-b border-amber-200/60 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-center space-x-3">
                    <div class="w-9 h-9 rounded-xl bg-amber-500 text-white flex items-center justify-center font-bold shadow-xs shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                    </div>
                    <div>
                        <h2 class="text-sm sm:text-base font-bold text-slate-900 leading-tight">Pending Access Requests</h2>
                        <p class="text-xs text-slate-500 mt-0.5">Users requesting access permission to private pitch content</p>
                    </div>
                </div>
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-extrabold bg-amber-100 text-amber-800 border border-amber-200/80 shrink-0 self-start sm:self-auto" id="pendingCountBadge">
                    <?= count($pendingAccessRequests) ?> Request<?= count($pendingAccessRequests) > 1 ? 's' : '' ?>
                </span>
            </div>
            
            <!-- List Body -->
            <div class="divide-y divide-slate-100">
                <?php foreach ($pendingAccessRequests as $req): ?>
                    <div class="p-4 sm:px-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3.5 sm:gap-4 transition hover:bg-amber-50/20" id="request-row-<?= (int)$req['id'] ?>">
                        <!-- Left Info -->
                        <div class="flex items-start space-x-3 min-w-0 flex-1">
                            <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-full bg-amber-100 text-amber-800 font-bold flex items-center justify-center shrink-0 uppercase text-xs sm:text-sm border border-amber-200/80 shadow-2xs">
                                <?= substr($req['email'], 0, 1) ?>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
                                    <span class="font-bold text-slate-900 text-xs sm:text-sm break-all"><?= htmlspecialchars($req['email']) ?></span>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] sm:text-[11px] font-medium bg-slate-100 text-slate-600 border border-slate-200/60">
                                        <?= getRelativeTimeStr($req['requested_at']) ?>
                                    </span>
                                </div>
                                <p class="text-xs text-slate-600 mt-1 flex flex-wrap items-center gap-1 leading-snug">
                                    <span>Requested access to</span>
                                    <strong class="text-slate-800 font-semibold bg-slate-100/80 px-1.5 py-0.5 rounded border border-slate-200/50 break-all">
                                        <?= htmlspecialchars($req['project_title']) ?>
                                    </strong>
                                    <?php if (!empty($req['video_title'])): ?>
                                        <span class="text-slate-400">&rsaquo;</span>
                                        <span class="text-indigo-600 font-medium bg-indigo-50 px-1.5 py-0.5 rounded border border-indigo-100 break-all">
                                            <?= htmlspecialchars($req['video_title']) ?>
                                        </span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        
                        <!-- Actions Row / Grid -->
                        <div class="grid grid-cols-2 gap-2 w-full sm:w-auto sm:flex sm:items-center shrink-0 pt-1 sm:pt-0">
                            <button onclick="handleRequestAction(<?= (int)$req['id'] ?>, 'approve')" class="w-full sm:w-auto justify-center px-3.5 py-2 sm:py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs rounded-xl transition shadow-xs flex items-center space-x-1.5 active:scale-95 cursor-pointer">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                <span>Approve</span>
                            </button>
                            <button onclick="handleRequestAction(<?= (int)$req['id'] ?>, 'reject')" class="w-full sm:w-auto justify-center px-3.5 py-2 sm:py-1.5 bg-slate-100 hover:bg-rose-50 text-slate-600 hover:text-rose-600 border border-slate-200 font-semibold text-xs rounded-xl transition flex items-center space-x-1.5 active:scale-95 cursor-pointer">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                <span>Decline</span>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        function handleRequestAction(requestId, action) {
            const row = document.getElementById('request-row-' + requestId);
            if (!row) return;

            fetch('<?= getBaseUrl() ?>/ajax/manage_request.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: action, request_id: requestId })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    row.style.transition = 'all 0.3s ease';
                    row.style.opacity = '0';
                    row.style.height = '0';
                    row.style.padding = '0';
                    setTimeout(() => {
                        row.remove();
                        const container = document.getElementById('pendingRequestsContainer');
                        const remaining = container ? container.querySelectorAll('[id^="request-row-"]').length : 0;
                        if (remaining === 0 && container) {
                            container.remove();
                        } else {
                            const badge = document.getElementById('pendingCountBadge');
                            if (badge) badge.innerText = remaining + ' Request' + (remaining > 1 ? 's' : '');
                        }
                    }, 300);
                } else {
                    alert(data.error || 'Failed to process request.');
                }
            })
            .catch(err => {
                alert('An error occurred. Please try again.');
            });
        }
        </script>
    <?php endif; ?>

    <!-- Summary Metrics Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <!-- Card 1 -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase text-slate-400 tracking-wider">Total Projects</span>
                <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
                </div>
            </div>
            <div class="mt-4">
                <span class="text-2xl font-bold text-slate-900"><?= number_format($totalProjects) ?></span>
                <span class="text-xs text-slate-500 ml-2">Active Categories</span>
            </div>
        </div>

        <!-- Card 2 -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase text-slate-400 tracking-wider">Private Videos</span>
                <div class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                </div>
            </div>
            <div class="mt-4">
                <span class="text-2xl font-bold text-slate-900"><?= number_format($totalVideos) ?></span>
                <span class="text-xs text-slate-500 ml-2">MP4 Uploads</span>
            </div>
        </div>

        <!-- Card 3 -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase text-slate-400 tracking-wider">Viewing Sessions</span>
                <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                </div>
            </div>
            <div class="mt-4">
                <span class="text-2xl font-bold text-slate-900"><?= number_format($totalSessions) ?></span>
                <span class="text-xs text-slate-500 ml-2">Total Plays</span>
            </div>
        </div>

        <!-- Card 4 -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase text-slate-400 tracking-wider">Total Watch Time</span>
                <div class="w-9 h-9 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
            </div>
            <div class="mt-4">
                <span class="text-2xl font-bold text-slate-900"><?= formatSeconds($totalWatchSecs) ?></span>
                <span class="text-xs text-slate-500 ml-2">Tracked Time</span>
            </div>
        </div>
    </div>

    <!-- Top 5 Most Engaged Users Section -->
    <div class="mt-8 bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        
        <!-- Section Header -->
        <div class="px-5 py-4 border-b border-slate-100 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div class="flex items-center space-x-3">
                <div class="w-9 h-9 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0 border border-amber-100">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z"></path>
                    </svg>
                </div>
                <div>
                    <h2 class="text-base font-bold text-slate-900 tracking-tight">Top 5 Most Engaged Viewers</h2>
                    <p class="text-xs text-slate-500">Users ranked by total watch time & session activity</p>
                </div>
            </div>
            <span class="inline-flex items-center text-xs font-semibold text-amber-700 bg-amber-50 px-2.5 py-1 rounded-lg border border-amber-200/60 shrink-0 self-start sm:self-auto">
                Leaderboard
            </span>
        </div>

        <?php if (empty($topEngagedUsers)): ?>
            <div class="p-8 text-center text-slate-500">
                <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 mx-auto flex items-center justify-center mb-3">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                </div>
                <p class="font-semibold text-slate-700 text-sm">No viewer activity recorded yet</p>
                <p class="text-xs text-slate-400 mt-0.5">As recipients watch your shared pitch videos, engagement rankings will populate here.</p>
            </div>
        <?php else: ?>
            
            <!-- Desktop & Tablet Table View -->
            <div class="hidden sm:block overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-slate-200/80 bg-slate-50/60 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                            <th scope="col" class="py-3 px-5 text-center w-16">Rank</th>
                            <th scope="col" class="py-3 px-5">User Email</th>
                            <th scope="col" class="py-3 px-5">Total Watch Time</th>
                            <th scope="col" class="py-3 px-5">Videos Viewed</th>
                            <th scope="col" class="py-3 px-5">Total Sessions</th>
                            <th scope="col" class="py-3 px-5 text-right">Last Activity</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-sm">
                        <?php 
                        $rank = 1;
                        foreach ($topEngagedUsers as $user): 
                            $rankBadgeClasses = 'bg-slate-100 text-slate-600 border-slate-200';
                            if ($rank === 1) $rankBadgeClasses = 'bg-amber-100 text-amber-800 border-amber-300 font-extrabold shadow-2xs';
                            elseif ($rank === 2) $rankBadgeClasses = 'bg-slate-200 text-slate-800 border-slate-300 font-extrabold';
                            elseif ($rank === 3) $rankBadgeClasses = 'bg-amber-700/10 text-amber-900 border-amber-300 font-bold';
                        ?>
                            <tr class="hover:bg-slate-50/60 transition group">
                                <td class="py-3.5 px-5 text-center">
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full text-xs border <?= $rankBadgeClasses ?>">
                                        #<?= $rank ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-5 font-semibold text-slate-900">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-8 h-8 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-xs font-bold shrink-0 uppercase border border-brand-200/60">
                                            <?= substr($user['user_email'], 0, 1) ?>
                                        </div>
                                        <div class="min-w-0">
                                            <span class="block truncate max-w-[240px]" title="<?= htmlspecialchars($user['user_email']) ?>">
                                                <?= htmlspecialchars($user['user_email']) ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3.5 px-5 font-bold text-slate-900">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold bg-purple-50 text-purple-700 border border-purple-200/80">
                                        <svg class="w-3.5 h-3.5 mr-1 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                        <?= formatSeconds($user['total_watch_secs']) ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-5 text-slate-600 font-medium">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold bg-slate-100 text-slate-700">
                                        <?= (int)$user['video_count'] ?> video<?= (int)$user['video_count'] !== 1 ? 's' : '' ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-5 text-slate-600 font-medium">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold bg-slate-100 text-slate-700">
                                        <?= (int)$user['session_count'] ?> play<?= (int)$user['session_count'] !== 1 ? 's' : '' ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-5 text-right whitespace-nowrap text-xs text-slate-500">
                                    <?= getRelativeTimeStr($user['last_activity']) ?>
                                </td>
                            </tr>
                        <?php 
                        $rank++;
                        endforeach; 
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile View Card List -->
            <div class="block sm:hidden divide-y divide-slate-100">
                <?php 
                $rank = 1;
                foreach ($topEngagedUsers as $user): 
                    $rankBadgeClasses = 'bg-slate-100 text-slate-600 border-slate-200';
                    if ($rank === 1) $rankBadgeClasses = 'bg-amber-100 text-amber-800 border-amber-300 font-extrabold';
                    elseif ($rank === 2) $rankBadgeClasses = 'bg-slate-200 text-slate-800 border-slate-300 font-extrabold';
                    elseif ($rank === 3) $rankBadgeClasses = 'bg-amber-700/10 text-amber-900 border-amber-300 font-bold';
                ?>
                    <div class="p-4 space-y-3">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center space-x-2.5 min-w-0">
                                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-[11px] border shrink-0 <?= $rankBadgeClasses ?>">
                                    #<?= $rank ?>
                                </span>
                                <div class="w-8 h-8 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-xs font-bold shrink-0 uppercase">
                                    <?= substr($user['user_email'], 0, 1) ?>
                                </div>
                                <span class="font-bold text-slate-900 text-xs truncate max-w-[180px]" title="<?= htmlspecialchars($user['user_email']) ?>">
                                    <?= htmlspecialchars($user['user_email']) ?>
                                </span>
                            </div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-extrabold bg-purple-50 text-purple-700 border border-purple-200 shrink-0">
                                <?= formatSeconds($user['total_watch_secs']) ?>
                            </span>
                        </div>

                        <div class="grid grid-cols-3 gap-2 bg-slate-50/80 rounded-xl p-2.5 text-center text-[11px] border border-slate-100">
                            <div>
                                <span class="block text-slate-400 font-medium">Videos</span>
                                <span class="font-bold text-slate-800"><?= (int)$user['video_count'] ?></span>
                            </div>
                            <div>
                                <span class="block text-slate-400 font-medium">Plays</span>
                                <span class="font-bold text-slate-800"><?= (int)$user['session_count'] ?></span>
                            </div>
                            <div>
                                <span class="block text-slate-400 font-medium">Last Active</span>
                                <span class="font-semibold text-slate-700"><?= getRelativeTimeStr($user['last_activity']) ?></span>
                            </div>
                        </div>
                    </div>
                <?php 
                $rank++;
                endforeach; 
                ?>
            </div>

        <?php endif; ?>

    </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
