<?php
// admin/shares.php - Full Share Tracking and Access Management
require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();

$db = getDBConnection();
$message = '';
$error = '';

$isMaster = isMasterAdmin();
$isSales = isSalesAdmin();
$currentAdminId = $_SESSION['admin_id'] ?? 0;
$assignedProjectIds = $isMaster ? [] : getEditorProjectIds($currentAdminId);

// Handle Access Revocation
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    $type = sanitize($_POST['type'] ?? '');
    $shareId = (int)($_POST['share_id'] ?? 0);
    $projectId = (int)($_POST['project_id'] ?? 0);
    $videoId = (int)($_POST['video_id'] ?? 0);
    $email = strtolower(trim(sanitize($_POST['email'] ?? '')));

    if ($action === 'revoke') {
        if (!empty($email)) {
            // Permission check: Sales can only manage shares created by their own account. Editors can only manage assigned projects.
            if ($isSales) {
                if ($type === 'project' && $projectId > 0) {
                    $checkStmt = $db->prepare("SELECT id FROM project_access WHERE project_id = :pid AND LOWER(email) = LOWER(:email) AND granted_by_admin_id = :aid");
                    $checkStmt->execute(['pid' => $projectId, 'email' => $email, 'aid' => $currentAdminId]);
                    if (!$checkStmt->fetch()) {
                        $error = 'Access denied: You can only revoke shares granted by your account.';
                    }
                } elseif ($type === 'video' && $videoId > 0) {
                    $checkStmt = $db->prepare("SELECT id FROM video_access WHERE video_id = :vid AND LOWER(email) = LOWER(:email) AND granted_by_admin_id = :aid");
                    $checkStmt->execute(['vid' => $videoId, 'email' => $email, 'aid' => $currentAdminId]);
                    if (!$checkStmt->fetch()) {
                        $error = 'Access denied: You can only revoke shares granted by your account.';
                    }
                }
            } elseif (!$isMaster) {
                if ($type === 'project' && !in_array($projectId, $assignedProjectIds)) {
                    $error = 'Access denied: You do not have permission to manage this project.';
                } elseif ($type === 'video') {
                    // Check if video belongs to an assigned project
                    $checkStmt = $db->prepare("SELECT project_id FROM videos WHERE id = :vid");
                    $checkStmt->execute(['vid' => $videoId]);
                    $vRow = $checkStmt->fetch();
                    if (!$vRow || !in_array((int)$vRow['project_id'], $assignedProjectIds)) {
                        $error = 'Access denied: You do not have permission to manage this video.';
                    }
                }
            }

            if (empty($error)) {
                if ($type === 'project' && $projectId > 0) {
                    if ($isSales) {
                        $stmt = $db->prepare("DELETE FROM project_access WHERE project_id = :pid AND LOWER(email) = LOWER(:email) AND granted_by_admin_id = :aid");
                        $stmt->execute(['pid' => $projectId, 'email' => $email, 'aid' => $currentAdminId]);

                        $stmtV = $db->prepare("
                            DELETE va FROM video_access va 
                            JOIN videos v ON va.video_id = v.id 
                            WHERE v.project_id = :pid AND LOWER(va.email) = LOWER(:email) AND va.granted_by_admin_id = :aid
                        ");
                        $stmtV->execute(['pid' => $projectId, 'email' => $email, 'aid' => $currentAdminId]);
                    } else {
                        // Revoke project access
                        $stmt = $db->prepare("DELETE FROM project_access WHERE project_id = :pid AND LOWER(email) = LOWER(:email)");
                        $stmt->execute(['pid' => $projectId, 'email' => $email]);

                        // Also revoke video access for videos in this project
                        $stmtV = $db->prepare("
                            DELETE va FROM video_access va 
                            JOIN videos v ON va.video_id = v.id 
                            WHERE v.project_id = :pid AND LOWER(va.email) = LOWER(:email)
                        ");
                        $stmtV->execute(['pid' => $projectId, 'email' => $email]);
                    }

                    $message = 'Project access revoked for ' . htmlspecialchars($email);
                } elseif ($type === 'video' && $videoId > 0) {
                    if ($isSales) {
                        $stmt = $db->prepare("DELETE FROM video_access WHERE video_id = :vid AND LOWER(email) = LOWER(:email) AND granted_by_admin_id = :aid");
                        $stmt->execute(['vid' => $videoId, 'email' => $email, 'aid' => $currentAdminId]);
                    } else {
                        // Revoke video access
                        $stmt = $db->prepare("DELETE FROM video_access WHERE video_id = :vid AND LOWER(email) = LOWER(:email)");
                        $stmt->execute(['vid' => $videoId, 'email' => $email]);
                    }

                    $message = 'Video access revoked for ' . htmlspecialchars($email);
                }
            }
        }
    }
}

// Read Filter Parameters
$search = trim(sanitize($_GET['search'] ?? ''));
$adminFilter = $isSales ? (string)$currentAdminId : sanitize($_GET['admin_id'] ?? 'all');
$typeFilter = sanitize($_GET['type'] ?? 'all');
$projectFilter = sanitize($_GET['project_id'] ?? 'all');
$sort = sanitize($_GET['sort'] ?? 'newest');

// Fetch Admins list for dropdown filter
$adminsStmt = $db->query("SELECT id, name, email FROM admins ORDER BY name ASC");
$allAdmins = $adminsStmt->fetchAll();

// Fetch Projects list for dropdown filter
if ($isMaster || $isSales) {
    $projectsStmt = $db->query("SELECT id, title FROM projects ORDER BY title ASC");
} else {
    if (!empty($assignedProjectIds)) {
        $inClause = implode(',', array_map('intval', $assignedProjectIds));
        $projectsStmt = $db->query("SELECT id, title FROM projects WHERE id IN ($inClause) ORDER BY title ASC");
    } else {
        $projectsStmt = null;
    }
}
$allProjects = $projectsStmt ? $projectsStmt->fetchAll() : [];

// Calculate Scoped SQL where clauses
$paWhere = "";
$vaWhere = "";
if (!$isMaster) {
    if ($isSales) {
        $paWhere = "WHERE pa.granted_by_admin_id = " . (int)$currentAdminId;
        $vaWhere = "WHERE va.granted_by_admin_id = " . (int)$currentAdminId;
    } else {
        if (!empty($assignedProjectIds)) {
            $inClause = implode(',', array_map('intval', $assignedProjectIds));
            $paWhere = "WHERE p.id IN ($inClause)";
            $vaWhere = "WHERE v.project_id IN ($inClause)";
        } else {
            $paWhere = "WHERE 1=0";
            $vaWhere = "WHERE 1=0";
        }
    }
}

// Metrics / Summary Stats
$summarySql = "
    SELECT access_type, recipient_email, resource_project_id, granted_by_admin_id FROM (
        SELECT 'project' as access_type, pa.email as recipient_email, pa.project_id as resource_project_id, pa.granted_by_admin_id
        FROM project_access pa
        JOIN projects p ON pa.project_id = p.id
        $paWhere

        UNION ALL

        SELECT 'video' as access_type, va.email as recipient_email, v.project_id as resource_project_id, va.granted_by_admin_id
        FROM video_access va
        JOIN videos v ON va.video_id = v.id
        JOIN projects p ON v.project_id = p.id
        $vaWhere
    ) AS summary_shares
";

$summaryRows = $db->query($summarySql)->fetchAll();

$totalSharesCount = count($summaryRows);
$projectSharesCount = 0;
$videoSharesCount = 0;
$uniqueEmailsMap = [];

foreach ($summaryRows as $sRow) {
    if ($sRow['access_type'] === 'project') {
        $projectSharesCount++;
    } else {
        $videoSharesCount++;
    }
    $uniqueEmailsMap[strtolower($sRow['recipient_email'])] = true;
}
$uniqueRecipientsCount = count($uniqueEmailsMap);

// Build Main Filtered Query
$queryConditions = [];
$queryParams = [];

if ($typeFilter === 'project') {
    $queryConditions[] = "shares.access_type = 'project'";
} elseif ($typeFilter === 'video') {
    $queryConditions[] = "shares.access_type = 'video'";
}

if ($adminFilter !== 'all') {
    if ($adminFilter === '0' || $adminFilter === 'unassigned') {
        $queryConditions[] = "shares.granted_by_admin_id IS NULL";
    } else {
        $queryConditions[] = "shares.granted_by_admin_id = :admin_filter_id";
        $queryParams['admin_filter_id'] = (int)$adminFilter;
    }
}

if ($projectFilter !== 'all') {
    $queryConditions[] = "shares.resource_project_id = :project_filter_id";
    $queryParams['project_filter_id'] = (int)$projectFilter;
}

if ($search !== '') {
    $queryConditions[] = "(shares.recipient_email LIKE :s1 OR shares.resource_title LIKE :s2 OR shares.admin_name LIKE :s3 OR shares.admin_email LIKE :s4)";
    $queryParams['s1'] = '%' . $search . '%';
    $queryParams['s2'] = '%' . $search . '%';
    $queryParams['s3'] = '%' . $search . '%';
    $queryParams['s4'] = '%' . $search . '%';
}

$whereClauseStr = !empty($queryConditions) ? "WHERE " . implode(" AND ", $queryConditions) : "";

$orderByStr = "ORDER BY shares.granted_at DESC";
if ($sort === 'oldest') {
    $orderByStr = "ORDER BY shares.granted_at ASC";
} elseif ($sort === 'email_asc') {
    $orderByStr = "ORDER BY shares.recipient_email ASC, shares.granted_at DESC";
}

$mainSql = "
    SELECT * FROM (
        SELECT 
            pa.id as share_id,
            'project' as access_type,
            pa.project_id as resource_project_id,
            0 as resource_video_id,
            p.title as resource_title,
            p.access_key as access_key,
            pa.email as recipient_email,
            pa.granted_at,
            pa.granted_by_admin_id,
            COALESCE(a.name, 'Admin') as admin_name,
            COALESCE(a.email, '') as admin_email,
            COALESCE(a.role, 'master') as admin_role
        FROM project_access pa
        JOIN projects p ON pa.project_id = p.id
        LEFT JOIN admins a ON pa.granted_by_admin_id = a.id
        $paWhere

        UNION ALL

        SELECT 
            va.id as share_id,
            'video' as access_type,
            v.project_id as resource_project_id,
            va.video_id as resource_video_id,
            v.title as resource_title,
            v.access_key as access_key,
            va.email as recipient_email,
            va.granted_at,
            va.granted_by_admin_id,
            COALESCE(a.name, 'Admin') as admin_name,
            COALESCE(a.email, '') as admin_email,
            COALESCE(a.role, 'master') as admin_role
        FROM video_access va
        JOIN videos v ON va.video_id = v.id
        JOIN projects p ON v.project_id = p.id
        LEFT JOIN admins a ON va.granted_by_admin_id = a.id
        $vaWhere
    ) AS shares
    $whereClauseStr
    $orderByStr
";

$stmt = $db->prepare($mainSql);
$stmt->execute($queryParams);
$sharesList = $stmt->fetchAll();

$stmt = $db->prepare($mainSql);
$stmt->execute($queryParams);
$sharesList = $stmt->fetchAll();

$pageTitle = "Share Tracking & Audit Logs - PitchVault";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-4 sm:py-8">
    
    <!-- Page Header & Action Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6 sm:mb-8">
        <div class="min-w-0 flex-1">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-brand-600 to-indigo-600 flex items-center justify-center text-white shadow-md shadow-brand-500/20 shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path>
                    </svg>
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl lg:text-3xl font-extrabold text-slate-900 tracking-tight">Share Tracking & Audit</h1>
                    <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Comprehensive audit trail of all email access permissions granted across projects and videos.</p>
                </div>
            </div>
        </div>
        <div class="flex items-center space-x-3 shrink-0">
            <a href="<?= getBaseUrl() ?>/admin/projects" class="w-full sm:w-auto inline-flex items-center justify-center space-x-2 px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-slate-700 text-xs sm:text-sm font-semibold hover:bg-slate-50 transition shadow-xs">
                <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
                <span>Manage Projects</span>
            </a>
        </div>
    </div>

    <!-- Alert Notifications -->
    <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 flex items-center justify-between shadow-xs">
            <div class="flex items-center space-x-2.5">
                <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                <span class="text-xs sm:text-sm font-semibold"><?= htmlspecialchars($message) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700 text-sm font-bold ml-4">&times;</button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 flex items-center justify-between shadow-xs">
            <div class="flex items-center space-x-2.5">
                <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span class="text-xs sm:text-sm font-semibold"><?= htmlspecialchars($error) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-700 text-sm font-bold ml-4">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Summary Metrics Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-6 sm:mb-8">
        <!-- Card 1: Total Active Shares -->
        <div class="bg-white p-3.5 sm:p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
            <div class="min-w-0 pr-2">
                <p class="text-[10px] sm:text-xs font-semibold text-slate-500 uppercase tracking-wider truncate">Active Shares</p>
                <h3 class="text-lg sm:text-2xl font-black text-slate-900 mt-0.5 sm:mt-1"><?= number_format($totalSharesCount) ?></h3>
                <p class="text-[10px] sm:text-xs text-slate-400 mt-0.5 hidden sm:block">Granted permissions</p>
            </div>
            <div class="w-9 h-9 sm:w-12 sm:h-12 rounded-xl bg-brand-50 text-brand-600 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
            </div>
        </div>

        <!-- Card 2: Unique Email Recipients -->
        <div class="bg-white p-3.5 sm:p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
            <div class="min-w-0 pr-2">
                <p class="text-[10px] sm:text-xs font-semibold text-slate-500 uppercase tracking-wider truncate">Recipients</p>
                <h3 class="text-lg sm:text-2xl font-black text-indigo-600 mt-0.5 sm:mt-1"><?= number_format($uniqueRecipientsCount) ?></h3>
                <p class="text-[10px] sm:text-xs text-slate-400 mt-0.5 hidden sm:block">Allowed Email IDs</p>
            </div>
            <div class="w-9 h-9 sm:w-12 sm:h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
            </div>
        </div>

        <!-- Card 3: Project Shares -->
        <div class="bg-white p-3.5 sm:p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
            <div class="min-w-0 pr-2">
                <p class="text-[10px] sm:text-xs font-semibold text-slate-500 uppercase tracking-wider truncate">Project Shares</p>
                <h3 class="text-lg sm:text-2xl font-black text-blue-600 mt-0.5 sm:mt-1"><?= number_format($projectSharesCount) ?></h3>
                <p class="text-[10px] sm:text-xs text-slate-400 mt-0.5 hidden sm:block">Full project access</p>
            </div>
            <div class="w-9 h-9 sm:w-12 sm:h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
            </div>
        </div>

        <!-- Card 4: Video Shares -->
        <div class="bg-white p-3.5 sm:p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
            <div class="min-w-0 pr-2">
                <p class="text-[10px] sm:text-xs font-semibold text-slate-500 uppercase tracking-wider truncate">Video Shares</p>
                <h3 class="text-lg sm:text-2xl font-black text-emerald-600 mt-0.5 sm:mt-1"><?= number_format($videoSharesCount) ?></h3>
                <p class="text-[10px] sm:text-xs text-slate-400 mt-0.5 hidden sm:block">Single video access</p>
            </div>
            <div class="w-9 h-9 sm:w-12 sm:h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
        </div>
    </div>

    <!-- Interactive Filter Toolbar -->
    <div class="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200 shadow-sm mb-6">
        <form method="GET" action="<?= getBaseUrl() ?>/admin/shares" id="filterForm" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3 sm:gap-4 items-end">
            
            <!-- Search Query Input with Instant Live Filter -->
            <div class="sm:col-span-2 lg:col-span-4">
                <label for="search" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Search Email, Resource or Admin</label>
                <div class="relative rounded-xl shadow-xs">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                    <input type="text" name="search" id="search" value="<?= htmlspecialchars($search) ?>" placeholder="Type recipient email, project, admin..." class="block w-full pl-9 pr-8 py-2 text-xs sm:text-sm border-slate-200 rounded-xl focus:ring-brand-500 focus:border-brand-500 bg-slate-50/50 hover:bg-white transition" autocomplete="off">
                    <?php if (!empty($search)): ?>
                        <a href="<?= getBaseUrl() ?>/admin/shares?<?= http_build_query(array_merge($_GET, ['search' => ''])) ?>" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 transition" title="Clear search">
                            &times;
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filter by Shared By Admin -->
            <div class="sm:col-span-1 lg:col-span-2">
                <label for="admin_id" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Shared By</label>
                <?php if ($isSales): ?>
                    <select name="admin_id" id="admin_id" disabled class="block w-full py-2 px-3 text-xs sm:text-sm border-slate-200 rounded-xl bg-slate-100 text-slate-500 cursor-not-allowed font-medium">
                        <option value="<?= $currentAdminId ?>" selected><?= htmlspecialchars($_SESSION['admin_name'] ?? 'My Account') ?> (Your Shares)</option>
                    </select>
                <?php else: ?>
                    <select name="admin_id" id="admin_id" onchange="this.form.submit()" class="block w-full py-2 px-3 text-xs sm:text-sm border-slate-200 rounded-xl focus:ring-brand-500 focus:border-brand-500 bg-slate-50/50 hover:bg-white transition">
                        <option value="all" <?= $adminFilter === 'all' ? 'selected' : '' ?>>All Admins</option>
                        <?php foreach ($allAdmins as $adm): ?>
                            <option value="<?= $adm['id'] ?>" <?= (string)$adminFilter === (string)$adm['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($adm['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <!-- Filter by Access Type -->
            <div class="sm:col-span-1 lg:col-span-2">
                <label for="type" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Access Scope</label>
                <select name="type" id="type" onchange="this.form.submit()" class="block w-full py-2 px-3 text-xs sm:text-sm border-slate-200 rounded-xl focus:ring-brand-500 focus:border-brand-500 bg-slate-50/50 hover:bg-white transition">
                    <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>All Types</option>
                    <option value="project" <?= $typeFilter === 'project' ? 'selected' : '' ?>>Project Access</option>
                    <option value="video" <?= $typeFilter === 'video' ? 'selected' : '' ?>>Video Access</option>
                </select>
            </div>

            <!-- Filter by Project -->
            <div class="sm:col-span-1 lg:col-span-2">
                <label for="project_id" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Project</label>
                <select name="project_id" id="project_id" onchange="this.form.submit()" class="block w-full py-2 px-3 text-xs sm:text-sm border-slate-200 rounded-xl focus:ring-brand-500 focus:border-brand-500 bg-slate-50/50 hover:bg-white transition">
                    <option value="all" <?= $projectFilter === 'all' ? 'selected' : '' ?>>All Projects</option>
                    <?php foreach ($allProjects as $proj): ?>
                        <option value="<?= $proj['id'] ?>" <?= (string)$projectFilter === (string)$proj['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($proj['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Sort & Reset Buttons -->
            <div class="sm:col-span-1 lg:col-span-2 flex items-center space-x-2">
                <div class="flex-1">
                    <label for="sort" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Sort</label>
                    <select name="sort" id="sort" onchange="this.form.submit()" class="block w-full py-2 px-2.5 text-xs sm:text-sm border-slate-200 rounded-xl focus:ring-brand-500 focus:border-brand-500 bg-slate-50/50 hover:bg-white transition">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
                        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest</option>
                        <option value="email_asc" <?= $sort === 'email_asc' ? 'selected' : '' ?>>Email (A-Z)</option>
                    </select>
                </div>

                <?php if ($search !== '' || $adminFilter !== 'all' || $typeFilter !== 'all' || $projectFilter !== 'all' || $sort !== 'newest'): ?>
                    <div class="pt-5">
                        <a href="<?= getBaseUrl() ?>/admin/shares" class="p-2 rounded-xl border border-slate-200 bg-white text-slate-500 hover:text-slate-900 hover:bg-slate-100 transition inline-flex items-center justify-center shrink-0" title="Reset all filters">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

        </form>
    </div>

    <!-- Active Shares Table & Mobile List Section -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        
        <!-- Table Header Info Bar -->
        <div class="px-4 sm:px-6 py-4 border-b border-slate-200 bg-slate-50/60 flex items-center justify-between">
            <div class="flex items-center space-x-2">
                <span class="font-bold text-slate-800 text-xs sm:text-sm">Shares Audit Logs</span>
                <span id="sharesRecordCount" class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-brand-100 text-brand-800 border border-brand-200">
                    <?= count($sharesList) ?> record<?= count($sharesList) !== 1 ? 's' : '' ?>
                </span>
            </div>
            <?php if ($search !== '' || $adminFilter !== 'all' || $typeFilter !== 'all' || $projectFilter !== 'all'): ?>
                <span class="text-xs text-slate-500 italic">Filtered view</span>
            <?php endif; ?>
        </div>

        <?php if (empty($sharesList)): ?>
            <div class="p-8 sm:p-12 text-center">
                <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-full bg-slate-100 text-slate-400 mx-auto flex items-center justify-center mb-4">
                    <svg class="w-6 h-6 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg>
                </div>
                <h3 class="text-base sm:text-lg font-bold text-slate-800">No share records found</h3>
                <p class="text-xs sm:text-sm text-slate-500 mt-1 max-w-sm mx-auto">There are no granted email permissions matching your selected filters.</p>
                <div class="mt-5">
                    <a href="<?= getBaseUrl() ?>/admin/shares" class="inline-flex items-center space-x-2 px-4 py-2 rounded-xl bg-brand-50 text-brand-700 font-semibold text-xs sm:text-sm hover:bg-brand-100 transition">
                        <span>Clear All Filters</span>
                    </a>
                </div>
            </div>
        <?php else: ?>
            
            <!-- Shared Fallback Row for Live Client-Side Search with 0 matches -->
            <div id="noLiveSearchResults" class="hidden p-8 sm:p-12 text-center text-slate-500 border-b border-slate-100">
                <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 mx-auto flex items-center justify-center mb-3">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>
                <p class="font-semibold text-slate-800 text-sm">No matching shares found for your live search query.</p>
                <p class="text-xs text-slate-400 mt-0.5">Try typing another email, title, or admin name.</p>
            </div>

            <!-- Desktop View: Data Table (Hidden on mobile < md) -->
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50/80 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                            <th scope="col" class="py-3.5 px-6">Recipient Email</th>
                            <th scope="col" class="py-3.5 px-6">Resource / Scope</th>
                            <th scope="col" class="py-3.5 px-6">Shared By Admin</th>
                            <th scope="col" class="py-3.5 px-6">Granted Date</th>
                            <th scope="col" class="py-3.5 px-6 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 text-sm" id="sharesTableBody">
                        <?php foreach ($sharesList as $share): 
                            $isProject = $share['access_type'] === 'project';
                            $shareUrl = $isProject 
                                ? getProjectUrl(['access_key' => $share['access_key'], 'id' => $share['resource_project_id']])
                                : getBaseUrl() . '/watch?v=' . urlencode($share['access_key']);
                            $searchKey = htmlspecialchars(strtolower($share['recipient_email'] . ' ' . $share['resource_title'] . ' ' . $share['admin_name'] . ' ' . $share['admin_email'] . ' ' . $share['access_type']), ENT_QUOTES);
                        ?>
                            <tr class="share-row hover:bg-slate-50/80 transition group" data-search="<?= $searchKey ?>">
                                
                                <!-- Recipient Email -->
                                <td class="py-4 px-6 font-medium text-slate-900">
                                    <div class="flex items-center space-x-2">
                                        <div class="w-8 h-8 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center text-xs font-bold shrink-0 uppercase">
                                            <?= substr($share['recipient_email'], 0, 1) ?>
                                        </div>
                                        <div>
                                            <span class="font-semibold text-slate-900 block"><?= htmlspecialchars($share['recipient_email']) ?></span>
                                            <button type="button" onclick="copyToClipboard('<?= htmlspecialchars($share['recipient_email']) ?>'); showToast('Email copied to clipboard');" class="text-[11px] text-slate-400 hover:text-brand-600 inline-flex items-center space-x-1 transition">
                                                <span>Copy Email</span>
                                            </button>
                                        </div>
                                    </div>
                                </td>

                                <!-- Resource / Scope -->
                                <td class="py-4 px-6">
                                    <?php if ($isProject): ?>
                                        <a href="<?= getProjectUrl(['access_key' => $share['access_key'], 'id' => $share['resource_project_id']]) ?>" target="_blank" class="flex items-center space-x-2 group-hover:text-brand-600 transition font-semibold text-slate-900">
                                            <svg class="w-4 h-4 text-brand-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
                                            <span class="truncate max-w-[220px]" title="<?= htmlspecialchars($share['resource_title']) ?>"><?= htmlspecialchars($share['resource_title']) ?></span>
                                            <svg class="w-3.5 h-3.5 opacity-0 group-hover:opacity-100 text-brand-500 transition shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= getBaseUrl() ?>/watch?v=<?= urlencode($share['access_key']) ?>" target="_blank" class="flex items-center space-x-2 group-hover:text-brand-600 transition font-semibold text-slate-900">
                                            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                            <span class="truncate max-w-[220px]" title="<?= htmlspecialchars($share['resource_title']) ?>"><?= htmlspecialchars($share['resource_title']) ?></span>
                                            <svg class="w-3.5 h-3.5 opacity-0 group-hover:opacity-100 text-brand-500 transition shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                                        </a>
                                    <?php endif; ?>
                                </td>


                                <!-- Shared By Admin -->
                                <td class="py-4 px-6">
                                    <div class="flex items-center space-x-2">
                                        <div class="w-7 h-7 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-xs font-bold shrink-0">
                                            <?= substr($share['admin_name'], 0, 1) ?>
                                        </div>
                                        <div>
                                            <span class="font-semibold text-slate-800 text-xs block"><?= htmlspecialchars($share['admin_name']) ?></span>
                                            <?php if (!empty($share['admin_email'])): ?>
                                                <span class="text-[11px] text-slate-400 block"><?= htmlspecialchars($share['admin_email']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <!-- Granted Date -->
                                <td class="py-4 px-6 whitespace-nowrap">
                                    <div class="text-xs text-slate-900 font-medium">
                                        <?= !empty($share['granted_at']) ? date('M j, Y • g:i A', strtotime($share['granted_at'])) : 'Legacy' ?>
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        <?= getRelativeTimeStr($share['granted_at']) ?>
                                    </div>
                                </td>

                                <!-- Actions -->
                                <td class="py-4 px-6 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end space-x-2">
                                        
                                        <!-- Copy Link Button -->
                                        <button type="button" onclick="copyToClipboard('<?= htmlspecialchars($shareUrl) ?>'); showToast('Link copied to clipboard!');" class="p-2 text-slate-500 hover:text-brand-600 hover:bg-brand-50 rounded-xl transition inline-flex items-center space-x-1 border border-slate-200 bg-white" title="Copy share link">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                                        </button>

                                        <!-- Revoke Access Form Button -->
                                        <form method="POST" action="<?= getBaseUrl() ?>/admin/shares" onsubmit="return confirm('Are you sure you want to revoke access for <?= htmlspecialchars($share['recipient_email'], ENT_QUOTES) ?>?');" class="inline">
                                            <input type="hidden" name="action" value="revoke">
                                            <input type="hidden" name="type" value="<?= htmlspecialchars($share['access_type']) ?>">
                                            <input type="hidden" name="share_id" value="<?= (int)$share['share_id'] ?>">
                                            <input type="hidden" name="project_id" value="<?= (int)$share['resource_project_id'] ?>">
                                            <input type="hidden" name="video_id" value="<?= (int)$share['resource_video_id'] ?>">
                                            <input type="hidden" name="email" value="<?= htmlspecialchars($share['recipient_email']) ?>">
                                            <button type="submit" class="p-2 text-rose-600 hover:text-rose-700 hover:bg-rose-50 rounded-xl transition inline-flex items-center space-x-1 border border-rose-200 bg-white" title="Revoke access">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                            </button>
                                        </form>

                                    </div>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile View: Card List (Block on mobile < md, hidden on desktop >= md) -->
            <div class="block md:hidden divide-y divide-slate-100" id="sharesMobileCards">
                <?php foreach ($sharesList as $share): 
                    $isProject = $share['access_type'] === 'project';
                    $shareUrl = $isProject 
                        ? getProjectUrl(['access_key' => $share['access_key'], 'id' => $share['resource_project_id']])
                        : getBaseUrl() . '/watch?v=' . urlencode($share['access_key']);
                    $searchKey = htmlspecialchars(strtolower($share['recipient_email'] . ' ' . $share['resource_title'] . ' ' . $share['admin_name'] . ' ' . $share['admin_email'] . ' ' . $share['access_type']), ENT_QUOTES);
                ?>
                    <div class="share-row p-4 hover:bg-slate-50/50 transition space-y-3" data-search="<?= $searchKey ?>">
                        
                        <!-- Top Header: Recipient & Badge -->
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center space-x-2.5 min-w-0">
                                <div class="w-9 h-9 rounded-full bg-slate-100 text-slate-700 flex items-center justify-center text-xs font-bold shrink-0 uppercase">
                                    <?= substr($share['recipient_email'], 0, 1) ?>
                                </div>
                                <div class="min-w-0">
                                    <span class="font-bold text-slate-900 text-sm block truncate" title="<?= htmlspecialchars($share['recipient_email']) ?>"><?= htmlspecialchars($share['recipient_email']) ?></span>
                                    <button type="button" onclick="copyToClipboard('<?= htmlspecialchars($share['recipient_email']) ?>'); showToast('Email copied to clipboard');" class="text-[11px] text-slate-400 hover:text-brand-600 inline-flex items-center space-x-1 transition">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                                        <span>Copy Email</span>
                                    </button>
                                </div>
                            </div>
                            <div class="shrink-0">
                                <?php if ($isProject): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                                        PROJECT
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        VIDEO
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Content Details -->
                        <div class="bg-slate-50/80 rounded-xl p-3 border border-slate-100 text-xs space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="text-slate-400 font-medium">Resource:</span>
                                <?php if ($isProject): ?>
                                    <a href="<?= getProjectUrl(['access_key' => $share['access_key'], 'id' => $share['resource_project_id']]) ?>" target="_blank" class="font-semibold text-slate-800 hover:text-brand-600 truncate max-w-[180px] inline-flex items-center space-x-1">
                                        <span class="truncate"><?= htmlspecialchars($share['resource_title']) ?></span>
                                        <svg class="w-3 h-3 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                                    </a>
                                <?php else: ?>
                                    <a href="<?= getBaseUrl() ?>/watch?v=<?= urlencode($share['access_key']) ?>" target="_blank" class="font-semibold text-slate-800 hover:text-brand-600 truncate max-w-[180px] inline-flex items-center space-x-1">
                                        <span class="truncate"><?= htmlspecialchars($share['resource_title']) ?></span>
                                        <svg class="w-3 h-3 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-slate-400 font-medium">Shared By:</span>
                                <span class="font-medium text-slate-700"><?= htmlspecialchars($share['admin_name']) ?></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-slate-400 font-medium">Granted:</span>
                                <span class="text-slate-600"><?= !empty($share['granted_at']) ? date('M j, Y', strtotime($share['granted_at'])) : 'Legacy' ?> (<?= getRelativeTimeStr($share['granted_at']) ?>)</span>
                            </div>
                        </div>

                        <!-- Card Action Buttons -->
                        <div class="flex items-center justify-end space-x-2 pt-1">
                            <button type="button" onclick="copyToClipboard('<?= htmlspecialchars($shareUrl) ?>'); showToast('Link copied to clipboard!');" class="flex-1 inline-flex items-center justify-center space-x-1.5 px-3 py-1.5 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-xl hover:bg-slate-50 transition shadow-2xs">
                                <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                                <span>Copy Link</span>
                            </button>

                            <form method="POST" action="<?= getBaseUrl() ?>/admin/shares" onsubmit="return confirm('Are you sure you want to revoke access for <?= htmlspecialchars($share['recipient_email'], ENT_QUOTES) ?>?');" class="flex-1">
                                <input type="hidden" name="action" value="revoke">
                                <input type="hidden" name="type" value="<?= htmlspecialchars($share['access_type']) ?>">
                                <input type="hidden" name="share_id" value="<?= (int)$share['share_id'] ?>">
                                <input type="hidden" name="project_id" value="<?= (int)$share['resource_project_id'] ?>">
                                <input type="hidden" name="video_id" value="<?= (int)$share['resource_video_id'] ?>">
                                <input type="hidden" name="email" value="<?= htmlspecialchars($share['recipient_email']) ?>">
                                <button type="submit" class="w-full inline-flex items-center justify-center space-x-1.5 px-3 py-1.5 text-xs font-semibold text-rose-600 bg-rose-50 border border-rose-200 rounded-xl hover:bg-rose-100 transition">
                                    <svg class="w-3.5 h-3.5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                    <span>Revoke</span>
                                </button>
                            </form>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </div>

</div>

<!-- Toast notification element -->
<div id="toast" class="fixed bottom-5 right-5 z-50 transform translate-y-20 opacity-0 transition-all duration-300 pointer-events-none">
    <div class="bg-slate-900 text-white px-4 py-3 rounded-xl shadow-2xl flex items-center space-x-2 text-sm font-medium border border-slate-700">
        <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
        <span id="toastMessage">Action completed</span>
    </div>
</div>

<script>
function showToast(msg) {
    var toast = document.getElementById('toast');
    var toastMsg = document.getElementById('toastMessage');
    if (!toast || !toastMsg) return;
    toastMsg.textContent = msg;
    toast.classList.remove('translate-y-20', 'opacity-0', 'pointer-events-none');
    setTimeout(function() {
        toast.classList.add('translate-y-20', 'opacity-0', 'pointer-events-none');
    }, 2500);
}

// Instant Live Client-Side Filtering as user types
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('search');
    var noResultsRow = document.getElementById('noLiveSearchResults');
    var countBadge = document.getElementById('sharesRecordCount');

    if (searchInput) {
        function filterRows() {
            var query = searchInput.value.trim().toLowerCase();
            var rows = document.querySelectorAll('.share-row');
            var visibleDesktopCount = 0;

            rows.forEach(function(row) {
                var searchKey = row.getAttribute('data-search') || row.innerText.toLowerCase();
                if (!query || searchKey.indexOf(query) !== -1) {
                    row.style.display = '';
                    if (row.tagName.toLowerCase() === 'tr') {
                        visibleDesktopCount++;
                    }
                } else {
                    row.style.display = 'none';
                }
            });

            if (noResultsRow) {
                if (visibleDesktopCount === 0 && rows.length > 0) {
                    noResultsRow.classList.remove('hidden');
                } else {
                    noResultsRow.classList.add('hidden');
                }
            }

            if (countBadge) {
                countBadge.textContent = visibleDesktopCount + ' record' + (visibleDesktopCount !== 1 ? 's' : '');
            }
        }

        // Realtime filter on typing
        searchInput.addEventListener('input', filterRows);
        searchInput.addEventListener('keyup', filterRows);

        // Run on page load if search value exists
        if (searchInput.value.trim() !== '') {
            filterRows();
        }
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
