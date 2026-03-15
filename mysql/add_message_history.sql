-- add_message_history.sql
-- Ajout de la table pour conserver un historique immuable de tous les messages envoyés

CREATE TABLE IF NOT EXISTS `message_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `author_id` CHAR(36) NOT NULL,
    `message_type` ENUM('in_app', 'email') NOT NULL,
    `target_type` ENUM('all', 'group', 'user') NOT NULL,
    `target_id` CHAR(36) NULL,
    `subject` VARCHAR(255) NULL,
    `content` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_message_history_author` FOREIGN KEY (`author_id`) REFERENCES `profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: Ce script est additionnel et doit être exécuté pour mettre à jour la BDD.
