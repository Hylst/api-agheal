-- =============================================================================
-- Migration: Ajout de la gestion des présences (Attendance)
-- Date : 2026-03-25
-- À exécuter UNE SEULE FOIS sur la base de données existante
-- (Inutile pour les nouvelles installations : déjà inclus dans init.sql)
-- =============================================================================

-- 1. Ajouter la colonne limit_registration_7_days à sessions (si pas déjà faite)
ALTER TABLE `sessions`
    ADD COLUMN IF NOT EXISTS `limit_registration_7_days` TINYINT(1) NOT NULL DEFAULT 0;

-- 2. Ajouter les colonnes de présence à registrations
ALTER TABLE `registrations`
    ADD COLUMN IF NOT EXISTS `attended` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `attended_at` TIMESTAMP NULL DEFAULT NULL;

-- 3. Ajouter un index de performance sur la colonne attended
ALTER TABLE `registrations`
    ADD INDEX IF NOT EXISTS `idx_registrations_attended` (`session_id`, `attended`);

-- 4. Mettre à jour la vue v_sessions_full (inclut limit_registration_7_days + attended_count)
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

-- 5. Créer la vue v_session_history (pour statistiques futures)
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

