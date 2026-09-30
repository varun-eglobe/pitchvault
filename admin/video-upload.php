<?php
// admin/video-upload.php - Video Asset Upload & Metadata Form

require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();

if (isSalesAdmin()) {
    header("Location: " . getBaseUrl() . "/admin/index?error=" . urlencode("Access denied: Sales role cannot upload or edit videos. You can only share projects and videos."));
    exit;
}

$db = getDBConnection();
$error = '';
$message = '';

$videoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$presetProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

$videoData = [
    'id' => 0,
    'project_id' => $presetProjectId,
    'title' => '',
    'description' => '',
    'status' => 'published',
    'video_filename' => '',
    'thumbnail_filename' => '',
    'duration' => 120
];

if ($videoId > 0) {
    $stmt = $db->prepare("SELECT * FROM videos WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $videoId]);
    $existing = $stmt->fetch();
    if ($existing) {
        $videoData = $existing;
    }
}

// Fetch projects for dropdown
$isMaster = isMasterAdmin();
$assignedProjectIds = $isMaster ? [] : getEditorProjectIds($_SESSION['admin_id']);
$projIdsList = empty($assignedProjectIds) ? '0' : implode(',', array_map('intval', $assignedProjectIds));

$whereClause = $isMaster ? "" : "WHERE id IN ($projIdsList)";
$projects = $db->query("SELECT * FROM projects $whereClause ORDER BY title ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if post data was truncated due to post_max_size limit in php.ini
    if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
        $error = 'The uploaded file exceeds the server post size limit (post_max_size in php.ini). Please select a smaller file.';
    } else {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $title = sanitize($_POST['title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $status = sanitize($_POST['status'] ?? 'published');
        $duration = (int)($_POST['duration'] ?? 120);
        $initialEmails = sanitize($_POST['initial_emails'] ?? '');

        // Preserve posted input data so fields are not cleared on validation error
        $videoData['project_id'] = $projectId;
        $videoData['title'] = $title;
        $videoData['description'] = $description;
        $videoData['status'] = $status;
        $videoData['duration'] = $duration;

        if ($projectId <= 0 || empty($title)) {
            $error = 'Please select a project and enter a video title.';
        } elseif (!canAdminManageProject($projectId)) {
            $error = 'Access denied to this project.';
        } else {
            $uploadVideoName = $videoData['video_filename'];
            $uploadThumbName = $videoData['thumbnail_filename'];

            // Handle MP4 File Upload
            if (!empty($_FILES['video_file']['name'])) {
                $file = $_FILES['video_file'];
                if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                    $error = 'Uploaded video file exceeds upload_max_filesize in php.ini.';
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

            // Handle Thumbnail File Upload
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
                    // Update Existing Video
                    $stmt = $db->prepare("
                        UPDATE videos 
                        SET project_id = :pid, title = :title, description = :desc, status = :status, 
                            video_filename = :vfile, thumbnail_filename = :tfile, duration = :dur
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        'pid' => $projectId,
                        'title' => $title,
                        'desc' => $description,
                        'status' => $status,
                        'vfile' => $uploadVideoName,
                        'tfile' => $uploadThumbName,
                        'dur' => $duration,
                        'id' => $videoId
                    ]);
                    $newVid = $videoId;
                } else {
                    // Insert New Video
                    $accessKey = 'v_' . bin2hex(random_bytes(8));
                    if (empty($uploadVideoName)) {
                        $uploadVideoName = 'sample_pitch.mp4'; // Fallback sample if no file uploaded
                    }
                    if (empty($uploadThumbName)) {
                        $uploadThumbName = 'sample_thumbnail.jpg';
                    }

                    $stmt = $db->prepare("
                        INSERT INTO videos (project_id, access_key, title, description, status, video_filename, thumbnail_filename, duration, created_at)
                        VALUES (:pid, :key, :title, :desc, :status, :vfile, :tfile, :dur, NOW())
                    ");
                    $stmt->execute([
                        'pid' => $projectId,
                        'key' => $accessKey,
                        'title' => $title,
                        'desc' => $description,
                        'status' => $status,
                        'vfile' => $uploadVideoName,
                        'tfile' => $uploadThumbName,
                        'dur' => $duration
                    ]);
                    $newVid = $db->lastInsertId();
                }

                // Grant initial email access if provided
                if (!empty($initialEmails)) {
                    $emails = array_map('trim', explode(',', $initialEmails));
                    $stmtAcc = $db->prepare("INSERT INTO video_access (video_id, email, granted_at) VALUES (:vid, :email, NOW()) ON DUPLICATE KEY UPDATE granted_at=NOW()");
                    foreach ($emails as $e) {
                        if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                            $stmtAcc->execute(['vid' => $newVid, 'email' => strtolower($e)]);
                        }
                    }
                }

                header("Location: video-details?id=" . $newVid);
                exit;
            }
        }
    }
}

$pageTitle = ($videoId > 0 ? "Edit Video" : "Upload Private Video") . " - PitchVault";
include __DIR__ . '/../includes/header.php';
?>

<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full">

    <div class="mb-8">
        <a href="index" class="text-xs font-semibold text-slate-500 hover:text-slate-800 flex items-center space-x-1 mb-2">
            <span>&larr; Back to Dashboard</span>
        </a>
        <h1 class="text-2xl font-bold text-slate-900 tracking-tight"><?= $videoId > 0 ? 'Edit Video Asset' : 'Upload Private Video' ?></h1>
        <p class="text-sm text-slate-500 mt-1">Configure MP4 video stream, thumbnail, and metadata.</p>
    </div>

    <?php if ($error): ?>
        <div class="mb-6 p-4 bg-rose-50 border border-rose-200 text-rose-700 text-xs rounded-xl flex items-center space-x-2">
            <svg class="w-5 h-5 text-rose-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6">
        <form method="POST" enctype="multipart/form-data" class="space-y-6">

            <!-- Project Selection -->
            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">
                    Target Project *
                    <?php if ($videoId > 0): ?>
                        <span class="text-[11px] font-normal lowercase text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-md ml-1.5 border border-indigo-100">Select another project to move this video</span>
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

            <!-- Video Title & Status -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="md:col-span-2">
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

            <!-- Description -->
            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Description</label>
                <textarea name="description" rows="3" placeholder="Context or agenda summary for viewers..."
                          class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-brand-500"><?= htmlspecialchars($videoData['description']) ?></textarea>
            </div>

            <!-- File Uploads (MP4 Video & Thumbnail) -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2 border-t border-slate-100">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">
                        MP4 Video File <?= $videoId == 0 ? '*' : '(Optional to replace)' ?>
                    </label>
                    <input type="file" name="video_file" accept="video/mp4,video/webm"
                           class="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100">
                    <?php if (!empty($videoData['video_filename'])): ?>
                        <p class="text-[11px] text-slate-400 mt-1">Current file: <code class="text-slate-600"><?= htmlspecialchars($videoData['video_filename']) ?></code></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Thumbnail Cover Image</label>
                    <input type="file" name="thumbnail_file" accept="image/*"
                           class="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200">
                    <?php if (!empty($videoData['thumbnail_filename'])): ?>
                        <p class="text-[11px] text-slate-400 mt-1">Current thumb: <code class="text-slate-600"><?= htmlspecialchars($videoData['thumbnail_filename']) ?></code></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Initial Access List -->
            <div class="pt-2 border-t border-slate-100">
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Grant Initial Email Access (Comma Separated)</label>
                <input type="text" name="initial_emails" placeholder="john@example.com, investor@partner.com"
                       class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-brand-500">
                <p class="text-[11px] text-slate-400 mt-1">You can also manage emails later using the Google Drive-style share dialog.</p>
            </div>

            <!-- Form Actions -->
            <div class="pt-4 flex justify-end space-x-3 border-t border-slate-100">
                <a href="index" onclick="sessionStorage.removeItem('video_upload_draft')" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium rounded-xl text-sm transition">Cancel</a>
                <button type="submit" class="px-5 py-2.5 bg-brand-600 hover:bg-brand-700 text-white font-medium rounded-xl text-sm transition shadow-md shadow-brand-600/20">
                    <?= $videoId > 0 ? 'Save Changes' : 'Upload Video' ?>
                </button>
            </div>
        </form>
    </div>

</div>

<!-- Client-side Form Autosave & Restore Script -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form');
    if (!form) return;

    const fields = ['project_id', 'title', 'description', 'status', 'initial_emails'];
    const draftKey = 'video_upload_draft_' + <?= $videoId ?>;

    // Restore draft if present and field is currently empty
    try {
        const saved = JSON.parse(sessionStorage.getItem(draftKey) || '{}');
        fields.forEach(name => {
            const el = form.querySelector(`[name="${name}"]`);
            if (el && (!el.value || el.value === '') && saved[name]) {
                el.value = saved[name];
            }
        });
    } catch (e) {}

    // Save field state as user types
    form.addEventListener('input', () => {
        const data = {};
        fields.forEach(name => {
            const el = form.querySelector(`[name="${name}"]`);
            if (el) data[name] = el.value;
        });
        sessionStorage.setItem(draftKey, JSON.stringify(data));
    });

    // Clear draft on successful form submit
    form.addEventListener('submit', () => {
        // Keep draft in case server rejects, clear after short delay if navigated
        setTimeout(() => sessionStorage.removeItem(draftKey), 5000);
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
