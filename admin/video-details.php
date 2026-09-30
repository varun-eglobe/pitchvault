<?php
// admin/video-details.php - Detailed Video Dashboard & Access Manager

require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();

$videoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($videoId <= 0) {
    header("Location: index");
    exit;
}

$db = getDBConnection();

// Fetch Video Details
$stmt = $db->prepare("
    SELECT v.*, p.title as project_title, p.access_key as project_access_key,
           (SELECT COUNT(*) FROM video_sessions WHERE video_id = v.id) as session_count,
           (SELECT COUNT(DISTINCT user_email) FROM video_sessions WHERE video_id = v.id) as viewer_count,
           (SELECT SUM(total_watch_time) FROM video_sessions WHERE video_id = v.id) as total_watch_secs
    FROM videos v 
    JOIN projects p ON v.project_id = p.id 
    WHERE v.id = :id LIMIT 1
");
$stmt->execute(['id' => $videoId]);
$video = $stmt->fetch();

if (!$video) {
    die("Video not found.");
}

// Access Control Check
if (!canAdminShareResource($video['project_id'])) {
    die("Access denied to this project.");
}

$successMessage = '';
$errorMessage = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (isSalesAdmin()) {
        $errorMessage = 'Access denied: Sales role cannot delete or move videos. You can only share projects and videos.';
    } elseif ($_POST['action'] === 'delete_video') {
        if (!empty($video['video_filename'])) {
            @unlink(__DIR__ . '/../uploads/videos/' . $video['video_filename']);
        }
        if (!empty($video['thumbnail_filename'])) {
            @unlink(__DIR__ . '/../uploads/thumbnails/' . $video['thumbnail_filename']);
        }
        $stmt = $db->prepare("DELETE FROM videos WHERE id = :id");
        $stmt->execute(['id' => $videoId]);
        header("Location: projects?msg=" . urlencode("Video deleted successfully."));
        exit;
    } elseif ($_POST['action'] === 'move_video') {
        $targetProjectId = (int)($_POST['target_project_id'] ?? 0);
        if ($targetProjectId <= 0) {
            $errorMessage = 'Please select a valid target project.';
        } elseif ($targetProjectId === (int)$video['project_id']) {
            $errorMessage = 'The video is already in this project.';
        } elseif (!canAdminManageProject($targetProjectId)) {
            $errorMessage = 'Access denied: You do not have permission to manage the target project.';
        } else {
            // Fetch target project details
            $stmtTgt = $db->prepare("SELECT id, title FROM projects WHERE id = :pid LIMIT 1");
            $stmtTgt->execute(['pid' => $targetProjectId]);
            $targetProject = $stmtTgt->fetch();

            if (!$targetProject) {
                $errorMessage = 'The selected target project does not exist.';
            } else {
                $stmtUpdate = $db->prepare("UPDATE videos SET project_id = :pid, updated_at = NOW() WHERE id = :vid");
                $stmtUpdate->execute(['pid' => $targetProjectId, 'vid' => $videoId]);
                
                $video['project_id'] = $targetProjectId;
                $video['project_title'] = $targetProject['title'];
                $successMessage = 'Video successfully moved to project "' . htmlspecialchars($targetProject['title']) . '".';
            }
        }
    }
}

// Fetch available projects for transfer
$isMaster = isMasterAdmin();
$assignedProjectIds = $isMaster ? [] : getEditorProjectIds($_SESSION['admin_id']);
$projIdsList = empty($assignedProjectIds) ? '0' : implode(',', array_map('intval', $assignedProjectIds));
$whereClause = $isMaster ? "" : "WHERE id IN ($projIdsList)";
$availableProjects = $db->query("SELECT id, title FROM projects $whereClause ORDER BY title ASC")->fetchAll();

// Fetch granted access list
if (isSalesAdmin()) {
    $stmtAcc = $db->prepare("SELECT email, granted_at FROM video_access WHERE video_id = :vid AND granted_by_admin_id = :aid ORDER BY granted_at DESC");
    $stmtAcc->execute(['vid' => $videoId, 'aid' => $_SESSION['admin_id'] ?? 0]);
} else {
    $stmtAcc = $db->prepare("SELECT email, granted_at FROM video_access WHERE video_id = :vid ORDER BY granted_at DESC");
    $stmtAcc->execute(['vid' => $videoId]);
}
$accessList = $stmtAcc->fetchAll();

$shareUrl = getBaseUrl() . "/watch?v=" . urlencode($video['access_key']);
$pageTitle = htmlspecialchars($video['title']) . " - Asset Details";
include __DIR__ . '/../includes/header.php';
?>

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full">

    <?php if (!empty($successMessage)): ?>
        <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl flex items-center space-x-3 text-sm">
            <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            <span class="font-semibold"><?= $successMessage ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="mb-6 p-4 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl flex items-center space-x-3 text-sm">
            <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <span class="font-semibold"><?= $errorMessage ?></span>
        </div>
    <?php endif; ?>

    <div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <a href="index" class="text-xs font-semibold text-slate-500 hover:text-slate-800 flex items-center space-x-1 mb-2">
                <span>&larr; Back to Dashboard</span>
            </a>
            <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                <h1 class="text-xl sm:text-2xl font-bold text-slate-900 tracking-tight"><?= htmlspecialchars($video['title']) ?></h1>
                <div class="flex items-center space-x-1.5">
                    <a href="<?= getProjectUrl(['access_key' => $video['project_access_key'] ?? '', 'id' => $video['project_id']]) ?>" class="inline-flex items-center space-x-1 px-2.5 py-1 rounded-lg text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200 hover:bg-indigo-100 transition" title="View Project Showcase">
                        <span><?= htmlspecialchars($video['project_title']) ?></span>
                        <svg class="w-3 h-3 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                    </a>
                    <?php if (!isSalesAdmin()): ?>
                        <button type="button" onclick="openMoveModal()" class="inline-flex items-center space-x-1 px-2 py-1 rounded-lg text-xs font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 hover:text-slate-900 transition" title="Move video to another project">
                            <svg class="w-3 h-3 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                            <span>Change</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex items-center flex-wrap gap-2 text-xs text-slate-500 mt-2 font-medium">
                <span class="inline-flex items-center space-x-1">
                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    <span>Uploaded: <?= date('M d, Y h:i A', strtotime($video['created_at'])) ?></span>
                </span>
                <?php if (!empty($video['updated_at']) && strtotime($video['updated_at']) > (strtotime($video['created_at']) + 60)): ?>
                    <span class="text-slate-300">&bull;</span>
                    <span class="inline-flex items-center space-x-1 text-amber-700 bg-amber-50 px-2 py-0.5 rounded-md border border-amber-200/80 font-semibold" title="Reuploaded / Last Updated">
                        <svg class="w-3.5 h-3.5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                        <span>Last Updated: <?= date('M d, Y h:i A', strtotime($video['updated_at'])) ?></span>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 sm:gap-2.5">
            <?php if (!isSalesAdmin()): ?>
                <button type="button" onclick="openMoveModal()" class="px-3.5 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-medium rounded-xl text-xs sm:text-sm transition shadow-2xs flex items-center space-x-1.5">
                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                    <span>Move Project</span>
                </button>
                <a href="analytics?id=<?= $video['id'] ?>" class="px-3.5 py-2 bg-slate-900 hover:bg-slate-800 text-white font-medium rounded-xl text-xs sm:text-sm transition shadow-xs flex items-center space-x-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    <span>Analytics</span>
                </a>
                <a href="video-upload?id=<?= $video['id'] ?>" class="px-3.5 py-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 font-medium rounded-xl text-xs sm:text-sm transition shadow-2xs">
                    Edit Video
                </a>
                <form method="POST" action="video-details?id=<?= $video['id'] ?>" onsubmit="return confirm('Are you sure you want to delete this video asset? This action cannot be undone.');" class="inline">
                    <input type="hidden" name="action" value="delete_video">
                    <button type="submit" class="px-3.5 py-2 bg-rose-50 border border-rose-200/80 hover:bg-rose-100 text-rose-700 font-semibold rounded-xl text-xs sm:text-sm transition flex items-center space-x-1.5 shadow-2xs">
                        <svg class="w-4 h-4 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        <span>Delete Video</span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Private URL Link Card -->
    <div class="bg-gradient-to-r from-slate-900 to-indigo-950 rounded-2xl p-6 text-white shadow-xl mb-8">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <span class="text-xs uppercase font-semibold tracking-wider text-indigo-300">Private Video Share URL</span>
                <p class="text-xs text-slate-400 mt-1">Requires user email authorization to view. Direct video file paths are protected.</p>
            </div>
            <div class="flex items-center space-x-2 bg-white/10 p-2 rounded-xl border border-white/10 flex-1 max-w-xl">
                <input type="text" id="shareUrlBox" readonly value="<?= $shareUrl ?>" class="bg-transparent border-0 text-xs text-indigo-100 font-mono flex-1 focus:ring-0 px-2">
                <button id="copyShareUrlBtn" class="px-3.5 py-1.5 bg-brand-600 hover:bg-brand-500 text-white rounded-lg text-xs font-semibold shadow-xs transition">
                    Copy Private URL
                </button>
            </div>
        </div>
    </div>

    <!-- Details Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Player & Info -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Video Player Preview -->
            <div class="bg-black rounded-2xl overflow-hidden shadow-lg border border-slate-800">
                <video class="w-full aspect-video" controls controlsList="nodownload nopictureinpicture" disablePictureInPicture 
                       poster="<?= !empty($video['thumbnail_filename']) ? getBaseUrl() . '/uploads/thumbnails/' . htmlspecialchars($video['thumbnail_filename']) : '' ?>">
                    <source src="<?= getBaseUrl() ?>/video-stream.php?v=<?= urlencode($video['access_key']) ?>" type="video/mp4">
                </video>
            </div>

            <!-- Description -->
            <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs">
                <h3 class="text-base font-bold text-slate-900 mb-2">Description</h3>
                <p class="text-xs text-slate-600 leading-relaxed whitespace-pre-line"><?= htmlspecialchars($video['description'] ?: 'No description provided.') ?></p>
            </div>
        </div>

        <!-- Access Control Sidebar (Google Drive Style) -->
        <div class="space-y-6">
            <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-bold text-slate-900">Access Permissions</h3>
                    <span class="text-xs px-2.5 py-0.5 bg-brand-50 text-brand-700 rounded-full font-semibold border border-brand-200">
                        <?= count($accessList) ?> Email(s)
                    </span>
                </div>

                <!-- Add Email Form -->
                <div class="space-y-2 mb-6">
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider">Add Authorized Email</label>
                    <div class="flex space-x-2">
                        <input type="email" id="sidebarEmailInput" placeholder="investor@partner.com" 
                               class="flex-1 px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-brand-500">
                        <button id="sidebarAddBtn" class="px-3 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-xs font-semibold transition">
                            Grant
                        </button>
                    </div>
                </div>

                <!-- Granted Emails List -->
                <div class="space-y-2">
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Emails with Access</label>
                    <div id="accessListSidebar" class="space-y-2 max-h-80 overflow-y-auto">
                        <?php foreach ($accessList as $acc): ?>
                            <div class="flex items-center justify-between p-2.5 rounded-xl border border-slate-100 bg-slate-50/50">
                                <div class="flex items-center space-x-2.5">
                                    <div class="w-7 h-7 rounded-full bg-slate-200 text-slate-700 font-bold text-[11px] flex items-center justify-center uppercase">
                                        <?= substr($acc['email'], 0, 2) ?>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold text-slate-800"><?= htmlspecialchars($acc['email']) ?></p>
                                        <p class="text-[10px] text-slate-400">Granted: <?= date('M d, Y', strtotime($acc['granted_at'])) ?></p>
                                    </div>
                                </div>
                                <button onclick="removeAccessSidebar('<?= htmlspecialchars($acc['email']) ?>')" class="text-slate-400 hover:text-rose-600 p-1 text-xs">
                                    Remove
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Copy Toast Notification -->
<div id="toast" class="fixed bottom-6 right-6 z-50 hidden bg-slate-900 text-white px-4 py-3 rounded-xl shadow-2xl border border-slate-800 text-sm flex items-center space-x-2">
    <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
    <span>Private URL copied to clipboard!</span>
</div>

<script>
const copyShareUrlBtn = document.getElementById('copyShareUrlBtn');
const shareUrlBox = document.getElementById('shareUrlBox');
const toast = document.getElementById('toast');

copyShareUrlBtn.addEventListener('click', () => {
    copyToClipboard(shareUrlBox.value);
    toast.classList.remove('hidden');
    setTimeout(() => toast.classList.add('hidden'), 3000);
});

// Sidebar Access Control AJAX
const sidebarAddBtn = document.getElementById('sidebarAddBtn');
const sidebarEmailInput = document.getElementById('sidebarEmailInput');
const accessListSidebar = document.getElementById('accessListSidebar');

sidebarAddBtn.addEventListener('click', () => {
    const email = sidebarEmailInput.value.trim();
    if (!email) return;

    fetch('<?= getBaseUrl() ?>/ajax/share_access.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'add',
            video_id: <?= $videoId ?>,
            email: email
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            sidebarEmailInput.value = '';
            renderSidebarList(data.access_list);
        } else {
            alert(data.error || 'Failed to add email');
        }
    });
});

function removeAccessSidebar(email) {
    if (!confirm('Remove access for ' + email + '?')) return;
    fetch('<?= getBaseUrl() ?>/ajax/share_access.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'remove',
            video_id: <?= $videoId ?>,
            email: email
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            renderSidebarList(data.access_list);
        }
    });
}

function renderSidebarList(list) {
    accessListSidebar.innerHTML = list.map(item => `
        <div class="flex items-center justify-between p-2.5 rounded-xl border border-slate-100 bg-slate-50/50">
            <div class="flex items-center space-x-2.5">
                <div class="w-7 h-7 rounded-full bg-slate-200 text-slate-700 font-bold text-[11px] flex items-center justify-center uppercase">
                    ${item.email.substring(0, 2)}
                </div>
                <div>
                    <p class="text-xs font-semibold text-slate-800">${item.email}</p>
                    <p class="text-[10px] text-slate-400">Granted access</p>
                </div>
            </div>
            <button onclick="removeAccessSidebar('${item.email}')" class="text-slate-400 hover:text-rose-600 p-1 text-xs">
                Remove
            </button>
        </div>
    `).join('');
}

function openMoveModal() {
    const modal = document.getElementById('moveVideoModal');
    if (modal) {
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
}

function closeMoveModal() {
    const modal = document.getElementById('moveVideoModal');
    if (modal) {
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }
}

// Close modal on escape key or clicking backdrop
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeMoveModal();
});
document.getElementById('moveVideoModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeMoveModal();
});
</script>

<!-- Move Video to Another Project Modal -->
<div id="moveVideoModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl max-w-md w-full border border-slate-200 overflow-hidden my-auto animate-fade-in">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center space-x-2.5">
                <div class="w-8 h-8 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                </div>
                <h3 class="font-bold text-slate-900 text-base">Move Video to Another Project</h3>
            </div>
            <button type="button" onclick="closeMoveModal()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-xl hover:bg-slate-100 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <!-- Modal Form -->
        <form method="POST" action="video-details?id=<?= $videoId ?>" class="p-6 space-y-4">
            <input type="hidden" name="action" value="move_video">
            
            <div>
                <label class="block text-xs font-semibold text-slate-600 uppercase tracking-wider mb-1">Video Asset</label>
                <div class="text-xs sm:text-sm font-semibold text-slate-900 truncate bg-slate-50 p-2.5 rounded-xl border border-slate-200/80">
                    <?= htmlspecialchars($video['title']) ?>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 uppercase tracking-wider mb-1">Current Project</label>
                <div class="flex items-center space-x-2 text-xs font-semibold text-slate-700 bg-slate-50 p-2.5 rounded-xl border border-slate-200/80">
                    <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                    <span><?= htmlspecialchars($video['project_title']) ?></span>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Select New Target Project *</label>
                <select name="target_project_id" required class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-xs sm:text-sm font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:bg-white transition">
                    <option value="">-- Choose Target Project --</option>
                    <?php foreach ($availableProjects as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $p['id'] == $video['project_id'] ? 'disabled class="text-slate-400 bg-slate-100"' : '' ?>>
                            <?= htmlspecialchars($p['title']) ?><?= $p['id'] == $video['project_id'] ? ' (Current Project)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="bg-indigo-50/80 border border-indigo-100 rounded-xl p-3 text-[11px] text-indigo-900 leading-relaxed">
                <span class="font-bold">Note:</span> Moving this video will transfer it to the chosen project directory. Viewer access email permissions, private access keys, and all session analytics remain completely preserved.
            </div>

            <div class="flex items-center justify-end space-x-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeMoveModal()" class="px-4 py-2 text-xs font-semibold text-slate-600 hover:text-slate-800 hover:bg-slate-100 rounded-xl transition">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold rounded-xl shadow-xs transition flex items-center space-x-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    <span>Confirm Move</span>
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
