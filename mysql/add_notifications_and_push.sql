-- Ajout des colonnes de notification manquantes dans la table `profiles`
ALTER TABLE `profiles`
ADD COLUMN IF NOT EXISTS `notify_medical_certif_push` TINYINT(1) DEFAULT 0 AFTER `notify_medical_certif_email`,
ADD COLUMN IF NOT EXISTS `notify_renewal_verify_email` TINYINT(1) DEFAULT 1 AFTER `notify_renewal_reminder_push`,
ADD COLUMN IF NOT EXISTS `notify_renewal_verify_push` TINYINT(1) DEFAULT 0 AFTER `notify_renewal_verify_email`,
ADD COLUMN IF NOT EXISTS `notify_expired_payment_push` TINYINT(1) DEFAULT 0 AFTER `notify_expired_payment_email`;

-- Création de la table des abonnements Push (VAPID)
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

-- Mettre à jour la vue `v_profiles_with_roles` pour inclure les nouveaux champs
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
