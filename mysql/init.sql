-- =============================================================================
-- AGHeal - Script d'initialisation COMPLET de la base de données
-- Synchronisé avec : agheal.sql (schéma original) + API PHP (code backend)
-- Date : 2026-03-12
-- 
-- ⚠️ COMPATIBLE HEIDISQL (pas de DELIMITER)
-- Pour le TRIGGER et la FONCTION : voir init_trigger.sql
-- =============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET foreign_key_checks = 0;
SET time_zone = "+00:00";

-- =============================================================================
-- 1. AUTHENTIFICATION
-- =============================================================================

CREATE TABLE IF NOT EXISTS `users` (
    `id` CHAR(36) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `email_confirmed_at` TIMESTAMP NULL DEFAULT NULL,
    `last_sign_in_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `refresh_tokens` (
    `id` CHAR(36) NOT NULL DEFAULT (UUID()),
    `user_id` CHAR(36) NOT NULL,
    `token` CHAR(64) NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `revoked_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_refresh_tokens_token` (`token`),
    KEY `idx_refresh_tokens_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` CHAR(36) NOT NULL DEFAULT (UUID()),
    `user_id` CHAR(36) NOT NULL,
    `token` CHAR(64) NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `used_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_password_resets_token` (`token`),
    KEY `idx_password_resets_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 2. PROFILS & RÔLES
-- =============================================================================

CREATE TABLE IF NOT EXISTS `profiles` (
    `id` CHAR(36) NOT NULL,
    `first_name` VARCHAR(100) DEFAULT NULL,
    `last_name` VARCHAR(100) DEFAULT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `remarks_health` TEXT DEFAULT NULL,
    `statut_compte` VARCHAR(20) DEFAULT 'actif',
    `organization` VARCHAR(100) DEFAULT NULL,
    `avatar_base64` LONGTEXT DEFAULT NULL,
    `additional_info` TEXT DEFAULT NULL,
    `coach_remarks` TEXT DEFAULT NULL,
    `age` INT DEFAULT NULL,
    `payment_status` VARCHAR(20) DEFAULT 'en_attente',
    `renewal_date` DATE DEFAULT NULL,
    `notify_session_reminder_email` TINYINT(1) DEFAULT 1,
    `notify_session_reminder_push` TINYINT(1) DEFAULT 0,
    `notify_new_sessions_email` TINYINT(1) DEFAULT 1,
    `notify_new_sessions_push` TINYINT(1) DEFAULT 0,
    `notify_scheduled_sessions_email` TINYINT(1) DEFAULT 1,
    `notify_scheduled_sessions_push` TINYINT(1) DEFAULT 0,
    `notify_renewal_reminder_email` TINYINT(1) DEFAULT 1,
    `notify_renewal_reminder_push` TINYINT(1) DEFAULT 0,
    `notify_renewal_verify_email` TINYINT(1) DEFAULT 1,
    `notify_renewal_verify_push` TINYINT(1) DEFAULT 0,
    `medical_certificate_date` DATE DEFAULT NULL,
    `notify_medical_certif_email` TINYINT(1) DEFAULT 1,
    `notify_medical_certif_push` TINYINT(1) DEFAULT 0,
    `notify_expired_payment_email` TINYINT(1) DEFAULT 0,
    `notify_expired_payment_push` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_profiles_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_roles` (
    `user_id` CHAR(36) NOT NULL,
    `role` ENUM('admin','coach','adherent') NOT NULL DEFAULT 'adherent',
    PRIMARY KEY (`user_id`, `role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` CHAR(36) NOT NULL,
    `endpoint` TEXT NOT NULL,
    `p256dh` VARCHAR(255) NOT NULL,
    `auth` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_push_subs_user` (`user_id`),
    CONSTRAINT `fk_push_subs_user` FOREIGN KEY (`user_id`) REFERENCES `profiles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 3. GROUPES
-- =============================================================================

CREATE TABLE IF NOT EXISTS `groups` (
    `id` CHAR(36) NOT NULL DEFAULT (UUID()),
    `name` VARCHAR(100) NOT NULL,
    `details` TEXT DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `created_by` CHAR(36) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_groups_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_groups` (
    `id` CHAR(36) NOT NULL DEFAULT (UUID()),
    `user_id` CHAR(36) NOT NULL,
    `group_id` CHAR(36) NOT NULL,
    `assigned_by` CHAR(36) DEFAULT NULL,
    `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_groups` (`user_id`, `group_id`),
    KEY `fk_user_groups_group` (`group_id`),
    KEY `fk_user_groups_assigned_by` (`assigned_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 4. LIEUX & TYPES DE SÉANCES
-- =============================================================================

CREATE TABLE IF NOT EXISTS `locations` (
    `id` CHAR(36) NOT NULL DEFAULT (UUID()),
    `name` VARCHAR(100) NOT NULL,
    `address` VARCHAR(255) DEFAULT NULL,
    `city` VARCHAR(100) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` CHAR(36) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_locations_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `session_types` (
    `id` CHAR(36) NOT NULL DEFAULT (UUID()),
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `default_location_id` CHAR(36) DEFAULT NULL,
    `created_by` CHAR(36) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_session_types_location` (`default_location_id`),
    KEY `fk_session_types_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 5. SÉANCES & INSCRIPTIONS
-- =============================================================================

CREATE TABLE IF NOT EXISTS `sessions` (
    `id` CHAR(36) NOT NULL DEFAULT (UUID()),
    `title` VARCHAR(200) NOT NULL,
    `type_id` CHAR(36) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `location_id` CHAR(36) DEFAULT NULL,
    `date` DATE NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `capacity` INT DEFAULT NULL,
    `min_people` INT DEFAULT 1,
    `min_people_blocking` TINYINT(1) DEFAULT 1,
    `max_people` INT DEFAULT 10,
    `max_people_blocking` TINYINT(1) DEFAULT 1,
    `equipment_location` TEXT DEFAULT NULL,
    `equipment_coach` TEXT DEFAULT NULL,
    `equipment_clients` TEXT DEFAULT NULL,
    `status` VARCHAR(20) DEFAULT 'published',
    `limit_registration_7_days` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` CHAR(36) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_sessions_type` (`type_id`),
    KEY `fk_sessions_location` (`location_id`),
    KEY `fk_sessions_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `registrations` (
    `id` CHAR(36) NOT NULL DEFAULT (UUID()),
    `session_id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `attended` TINYINT(1) NOT NULL DEFAULT 0,
    `attended_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_registrations_session_user` (`session_id`, `user_id`),
    KEY `fk_registrations_user` (`user_id`),
    KEY `idx_registrations_attended` (`session_id`, `attended`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 6. COMMUNICATIONS CIBLÉES
-- =============================================================================

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
    KEY `fk_communications_author` (`author_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- =============================================================================
-- 7. LOGS
-- =============================================================================

CREATE TABLE IF NOT EXISTS `logs` (
    `id` CHAR(36) NOT NULL DEFAULT (UUID()),
    `user_id` CHAR(36) DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_logs_user` (`user_id`),
    KEY `idx_logs_action` (`action`),
    KEY `idx_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payments_history` (
    `id` CHAR(36) NOT NULL DEFAULT (UUID()),
    `user_id` CHAR(36) NOT NULL,
    `amount` DECIMAL(10,2) DEFAULT NULL,
    `payment_date` DATE NOT NULL,
    `payment_method` ENUM('espece','cheque','virement') DEFAULT NULL,
    `renewal_date` DATE DEFAULT NULL,
    `received_by` CHAR(36) DEFAULT NULL,
    `comment` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_payments_user` (`user_id`),
    KEY `idx_payments_received_by` (`received_by`),
    KEY `idx_payments_date` (`payment_date`),
    KEY `idx_payments_method` (`payment_method`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX `idx_profiles_renewal_date` ON `profiles`(`renewal_date`);
CREATE INDEX `idx_profiles_medical_certif_date` ON `profiles`(`medical_certificate_date`);

-- =============================================================================
-- 8. CONTRAINTES (CLÉS ÉTRANGÈRES)
-- =============================================================================

ALTER TABLE `profiles`
    ADD CONSTRAINT `fk_profiles_user` FOREIGN KEY (`id`) REFERENCES `users`(`id`) ON DELETE CASCADE;

ALTER TABLE `user_roles`
    ADD CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `profiles`(`id`) ON DELETE CASCADE;

ALTER TABLE `communications`
    ADD CONSTRAINT `fk_communications_author` FOREIGN KEY (`author_id`) REFERENCES `profiles`(`id`) ON DELETE SET NULL;

ALTER TABLE `groups`
    ADD CONSTRAINT `fk_groups_created_by` FOREIGN KEY (`created_by`) REFERENCES `profiles`(`id`) ON DELETE SET NULL;

ALTER TABLE `user_groups`
    ADD CONSTRAINT `fk_user_groups_user` FOREIGN KEY (`user_id`) REFERENCES `profiles`(`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `fk_user_groups_group` FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `fk_user_groups_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `profiles`(`id`) ON DELETE SET NULL;

ALTER TABLE `locations`
    ADD CONSTRAINT `fk_locations_created_by` FOREIGN KEY (`created_by`) REFERENCES `profiles`(`id`) ON DELETE SET NULL;

ALTER TABLE `session_types`
    ADD CONSTRAINT `fk_session_types_location` FOREIGN KEY (`default_location_id`) REFERENCES `locations`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_session_types_created_by` FOREIGN KEY (`created_by`) REFERENCES `profiles`(`id`) ON DELETE SET NULL;

ALTER TABLE `sessions`
    ADD CONSTRAINT `fk_sessions_type` FOREIGN KEY (`type_id`) REFERENCES `session_types`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_sessions_location` FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_sessions_created_by` FOREIGN KEY (`created_by`) REFERENCES `profiles`(`id`) ON DELETE SET NULL;

ALTER TABLE `registrations`
    ADD CONSTRAINT `fk_registrations_session` FOREIGN KEY (`session_id`) REFERENCES `sessions`(`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `fk_registrations_user` FOREIGN KEY (`user_id`) REFERENCES `profiles`(`id`) ON DELETE CASCADE;

ALTER TABLE `password_resets`
    ADD CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;

ALTER TABLE `refresh_tokens`
    ADD CONSTRAINT `fk_refresh_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;

ALTER TABLE `logs`
    ADD CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `profiles`(`id`) ON DELETE SET NULL;

ALTER TABLE `payments_history`
    ADD CONSTRAINT `fk_payments_user` FOREIGN KEY (`user_id`) REFERENCES `profiles`(`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `fk_payments_received_by` FOREIGN KEY (`received_by`) REFERENCES `profiles`(`id`) ON DELETE SET NULL;

-- =============================================================================
-- 9. VUES
-- =============================================================================

DROP VIEW IF EXISTS `v_profiles_with_roles`;
CREATE VIEW `v_profiles_with_roles` AS
SELECT
    p.id, p.first_name, p.last_name, p.email, p.phone,
    p.remarks_health, p.statut_compte, p.organization,
    p.avatar_base64, p.additional_info, p.coach_remarks,
    p.age, p.payment_status, p.renewal_date,
    p.notify_session_reminder_email,
    p.notify_session_reminder_push,
    p.notify_new_sessions_email,
    p.notify_new_sessions_push,
    p.notify_scheduled_sessions_email,
    p.notify_scheduled_sessions_push,
    p.notify_renewal_reminder_email,
    p.notify_renewal_reminder_push,
    p.notify_renewal_verify_email,
    p.notify_renewal_verify_push,
    p.medical_certificate_date,
    p.notify_medical_certif_email,
    p.notify_medical_certif_push,
    p.notify_expired_payment_email,
    p.notify_expired_payment_push,
    p.created_at, p.updated_at,
    GROUP_CONCAT(ur.role ORDER BY
        CASE ur.role WHEN 'admin' THEN 1 WHEN 'coach' THEN 2 ELSE 3 END
        SEPARATOR ','
    ) AS roles,
    (SELECT ur2.role FROM user_roles ur2
     WHERE ur2.user_id = p.id
     ORDER BY CASE ur2.role WHEN 'admin' THEN 1 WHEN 'coach' THEN 2 ELSE 3 END
     LIMIT 1
    ) AS primary_role
FROM profiles p
LEFT JOIN user_roles ur ON p.id = ur.user_id
GROUP BY p.id;

DROP VIEW IF EXISTS `v_sessions_full`;
CREATE VIEW `v_sessions_full` AS
SELECT
    s.id, s.title, s.type_id, s.description, s.location_id,
    s.date, s.start_time, s.end_time,
    s.min_people, s.max_people, s.capacity,
    s.limit_registration_7_days,
    s.equipment_location, s.equipment_coach, s.equipment_clients,
    s.status, s.created_by, s.created_at, s.updated_at,
    st.name AS type_name,
    st.description AS type_description,
    l.name AS location_name,
    l.address AS location_address,
    CONCAT(p.first_name, ' ', p.last_name) AS created_by_name,
    p.email AS coach_email,
    (SELECT COUNT(*) FROM registrations r WHERE r.session_id = s.id) AS registration_count,
    (SELECT COUNT(*) FROM registrations r WHERE r.session_id = s.id AND r.attended = 1) AS attended_count
FROM sessions s
LEFT JOIN session_types st ON s.type_id = st.id
LEFT JOIN locations l ON s.location_id = l.id
LEFT JOIN profiles p ON s.created_by = p.id;

-- Vue historique des séances avec présences (pour statistiques futures)
DROP VIEW IF EXISTS `v_session_history`;
CREATE VIEW `v_session_history` AS
SELECT
    s.id AS session_id,
    s.title,
    s.date,
    s.start_time,
    s.end_time,
    st.name AS session_type,
    l.name AS location,
    CONCAT(coach.first_name, ' ', coach.last_name) AS coach_name,
    coach.id AS coach_id,
    coach.email AS coach_email,
    r.user_id AS member_id,
    CONCAT(m.first_name, ' ', m.last_name) AS member_name,
    m.email AS member_email,
    r.attended,
    r.attended_at,
    r.created_at AS registered_at
FROM sessions s
LEFT JOIN session_types st ON st.id = s.type_id
LEFT JOIN locations l ON l.id = s.location_id
LEFT JOIN profiles coach ON coach.id = s.created_by
LEFT JOIN registrations r ON r.session_id = s.id
LEFT JOIN profiles m ON m.id = r.user_id
WHERE s.status != 'draft'
ORDER BY s.date DESC, s.start_time DESC, m.last_name ASC;


-- =============================================================================
-- 10. DONNÉES INITIALES
-- =============================================================================

-- Plus d'insertion manuelle requise pour les communications, elles seront créées via l'application.

SET foreign_key_checks = 1;

-- =============================================================================
-- ✅ TABLES ET VUES CRÉÉES !
-- ➡️ Exécute maintenant init_trigger.sql pour le trigger et la fonction uuid_v4
-- =============================================================================
