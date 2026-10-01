-- Private Video-Sharing Web Application Database Schema
-- Note: Select your target database before importing this file if using phpMyAdmin or CLI.

-- --------------------------------------------------------
-- Table structure for `admins`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `video_events`;
DROP TABLE IF EXISTS `video_sessions`;
DROP TABLE IF EXISTS `video_access`;
DROP TABLE IF EXISTS `project_access`;
DROP TABLE IF EXISTS `videos`;
DROP TABLE IF EXISTS `projects`;
DROP TABLE IF EXISTS `admins`;

CREATE TABLE `admins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('master', 'editor') DEFAULT 'editor',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default admin account (email: admin@example.com, password: adminpassword)
INSERT INTO `admins` (`id`, `name`, `email`, `password_hash`, `role`, `created_at`) VALUES
(1, 'System Admin', 'admin@example.com', '$2y$10$Truxkq68kn30Pv1WH/8q3.lZBpHw/FL5azENkxTpsnATEd5xD4N72', 'master', NOW());
-- Note: Re-seed script in PHP will hash 'admin123' reliably.

-- --------------------------------------------------------
-- Table structure for `admin_projects`
-- --------------------------------------------------------
CREATE TABLE `admin_projects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `admin_id` INT NOT NULL,
  `project_id` INT NOT NULL,
  `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_admin_project` (`admin_id`, `project_id`)
  -- Note: Foreign keys can be added, but skipping strictly for now to avoid order issues if projects table is created after.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `projects`
-- --------------------------------------------------------
CREATE TABLE `projects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `access_key` VARCHAR(64) NULL UNIQUE,
  `access_type` ENUM('invited', 'anyone') NOT NULL DEFAULT 'invited',
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `videos`
-- --------------------------------------------------------
CREATE TABLE `videos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `access_key` VARCHAR(64) NOT NULL UNIQUE,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `status` ENUM('draft', 'published') DEFAULT 'published',
  `video_filename` VARCHAR(255) NOT NULL,
  `thumbnail_filename` VARCHAR(255) NULL,
  `duration` INT DEFAULT 0 COMMENT 'Duration in seconds',
  `total_views` INT DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `project_access`
-- --------------------------------------------------------
CREATE TABLE `project_access` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `granted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_project_email` (`project_id`, `email`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `video_access`
-- --------------------------------------------------------
CREATE TABLE `video_access` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `video_id` INT NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `granted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_video_email` (`video_id`, `email`),
  FOREIGN KEY (`video_id`) REFERENCES `videos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `video_sessions`
-- --------------------------------------------------------
CREATE TABLE `video_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_token` VARCHAR(64) NOT NULL UNIQUE,
  `video_id` INT NOT NULL,
  `user_email` VARCHAR(191) NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` TEXT NULL,
  `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `last_activity` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `total_watch_time` FLOAT DEFAULT 0 COMMENT 'Actual seconds watched',
  `last_position` FLOAT DEFAULT 0 COMMENT 'Last playback position in seconds',
  `completed` TINYINT(1) DEFAULT 0,
  FOREIGN KEY (`video_id`) REFERENCES `videos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `video_events`
-- --------------------------------------------------------
CREATE TABLE `video_events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT NOT NULL,
  `video_id` INT NOT NULL,
  `user_email` VARCHAR(191) NOT NULL,
  `event_type` VARCHAR(50) NOT NULL COMMENT 'play, pause, seek, resume, ended, progress',
  `prev_position` FLOAT DEFAULT 0,
  `current_position` FLOAT DEFAULT 0,
  `watch_time_delta` FLOAT DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`session_id`) REFERENCES `video_sessions`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`video_id`) REFERENCES `videos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `video_documents`
-- --------------------------------------------------------
CREATE TABLE `video_documents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `video_id` INT NOT NULL,
  `doc_name` VARCHAR(255) NOT NULL,
  `doc_link` VARCHAR(1000) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (`video_id`),
  FOREIGN KEY (`video_id`) REFERENCES `videos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Sample Project & Video
INSERT INTO `projects` (`id`, `access_key`, `title`, `description`, `created_at`) VALUES
(1, 'p_d9f8a1b2c3d4e5f6', 'Q3 Investor Pitch Series', 'Product demo pitches and executive summary presentations for Q3 key stakeholders.', NOW());

INSERT INTO `videos` (`id`, `project_id`, `access_key`, `title`, `description`, `status`, `video_filename`, `thumbnail_filename`, `duration`, `created_at`) VALUES
(1, 1, 'v_demo_7f9a8b1c2d3e', 'Product Demo & Vision 2026', 'Comprehensive walkthrough of our core platform features, architectural innovations, and growth plan.', 'published', 'sample_pitch.mp4', 'sample_thumbnail.jpg', 120, NOW());

INSERT INTO `video_access` (`video_id`, `email`, `granted_at`) VALUES
(1, 'john@example.com', NOW()),
(1, 'investor@firm.com', NOW());
