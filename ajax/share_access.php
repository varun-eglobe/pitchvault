<?php
// ajax/share_access.php - AJAX Endpoint for Admin / Share Modal Email Access Management

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

// Verification: Must be logged in as Admin to edit access list
if (!isAdminLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin authorization required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = sanitize($input['action'] ?? 'list');
$videoId = isset($input['video_id']) ? (int)$input['video_id'] : 0;
$email = strtolower(trim(sanitize($input['email'] ?? '')));

if ($videoId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid video ID']);
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
            INSERT INTO video_access (video_id, email, granted_by_admin_id, granted_at) 
            VALUES (:vid, :email, :admin_id, NOW()) 
            ON DUPLICATE KEY UPDATE granted_by_admin_id = :update_admin_id, granted_at = NOW()
        ");
        $stmt->execute([
            'vid' => $videoId, 
            'email' => $email, 
            'admin_id' => $currentAdminId,
            'update_admin_id' => $currentAdminId
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to grant access: ' . $e->getMessage()]);
        exit;
    }
} elseif ($action === 'remove') {
    if (!empty($email)) {
        if (isSalesAdmin()) {
            $stmt = $db->prepare("DELETE FROM video_access WHERE video_id = :vid AND LOWER(email) = LOWER(:email) AND granted_by_admin_id = :aid");
            $stmt->execute(['vid' => $videoId, 'email' => $email, 'aid' => $currentAdminId]);
        } else {
            $stmt = $db->prepare("DELETE FROM video_access WHERE video_id = :vid AND LOWER(email) = LOWER(:email)");
            $stmt->execute(['vid' => $videoId, 'email' => $email]);
        }
    }
}

// Return Updated Access List for Video with Admin Share Tracking Info
$salesWhere = isSalesAdmin() ? " AND va.granted_by_admin_id = :aid" : "";
$queryParams = ['vid' => $videoId];
if (isSalesAdmin()) {
    $queryParams['aid'] = $currentAdminId;
}

$stmt = $db->prepare("
    SELECT va.email, va.granted_at, va.granted_by_admin_id, 
           COALESCE(a.name, 'Admin') as granted_by_name, 
           a.email as granted_by_email
    FROM video_access va 
    LEFT JOIN admins a ON va.granted_by_admin_id = a.id 
    WHERE va.video_id = :vid {$salesWhere}
    ORDER BY va.granted_at DESC
");
$stmt->execute($queryParams);
$accessList = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'video_id' => $videoId,
    'access_list' => $accessList
]);
exit;
