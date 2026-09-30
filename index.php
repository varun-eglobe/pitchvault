<?php
// index.php - Application Root / Viewer Portal with Strict Access Control

require_once __DIR__ . '/includes/auth.php';

if (isAdminLoggedIn()) {
    header("Location: " . getBaseUrl() . "/admin/index");
    exit;
}

// Prevent caching so access changes take effect immediately
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$db = getDBConnection();
$gateError = '';

// Handle email entry and session clearing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'verify_email') {
        $submittedEmail = strtolower(trim(sanitize($_POST['email'] ?? '')));
        if (empty($submittedEmail) || !filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
            $gateError = 'Please enter a valid email address.';
        } else {
            // Check if user has ANY project or video access
            $stmtCheckP = $db->prepare("SELECT id FROM project_access WHERE LOWER(email) = LOWER(:email) LIMIT 1");
            $stmtCheckP->execute(['email' => $submittedEmail]);
            $hasAnyP = (bool)$stmtCheckP->fetch();

            $stmtCheckV = $db->prepare("
                SELECT va.id 
                FROM video_access va 
                JOIN videos v ON va.video_id = v.id 
                WHERE LOWER(va.email) = LOWER(:email) AND v.status = 'published' 
                LIMIT 1
            ");
            $stmtCheckV->execute(['email' => $submittedEmail]);
            $hasAnyV = (bool)$stmtCheckV->fetch();

            if ($hasAnyP || $hasAnyV) {
                $_SESSION['global_viewer_email'] = $submittedEmail;
                header("Location: " . getBaseUrl() . "/");
                exit;
            } else {
                $gateError = "Access Denied: '$submittedEmail' has not been granted access to any projects or video presentations.";
            }
        }
    } elseif ($_POST['action'] === 'clear_email') {
        unset($_SESSION['global_viewer_email']);
        unset($_SESSION['viewer_access']);
        unset($_SESSION['project_viewer_access']);
        header("Location: " . getBaseUrl() . "/");
        exit;
    }
}

// Determine current viewer's email from session
$viewerEmail = $_SESSION['global_viewer_email'] ?? null;
$accessedProjects = [];
$accessedVideos   = [];

if (!empty($viewerEmail)) {
    // 1. Projects where the viewer has project-level access in project_access
    $stmtP = $db->prepare("
        SELECT p.*, 
               (SELECT COUNT(*) FROM videos WHERE project_id = p.id AND status = 'published') as video_count
        FROM project_access pa
        JOIN projects p ON pa.project_id = p.id
        WHERE LOWER(pa.email) = LOWER(:email)
        ORDER BY p.created_at DESC
    ");
    $stmtP->execute(['email' => $viewerEmail]);
    $accessedProjects = $stmtP->fetchAll();

    $fullProjectIds = array_column($accessedProjects, 'id');

    // 2. Videos the viewer has direct video-level access to (excluding projects already listed above)
    $notInClause = "";
    if (!empty($fullProjectIds)) {
        $cleanIds = implode(',', array_map('intval', $fullProjectIds));
        $notInClause = "AND v.project_id NOT IN ($cleanIds)";
    }

    $stmtV = $db->prepare("
        SELECT DISTINCT v.*, p.title as project_title
        FROM video_access va
        JOIN videos v ON va.video_id = v.id
        JOIN projects p ON v.project_id = p.id
        WHERE LOWER(va.email) = LOWER(:email)
          AND v.status = 'published'
          $notInClause
        ORDER BY v.created_at DESC
    ");
    $stmtV->execute(['email' => $viewerEmail]);
    $accessedVideos = $stmtV->fetchAll();

    // If permissions were revoked in the database, clear stale session
    if (empty($accessedProjects) && empty($accessedVideos)) {
        unset($_SESSION['global_viewer_email']);
        $viewerEmail = null;
    }
}

$pageTitle = "PitchVault - Private Video Presentations";
include __DIR__ . '/includes/header.php';
?>

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12 w-full">

    <?php if (empty($viewerEmail)): ?>
        <!-- Email Access Gate for Portal Landing -->
        <div class="max-w-md mx-auto my-6 sm:my-10 bg-white rounded-3xl border border-slate-200/90 shadow-2xl overflow-hidden relative">
            <div class="absolute -top-24 -left-24 w-48 h-48 bg-brand-500/15 rounded-full blur-3xl pointer-events-none"></div>
            <div class="absolute -top-24 -right-24 w-48 h-48 bg-indigo-500/15 rounded-full blur-3xl pointer-events-none"></div>

            <div class="bg-gradient-to-b from-slate-900 to-slate-950 px-8 py-10 text-center text-white relative">
                <div class="w-16 h-16 bg-gradient-to-tr from-brand-600 to-indigo-500 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-xl shadow-brand-500/25 ring-4 ring-white/10">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                </div>
                <span class="inline-block px-3 py-1 bg-brand-500/20 border border-brand-400/30 text-brand-300 text-[11px] font-semibold rounded-full uppercase tracking-wider mb-2">Private Portal</span>
                <h1 class="text-2xl font-bold tracking-tight text-white">PitchVault Presentations</h1>
                <p class="text-xs text-slate-400 mt-1">Enter your authorized email to view your shared projects & pitches</p>
            </div>

            <div class="p-8">
                <?php if (!empty($gateError)): ?>
                    <div class="mb-5 p-3.5 bg-rose-50 border border-rose-200/80 text-rose-700 text-xs rounded-xl flex items-start space-x-2.5 shadow-xs">
                        <svg class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span class="font-medium"><?= htmlspecialchars($gateError) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= getBaseUrl() ?>/" class="space-y-4">
                    <input type="hidden" name="action" value="verify_email">
                    <div>
                        <label for="portalEmail" class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Your Email Address</label>
                        <input type="email" id="portalEmail" name="email" required placeholder="name@company.com" 
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                               class="w-full px-4 py-3 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition">
                    </div>
                    <button type="submit" class="w-full py-3 bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-xl text-sm transition shadow-lg shadow-brand-600/20 flex items-center justify-center space-x-2">
                        <span>Access Shared Content</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                    </button>
                </form>

                <div class="mt-6 pt-5 border-t border-slate-100 text-center">
                    <p class="text-[11px] text-slate-400">Content is strictly restricted to invited recipients.</p>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Viewer is Verified: Show ONLY Content They Have Access To -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8 pb-5 border-b border-slate-200/80">
            <div>
                <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Your Shared Content</h1>
                <p class="text-xs text-slate-500 mt-1">Viewing presentations authorized for <strong class="text-slate-800 font-semibold"><?= htmlspecialchars($viewerEmail) ?></strong></p>
            </div>
            <div class="flex items-center space-x-3">
                <!-- Grid / List View Toggle Switcher -->
                <div class="inline-flex items-center bg-slate-200/60 p-1 rounded-xl border border-slate-200/80 shadow-2xs">
                    <button type="button" id="toggleGridBtn" onclick="setUserViewMode('grid')" 
                            class="px-2.5 py-1.5 rounded-lg text-xs font-semibold flex items-center space-x-1.5 transition text-slate-600 hover:text-slate-900" 
                            title="Grid View">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                        <span class="hidden sm:inline">Grid</span>
                    </button>
                    <button type="button" id="toggleListBtn" onclick="setUserViewMode('list')" 
                            class="px-2.5 py-1.5 rounded-lg text-xs font-semibold flex items-center space-x-1.5 transition text-slate-600 hover:text-slate-900" 
                            title="List View">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                        <span class="hidden sm:inline">List</span>
                    </button>
                </div>

                <form method="POST" action="<?= getBaseUrl() ?>/">
                    <input type="hidden" name="action" value="clear_email">
                    <button type="submit" class="inline-flex items-center space-x-1.5 px-3.5 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-semibold rounded-xl transition shadow-xs">
                        <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                        <span>Switch Email</span>
                    </button>
                </form>
            </div>
        </div>

        <?php if (!empty($accessedProjects)): ?>
            <!-- Projects Granted to Viewer -->
            <div class="mb-10">
                <div class="flex items-center space-x-2 mb-4">
                    <span class="p-1 rounded-lg bg-indigo-50 text-indigo-600">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
                    </span>
                    <h2 class="text-xs font-bold text-slate-700 uppercase tracking-wider">Authorized Projects</h2>
                    <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600"><?= count($accessedProjects) ?></span>
                </div>

                <div id="userProjectsContainer" class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <?php foreach ($accessedProjects as $proj): ?>
                        <div class="user-proj-card bg-white rounded-2xl border border-slate-200/80 p-5 flex flex-col justify-between shadow-xs hover:shadow-md hover:border-slate-300 transition-all duration-300 group">
                            <div class="proj-card-body">
                                <div class="flex items-center justify-between gap-2 mb-3">
                                    <span class="text-[10px] font-bold px-2.5 py-1 bg-indigo-50 text-indigo-700 rounded-lg border border-indigo-200/80 uppercase tracking-wider">
                                        <?= $proj['video_count'] ?> Video<?= $proj['video_count'] != 1 ? 's' : '' ?>
                                    </span>
                                    <span class="text-[11px] text-slate-400 font-medium"><?= date('M d, Y', strtotime($proj['created_at'])) ?></span>
                                </div>
                                <h3 class="font-bold text-slate-900 text-lg group-hover:text-brand-600 transition leading-snug">
                                    <a href="<?= getProjectUrl($proj) ?>">
                                        <?= htmlspecialchars($proj['title']) ?>
                                    </a>
                                </h3>
                                <p class="text-xs text-slate-500 mt-2 line-clamp-2 leading-relaxed">
                                    <?= htmlspecialchars($proj['description'] ?: 'No description provided.') ?>
                                </p>
                            </div>

                            <div class="proj-card-footer mt-5 pt-4 border-t border-slate-100 flex items-center justify-end">
                                <a href="<?= getProjectUrl($proj) ?>" 
                                   class="inline-flex items-center space-x-1.5 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold rounded-xl transition shadow-xs">
                                    <span>View Project</span>
                                    <svg class="w-3.5 h-3.5 text-brand-200 group-hover:translate-x-0.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($accessedVideos)): ?>
            <!-- Individual Videos Granted to Viewer -->
            <div>
                <div class="flex items-center space-x-2 mb-4">
                    <span class="p-1 rounded-lg bg-brand-50 text-brand-600">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </span>
                    <h2 class="text-xs font-bold text-slate-700 uppercase tracking-wider">Authorized Video Pitches</h2>
                    <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600"><?= count($accessedVideos) ?></span>
                </div>

                <div id="userVideosContainer" class="user-videos-container grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                    <?php foreach ($accessedVideos as $vid): ?>
                        <div class="user-video-card bg-white rounded-2xl border border-slate-200/80 shadow-xs hover:shadow-md hover:border-slate-300 transition-all duration-300 overflow-hidden flex flex-col group">
                            <!-- Thumbnail Preview -->
                            <div class="video-thumb-box w-full aspect-video rounded-t-2xl overflow-hidden relative bg-slate-900 shrink-0">
                                <?php if (!empty($vid['thumbnail_filename'])): ?>
                                    <img src="<?= getBaseUrl() ?>/uploads/thumbnails/<?= htmlspecialchars($vid['thumbnail_filename']) ?>" 
                                         alt="<?= htmlspecialchars($vid['title']) ?>"
                                         class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                                <?php else: ?>
                                    <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-slate-900 to-indigo-950 text-slate-600">
                                        <svg class="w-10 h-10 text-slate-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </div>
                                <?php endif; ?>

                                <!-- Play Overlay -->
                                <a href="<?= getBaseUrl() ?>/watch?v=<?= urlencode($vid['access_key']) ?>" 
                                   class="absolute inset-0 bg-slate-900/40 backdrop-blur-[2px] opacity-0 group-hover:opacity-100 flex items-center justify-center transition-all duration-300">
                                    <div class="w-11 h-11 rounded-full bg-brand-600 text-white flex items-center justify-center shadow-xl transform group-hover:scale-110 transition">
                                        <svg class="w-5 h-5 ml-0.5" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M8 5v14l11-7z"/>
                                        </svg>
                                    </div>
                                </a>
                            </div>

                            <!-- Card Content -->
                            <div class="video-info-box p-4 flex-1 flex flex-col justify-between">
                                <div>
                                    <span class="text-[10px] font-semibold text-brand-600 uppercase tracking-wider block mb-1">
                                        <?= htmlspecialchars($vid['project_title']) ?>
                                    </span>
                                    <h3 class="font-bold text-slate-900 text-sm group-hover:text-brand-600 transition leading-snug line-clamp-2 mb-1.5">
                                        <a href="<?= getBaseUrl() ?>/watch?v=<?= urlencode($vid['access_key']) ?>">
                                            <?= htmlspecialchars($vid['title']) ?>
                                        </a>
                                    </h3>
                                    <?php if (!empty($vid['description'])): ?>
                                        <p class="text-xs text-slate-500 line-clamp-2 leading-relaxed">
                                            <?= htmlspecialchars($vid['description']) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <div class="video-card-footer mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                                    <span class="text-slate-400 text-[11px]"><?= date('M d, Y', strtotime($vid['created_at'])) ?></span>
                                    <a href="<?= getBaseUrl() ?>/watch?v=<?= urlencode($vid['access_key']) ?>" 
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

<script>
function setUserViewMode(mode) {
    localStorage.setItem('pitchvault_view_pref', mode);

    const isGrid = mode === 'grid';
    const btnGrid = document.getElementById('toggleGridBtn');
    const btnList = document.getElementById('toggleListBtn');

    if (btnGrid && btnList) {
        if (isGrid) {
            btnGrid.className = 'px-2.5 py-1.5 rounded-lg text-xs font-semibold flex items-center space-x-1.5 transition bg-white text-brand-600 shadow-xs border border-slate-200/60';
            btnList.className = 'px-2.5 py-1.5 rounded-lg text-xs font-semibold flex items-center space-x-1.5 transition text-slate-600 hover:text-slate-900 bg-transparent';
        } else {
            btnList.className = 'px-2.5 py-1.5 rounded-lg text-xs font-semibold flex items-center space-x-1.5 transition bg-white text-brand-600 shadow-xs border border-slate-200/60';
            btnGrid.className = 'px-2.5 py-1.5 rounded-lg text-xs font-semibold flex items-center space-x-1.5 transition text-slate-600 hover:text-slate-900 bg-transparent';
        }
    }

    const projContainer = document.getElementById('userProjectsContainer');
    if (projContainer) {
        if (isGrid) {
            projContainer.className = 'grid grid-cols-1 md:grid-cols-2 gap-5';
            projContainer.querySelectorAll('.user-proj-card').forEach(card => {
                card.className = 'user-proj-card bg-white rounded-2xl border border-slate-200/80 p-5 flex flex-col justify-between shadow-xs hover:shadow-md hover:border-slate-300 transition-all duration-300 group';
                const footer = card.querySelector('.proj-card-footer');
                if (footer) footer.className = 'proj-card-footer mt-5 pt-4 border-t border-slate-100 flex items-center justify-end';
            });
        } else {
            projContainer.className = 'flex flex-col space-y-3.5';
            projContainer.querySelectorAll('.user-proj-card').forEach(card => {
                card.className = 'user-proj-card bg-white rounded-2xl border border-slate-200/80 p-4.5 flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-xs hover:shadow-md hover:border-slate-300 transition-all duration-300 group';
                const footer = card.querySelector('.proj-card-footer');
                if (footer) footer.className = 'proj-card-footer mt-0 pt-0 border-t-0 flex items-center justify-end shrink-0';
            });
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
                if (footer) footer.className = 'video-card-footer mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs w-full';
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
                if (footer) footer.className = 'video-card-footer mt-0 pt-0 border-t-0 flex items-center justify-end gap-2 shrink-0 w-full sm:w-auto';
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
