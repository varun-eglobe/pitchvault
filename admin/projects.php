<?php
// admin/projects.php - Project Management (Create / Edit / Delete / Access Control)

require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();

$db = getDBConnection();
$message = '';
$error = '';

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    $projectId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $title = sanitize($_POST['title'] ?? '');
    $description = sanitize($_POST['description'] ?? '');

    if (isSalesAdmin()) {
        $error = 'Access denied: Sales role can only share projects or videos.';
    } elseif ($action === 'create') {
        if (!isMasterAdmin()) {
            $error = 'Only Master Admins can create new projects.';
        } elseif (empty($title)) {
            $error = 'Project title is required.';
        } else {
            $accessKey = 'p_' . bin2hex(random_bytes(8));
            $stmt = $db->prepare("INSERT INTO projects (title, description, access_key, access_type, created_at) VALUES (:title, :desc, :key, 'invited', NOW())");
            $stmt->execute(['title' => $title, 'desc' => $description, 'key' => $accessKey]);
            $message = 'Project created successfully!';
        }
    } elseif ($action === 'update') {
        if (!canAdminManageProject($projectId)) {
            $error = 'Access denied.';
        } elseif ($projectId > 0 && !empty($title)) {
            $stmt = $db->prepare("UPDATE projects SET title = :title, description = :desc, access_type = 'invited' WHERE id = :id");
            $stmt->execute(['title' => $title, 'desc' => $description, 'id' => $projectId]);
            $message = 'Project updated successfully!';
        }
    } elseif ($action === 'delete') {
        if (!canAdminManageProject($projectId)) {
            $error = 'Access denied.';
        } elseif ($projectId > 0) {
            $vStmt = $db->prepare("SELECT video_filename, thumbnail_filename FROM videos WHERE project_id = :pid");
            $vStmt->execute(['pid' => $projectId]);
            $projVideos = $vStmt->fetchAll();
            foreach ($projVideos as $pV) {
                if (!empty($pV['video_filename'])) {
                    @unlink(__DIR__ . '/../uploads/videos/' . $pV['video_filename']);
                }
                if (!empty($pV['thumbnail_filename'])) {
                    @unlink(__DIR__ . '/../uploads/thumbnails/' . $pV['thumbnail_filename']);
                }
            }
            $stmt = $db->prepare("DELETE FROM projects WHERE id = :id");
            $stmt->execute(['id' => $projectId]);
            $message = 'Project and all associated videos deleted.';
        }
    }
}

// Fetch all projects with video count and granted access count
$isMaster = isMasterAdmin();
$isSales = isSalesAdmin();

if ($isMaster || ($isSales && empty(getEditorProjectIds($_SESSION['admin_id'])))) {
    $whereClause = "";
} else {
    $assignedProjectIds = getEditorProjectIds($_SESSION['admin_id']);
    $projIdsList = empty($assignedProjectIds) ? '0' : implode(',', array_map('intval', $assignedProjectIds));
    $whereClause = "WHERE p.id IN ($projIdsList)";
}

$currentAdminId = $_SESSION['admin_id'] ?? 0;
$paJoin = "LEFT JOIN project_access pa ON p.id = pa.project_id";
if ($isSales) {
    $paJoin = "LEFT JOIN project_access pa ON p.id = pa.project_id AND pa.granted_by_admin_id = " . (int)$currentAdminId;
}

$projects = $db->query("
    SELECT p.*, 
           COUNT(DISTINCT v.id) as video_count,
           COUNT(DISTINCT pa.id) as access_count
    FROM projects p 
    LEFT JOIN videos v ON p.id = v.project_id 
    $paJoin
    $whereClause
    GROUP BY p.id 
    ORDER BY p.created_at DESC
")->fetchAll();

if ($isSales) {
    $recentStmt = $db->prepare("
        SELECT s.*, v.title as video_title, v.access_key, v.duration
        FROM video_sessions s
        JOIN videos v ON s.video_id = v.id
        WHERE s.user_email IS NOT NULL AND TRIM(s.user_email) != ''
          AND LOWER(s.user_email) NOT IN (SELECT LOWER(email) FROM admins)
          AND LOWER(s.user_email) IN (
            SELECT LOWER(email) FROM project_access WHERE granted_by_admin_id = :aid1
            UNION
            SELECT LOWER(email) FROM video_access WHERE granted_by_admin_id = :aid2
        )
        ORDER BY s.last_activity DESC LIMIT 6
    ");
    $recentStmt->execute(['aid1' => $currentAdminId, 'aid2' => $currentAdminId]);
    $recentSessions = $recentStmt->fetchAll();
} elseif ($isMaster) {
    $recentSessions = $db->query("
        SELECT s.*, v.title as video_title, v.access_key, v.duration
        FROM video_sessions s
        JOIN videos v ON s.video_id = v.id
        WHERE s.user_email IS NOT NULL AND TRIM(s.user_email) != ''
          AND LOWER(s.user_email) NOT IN (SELECT LOWER(email) FROM admins)
        ORDER BY s.last_activity DESC LIMIT 6
    ")->fetchAll();
} else {
    $assignedProjectIds = getEditorProjectIds($currentAdminId);
    $projIdsList = empty($assignedProjectIds) ? '0' : implode(',', array_map('intval', $assignedProjectIds));
    $recentSessions = $db->query("
        SELECT s.*, v.title as video_title, v.access_key, v.duration
        FROM video_sessions s
        JOIN videos v ON s.video_id = v.id
        WHERE s.user_email IS NOT NULL AND TRIM(s.user_email) != ''
          AND LOWER(s.user_email) NOT IN (SELECT LOWER(email) FROM admins)
          AND v.project_id IN ($projIdsList)
        ORDER BY s.last_activity DESC LIMIT 6
    ")->fetchAll();
}

$pageTitle = "Manage Projects - PitchVault";
include __DIR__ . '/../includes/header.php';
?>

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 w-full">

    <!-- Top Action Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 sm:mb-8">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Project Management</h1>
            <p class="text-xs sm:text-sm text-slate-500 mt-1">Organize pitch videos into discrete project categories and manage email access permissions.</p>
        </div>
        <?php if (isMasterAdmin()): ?>
        <button onclick="openCreateModal()" class="w-full sm:w-auto inline-flex items-center justify-center space-x-2 px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white font-medium rounded-xl text-sm transition shadow-md shadow-brand-600/20 shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            <span>New Project</span>
        </button>
        <?php endif; ?>
    </div>

    <?php if ($message): ?>
        <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200/90 text-emerald-800 text-xs sm:text-sm rounded-2xl flex items-center space-x-2.5 shadow-xs">
            <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            <span class="font-medium"><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="mb-6 p-4 bg-rose-50 border border-rose-200/90 text-rose-800 text-xs sm:text-sm rounded-2xl flex items-center space-x-2.5 shadow-xs">
            <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <span class="font-medium"><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Projects List -->
    <div class="space-y-4 sm:space-y-5">
        <?php if (empty($projects)): ?>
            <div class="bg-white rounded-3xl border border-slate-200/90 p-8 sm:p-12 text-center shadow-xs">
                <div class="w-14 h-14 bg-slate-100 text-slate-400 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
                </div>
                <h3 class="text-base sm:text-lg font-bold text-slate-900">No Projects Found</h3>
                <p class="text-xs sm:text-sm text-slate-500 mt-1 max-w-sm mx-auto">Get started by creating your first project category to organize your pitch videos.</p>
                <?php if (isMasterAdmin()): ?>
                    <button onclick="openCreateModal()" class="mt-5 inline-flex items-center space-x-2 px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold rounded-xl transition shadow-md shadow-brand-600/20">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        <span>Create New Project</span>
                    </button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($projects as $proj): ?>
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-md transition-all duration-300 p-5 sm:p-6 group">
                    <!-- Project Info -->
                    <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-3 sm:gap-4">
                        <div class="flex items-start space-x-3 sm:space-x-4 min-w-0">
                            <div class="shrink-0 text-brand-600 mt-0.5">
                                <svg class="w-6 h-6 sm:w-7 sm:h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center flex-wrap gap-2">
                                    <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-brand-600 transition leading-snug">
                                        <a href="<?= getProjectUrl($proj) ?>" class="hover:underline">
                                            <?= htmlspecialchars($proj['title']) ?>
                                        </a>
                                    </h3>
                                    <span class="inline-flex items-center px-2 py-0.5 bg-slate-100 text-slate-600 rounded-md text-[11px] font-semibold">
                                        <?= $proj['video_count'] ?> Video<?= $proj['video_count'] != 1 ? 's' : '' ?>
                                    </span>
                                    <span class="inline-flex items-center px-2 py-0.5 bg-indigo-50 text-indigo-700 rounded-md text-[11px] font-semibold border border-indigo-200/80">
                                        <?= $proj['access_count'] ?> Allowed Email<?= $proj['access_count'] != 1 ? 's' : '' ?>
                                    </span>
                                </div>
                                <?php if (!empty($proj['description'])): ?>
                                    <p class="text-xs sm:text-sm text-slate-500 mt-1.5 line-clamp-2 leading-relaxed">
                                        <?= htmlspecialchars($proj['description']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Date stamp on desktop -->
                        <div class="hidden lg:block text-right shrink-0">
                            <span class="text-xs text-slate-400 font-medium">Created <?= date('M d, Y', strtotime($proj['created_at'])) ?></span>
                        </div>
                    </div>

                    <!-- Divider & Actions Bar -->
                    <div class="mt-4 pt-3.5 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <span class="text-[11px] text-slate-400 font-medium lg:hidden">Created <?= date('M d, Y', strtotime($proj['created_at'])) ?></span>
                        
                        <!-- Actions Grid: 2 columns on mobile, row on tablet/desktop -->
                        <div class="grid grid-cols-2 sm:flex sm:flex-wrap items-center gap-2 w-full lg:w-auto justify-start sm:justify-end">
                            <!-- View Project Videos (Primary High-Priority Action) -->
                            <a href="<?= getProjectUrl($proj) ?>" 
                               class="inline-flex items-center justify-center space-x-1.5 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white font-bold rounded-xl text-xs sm:text-sm transition shadow-md shadow-brand-600/20 active:scale-[0.98]">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <span>Videos</span>
                            </a>

                            <?php if (!isSalesAdmin()): ?>
                                <!-- Add Video -->
                                <a href="video-upload?project_id=<?= $proj['id'] ?>" 
                                   class="inline-flex items-center justify-center space-x-1.5 px-3 py-2 bg-brand-50 hover:bg-brand-100 text-brand-700 font-semibold rounded-xl text-xs transition border border-brand-200/80">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                                    <span>Add Video</span>
                                </a>
                            <?php endif; ?>

                            <!-- Share Access -->
                            <button onclick='openShareModal(<?= json_encode($proj) ?>)' 
                                    class="inline-flex items-center justify-center space-x-1.5 px-3 py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-semibold rounded-xl text-xs transition border border-indigo-200/80">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                                <span>Share (<?= (int)$proj['access_count'] ?>)</span>
                            </button>

                            <!-- Copy Link -->
                            <button onclick="copyProjectLink('<?= getProjectUrl($proj) ?>')" 
                                    class="inline-flex items-center justify-center space-x-1.5 px-3 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-semibold rounded-xl text-xs transition shadow-2xs">
                                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path></svg>
                                <span>Copy Link</span>
                            </button>

                            <?php if (!isSalesAdmin()): ?>
                                <!-- Edit Project -->
                                <button onclick='openEditModal(<?= json_encode($proj) ?>)' 
                                        class="inline-flex items-center justify-center space-x-1.5 px-3 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-semibold rounded-xl text-xs transition shadow-2xs">
                                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                    <span>Edit</span>
                                </button>

                                <!-- Delete Project -->
                                <form method="POST" action="projects" onsubmit="return confirm('Delete project & all its videos?');" class="inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $proj['id'] ?>">
                                    <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center space-x-1.5 px-3 py-2 bg-rose-50 border border-rose-200/80 hover:bg-rose-100 text-rose-700 font-semibold rounded-xl text-xs transition">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                        <span>Delete</span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <!-- Recent Viewer Activity Section -->
    <div class="mt-8 sm:mt-10 bg-white rounded-2xl border border-slate-200/80 p-5 sm:p-6 shadow-xs">
        <h3 class="text-base font-bold text-slate-900 mb-4">Recent Viewer Sessions</h3>
        <?php if (empty($recentSessions)): ?>
            <p class="text-xs text-slate-400">No viewer sessions logged yet. Share a video to start tracking.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
                            <th class="pb-3">Viewer Email</th>
                            <th class="pb-3">Video Title</th>
                            <th class="pb-3">Started</th>
                            <th class="pb-3">Watch Time</th>
                            <th class="pb-3">Status</th>
                            <?php if (!isSalesAdmin()): ?>
                                <th class="pb-3 text-right">Action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs">
                        <?php foreach ($recentSessions as $sess): ?>
                            <tr class="hover:bg-slate-50/50">
                                <td class="py-3 font-semibold text-slate-900"><?= htmlspecialchars($sess['user_email']) ?></td>
                                <td class="py-3 text-slate-700"><?= htmlspecialchars($sess['video_title']) ?></td>
                                <td class="py-3 text-slate-500"><?= date('M d, H:i', strtotime($sess['started_at'])) ?></td>
                                <td class="py-3 text-slate-700 font-mono"><?= formatSeconds($sess['total_watch_time']) ?></td>
                                <td class="py-3">
                                    <?php 
                                        $isSessCompleted = ($sess['completed'] == 1) && (empty($sess['duration']) || $sess['last_position'] >= ($sess['duration'] - 5));
                                        if ($isSessCompleted): 
                                    ?>
                                        <span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 rounded text-[10px] font-semibold border border-emerald-200">Completed</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 bg-amber-50 text-amber-700 rounded text-[10px] font-semibold border border-amber-200">In Progress</span>
                                    <?php endif; ?>
                                </td>
                                <?php if (!isSalesAdmin()): ?>
                                    <td class="py-3 text-right">
                                        <a href="analytics?id=<?= $sess['video_id'] ?>" class="text-brand-600 font-semibold hover:underline">View Log</a>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Share Project Access Modal -->
<!-- Manage Project Access Modal -->
<div id="shareAccessModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl max-w-3xl sm:max-w-4xl w-full border border-slate-200 overflow-hidden my-auto max-h-[90vh] flex flex-col transform transition-all">
        <!-- Modal Header -->
        <div class="px-5 sm:px-6 py-4 border-b border-slate-100 flex items-center justify-between shrink-0 bg-slate-50/50">
            <div class="flex items-center space-x-2.5 min-w-0 pr-2">
                <div class="w-8 h-8 bg-brand-50 text-brand-600 rounded-xl flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                </div>
                <h3 id="shareModalProjectTitle" class="font-bold text-slate-900 text-base sm:text-lg truncate">Manage Project Access</h3>
            </div>
            <button onclick="closeShareModal()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-xl hover:bg-slate-100 transition shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <!-- 2-Column Grid Body -->
        <div class="p-6 grid grid-cols-1 md:grid-cols-12 gap-6 overflow-y-auto">
            <!-- Left Column: Grant Access & Share Link -->
            <div class="md:col-span-5 space-y-6 flex flex-col justify-between">
                <div>
                    <div class="mb-5">
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Grant Access to Email</label>
                        <div class="space-y-2">
                            <input type="email" id="shareEmailInput" placeholder="investor@partner.com" 
                                   class="w-full px-3.5 py-2.5 bg-slate-50/50 border border-slate-300 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition">
                            <button id="shareGrantBtn" class="w-full px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold rounded-xl transition shrink-0 shadow-xs flex items-center justify-center space-x-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                                <span>Grant Access</span>
                            </button>
                        </div>
                        <p class="text-[11px] text-slate-400 mt-1.5 leading-normal">Granted viewers will receive full access to all current and future videos in this project.</p>
                    </div>

                    <div class="pt-4 border-t border-slate-100">
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Project Share Link</label>
                        <div class="flex items-center space-x-2 p-1.5 bg-slate-50 border border-slate-200 rounded-xl">
                            <input type="text" id="shareUrlInput" readonly value="" class="flex-1 bg-transparent border-0 text-xs text-slate-600 focus:ring-0 px-2 font-mono truncate">
                            <button onclick="copyProjectLink(document.getElementById('shareUrlInput').value)" class="px-3.5 py-2 bg-white border border-slate-200 hover:bg-slate-100 text-xs font-semibold text-slate-700 rounded-lg transition shadow-2xs shrink-0">
                                Copy Link
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Allowed Emails List -->
            <div class="md:col-span-7 flex flex-col min-h-0 md:border-l md:border-slate-100 md:pl-6">
                <div class="flex items-center justify-between mb-3">
                    <h4 class="text-xs font-bold text-slate-700 uppercase tracking-wider">People with project access</h4>
                </div>
                <div id="accessListModalContainer" class="space-y-2 max-h-80 sm:max-h-[380px] overflow-y-auto pr-1 flex-1">
                    <!-- Populated dynamically -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Copy Toast Notification -->
<div id="toast" class="fixed bottom-6 right-6 z-50 hidden bg-slate-900 text-white px-4 py-3 rounded-xl shadow-2xl border border-slate-800 text-sm flex items-center space-x-2">
    <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
    <span id="toastMsg">Project link copied!</span>
</div>

<!-- Create / Edit Project Modal -->
<div id="projectModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl max-w-md w-full border border-slate-200 overflow-hidden my-auto max-h-[90vh] flex flex-col">
        <div class="px-5 sm:px-6 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
            <h3 id="modalTitle" class="font-bold text-slate-900 text-base sm:text-lg">Create New Project</h3>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-xl hover:bg-slate-100 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <form method="POST" action="projects" class="p-5 sm:p-6 space-y-4 overflow-y-auto">
            <input type="hidden" id="formAction" name="action" value="create">
            <input type="hidden" id="projectId" name="id" value="0">

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Project Title <span class="text-rose-500">*</span></label>
                <input type="text" id="projectTitle" name="title" required placeholder="e.g. Series A Pitch Decks"
                       class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Description</label>
                <textarea id="projectDesc" name="description" rows="3" placeholder="Brief summary of videos in this project..."
                          class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition"></textarea>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Folder Access Control</label>
                <div class="bg-indigo-50/60 p-3.5 rounded-xl border border-indigo-100 flex items-center space-x-3 text-indigo-900">
                    <div class="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    </div>
                    <div>
                        <span class="block text-xs font-bold text-slate-900">OTP Verified Email Access</span>
                        <span class="block text-[11px] text-slate-600">Viewers must enter their email address and verify with a 6-digit OTP code to view videos.</span>
                    </div>
                </div>
            </div>

            <div class="pt-3 border-t border-slate-100 flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                <button type="button" onclick="closeModal()" class="w-full sm:w-auto px-4 py-2.5 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 rounded-xl text-sm font-semibold transition">Cancel</button>
                <button type="submit" class="w-full sm:w-auto px-5 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-semibold shadow-md shadow-brand-600/20 transition">Save Project</button>
            </div>
        </form>
    </div>
</div>

<script>
const projectModal = document.getElementById('projectModal');
const modalTitle = document.getElementById('modalTitle');
const formAction = document.getElementById('formAction');
const projectId = document.getElementById('projectId');
const projectTitle = document.getElementById('projectTitle');
const projectDesc = document.getElementById('projectDesc');

// Share modal variables
const shareAccessModal = document.getElementById('shareAccessModal');
const shareModalProjectTitle = document.getElementById('shareModalProjectTitle');
const shareEmailInput = document.getElementById('shareEmailInput');
const shareGrantBtn = document.getElementById('shareGrantBtn');
const shareUrlInput = document.getElementById('shareUrlInput');
const accessListModalContainer = document.getElementById('accessListModalContainer');

let currentShareProjectId = 0;

function copyProjectLink(url) {
    copyToClipboard(url);
    const toast = document.getElementById('toast');
    if (toast) {
        toast.classList.remove('hidden');
        setTimeout(() => toast.classList.add('hidden'), 3000);
    }
}

function openCreateModal() {
    modalTitle.textContent = 'Create New Project';
    formAction.value = 'create';
    projectId.value = '0';
    projectTitle.value = '';
    projectDesc.value = '';
    projectModal.classList.remove('hidden');
}

function openEditModal(proj) {
    modalTitle.textContent = 'Edit Project';
    formAction.value = 'update';
    projectId.value = proj.id;
    projectTitle.value = proj.title;
    projectDesc.value = proj.description || '';
    projectModal.classList.remove('hidden');
}

function closeModal() {
    projectModal.classList.add('hidden');
}

let currentShareProjectKey = '';

function openShareModal(proj) {
    currentShareProjectId = proj.id;
    currentShareProjectKey = proj.access_key ? proj.access_key : proj.id;
    shareModalProjectTitle.textContent = 'Share "' + proj.title + '"';
    shareUrlInput.value = '<?= getBaseUrl() ?>/project?id=' + currentShareProjectKey;
    shareEmailInput.value = '';
    loadProjectAccessList(proj.id);
    shareAccessModal.classList.remove('hidden');
}

function closeShareModal() {
    shareAccessModal.classList.add('hidden');
}

function loadProjectAccessList(pid) {
    fetch('<?= getBaseUrl() ?>/ajax/share_project_access.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'list', project_id: pid })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            renderModalAccessList(data.access_list);
        }
    });
}

if (shareGrantBtn) {
    shareGrantBtn.addEventListener('click', () => {
        const email = shareEmailInput.value.trim();
        if (!email || currentShareProjectId === 0) return;

        fetch('<?= getBaseUrl() ?>/ajax/share_project_access.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'add',
                project_id: currentShareProjectId,
                email: email
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                shareEmailInput.value = '';
                renderModalAccessList(data.access_list);
                copyProjectLink('<?= getBaseUrl() ?>/project?id=' + currentShareProjectKey);
            } else {
                alert(data.error || 'Failed to grant access');
            }
        });
    });
}

function removeModalProjectAccess(email) {
    if (!confirm('Remove project access for ' + email + '?')) return;
    fetch('<?= getBaseUrl() ?>/ajax/share_project_access.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'remove',
            project_id: currentShareProjectId,
            email: email
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            renderModalAccessList(data.access_list);
        }
    });
}

function renderModalAccessList(list) {
    if (!accessListModalContainer) return;
    if (!list || list.length === 0) {
        accessListModalContainer.innerHTML = '<p class="text-xs text-slate-400 py-3 text-center">No allowed emails granted access yet.</p>';
        return;
    }
    accessListModalContainer.innerHTML = list.map(item => {
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
                <button onclick="removeModalProjectAccess('${escapeHtml(item.email)}')" class="text-slate-400 hover:text-rose-600 p-1 text-xs font-medium shrink-0 ml-2">
                    Remove
                </button>
            </div>
        `;
    }).join('');
}

<?php if (isset($_GET['action']) && $_GET['action'] === 'new'): ?>
openCreateModal();
<?php endif; ?>
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
