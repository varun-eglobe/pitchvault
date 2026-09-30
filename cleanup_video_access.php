<?php
// cleanup_video_access.php - One-time cleanup for orphaned video_access records
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain');
$db = getDBConnection();

$findOrphans = $db->query("
    SELECT va.id, va.email, va.video_id, v.title as video_title, v.project_id
    FROM video_access va
    JOIN videos v ON va.video_id = v.id
    LEFT JOIN project_access pa ON pa.project_id = v.project_id AND LOWER(pa.email) = LOWER(va.email)
    WHERE pa.id IS NULL
");
$orphans = $findOrphans->fetchAll();

if (empty($orphans)) {
    echo "No orphaned video_access records found. All good!\n";
    exit;
}

echo "Found " . count($orphans) . " orphaned video_access records:\n\n";
foreach ($orphans as $o) {
    echo "  [id={$o['id']}] email={$o['email']} | video='{$o['video_title']}' (project_id={$o['project_id']})\n";
}

$ids = array_column($orphans, 'id');
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$del = $db->prepare("DELETE FROM video_access WHERE id IN ($placeholders)");
$del->execute($ids);

echo "\n Deleted " . count($orphans) . " orphaned video_access record(s). Access revoked.\n";
