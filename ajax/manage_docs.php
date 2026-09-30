<?php
// ajax/manage_docs.php - AJAX Endpoint for Admin Related Documents Management

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

// Verification: Must be logged in as Admin
if (!isAdminLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin authorization required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = sanitize($input['action'] ?? 'list');
$videoId = isset($input['video_id']) ? (int)$input['video_id'] : 0;

if ($videoId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid video ID']);
    exit;
}

$db = getDBConnection();

// Check if video exists and admin has access to its project
$stmtVid = $db->prepare("SELECT id, project_id FROM videos WHERE id = :id LIMIT 1");
$stmtVid->execute(['id' => $videoId]);
$video = $stmtVid->fetch();

if (!$video) {
    http_response_code(404);
    echo json_encode(['error' => 'Video not found']);
    exit;
}

if (!canAdminManageProject($video['project_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied to this project.']);
    exit;
}

if ($action === 'save') {
    $rawDocs = $input['documents'] ?? [];
    if (!is_array($rawDocs)) {
        $rawDocs = [];
    }

    $validDocs = [];
    foreach ($rawDocs as $item) {
        $name = trim(sanitize($item['doc_name'] ?? ''));
        $link = trim(sanitize($item['doc_link'] ?? ''));

        if (empty($name) && empty($link)) {
            continue;
        }

        if (empty($name)) {
            $name = 'Related Document';
        }

        if (!empty($link)) {
            // Ensure http:// or https:// prefix if user entered www. or plain domain
            if (!preg_match('#^https?://#i', $link) && !preg_match('#^/#', $link)) {
                $link = 'https://' . $link;
            }
        }

        $validDocs[] = [
            'doc_name' => $name,
            'doc_link' => $link
        ];
    }

    try {
        $db->beginTransaction();
        $delStmt = $db->prepare("DELETE FROM video_documents WHERE video_id = :vid");
        $delStmt->execute(['vid' => $videoId]);

        if (!empty($validDocs)) {
            $insStmt = $db->prepare("INSERT INTO video_documents (video_id, doc_name, doc_link) VALUES (:vid, :name, :link)");
            foreach ($validDocs as $doc) {
                $insStmt->execute([
                    'vid' => $videoId,
                    'name' => $doc['doc_name'],
                    'link' => $doc['doc_link']
                ]);
            }
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save documents: ' . $e->getMessage()]);
        exit;
    }
}

// Fetch current documents
$stmtDocs = $db->prepare("SELECT id, doc_name, doc_link, created_at FROM video_documents WHERE video_id = :vid ORDER BY id ASC");
$stmtDocs->execute(['vid' => $videoId]);
$documents = $stmtDocs->fetchAll();

echo json_encode([
    'success' => true,
    'video_id' => $videoId,
    'documents' => $documents
]);
exit;
