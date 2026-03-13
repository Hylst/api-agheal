-- =============================================================================
-- AGHeal - Trigger + Fonction UUID (À EXÉCUTER SÉPARÉMENT dans HeidiSQL)
-- =============================================================================
--
-- ⚠️ INSTRUCTIONS HEIDISQL :
-- 1. Ouvre ce fichier dans un NOUVEL onglet de requête
-- 2. En bas à gauche de la zone de requête, change le "Délimiteur" de ";" à "//"
-- 3. Exécute ce script (F9)
-- 4. Remets le délimiteur à ";" après l'exécution
-- =============================================================================

SET foreign_key_checks = 0//

-- Fonction UUID v4 (compatible MariaDB)
DROP FUNCTION IF EXISTS `uuid_v4`//

CREATE FUNCTION `uuid_v4` () RETURNS CHAR(36) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci DETERMINISTIC
BEGIN
    RETURN LOWER(CONCAT(
        HEX(RANDOM_BYTES(4)), '-',
        HEX(RANDOM_BYTES(2)), '-',
        '4', SUBSTR(HEX(RANDOM_BYTES(2)), 2, 3), '-',
        CONCAT(HEX(FLOOR(ASCII(RANDOM_BYTES(1)) / 64) + 8), SUBSTR(HEX(RANDOM_BYTES(2)), 2, 3)), '-',
        HEX(RANDOM_BYTES(6))
    ));
END//

-- Trigger : Création automatique du profil + rôle adherent à chaque inscription
DROP TRIGGER IF EXISTS `after_user_insert`//

CREATE TRIGGER `after_user_insert` AFTER INSERT ON `users` FOR EACH ROW
BEGIN
    INSERT IGNORE INTO `profiles` (`id`, `email`, `created_at`)
    VALUES (NEW.id, NEW.email, NOW());
    INSERT IGNORE INTO `user_roles` (`user_id`, `role`)
    VALUES (NEW.id, 'adherent');
END//

-- Sécurité : Empêcher de supprimer le dernier administrateur
DROP TRIGGER IF EXISTS `before_user_role_delete`//
CREATE TRIGGER `before_user_role_delete` BEFORE DELETE ON `user_roles` FOR EACH ROW
BEGIN
    DECLARE admin_count INT;
    IF OLD.role = 'admin' THEN
        SELECT COUNT(*) INTO admin_count FROM user_roles WHERE role = 'admin';
        IF admin_count <= 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Action interdite : Il doit rester au moins un administrateur dans le système.';
        END IF;
    END IF;
END//

-- Sécurité : Empêcher de bloquer le dernier administrateur actif
DROP TRIGGER IF EXISTS `before_profile_update_status`//
CREATE TRIGGER `before_profile_update_status` BEFORE UPDATE ON `profiles` FOR EACH ROW
BEGIN
    DECLARE is_admin_user INT;
    DECLARE admin_active_count INT;
    
    IF NEW.statut_compte = 'bloque' AND OLD.statut_compte = 'actif' THEN
        SELECT COUNT(*) INTO is_admin_user FROM user_roles WHERE user_id = NEW.id AND role = 'admin';
        
        IF is_admin_user > 0 THEN
            SELECT COUNT(DISTINCT ur.user_id) INTO admin_active_count 
            FROM user_roles ur
            JOIN profiles p ON p.id = ur.user_id
            WHERE ur.role = 'admin' AND p.statut_compte = 'actif';
            
            IF admin_active_count <= 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Action interdite : Impossible de bloquer le dernier administrateur actif.';
            END IF;
        END IF;
    END IF;
END//

SET foreign_key_checks = 1//
