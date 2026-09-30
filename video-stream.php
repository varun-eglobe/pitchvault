<?php
// video-stream.php - Protected Video Streaming Endpoint with HTTP Range Support (206 Partial Content)

require_once __DIR__ . '/includes/auth.php';

// Disable output buffering
if (ob_get_level()) {
    ob_end_clean();
}

$accessKey = $_GET['v'] ?? $_GET['access_key'] ?? '';
$videoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (empty($accessKey) && empty($videoId)) {
    header("HTTP/1.1 400 Bad Request");
    die("Missing video identification parameter.");
}

$db = getDBConnection();
if (!empty($accessKey)) {
    $stmt = $db->prepare("SELECT * FROM videos WHERE access_key = :key LIMIT 1");
    $stmt->execute(['key' => $accessKey]);
} else {
    $stmt = $db->prepare("SELECT * FROM videos WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $videoId]);
}

$video = $stmt->fetch();

if (!$video) {
    header("HTTP/1.1 404 Not Found");
    die("Video not found.");
}

$vId = (int)$video['id'];

// Check Authorization: Admin OR authorized viewer email session
$isAdmin = isAdminLoggedIn();
$viewerEmail = getViewerEmailForVideo($vId);
$isAuthorizedViewer = !empty($viewerEmail) && hasVideoAccess($vId, $viewerEmail);

if (!$isAdmin && !$isAuthorizedViewer) {
    header("HTTP/1.1 403 Forbidden");
    die("Access Denied: You do not have permission to view or stream this private video.");
}

// Locate video file
$filename = basename($video['video_filename']);
$filePath = __DIR__ . '/uploads/videos/' . $filename;

if (!file_exists($filePath)) {
    header("HTTP/1.1 404 Not Found");
    die("Video asset file missing on server.");
}

$fileSize = filesize($filePath);
$mimeType = 'video/mp4';

// Parse HTTP Range header if present
$start = 0;
$end = $fileSize - 1;

if (isset($_SERVER['HTTP_RANGE'])) {
    $rangeHeader = $_SERVER['HTTP_RANGE'];
    if (preg_match('/bytes=(\d+)-(\d+)?/', $rangeHeader, $matches)) {
        $start = (int)$matches[1];
        if (isset($matches[2]) && $matches[2] !== '') {
            $end = (int)$matches[2];
        }
    }
}

// Sanitize range bounds
if ($start > $end || $start >= $fileSize) {
    header("HTTP/1.1 416 Range Not Satisfiable");
    header("Content-Range: bytes */$fileSize");
    exit;
}

if ($end >= $fileSize) {
    $end = $fileSize - 1;
}

$length = ($end - $start) + 1;

// Set HTTP Response Headers for Video Streaming
header("Content-Type: " . $mimeType);
header("Accept-Ranges: bytes");
header("Cache-Control: private, max-age=3600");

if (isset($_SERVER['HTTP_RANGE'])) {
    header("HTTP/1.1 206 Partial Content");
    header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
} else {
    header("HTTP/1.1 200 OK");
}

header("Content-Length: " . $length);

// Stream File in Chunks
$fp = fopen($filePath, 'rb');
if ($fp === false) {
    header("HTTP/1.1 500 Internal Server Error");
    exit;
}

fseek($fp, $start);
$bufferSize = 8192 * 4; // 32KB buffer chunk
$bytesRemaining = $length;

while (!feof($fp) && $bytesRemaining > 0 && connection_status() == 0) {
    $bytesToRead = min($bufferSize, $bytesRemaining);
    $data = fread($fp, $bytesToRead);
    echo $data;
    flush();
    $bytesRemaining -= strlen($data);
}

fclose($fp);
exit;
