-- Ajout des nouveaux champs pour le module Certificat Médical et Gestion des Expirations
ALTER TABLE `profiles`
ADD COLUMN `medical_certificate_date` DATE DEFAULT NULL,
ADD COLUMN `notify_medical_certif_email` TINYINT(1) DEFAULT 1,
ADD COLUMN `notify_expired_payment_email` TINYINT(1) DEFAULT 0;

-- Index pour faciliter les recherches CRON sur les certificats médicaux
CREATE INDEX `idx_profiles_medical_certif_date` ON `profiles`(`medical_certificate_date`);
