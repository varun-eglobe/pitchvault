<?php
// ajax/manage_request.php - AJAX Handler for Approving/Rejecting Access Requests
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized admin login required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = sanitize($input['action'] ?? '');
$requestId = (int)($input['request_id'] ?? 0);
$adminId = $_SESSION['admin_id'] ?? 0;

if ($requestId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid request identifier.']);
    exit;
}

$db = getDBConnection();
$stmt = $db->prepare("SELECT * FROM access_requests WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $requestId]);
$req = $stmt->fetch();

if (!$req) {
    echo json_encode(['success' => false, 'error' => 'Access request not found.']);
    exit;
}

if (!canAdminShareResource($req['project_id'])) {
    echo json_encode(['success' => false, 'error' => 'Access denied to manage this project request.']);
    exit;
}

if ($action === 'approve') {
    $approvedReq = approveAccessRequest($requestId, $adminId);
    echo json_encode([
        'success' => true, 
        'message' => 'Access granted to ' . $req['email'],
        'email' => $req['email']
    ]);
    exit;
} elseif ($action === 'reject') {
    rejectAccessRequest($requestId, $adminId);
    echo json_encode([
        'success' => true, 
        'message' => 'Request declined for ' . $req['email'],
        'email' => $req['email']
    ]);
    exit;
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid action parameter.']);
    exit;
}
