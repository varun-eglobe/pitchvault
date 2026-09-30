<?php
// ajax/share_project_access.php - AJAX Endpoint for Admin / Share Modal Project Access Management

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

// Verification: Must be logged in as Admin to edit project access list
if (!isAdminLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin authorization required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = sanitize($input['action'] ?? 'list');
$projectId = isset($input['project_id']) ? (int)$input['project_id'] : 0;
$email = strtolower(trim(sanitize($input['email'] ?? '')));

if ($projectId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid project ID']);
    exit;
}

$db = getDBConnection();

$currentAdminId = $_SESSION['admin_id'] ?? null;

// Perform Requested Action
if ($action === 'add') {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['error' => 'Please enter a valid email address.']);
        exit;
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO project_access (project_id, email, granted_by_admin_id, granted_at) 
            VALUES (:pid, :email, :admin_id, NOW()) 
            ON DUPLICATE KEY UPDATE granted_by_admin_id = :update_admin_id, granted_at = NOW()
        ");
        $stmt->execute([
            'pid' => $projectId, 
            'email' => $email, 
            'admin_id' => $currentAdminId,
            'update_admin_id' => $currentAdminId
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to grant project access: ' . $e->getMessage()]);
        exit;
    }
} elseif ($action === 'remove') {
    if (!empty($email)) {
        if (isSalesAdmin()) {
            $stmt = $db->prepare("DELETE FROM project_access WHERE project_id = :pid AND LOWER(email) = LOWER(:email) AND granted_by_admin_id = :aid");
            $stmt->execute(['pid' => $projectId, 'email' => $email, 'aid' => $currentAdminId]);

            $stmtV = $db->prepare("
                DELETE va FROM video_access va 
                JOIN videos v ON va.video_id = v.id 
                WHERE v.project_id = :pid AND LOWER(va.email) = LOWER(:email) AND va.granted_by_admin_id = :aid
            ");
            $stmtV->execute(['pid' => $projectId, 'email' => $email, 'aid' => $currentAdminId]);
        } else {
            // Delete from project_access
            $stmt = $db->prepare("DELETE FROM project_access WHERE project_id = :pid AND LOWER(email) = LOWER(:email)");
            $stmt->execute(['pid' => $projectId, 'email' => $email]);

            // Also delete from video_access for all videos in this project to fully revoke access
            $stmtV = $db->prepare("
                DELETE va FROM video_access va 
                JOIN videos v ON va.video_id = v.id 
                WHERE v.project_id = :pid AND LOWER(va.email) = LOWER(:email)
            ");
            $stmtV->execute(['pid' => $projectId, 'email' => $email]);
        }
    }
}

// Return Updated Access List for Project with Admin Share Tracking Info
$salesWhere = isSalesAdmin() ? " AND pa.granted_by_admin_id = :aid" : "";
$queryParams = ['pid' => $projectId];
if (isSalesAdmin()) {
    $queryParams['aid'] = $currentAdminId;
}

$stmt = $db->prepare("
    SELECT pa.email, pa.granted_at, pa.granted_by_admin_id, 
           a.name as granted_by_name, 
           a.email as granted_by_email
    FROM project_access pa 
    LEFT JOIN admins a ON pa.granted_by_admin_id = a.id 
    WHERE pa.project_id = :pid {$salesWhere}
    ORDER BY pa.granted_at DESC
");
$stmt->execute($queryParams);
$accessList = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'project_id' => $projectId,
    'access_list' => $accessList
]);
exit;
