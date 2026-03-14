-- patch_email_campaigns.sql
-- Ajout de la table pour gérer les campagnes d'e-mails programmables

CREATE TABLE IF NOT EXISTS `email_campaigns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `author_id` char(36) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `target_type` enum('all','group','user') NOT NULL,
  `target_id` varchar(36) DEFAULT NULL,
  `scheduled_at` datetime NOT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_email_campaigns_author` (`author_id`),
  CONSTRAINT `fk_email_campaigns_author` FOREIGN KEY (`author_id`) REFERENCES `profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mettre à jour init.sql pour inclure cette table si on recrée la BDD de zéro
-- Ceci est un script additionnel.
