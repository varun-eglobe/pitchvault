<?php
// ajax/track_event.php - Handle AJAX video player event logging & session updates

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$accessKey = sanitize($input['access_key'] ?? '');
$videoId = isset($input['video_id']) ? (int)$input['video_id'] : 0;
$sessionToken = sanitize($input['session_token'] ?? '');
$eventType = sanitize($input['event_type'] ?? 'progress');
$prevPos = isset($input['prev_position']) ? (float)$input['prev_position'] : 0.0;
$currentPos = isset($input['current_position']) ? (float)$input['current_position'] : 0.0;
$watchTimeDelta = isset($input['watch_time_delta']) ? max(0, (float)$input['watch_time_delta']) : 0.0;

if (empty($sessionToken)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing session token']);
    exit;
}

$db = getDBConnection();

// Fetch video
if (!empty($accessKey)) {
    $stmt = $db->prepare("SELECT id, duration FROM videos WHERE access_key = :key LIMIT 1");
    $stmt->execute(['key' => $accessKey]);
} else {
    $stmt = $db->prepare("SELECT id, duration FROM videos WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $videoId]);
}

$video = $stmt->fetch();
if (!$video) {
    http_response_code(440);
    echo json_encode(['error' => 'Invalid video']);
    exit;
}

$vId = (int)$video['id'];
$userEmail = getViewerEmailForVideo($vId);

// Check access permission
if (!isAdminLoggedIn() && (empty($userEmail) || !hasVideoAccess($vId, $userEmail))) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (empty($userEmail)) {
    $userEmail = 'admin@example.com';
}

// Auto-sync video duration if client reports accurate HTML5 duration
$clientDuration = isset($input['video_duration']) ? max(0, (int)$input['video_duration']) : 0;
if ($clientDuration > 0 && (empty($video['duration']) || abs((int)$video['duration'] - $clientDuration) > 2)) {
    $uStmt = $db->prepare("UPDATE videos SET duration = :dur WHERE id = :vid");
    $uStmt->execute(['dur' => $clientDuration, 'vid' => $vId]);
    $video['duration'] = $clientDuration;
}

$videoDuration = max(0, (int)($video['duration'] ?? 0));
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Check or create video session
$stmt = $db->prepare("SELECT id, total_watch_time, completed FROM video_sessions WHERE session_token = :token LIMIT 1");
$stmt->execute(['token' => $sessionToken]);
$session = $stmt->fetch();

if (!$session) {
    $accumulatedWatchTime = $watchTimeDelta;
    $reachedEnd = ($videoDuration > 0 && $currentPos >= ($videoDuration - 4));
    $hasSubstantialWatchTime = ($videoDuration > 0 && $accumulatedWatchTime >= ($videoDuration * 0.5));
    $isCompleted = ($eventType === 'ended') || ($reachedEnd && $hasSubstantialWatchTime);

    // Create new session entry
    $stmt = $db->prepare("INSERT INTO video_sessions (session_token, video_id, user_email, ip_address, user_agent, started_at, last_activity, total_watch_time, last_position, completed) 
                          VALUES (:token, :vid, :email, :ip, :ua, NOW(), NOW(), :watch_time, :last_pos, :completed)");
    $stmt->execute([
        'token' => $sessionToken,
        'vid' => $vId,
        'email' => $userEmail,
        'ip' => $ipAddress,
        'ua' => $userAgent,
        'watch_time' => $watchTimeDelta,
        'last_pos' => $currentPos,
        'completed' => $isCompleted ? 1 : 0
    ]);
    $sessionId = $db->lastInsertId();

    // Increment total_views cached counter on videos table
    try {
        $vStmt = $db->prepare("UPDATE videos SET total_views = total_views + 1 WHERE id = :vid");
        $vStmt->execute(['vid' => $vId]);
    } catch (Exception $e) {
        // Ignore if column not added yet
    }
} else {
    $sessionId = (int)$session['id'];
    $accumulatedWatchTime = (float)$session['total_watch_time'] + $watchTimeDelta;
    $reachedEnd = ($videoDuration > 0 && $currentPos >= ($videoDuration - 4));
    $hasSubstantialWatchTime = ($videoDuration > 0 && $accumulatedWatchTime >= ($videoDuration * 0.5));
    $isCompleted = ($session['completed'] == 1 && $reachedEnd && $hasSubstantialWatchTime) || ($eventType === 'ended') || ($reachedEnd && $hasSubstantialWatchTime);

    // Update session metrics
    $stmt = $db->prepare("UPDATE video_sessions 
                          SET last_activity = NOW(), 
                              last_position = :pos, 
                              total_watch_time = total_watch_time + :delta, 
                              completed = :completed 
                          WHERE id = :sid");
    $stmt->execute([
        'pos' => $currentPos,
        'delta' => $watchTimeDelta,
        'completed' => $isCompleted ? 1 : 0,
        'sid' => $sessionId
    ]);
}

// Log Event in video_events (IMMUTABLE INSERT)
$stmt = $db->prepare("INSERT INTO video_events (session_id, video_id, user_email, event_type, prev_position, current_position, watch_time_delta, created_at) 
                      VALUES (:sid, :vid, :email, :event_type, :prev_pos, :current_pos, :delta, NOW())");
$stmt->execute([
    'sid' => $sessionId,
    'vid' => $vId,
    'email' => $userEmail,
    'event_type' => $eventType,
    'prev_pos' => $prevPos,
    'current_pos' => $currentPos,
    'delta' => $watchTimeDelta
]);

echo json_encode([
    'success' => true,
    'session_id' => $sessionId,
    'event_logged' => $eventType
]);
exit;
