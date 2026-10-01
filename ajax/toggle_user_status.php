<?php
// ajax/toggle_user_status.php - Instant AJAX handler for User Active/Inactive toggle
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (!isMasterAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Master admin access required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$userId = (int)($input['user_id'] ?? $_POST['user_id'] ?? 0);

if ($userId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid user ID.']);
    exit;
}

if ($userId === (int)$_SESSION['admin_id']) {
    echo json_encode(['success' => false, 'error' => 'You cannot deactivate your own logged-in account.']);
    exit;
}

$db = getDBConnection();

$stmt = $db->prepare("UPDATE admins SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id");
$stmt->execute(['id' => $userId]);

$checkStmt = $db->prepare("SELECT is_active, name FROM admins WHERE id = :id LIMIT 1");
$checkStmt->execute(['id' => $userId]);
$user = $checkStmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User account not found.']);
    exit;
}

$isActive = (int)$user['is_active'] === 1;
$statusText = $isActive ? 'activated' : 'deactivated';

echo json_encode([
    'success' => true,
    'is_active' => $isActive,
    'message' => "User " . $user['name'] . " has been " . $statusText . "."
]);
exit;
