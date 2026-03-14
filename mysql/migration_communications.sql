CREATE TABLE IF NOT EXISTS `communications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `author_id` CHAR(36) DEFAULT NULL,
    `target_type` ENUM('all', 'group', 'user') NOT NULL DEFAULT 'all',
    `target_id` CHAR(36) NULL DEFAULT NULL,
    `content` TEXT NOT NULL,
    `is_urgent` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_communications_target` (`target_type`, `target_id`),
    KEY `fk_communications_author` (`author_id`),
    CONSTRAINT `fk_communications_author` FOREIGN KEY (`author_id`) REFERENCES `profiles`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `app_info`;
