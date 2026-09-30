<?php
// admin/analytics.php - In-depth Video Analytics & Viewer Activity Timeline

require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();

if (isSalesAdmin()) {
    header("Location: " . getBaseUrl() . "/admin/shares?error=" . urlencode("Access denied: Sales role cannot view video analytics. You can track and manage share permissions in Shares."));
    exit;
}

$db = getDBConnection();

$videoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$selectedEmail = isset($_GET['email']) ? strtolower(trim(sanitize($_GET['email']))) : '';

// Fetch video list for dropdown selector
$isMaster = isMasterAdmin();
$assignedProjectIds = $isMaster ? [] : getEditorProjectIds($_SESSION['admin_id']);
$projIdsList = empty($assignedProjectIds) ? '0' : implode(',', array_map('intval', $assignedProjectIds));

$whereClause = $isMaster ? "" : "WHERE v.project_id IN ($projIdsList)";
$videos = $db->query("
    SELECT v.id, v.title, v.thumbnail_filename, v.duration, v.created_at, p.title as project_title,
           (SELECT COUNT(*) FROM video_sessions WHERE video_id = v.id) as session_count
    FROM videos v 
    JOIN projects p ON v.project_id = p.id 
    $whereClause 
    ORDER BY v.created_at DESC
")->fetchAll();

if ($videoId <= 0 && !empty($videos)) {
    $videoId = (int)$videos[0]['id'];
}

// Fetch current video metadata
$video = null;
if ($videoId > 0) {
    $stmt = $db->prepare("SELECT v.*, p.title as project_title FROM videos v JOIN projects p ON v.project_id = p.id WHERE v.id = :id LIMIT 1");
    $stmt->execute(['id' => $videoId]);
    $video = $stmt->fetch();
    
    // Validate project access
    if ($video && !canAdminManageProject($video['project_id'])) {
        $video = null;
    }
}

$userAggregates = [];
$sessions = [];
$totalWatchSecsAll = 0;
$avgCompletionRate = 0;

if ($video) {
    // 1. Aggregate metrics per user email
    $sqlAgg = "
        SELECT 
            user_email,
            COUNT(id) as total_sessions,
            SUM(total_watch_time) as combined_watch_time,
            MAX(last_position) as max_position,
            MAX(last_activity) as last_activity,
            SUM(CASE WHEN completed = 1 AND (last_position >= (SELECT duration FROM videos WHERE id = video_id) - 5) THEN 1 ELSE 0 END) as completed_sessions
        FROM video_sessions 
        WHERE video_id = :vid 
          AND user_email IS NOT NULL AND TRIM(user_email) != ''
          AND LOWER(user_email) NOT IN (SELECT LOWER(email) FROM admins)
    ";
    $paramsAgg = ['vid' => $videoId];
    if (!empty($selectedEmail)) {
        $sqlAgg .= " AND LOWER(user_email) = LOWER(:email)";
        $paramsAgg['email'] = $selectedEmail;
    }
    $sqlAgg .= " GROUP BY user_email ORDER BY last_activity DESC";

    $stmtAgg = $db->prepare($sqlAgg);
    $stmtAgg->execute($paramsAgg);
    $userAggregates = $stmtAgg->fetchAll();

    $dur = max(1, (int)$video['duration']);
    foreach ($userAggregates as &$user) {
        $user['watch_percentage'] = min(100, round(($user['combined_watch_time'] / $dur) * 100, 1));
        $totalWatchSecsAll += $user['combined_watch_time'];
    }
    unset($user);

    // 2. Fetch full session list with event timeline items
    $sqlSess = "
        SELECT s.* 
        FROM video_sessions s 
        WHERE s.video_id = :vid 
          AND s.user_email IS NOT NULL AND TRIM(s.user_email) != ''
          AND LOWER(s.user_email) NOT IN (SELECT LOWER(email) FROM admins)
    ";
    $paramsSess = ['vid' => $videoId];
    if (!empty($selectedEmail)) {
        $sqlSess .= " AND LOWER(s.user_email) = LOWER(:email)";
        $paramsSess['email'] = $selectedEmail;
    }
    $sqlSess .= " ORDER BY s.started_at DESC";

    $stmtSess = $db->prepare($sqlSess);
    $stmtSess->execute($paramsSess);
    $sessions = $stmtSess->fetchAll();

    // Calculate completion percentage across sessions
    if (count($sessions) > 0) {
        $completedCount = count(array_filter($sessions, fn($s) => $s['completed'] == 1));
        $avgCompletionRate = round(($completedCount / count($sessions)) * 100);
    }

    // Fetch timeline events for each session (filtering out progress pings)
    foreach ($sessions as &$sess) {
        $stmtEv = $db->prepare("SELECT * FROM video_events WHERE session_id = :sid AND event_type != 'progress' ORDER BY created_at ASC");
        $stmtEv->execute(['sid' => $sess['id']]);
        $sess['events'] = $stmtEv->fetchAll();
    }
    unset($sess);
}

$pageTitle = "Video Analytics - PitchVault";
include __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full">

    <!-- Top Header & Selector Bar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
        <div>
            <a href="index" class="text-xs font-semibold text-slate-500 hover:text-slate-800 flex items-center space-x-1 mb-2">
                <span>&larr; Back to Dashboard</span>
            </a>
            <div class="flex items-center space-x-3">
                <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Analytics & Viewer Intelligence</h1>
                <?php if ($video): ?>
                    <span class="px-2.5 py-0.5 rounded-md text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200">
                        <?= htmlspecialchars($video['project_title']) ?>
                    </span>
                <?php endif; ?>
            </div>
            <p class="text-sm text-slate-500 mt-1">Key viewing milestones, watch times, and session timelines.</p>
        </div>

        <!-- Custom Video Selector Dropdown with Thumbnail Cover Image -->
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative inline-block text-left" id="videoDropdownContainer">
                <!-- Dropdown Trigger Button -->
                <button type="button" id="videoDropdownBtn" onclick="toggleVideoDropdown()" 
                        class="inline-flex items-center justify-between gap-3 px-3 py-1.5 bg-white border border-slate-300 hover:border-brand-500 rounded-2xl text-xs font-semibold text-slate-800 shadow-xs transition-all w-full sm:w-auto">
                    <?php 
                    $currentVid = null;
                    foreach ($videos as $v) {
                        if ($v['id'] == $videoId) { $currentVid = $v; break; }
                    }
                    ?>
                    <?php if ($currentVid): ?>
                        <div class="flex items-center space-x-2.5 min-w-0">
                            <div class="w-10 h-7 aspect-video rounded-md overflow-hidden bg-slate-900 shrink-0 border border-slate-200">
                                <?php if (!empty($currentVid['thumbnail_filename'])): ?>
                                    <img src="<?= getBaseUrl() ?>/uploads/thumbnails/<?= htmlspecialchars($currentVid['thumbnail_filename']) ?>" alt="" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <div class="w-full h-full flex items-center justify-center bg-slate-800 text-slate-500">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path></svg>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="text-left min-w-0">
                                <span class="block text-xs font-bold text-slate-900 truncate max-w-[180px] sm:max-w-[220px]">
                                    <?= htmlspecialchars($currentVid['title']) ?>
                                </span>
                                <span class="text-[10px] text-slate-500 font-medium truncate block">
                                    <?= htmlspecialchars($currentVid['project_title']) ?>
                                </span>
                            </div>
                        </div>
                    <?php else: ?>
                        <span class="text-slate-500">Select Video...</span>
                    <?php endif; ?>
                    
                    <svg class="w-4 h-4 text-slate-400 shrink-0 ml-1 transform transition-transform duration-200" id="videoDropdownChevron" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>

                <!-- Dropdown Menu Box (Responsive position: left-aligned on mobile to prevent left screen clipping) -->
                <div id="videoDropdownMenu" class="hidden absolute left-0 md:left-auto md:right-0 mt-2 w-[calc(100vw-2.5rem)] max-w-sm sm:w-96 bg-white rounded-2xl border border-slate-200 shadow-2xl z-50 overflow-hidden transform transition-all">
                    <!-- Search Input -->
                    <div class="p-3 border-b border-slate-100 bg-slate-50/80">
                        <div class="relative">
                            <input type="text" id="videoSearchInput" onkeyup="filterVideoDropdownOptions()" placeholder="Search video title or project..." 
                                   class="w-full pl-9 pr-3.5 py-2 bg-white border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                            <svg class="w-4 h-4 text-slate-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                    </div>

                    <!-- Video Items List -->
                    <div class="max-h-80 overflow-y-auto p-1.5 space-y-1" id="videoOptionsList">
                        <?php foreach ($videos as $v): ?>
                            <?php
                            $isSelected = ($v['id'] == $videoId);
                            $optUrlParams = ['id' => $v['id']];
                            if (!empty($selectedEmail)) $optUrlParams['email'] = $selectedEmail;
                            $optUrl = 'analytics?' . http_build_query($optUrlParams);
                            ?>
                            <a href="<?= $optUrl ?>" 
                               class="video-option-item flex items-center justify-between p-2 rounded-xl transition <?= $isSelected ? 'bg-brand-50 border border-brand-200/80' : 'hover:bg-slate-50 border border-transparent' ?>"
                               data-search="<?= htmlspecialchars(strtolower($v['title'] . ' ' . $v['project_title'])) ?>">
                                
                                <div class="flex items-center space-x-3 min-w-0">
                                    <!-- Thumbnail Badge -->
                                    <div class="w-12 h-8 aspect-video rounded-lg overflow-hidden bg-slate-950 shrink-0 border border-slate-200 relative">
                                        <?php if (!empty($v['thumbnail_filename'])): ?>
                                            <img src="<?= getBaseUrl() ?>/uploads/thumbnails/<?= htmlspecialchars($v['thumbnail_filename']) ?>" alt="" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <div class="w-full h-full flex items-center justify-center bg-slate-900 text-slate-600">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path></svg>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Video Title & Project Tag -->
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold text-slate-900 truncate <?= $isSelected ? 'text-brand-700' : '' ?>">
                                            <?= htmlspecialchars($v['title']) ?>
                                        </p>
                                        <div class="flex items-center space-x-1.5 text-[10px] text-slate-500 font-medium mt-0.5">
                                            <span class="bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded font-semibold truncate max-w-[130px]"><?= htmlspecialchars($v['project_title']) ?></span>
                                            <span>&bull;</span>
                                            <span><?= (int)($v['session_count'] ?? 0) ?> plays</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Selected Indicator -->
                                <?php if ($isSelected): ?>
                                    <div class="w-5 h-5 rounded-full bg-brand-600 text-white flex items-center justify-center shrink-0 ml-2">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                                    </div>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                        
                        <p id="noVideoFoundMsg" class="hidden text-xs text-slate-400 text-center py-6">No matching videos found.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$video): ?>
        <div class="bg-white rounded-2xl p-12 text-center border border-slate-200">
            <p class="text-sm text-slate-500">No videos available to display analytics.</p>
        </div>
    <?php else: ?>

        <!-- Selected Email Active Filter Notification -->
        <?php if (!empty($selectedEmail)): ?>
            <div class="mb-6 p-4 bg-indigo-50 border border-indigo-200 rounded-xl flex items-center justify-between">
                <div class="flex items-center space-x-2 text-xs text-indigo-900 font-medium">
                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path></svg>
                    <span>Filtering analytics for viewer: <strong><?= htmlspecialchars($selectedEmail) ?></strong></span>
                </div>
                <a href="analytics?id=<?= $videoId ?>" class="text-xs font-semibold text-indigo-700 hover:text-indigo-900 underline">Clear Filter &rarr;</a>
            </div>
        <?php endif; ?>

        <!-- Key Metrics Cards Banner -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase text-slate-400 tracking-wider">Unique Viewers</span>
                    <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    </div>
                </div>
                <div class="mt-4">
                    <span class="text-2xl font-bold text-slate-900"><?= number_format(count($userAggregates)) ?></span>
                    <span class="text-xs text-slate-500 ml-2">Authorized Users</span>
                </div>
            </div>

            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase text-slate-400 tracking-wider">Total Sessions</span>
                    <div class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                    </div>
                </div>
                <div class="mt-4">
                    <span class="text-2xl font-bold text-slate-900"><?= number_format(count($sessions)) ?></span>
                    <span class="text-xs text-slate-500 ml-2">Viewing Log Entries</span>
                </div>
            </div>

            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase text-slate-400 tracking-wider">Total Watch Time</span>
                    <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                </div>
                <div class="mt-4">
                    <span class="text-2xl font-bold text-slate-900"><?= formatSeconds($totalWatchSecsAll) ?></span>
                    <span class="text-xs text-slate-500 ml-2">Video Duration: <?= formatSeconds($video['duration']) ?></span>
                </div>
            </div>

            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase text-slate-400 tracking-wider">Completion Rate</span>
                    <div class="w-9 h-9 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                </div>
                <div class="mt-4">
                    <span class="text-2xl font-bold text-slate-900"><?= $avgCompletionRate ?>%</span>
                    <span class="text-xs text-slate-500 ml-2">Full Playthroughs</span>
                </div>
            </div>
        </div>

        <!-- 1. Per-User Viewing Summary Table -->
        <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs mb-8">
            <h3 class="text-base font-bold text-slate-900 mb-4">Viewer Engagement Summary</h3>
            <?php if (empty($userAggregates)): ?>
                <p class="text-xs text-slate-400">No viewers have played this video yet.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-slate-100 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
                                <th class="pb-3">User Email</th>
                                <th class="pb-3">Sessions</th>
                                <th class="pb-3">Watch %</th>
                                <th class="pb-3">Total Watch Time</th>
                                <th class="pb-3">Last Position</th>
                                <th class="pb-3">Completion</th>
                                <th class="pb-3">Last Activity</th>
                                <th class="pb-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-xs">
                            <?php foreach ($userAggregates as $user): ?>
                                <tr class="hover:bg-slate-50/50">
                                    <td class="py-3 font-semibold text-slate-900"><?= htmlspecialchars($user['user_email']) ?></td>
                                    <td class="py-3 font-medium text-slate-700"><?= $user['total_sessions'] ?> session(s)</td>
                                    <td class="py-3">
                                        <div class="flex items-center space-x-2">
                                            <div class="w-20 bg-slate-100 h-2 rounded-full overflow-hidden">
                                                <div class="h-full rounded-full <?= $user['watch_percentage'] >= 90 ? 'bg-emerald-500' : ($user['watch_percentage'] >= 50 ? 'bg-brand-600' : 'bg-amber-500') ?>" 
                                                     style="width: <?= min(100, $user['watch_percentage']) ?>%"></div>
                                            </div>
                                            <span class="font-bold text-slate-800"><?= $user['watch_percentage'] ?>%</span>
                                        </div>
                                    </td>
                                    <td class="py-3 font-mono text-slate-700"><?= formatSeconds($user['combined_watch_time']) ?></td>
                                    <td class="py-3 text-slate-500 font-mono"><?= formatSeconds($user['max_position']) ?></td>
                                    <td class="py-3">
                                        <?php 
                                            $userIsCompleted = ($user['completed_sessions'] > 0) && ($video['duration'] <= 0 || $user['max_position'] >= ($video['duration'] - 5));
                                            if ($userIsCompleted): 
                                        ?>
                                            <span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 rounded text-[10px] font-semibold border border-emerald-200">Completed</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 bg-amber-50 text-amber-700 rounded text-[10px] font-semibold border border-amber-200">Incomplete</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 text-slate-500"><?= date('M d, H:i', strtotime($user['last_activity'])) ?></td>
                                    <td class="py-3 text-right">
                                        <?php if ($selectedEmail === strtolower($user['user_email'])): ?>
                                            <a href="analytics?id=<?= $videoId ?>" class="text-rose-600 font-semibold hover:underline">Clear Filter</a>
                                        <?php else: ?>
                                            <a href="analytics?id=<?= $videoId ?>&email=<?= urlencode($user['user_email']) ?>" class="text-brand-600 font-semibold hover:underline">Filter Email</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- 2. Clean High-Density Session History Table with Modal Action -->
        <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Session History & Milestone Log</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Click "View Key Events" on any session to open the milestone timeline modal.</p>
                </div>
                <span class="text-xs font-semibold px-2.5 py-1 bg-slate-100 text-slate-600 rounded-lg">
                    <?= count($sessions) ?> Session(s)
                </span>
            </div>

            <?php if (empty($sessions)): ?>
                <div class="p-8 text-center text-xs text-slate-400">
                    No sessions logged.
                </div>
            <?php else: ?>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-slate-100 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
                                <th class="pb-3">Session</th>
                                <th class="pb-3">Viewer Email</th>
                                <th class="pb-3">Started At</th>
                                <th class="pb-3">Watch Duration</th>
                                <th class="pb-3">Last Position</th>
                                <th class="pb-3">Status</th>
                                <th class="pb-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-xs">
                            <?php foreach ($sessions as $index => $sess): ?>
                                <tr class="hover:bg-slate-50/60 transition">
                                    <td class="py-3.5">
                                        <span class="w-7 h-7 rounded-lg bg-slate-900 text-white font-mono font-bold text-[11px] inline-flex items-center justify-center">
                                            S<?= count($sessions) - $index ?>
                                        </span>
                                    </td>
                                    <td class="py-3.5 font-semibold text-slate-900">
                                        <?= htmlspecialchars($sess['user_email']) ?>
                                    </td>
                                    <td class="py-3.5 text-slate-500">
                                        <?= date('M d, Y @ H:i:s', strtotime($sess['started_at'])) ?>
                                    </td>
                                    <td class="py-3.5 font-mono text-slate-800 font-semibold">
                                        <?= formatSeconds($sess['total_watch_time']) ?>
                                    </td>
                                    <td class="py-3.5 font-mono text-slate-500">
                                        <?= formatSeconds($sess['last_position']) ?>
                                    </td>
                                    <td class="py-3.5">
                                        <?php 
                                            $isSessCompleted = ($sess['completed'] == 1) && ($video['duration'] <= 0 || $sess['last_position'] >= ($video['duration'] - 5));
                                            if ($isSessCompleted): 
                                        ?>
                                            <span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 rounded text-[10px] font-semibold border border-emerald-200">Completed</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 bg-slate-100 text-slate-600 rounded text-[10px] font-semibold border border-slate-200">In Progress</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3.5 text-right">
                                        <button onclick='openEventsModal(<?= json_encode($sess), ", ", (count($sessions) - $index) ?>)' 
                                                class="inline-flex items-center space-x-1.5 px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs rounded-xl shadow-xs transition">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                            <span>View Key Events (<?= count($sess['events']) ?>)</span>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Milestone Events Modal -->
<div id="eventsModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-xl w-full border border-slate-200 overflow-hidden transform transition-all flex flex-col max-h-[85vh]">
        
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-900 text-white shrink-0">
            <div class="flex items-center space-x-3">
                <span id="modalSessionBadge" class="w-8 h-8 rounded-lg bg-brand-600 text-white font-bold text-xs flex items-center justify-center font-mono">
                    S1
                </span>
                <div>
                    <h3 id="modalUserEmail" class="font-bold text-white text-base">user@domain.com</h3>
                    <p id="modalSessionStarted" class="text-[11px] text-slate-400 mt-0.5">Started At: --</p>
                </div>
            </div>
            <button onclick="closeEventsModal()" class="text-slate-400 hover:text-white p-1.5 rounded-lg hover:bg-slate-800 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <!-- Session Overview Bar -->
        <div class="px-6 py-3 bg-slate-50 border-b border-slate-200/80 flex items-center justify-between text-xs text-slate-600 shrink-0">
            <div>Watch Duration: <strong id="modalWatchTime" class="font-mono text-slate-900">00:00</strong></div>
            <div>Last Position: <strong id="modalLastPos" class="font-mono text-slate-900">00:00</strong></div>
            <div>Status: <span id="modalStatusBadge" class="font-semibold"></span></div>
        </div>

        <!-- Timeline Events Content -->
        <div class="p-6 overflow-y-auto flex-1 space-y-4">
            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400">Milestone Event Timeline</h4>
            
            <div id="modalTimelineContainer" class="space-y-3 relative pl-6 before:absolute before:left-2.5 before:top-2 before:bottom-2 before:w-0.5 before:bg-slate-200">
                <!-- Javascript will populate timeline rows here -->
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="px-6 py-3.5 border-t border-slate-100 bg-slate-50 flex items-center justify-between shrink-0">
            <span class="text-[11px] text-slate-400">Events are recorded chronologically</span>
            <button onclick="closeEventsModal()" class="px-4 py-2 bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold rounded-xl transition shadow-xs">
                Close Timeline
            </button>
        </div>
    </div>
</div>

<script>
const eventsModal = document.getElementById('eventsModal');
const modalSessionBadge = document.getElementById('modalSessionBadge');
const modalUserEmail = document.getElementById('modalUserEmail');
const modalSessionStarted = document.getElementById('modalSessionStarted');
const modalWatchTime = document.getElementById('modalWatchTime');
const modalLastPos = document.getElementById('modalLastPos');
const modalStatusBadge = document.getElementById('modalStatusBadge');
const modalTimelineContainer = document.getElementById('modalTimelineContainer');

function formatSecs(secs) {
    secs = Math.max(0, Math.floor(secs || 0));
    const h = Math.floor(secs / 3600);
    const m = Math.floor((secs % 3600) / 60);
    const s = secs % 60;
    if (h > 0) {
        return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }
    return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
}

function openEventsModal(sess, sNum) {
    if (!sess) return;

    modalSessionBadge.textContent = 'S' + sNum;
    modalUserEmail.textContent = sess.user_email;
    modalSessionStarted.textContent = 'Started At: ' + sess.started_at;
    modalWatchTime.textContent = formatSecs(sess.total_watch_time);
    modalLastPos.textContent = formatSecs(sess.last_position);
    
    if (sess.completed == 1) {
        modalStatusBadge.className = 'px-2 py-0.5 bg-emerald-50 text-emerald-700 rounded text-[10px] font-semibold border border-emerald-200';
        modalStatusBadge.textContent = 'Completed';
    } else {
        modalStatusBadge.className = 'px-2 py-0.5 bg-slate-100 text-slate-600 rounded text-[10px] font-semibold border border-slate-200';
        modalStatusBadge.textContent = 'In Progress';
    }

    renderModalTimeline(sess.events || []);
    eventsModal.classList.remove('hidden');
}

function closeEventsModal() {
    eventsModal.classList.add('hidden');
}

function renderModalTimeline(events) {
    if (!modalTimelineContainer) return;
    if (events.length === 0) {
        modalTimelineContainer.innerHTML = '<p class="text-xs text-slate-400 py-2">No milestone events recorded for this session.</p>';
        return;
    }

    modalTimelineContainer.innerHTML = events.map(ev => {
        let dotColor = 'bg-brand-500';
        let badgeStyle = 'bg-brand-50 text-brand-700 border-brand-200';
        if (ev.event_type === 'play') {
            dotColor = 'bg-emerald-500';
            badgeStyle = 'bg-emerald-50 text-emerald-700 border-emerald-200';
        } else if (ev.event_type === 'pause') {
            dotColor = 'bg-amber-500';
            badgeStyle = 'bg-amber-50 text-amber-700 border-amber-200';
        } else if (ev.event_type === 'seek') {
            dotColor = 'bg-purple-500';
            badgeStyle = 'bg-purple-50 text-purple-700 border-purple-200';
        } else if (ev.event_type === 'ended') {
            dotColor = 'bg-rose-500';
            badgeStyle = 'bg-rose-50 text-rose-700 border-rose-200';
        }

        const timeStr = ev.created_at ? ev.created_at.substring(11, 19) : '--:--';
        let detailText = `Timestamp: <strong class="text-slate-900">${formatSecs(ev.current_position)}</strong>`;
        if (ev.event_type === 'seek') {
            const pPos = parseFloat(ev.prev_position);
            const cPos = parseFloat(ev.current_position);
            if (Math.abs(pPos - cPos) < 0.5) {
                detailText = `Seeked to <strong class="text-purple-700">${formatSecs(cPos)}</strong>`;
            } else {
                detailText = `Seeked: ${formatSecs(pPos)} &rarr; <strong class="text-purple-700">${formatSecs(cPos)}</strong>`;
            }
        }

        let deltaStr = '';
        if (parseFloat(ev.watch_time_delta) > 0) {
            deltaStr = `+${parseFloat(ev.watch_time_delta).toFixed(1)}s`;
        }

        return `
            <div class="relative flex items-center justify-between text-xs py-1.5">
                <div class="absolute -left-6 top-2 w-3 h-3 rounded-full border-2 border-white shadow-xs ${dotColor}"></div>
                
                <div class="flex items-center space-x-3">
                    <span class="font-mono text-slate-400 text-[11px] w-16 shrink-0">${timeStr}</span>
                    <span class="font-bold px-2.5 py-0.5 rounded text-[10px] uppercase tracking-wider border shrink-0 ${badgeStyle}">
                        ${ev.event_type.toUpperCase()}
                    </span>
                    <span class="font-mono text-slate-800 font-medium">
                        ${detailText}
                    </span>
                </div>

                <div class="text-right text-slate-400 font-mono text-[11px]">
                    ${deltaStr}
                </div>
            </div>
        `;
    }).join('');
}

// Custom Video Dropdown Logic
function toggleVideoDropdown() {
    const menu = document.getElementById('videoDropdownMenu');
    const chevron = document.getElementById('videoDropdownChevron');
    const searchInput = document.getElementById('videoSearchInput');
    
    if (menu) {
        const isHidden = menu.classList.contains('hidden');
        if (isHidden) {
            menu.classList.remove('hidden');
            if (chevron) chevron.classList.add('rotate-180');
            if (searchInput) {
                searchInput.value = '';
                filterVideoDropdownOptions();
                setTimeout(() => searchInput.focus(), 50);
            }
        } else {
            menu.classList.add('hidden');
            if (chevron) chevron.classList.remove('rotate-180');
        }
    }
}

function filterVideoDropdownOptions() {
    const q = (document.getElementById('videoSearchInput')?.value || '').toLowerCase().trim();
    const items = document.querySelectorAll('.video-option-item');
    let matchCount = 0;

    items.forEach(item => {
        const searchData = item.getAttribute('data-search') || '';
        if (searchData.includes(q)) {
            item.classList.remove('hidden');
            matchCount++;
        } else {
            item.classList.add('hidden');
        }
    });

    const noMsg = document.getElementById('noVideoFoundMsg');
    if (noMsg) {
        if (matchCount === 0) noMsg.classList.remove('hidden');
        else noMsg.classList.add('hidden');
    }
}

document.addEventListener('click', (e) => {
    const container = document.getElementById('videoDropdownContainer');
    const menu = document.getElementById('videoDropdownMenu');
    const chevron = document.getElementById('videoDropdownChevron');
    if (container && !container.contains(e.target) && menu && !menu.classList.contains('hidden')) {
        menu.classList.add('hidden');
        if (chevron) chevron.classList.remove('rotate-180');
    }
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const menu = document.getElementById('videoDropdownMenu');
        const chevron = document.getElementById('videoDropdownChevron');
        if (menu && !menu.classList.contains('hidden')) {
            menu.classList.add('hidden');
            if (chevron) chevron.classList.remove('rotate-180');
        }
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
