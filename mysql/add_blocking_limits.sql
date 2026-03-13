-- Script de migration pour ajouter les colonnes bloquantes
-- Exécutable sur la base de données de production (MariaDB)

ALTER TABLE `sessions`
ADD COLUMN `min_people_blocking` TINYINT(1) DEFAULT 1 AFTER `min_people`,
ADD COLUMN `max_people_blocking` TINYINT(1) DEFAULT 1 AFTER `max_people`;
