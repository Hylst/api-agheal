-- =============================================================================
-- AGHeal - Migration : Enrichissement de payments_history
-- À exécuter via HeidiSQL sur la base agheal existante
-- Date : 2026-03-15
-- =============================================================================

ALTER TABLE `payments_history`
  ADD COLUMN IF NOT EXISTS `payment_method` ENUM('espece','cheque','virement') DEFAULT NULL AFTER `payment_date`,
  ADD COLUMN IF NOT EXISTS `comment` TEXT DEFAULT NULL AFTER `received_by`;
