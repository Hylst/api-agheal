-- Script de secours pour créer la table payments_history si manquante
-- À exécuter dans HeidiSQL sur la base 'agheal'

USE `agheal`;

CREATE TABLE IF NOT EXISTS `payments_history` (
    `id` CHAR(36) NOT NULL DEFAULT (UUID()),
    `user_id` CHAR(36) NOT NULL,
    `amount` DECIMAL(10,2) DEFAULT NULL,
    `payment_date` DATE NOT NULL,
    `renewal_date` DATE DEFAULT NULL,
    `received_by` CHAR(36) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_payments_user` (`user_id`),
    KEY `idx_payments_received_by` (`received_by`),
    KEY `idx_payments_date` (`payment_date`),
    CONSTRAINT `fk_payments_user` FOREIGN KEY (`user_id`) REFERENCES `profiles`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_payments_received_by` FOREIGN KEY (`received_by`) REFERENCES `profiles`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
