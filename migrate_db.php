<?php
require_once __DIR__ . '/config/database.php';
$db = getDBConnection();

try {
    $db->exec("ALTER TABLE `admins` ADD COLUMN `role` ENUM('master', 'editor') DEFAULT 'editor' AFTER `password_hash`");
    echo "Added role column to admins.\n";
} catch (Exception $e) {
    echo "Error adding role: " . $e->getMessage() . "\n";
}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS `admin_projects` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `admin_id` INT NOT NULL,
        `project_id` INT NOT NULL,
        `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_admin_project` (`admin_id`, `project_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Created admin_projects table.\n";
} catch (Exception $e) {
    echo "Error creating admin_projects: " . $e->getMessage() . "\n";
}

try {
    $db->exec("UPDATE `admins` SET `role` = 'master' WHERE `id` = 1");
    echo "Set admin 1 to master.\n";
} catch (Exception $e) {
    echo "Error updating admin role: " . $e->getMessage() . "\n";
}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS `video_documents` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `video_id` INT NOT NULL,
        `doc_name` VARCHAR(255) NOT NULL,
        `doc_link` VARCHAR(1000) NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (`video_id`),
        FOREIGN KEY (`video_id`) REFERENCES `videos`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Created video_documents table.\n";
} catch (Exception $e) {
    echo "Error creating video_documents: " . $e->getMessage() . "\n";
}

try {
    $db->exec("ALTER TABLE `project_access` ADD COLUMN `granted_by_admin_id` INT NULL AFTER `email`");
    echo "Added granted_by_admin_id to project_access.\n";
} catch (Exception $e) {
    echo "project_access granted_by_admin_id: " . $e->getMessage() . "\n";
}

try {
    $db->exec("ALTER TABLE `video_access` ADD COLUMN `granted_by_admin_id` INT NULL AFTER `email`");
    echo "Added granted_by_admin_id to video_access.\n";
} catch (Exception $e) {
    echo "video_access granted_by_admin_id: " . $e->getMessage() . "\n";
}

try {
    $db->exec("ALTER TABLE `projects` ADD COLUMN `access_key` VARCHAR(64) NULL UNIQUE AFTER `id`");
    echo "Added access_key to projects.\n";
} catch (Exception $e) {
    echo "projects access_key: " . $e->getMessage() . "\n";
}

try {
    $db->exec("ALTER TABLE `projects` ADD COLUMN `access_type` ENUM('invited', 'anyone') NOT NULL DEFAULT 'invited' AFTER `access_key`");
    echo "Added access_type to projects.\n";
} catch (Exception $e) {
    echo "projects access_type: " . $e->getMessage() . "\n";
}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS `access_requests` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `project_id` INT NOT NULL,
        `video_id` INT NULL,
        `email` VARCHAR(191) NOT NULL,
        `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        `requested_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `processed_at` DATETIME NULL,
        `processed_by_admin_id` INT NULL,
        UNIQUE KEY `unique_proj_vid_email_req` (`project_id`, `video_id`, `email`),
        INDEX (`project_id`),
        INDEX (`video_id`),
        INDEX (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Created access_requests table.\n";
} catch (Exception $e) {
    echo "Error creating access_requests: " . $e->getMessage() . "\n";
}

try {
    $db->exec("ALTER TABLE `videos` ADD COLUMN `total_views` INT DEFAULT 0 AFTER `duration`");
    echo "Added total_views column to videos.\n";
} catch (Exception $e) {
    echo "videos total_views: " . $e->getMessage() . "\n";
}

// Backfill total_views from existing sessions
try {
    $db->exec("UPDATE videos v SET total_views = (SELECT COUNT(*) FROM video_sessions WHERE video_id = v.id)");
    echo "Backfilled total_views from existing sessions.\n";
} catch (Exception $e) {
    echo "Error backfilling total_views: " . $e->getMessage() . "\n";
}

try {
    $db->exec("ALTER TABLE `admins` ADD COLUMN `is_active` TINYINT(1) DEFAULT 1 AFTER `role`");
    echo "Added is_active column to admins.\n";
} catch (Exception $e) {
    echo "admins is_active: " . $e->getMessage() . "\n";
}





