<?php
// config/database.php - PDO Database Connection Configuration

// Load local/production credentials if present (ignored by Git)
if (file_exists(__DIR__ . '/database.local.php')) {
    require_once __DIR__ . '/database.local.php';
}

defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_PORT') || define('DB_PORT', '3306');
defined('DB_NAME') || define('DB_NAME', 'pitching_videos_db');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');

date_default_timezone_set('Asia/Kolkata');

function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $pdo->exec("SET time_zone = '+05:30'");
            ensureProjectsAccessKeyColumn($pdo);
            ensureSystemSettingsAndOTPTable($pdo);
        } catch (PDOException $e) {
            // For production, render clean error page or JSON
            die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
        }
    }
    return $pdo;
}

function ensureProjectsAccessKeyColumn($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM `projects` LIKE 'access_key'")->fetch();
        if (!$colCheck) {
            $pdo->exec("ALTER TABLE `projects` ADD COLUMN `access_key` VARCHAR(64) NULL UNIQUE AFTER `id`");
        }
        $unkeyed = $pdo->query("SELECT id FROM `projects` WHERE access_key IS NULL OR access_key = ''")->fetchAll();
        if (!empty($unkeyed)) {
            $upd = $pdo->prepare("UPDATE `projects` SET access_key = :key WHERE id = :id");
            foreach ($unkeyed as $p) {
                $newKey = 'p_' . bin2hex(random_bytes(8));
                $upd->execute(['key' => $newKey, 'id' => $p['id']]);
            }
        }
        $colCheckType = $pdo->query("SHOW COLUMNS FROM `projects` LIKE 'access_type'")->fetch();
        if (!$colCheckType) {
            $pdo->exec("ALTER TABLE `projects` ADD COLUMN `access_type` ENUM('invited', 'anyone') NOT NULL DEFAULT 'invited' AFTER `access_key`");
        }
        $pdo->exec("UPDATE `projects` SET `access_type` = 'invited' WHERE `access_type` = 'anyone'");
        // Auto-correct any legacy false completed sessions where last_position < (duration - 5) or total_watch_time is insufficient
        $pdo->exec("UPDATE video_sessions s JOIN videos v ON s.video_id = v.id SET s.completed = 0 WHERE s.completed = 1 AND v.duration > 0 AND (s.last_position < (v.duration - 5) OR s.total_watch_time < (v.duration * 0.4))");
    } catch (Exception $e) {
        // Ignored if table doesn't exist yet
    }
}

function ensureSystemSettingsAndOTPTable($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
            `setting_key` VARCHAR(100) PRIMARY KEY,
            `setting_value` TEXT NULL,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `otp_verifications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `video_id` INT DEFAULT 0,
            `project_id` INT DEFAULT 0,
            `email` VARCHAR(191) NOT NULL,
            `otp_code` VARCHAR(6) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `verified` TINYINT(1) DEFAULT 0,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (`video_id`, `email`),
            INDEX (`project_id`, `email`),
            INDEX (`otp_code`),
            INDEX (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $colCheckProj = $pdo->query("SHOW COLUMNS FROM `otp_verifications` LIKE 'project_id'")->fetch();
        if (!$colCheckProj) {
            $pdo->exec("ALTER TABLE `otp_verifications` ADD COLUMN `project_id` INT DEFAULT 0 AFTER `video_id`");
        }
    } catch (Exception $e) {
        // Ignored if errors occur during bootstrap
    }
}

