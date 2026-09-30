<?php
// admin/video-details.php - Redirect legacy video-details route to unified video-upload page

require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();

$videoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($videoId > 0) {
    header("Location: " . getBaseUrl() . "/admin/video-upload?id=" . $videoId, true, 301);
} else {
    header("Location: " . getBaseUrl() . "/admin/index", true, 301);
}
exit;
