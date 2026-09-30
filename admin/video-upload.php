<?php
// admin/video-upload.php - Unified Video Upload, Edit, Access Control & Asset Manager

require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();

if (isSalesAdmin()) {
    header("Location: " . getBaseUrl() . "/admin/index?error=" . urlencode("Access denied: Sales role cannot upload or edit videos. You can only share projects and videos."));
    exit;
}

$db = getDBConnection();
$error = '';
$message = $_GET['msg'] ?? '';

$videoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$presetProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

$videoData = [
    'id' => 0,
    'project_id' => $presetProjectId,
    'access_key' => '',
    'title' => '',
    'description' => '',
    'status' => 'published',
    'video_filename' => '',
    'thumbnail_filename' => '',
    'duration' => 120
];

$videoStats = [
    'session_count' => 0,
    'viewer_count' => 0,
    'total_watch_secs' => 0
];

if ($videoId > 0) {
    $stmt = $db->prepare("
        SELECT v.*, p.title as project_title, p.access_key as project_access_key,
               (SELECT COUNT(*) FROM video_sessions WHERE video_id = v.id) as session_count,
               (SELECT COUNT(DISTINCT user_email) FROM video_sessions WHERE video_id = v.id) as viewer_count,
               (SELECT SUM(total_watch_time) FROM video_sessions WHERE video_id = v.id) as total_watch_secs
        FROM videos v 
        LEFT JOIN projects p ON v.project_id = p.id 
        WHERE v.id = :id LIMIT 1
    ");
    $stmt->execute(['id' => $videoId]);
    $existing = $stmt->fetch();
    if ($existing) {
        $videoData = $existing;
        $videoStats = [
            'session_count' => (int)($existing['session_count'] ?? 0),
            'viewer_count' => (int)($existing['viewer_count'] ?? 0),
            'total_watch_secs' => (int)($existing['total_watch_secs'] ?? 0)
        ];
    } else {
        header("Location: projects?error=" . urlencode("Video not found."));
        exit;
    }

    if (!canAdminManageProject($videoData['project_id'])) {
        header("Location: projects?error=" . urlencode("Access denied to this project."));
        exit;
    }
}

// Fetch available projects for dropdown
$isMaster = isMasterAdmin();
$assignedProjectIds = $isMaster ? [] : getEditorProjectIds($_SESSION['admin_id']);
$projIdsList = empty($assignedProjectIds) ? '0' : implode(',', array_map('intval', $assignedProjectIds));
$whereClause = $isMaster ? "" : "WHERE id IN ($projIdsList)";
$projects = $db->query("SELECT * FROM projects $whereClause ORDER BY title ASC")->fetchAll();

// Handle Form Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'delete_video' && $videoId > 0) {
        if (!empty($videoData['video_filename'])) {
            @unlink(__DIR__ . '/../uploads/videos/' . $videoData['video_filename']);
        }
        if (!empty($videoData['thumbnail_filename'])) {
            @unlink(__DIR__ . '/../uploads/thumbnails/' . $videoData['thumbnail_filename']);
        }
        $stmtDel = $db->prepare("DELETE FROM videos WHERE id = :id");
        $stmtDel->execute(['id' => $videoId]);
        header("Location: projects?msg=" . urlencode("Video asset deleted successfully."));
        exit;
    } elseif ($action === 'add_access' && $videoId > 0) {
        $addEmail = strtolower(trim(sanitize($_POST['access_email'] ?? '')));
        if (!empty($addEmail) && filter_var($addEmail, FILTER_VALIDATE_EMAIL)) {
            $stmtGrant = $db->prepare("INSERT INTO video_access (video_id, email, granted_by_admin_id, granted_at) VALUES (:vid, :email, :aid, NOW()) ON DUPLICATE KEY UPDATE granted_at=NOW()");
            $stmtGrant->execute([
                'vid' => $videoId,
                'email' => $addEmail,
                'aid' => $_SESSION['admin_id'] ?? 0
            ]);
            $message = "Access granted to email '$addEmail'.";
        } else {
            $error = 'Please enter a valid email address.';
        }
    } elseif ($action === 'remove_access' && $videoId > 0) {
        $remEmail = strtolower(trim(sanitize($_POST['access_email'] ?? '')));
        if (!empty($remEmail)) {
            $stmtRev = $db->prepare("DELETE FROM video_access WHERE video_id = :vid AND LOWER(email) = LOWER(:email)");
            $stmtRev->execute(['vid' => $videoId, 'email' => $remEmail]);
            $message = "Access removed for email '$remEmail'.";
        }
    } elseif ($action === 'save_video') {
        if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
            $error = 'The uploaded file exceeds the server post size limit (post_max_size in php.ini). Please select a smaller file.';
        } else {
            $projectId = (int)($_POST['project_id'] ?? 0);
            $title = sanitize($_POST['title'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $status = sanitize($_POST['status'] ?? 'published');
            $duration = (int)($_POST['duration'] ?? 120);
            $initialEmails = sanitize($_POST['initial_emails'] ?? '');

            $videoData['project_id'] = $projectId;
            $videoData['title'] = $title;
            $videoData['description'] = $description;
            $videoData['status'] = $status;
            $videoData['duration'] = $duration;

            if ($projectId <= 0 || empty($title)) {
                $error = 'Please select a target project and enter a video title.';
            } elseif (!canAdminManageProject($projectId)) {
                $error = 'Access denied to target project.';
            } else {
                $uploadVideoName = $videoData['video_filename'];
                $uploadThumbName = $videoData['thumbnail_filename'];

                // MP4 Video Upload
                if (!empty($_FILES['video_file']['name'])) {
                    $file = $_FILES['video_file'];
                    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                        $error = 'Uploaded video file exceeds upload_max_filesize limit in php.ini.';
                    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                        $error = 'Error uploading video file (Code: ' . $file['error'] . ').';
                    } else {
                        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        if (!in_array($ext, ['mp4', 'mov', 'webm', 'mkv'])) {
                            $error = 'Only MP4, MOV, or WebM video files are supported.';
                        } else {
                            $uploadVideoName = 'video_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                            $targetPath = __DIR__ . '/../uploads/videos/' . $uploadVideoName;
                            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                                $error = 'Failed to save uploaded video file to disk.';
                            }
                        }
                    }
                }

                // Thumbnail Upload
                if (empty($error) && !empty($_FILES['thumbnail_file']['name'])) {
                    $file = $_FILES['thumbnail_file'];
                    if ($file['error'] === UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                            $error = 'Only JPG, PNG, or WebP images supported for thumbnails.';
                        } else {
                            $uploadThumbName = 'thumb_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                            $targetPath = __DIR__ . '/../uploads/thumbnails/' . $uploadThumbName;
                            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                                $error = 'Failed to save thumbnail image.';
                            }
                        }
                    }
                }

                if (empty($error)) {
                    if ($videoId > 0) {
                        $stmtUpdate = $db->prepare("
                            UPDATE videos 
                            SET project_id = :pid, title = :title, description = :desc, status = :status, 
                                video_filename = :vfile, thumbnail_filename = :tfile, duration = :dur, updated_at = NOW()
                            WHERE id = :id
                        ");
                        $stmtUpdate->execute([
                            'pid' => $projectId,
                            'title' => $title,
                            'desc' => $description,
                            'status' => $status,
                            'vfile' => $uploadVideoName,
                            'tfile' => $uploadThumbName,
                            'dur' => $duration,
                            'id' => $videoId
                        ]);
                        $targetId = $videoId;
                    } else {
                        $accessKey = 'v_' . bin2hex(random_bytes(8));
                        if (empty($uploadVideoName)) $uploadVideoName = 'sample_pitch.mp4';
                        if (empty($uploadThumbName)) $uploadThumbName = 'sample_thumbnail.jpg';

                        $stmtInsert = $db->prepare("
                            INSERT INTO videos (project_id, access_key, title, description, status, video_filename, thumbnail_filename, duration, created_at)
                            VALUES (:pid, :key, :title, :desc, :status, :vfile, :tfile, :dur, NOW())
                        ");
                        $stmtInsert->execute([
                            'pid' => $projectId,
                            'key' => $accessKey,
                            'title' => $title,
                            'desc' => $description,
                            'status' => $status,
                            'vfile' => $uploadVideoName,
                            'tfile' => $uploadThumbName,
                            'dur' => $duration
                        ]);
                        $targetId = $db->lastInsertId();
                    }

                    if (!empty($initialEmails)) {
                        $emails = array_map('trim', explode(',', $initialEmails));
                        $stmtAcc = $db->prepare("INSERT INTO video_access (video_id, email, granted_by_admin_id, granted_at) VALUES (:vid, :email, :aid, NOW()) ON DUPLICATE KEY UPDATE granted_at=NOW()");
                        foreach ($emails as $e) {
                            if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                                $stmtAcc->execute(['vid' => $targetId, 'email' => strtolower($e), 'aid' => $_SESSION['admin_id'] ?? 0]);
                            }
                        }
                    }

                    header("Location: video-upload?id=" . $targetId . "&msg=" . urlencode("Video saved successfully."));
                    exit;
                }
            }
        }
    }
}

// Fetch Granted Access List for this video
$accessList = [];
if ($videoId > 0) {
    $stmtAccList = $db->prepare("SELECT email, granted_at FROM video_access WHERE video_id = :vid ORDER BY granted_at DESC");
    $stmtAccList->execute(['vid' => $videoId]);
    $accessList = $stmtAccList->fetchAll();
}

$shareUrl = $videoId > 0 && !empty($videoData['access_key']) ? getBaseUrl() . "/watch?v=" . urlencode($videoData['access_key']) : '';
$pageTitle = ($videoId > 0 ? "Edit & Manage Video" : "Upload Private Video") . " - PitchVault";
include __DIR__ . '/../includes/header.php';
?>

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full">

    <!-- Flash Banners -->
    <?php if ($message): ?>
        <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl flex items-center space-x-3 text-xs sm:text-sm shadow-xs">
            <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            <span class="font-semibold"><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="mb-6 p-4 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl flex items-center space-x-3 text-xs sm:text-sm shadow-xs">
            <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <span class="font-semibold"><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Page Header & Actions -->
    <div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <a href="index" class="text-xs font-semibold text-slate-500 hover:text-slate-800 flex items-center space-x-1 mb-2">
                <span>&larr; Back to Dashboard</span>
            </a>
            <div class="flex flex-wrap items-center gap-2.5">
                <h1 class="text-xl sm:text-2xl font-bold text-slate-900 tracking-tight">
                    <?= $videoId > 0 ? 'Edit & Manage Video' : 'Upload Private Video' ?>
                </h1>
                <?php if ($videoId > 0 && !empty($videoData['project_title'])): ?>
                    <span class="px-2.5 py-0.5 rounded-lg text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200">
                        <?= htmlspecialchars($videoData['project_title']) ?>
                    </span>
                <?php endif; ?>
            </div>
            <p class="text-xs text-slate-500 mt-1">Configure video media files, metadata, and private viewer permissions.</p>
        </div>

        <!-- Quick Action Tools (When Editing Existing Video) -->
        <?php if ($videoId > 0): ?>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <a href="analytics?id=<?= $videoId ?>" class="px-3.5 py-2 bg-slate-900 hover:bg-slate-800 text-white font-semibold rounded-xl text-xs transition shadow-xs flex items-center space-x-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 00-2 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    <span>Analytics (<?= $videoStats['session_count'] ?> plays)</span>
                </a>
                <a href="<?= $shareUrl ?>" target="_blank" class="px-3.5 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-semibold rounded-xl text-xs transition shadow-2xs flex items-center space-x-1.5">
                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span>Watch Preview</span>
                </a>
                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this video asset? This action cannot be undone.');" class="inline">
                    <input type="hidden" name="action" value="delete_video">
                    <button type="submit" class="px-3 py-2 bg-rose-50 border border-rose-200 hover:bg-rose-100 text-rose-700 font-semibold rounded-xl text-xs transition shadow-2xs flex items-center space-x-1">
                        <svg class="w-3.5 h-3.5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        <span>Delete</span>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <!-- Private URL Banner (When Editing Existing Video) -->
    <?php if ($videoId > 0 && !empty($shareUrl)): ?>
        <div class="bg-gradient-to-r from-slate-900 to-indigo-950 rounded-2xl p-5 text-white shadow-xl mb-8 border border-slate-800">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <span class="text-[11px] uppercase font-bold tracking-wider text-indigo-300">Private Video Share Link</span>
                    <p class="text-xs text-slate-400 mt-0.5">Invited viewers are verified via 6-digit OTP code before viewing.</p>
                </div>
                <div class="flex items-center space-x-2 bg-white/10 p-1.5 rounded-xl border border-white/10 flex-1 max-w-lg">
                    <input type="text" id="shareUrlBox" readonly value="<?= $shareUrl ?>" class="bg-transparent border-0 text-xs text-indigo-100 font-mono flex-1 focus:ring-0 px-2 truncate">
                    <button onclick="copyToClipboard('<?= $shareUrl ?>').then(() => alert('Private URL copied to clipboard!'))" class="px-3.5 py-1.5 bg-brand-600 hover:bg-brand-500 text-white rounded-lg text-xs font-semibold shadow-xs transition shrink-0">
                        Copy Link
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Main Content Layout (Form + Access Sidebar) -->
    <div class="grid grid-cols-1 <?= $videoId > 0 ? 'lg:grid-cols-3' : '' ?> gap-8">
        
        <!-- Video Details & Upload Form -->
        <div class="<?= $videoId > 0 ? 'lg:col-span-2' : '' ?> bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6">
            <form method="POST" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="action" value="save_video">

                <!-- Target Project Selection -->
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">
                        Target Project *
                        <?php if ($videoId > 0): ?>
                            <span class="text-[11px] font-normal lowercase text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-md ml-1.5 border border-indigo-100">Move video by changing project</span>
                        <?php endif; ?>
                    </label>
                    <select name="project_id" required class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-brand-500">
                        <option value="">-- Select Project Container --</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $videoData['project_id'] == $p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Video Title & Publication Status -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Video Title *</label>
                        <input type="text" name="title" required value="<?= htmlspecialchars($videoData['title']) ?>" placeholder="e.g. Q3 Investor Presentation Deck"
                               class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-brand-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Publication Status</label>
                        <select name="status" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-brand-500">
                            <option value="published" <?= $videoData['status'] === 'published' ? 'selected' : '' ?>>Published</option>
                            <option value="draft" <?= $videoData['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                        </select>
                    </div>
                </div>

                <!-- Video Description -->
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Description</label>
                    <textarea name="description" rows="3" placeholder="Context or agenda summary for viewers..."
                              class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-brand-500"><?= htmlspecialchars($videoData['description']) ?></textarea>
                </div>

                <!-- File Upload Controls -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 pt-3 border-t border-slate-100">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">
                            MP4 Video File <?= $videoId == 0 ? '*' : '(Optional to replace)' ?>
                        </label>
                        <input type="file" name="video_file" accept="video/mp4,video/webm"
                               class="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100">
                        <?php if (!empty($videoData['video_filename'])): ?>
                            <p class="text-[11px] text-slate-400 mt-1">Current file: <code class="text-slate-600 font-mono"><?= htmlspecialchars($videoData['video_filename']) ?></code></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Thumbnail Cover Image</label>
                        <input type="file" name="thumbnail_file" id="thumbnailFileInput" accept="image/*"
                               class="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200">
                        
                        <!-- Visual Thumbnail Preview Box -->
                        <div id="thumbPreviewContainer" class="mt-3 flex items-start space-x-3 p-2 bg-slate-50 border border-slate-200/80 rounded-xl <?= empty($videoData['thumbnail_filename']) ? 'hidden' : '' ?>">
                            <div class="w-28 h-18 aspect-video rounded-lg overflow-hidden bg-slate-900 border border-slate-200 shadow-2xs relative shrink-0">
                                <img id="thumbPreviewImg" 
                                     src="<?= !empty($videoData['thumbnail_filename']) ? getBaseUrl() . '/uploads/thumbnails/' . htmlspecialchars($videoData['thumbnail_filename']) : '' ?>" 
                                     alt="Thumbnail Preview" 
                                     class="w-full h-full object-cover">
                            </div>
                            <div class="min-w-0 flex-1">
                                <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider block">Thumbnail Preview</span>
                                <p id="thumbFilenameText" class="text-[11px] text-slate-600 mt-0.5 font-mono truncate">
                                    <?= htmlspecialchars($videoData['thumbnail_filename'] ?? '') ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Initial Access Input (New Video Mode) -->
                <?php if ($videoId == 0): ?>
                    <div class="pt-3 border-t border-slate-100">
                        <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Grant Initial Email Access (Comma Separated)</label>
                        <input type="text" name="initial_emails" placeholder="investor@partner.com, client@firm.com"
                               class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-brand-500">
                        <p class="text-[11px] text-slate-400 mt-1">You can also manage viewer email permissions directly from the side panel after saving.</p>
                    </div>
                <?php endif; ?>

                <!-- Form Submit Buttons -->
                <div class="pt-4 flex items-center justify-end space-x-3 border-t border-slate-100">
                    <a href="index" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium rounded-xl text-sm transition">Cancel</a>
                    <button type="submit" class="px-5 py-2.5 bg-brand-600 hover:bg-brand-700 text-white font-medium rounded-xl text-sm transition shadow-md shadow-brand-600/20">
                        <?= $videoId > 0 ? 'Save Changes' : 'Upload Video' ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Sidebar: Access Permissions & Viewers (When Editing Existing Video) -->
        <?php if ($videoId > 0): ?>
            <div class="space-y-6">
                <!-- Direct Access Manager -->
                <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs">
                    <div class="flex items-center justify-between gap-2 mb-4">
                        <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider leading-snug">Direct Access Permissions</h3>
                        <span class="text-xs font-semibold px-2.5 py-1 bg-brand-50 text-brand-700 rounded-full border border-brand-200/80 whitespace-nowrap shrink-0">
                            <?= count($accessList) ?> Email<?= count($accessList) != 1 ? 's' : '' ?>
                        </span>
                    </div>

                    <!-- Grant Access Form -->
                    <form method="POST" class="mb-5">
                        <input type="hidden" name="action" value="add_access">
                        <label class="block text-[11px] font-semibold text-slate-600 uppercase tracking-wider mb-1.5">Grant Video Access</label>
                        <div class="flex space-x-2">
                            <input type="email" name="access_email" required placeholder="recipient@company.com" 
                                   class="flex-1 px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-brand-500">
                            <button type="submit" class="px-3.5 py-2 bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold rounded-xl transition shrink-0 shadow-xs">
                                Grant
                            </button>
                        </div>
                    </form>

                    <!-- List of Granted Emails -->
                    <div>
                        <h4 class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider mb-2">People with Direct Access</h4>
                        <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                            <?php if (empty($accessList)): ?>
                                <p class="text-xs text-slate-400 py-3 text-center bg-slate-50 rounded-xl border border-slate-100">No direct email permissions added yet.<br><span class="text-[10px] text-slate-400">Project-level viewers also inherit access.</span></p>
                            <?php else: ?>
                                <?php foreach ($accessList as $acc): ?>
                                    <div class="flex items-center justify-between p-2.5 rounded-xl hover:bg-slate-50 border border-slate-100 transition">
                                        <div class="flex items-center space-x-2.5 min-w-0">
                                            <div class="w-7 h-7 rounded-full bg-brand-50 text-brand-700 font-bold text-xs flex items-center justify-center uppercase shrink-0">
                                                <?= substr($acc['email'], 0, 2) ?>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-xs font-semibold text-slate-800 truncate"><?= htmlspecialchars($acc['email']) ?></p>
                                                <p class="text-[10px] text-slate-400"><?= date('M d, Y', strtotime($acc['granted_at'])) ?></p>
                                            </div>
                                        </div>
                                        <form method="POST" onsubmit="return confirm('Revoke access for <?= htmlspecialchars($acc['email']) ?>?');" class="shrink-0 ml-2">
                                            <input type="hidden" name="action" value="remove_access">
                                            <input type="hidden" name="access_email" value="<?= htmlspecialchars($acc['email']) ?>">
                                            <button type="submit" class="text-slate-400 hover:text-rose-600 p-1 text-xs font-medium transition" title="Revoke Access">
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Video Engagement Quick Stats Card (Light Theme) -->
                <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-xs space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider">Engagement Overview</h3>
                        <a href="analytics?id=<?= $videoId ?>" class="text-[11px] font-semibold text-brand-600 hover:text-brand-700 transition">Full Stats &rarr;</a>
                    </div>
                    <div class="grid grid-cols-3 gap-2.5 text-center pt-1">
                        <div class="bg-slate-50 border border-slate-200/70 p-3 rounded-xl">
                            <span class="block text-xl font-extrabold text-slate-900"><?= $videoStats['session_count'] ?></span>
                            <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Plays</span>
                        </div>
                        <div class="bg-slate-50 border border-slate-200/70 p-3 rounded-xl">
                            <span class="block text-xl font-extrabold text-slate-900"><?= $videoStats['viewer_count'] ?></span>
                            <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Viewers</span>
                        </div>
                        <div class="bg-slate-50 border border-slate-200/70 p-3 rounded-xl">
                            <span class="block text-xl font-extrabold text-slate-900"><?= round($videoStats['total_watch_secs'] / 60, 1) ?>m</span>
                            <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Watch Time</span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>

</div>

<!-- Client-side Form Autosave & Restore Script -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form');
    if (!form) return;

    const fields = ['project_id', 'title', 'description', 'status', 'initial_emails'];
    const draftKey = 'video_upload_draft_' + <?= $videoId ?>;

    try {
        const saved = JSON.parse(sessionStorage.getItem(draftKey) || '{}');
        fields.forEach(name => {
            const el = form.querySelector(`[name="${name}"]`);
            if (el && (!el.value || el.value === '') && saved[name]) {
                el.value = saved[name];
            }
        });
    } catch (e) {}

    form.addEventListener('input', () => {
        const data = {};
        fields.forEach(name => {
            const el = form.querySelector(`[name="${name}"]`);
            if (el) data[name] = el.value;
        });
        sessionStorage.setItem(draftKey, JSON.stringify(data));
    });

    // Live Thumbnail Image Preview Handler
    const thumbInput = document.getElementById('thumbnailFileInput');
    if (thumbInput) {
        thumbInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = (evt) => {
                    const img = document.getElementById('thumbPreviewImg');
                    const container = document.getElementById('thumbPreviewContainer');
                    const text = document.getElementById('thumbFilenameText');
                    if (img) img.src = evt.target.result;
                    if (text) text.textContent = file.name;
                    if (container) container.classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            }
        });
    }

    form.addEventListener('submit', () => {
        setTimeout(() => sessionStorage.removeItem(draftKey), 5000);
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
