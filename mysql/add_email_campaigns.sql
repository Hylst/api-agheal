-- add_email_campaigns.sql
-- Table de stockage des campagnes d'e-mails programmables (envoi différé par cron_hourly.php).
-- Utilisée par EmailCampaignController et le job CRON horaire.
-- Date d'intégration aux scripts canoniques : 2026-05-20
-- (le contenu de cette table existait précédemment en archive/sql/patch_email_campaigns.sql ;
--  cette intégration corrige un trou de reconstruction from-scratch.)

CREATE TABLE IF NOT EXISTS `email_campaigns` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `author_id` CHAR(36) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `target_type` ENUM('all','group','user') NOT NULL,
  `target_id` VARCHAR(36) DEFAULT NULL,
  `scheduled_at` DATETIME NOT NULL,
  `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_email_campaigns_author` (`author_id`),
  KEY `idx_email_campaigns_status_schedule` (`status`, `scheduled_at`),
  CONSTRAINT `fk_email_campaigns_author`
    FOREIGN KEY (`author_id`) REFERENCES `profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
