-- =============================================================================
-- AGHeal - seed.sql : Données de test (Mars 2026)
-- Version : 2.0 - Corrigée pour correspondre exactement à init.sql
-- =============================================================================
-- COMMENT UTILISER CE SCRIPT :
--   1. Exécuter init.sql (schema complet)
--   2. Exécuter init_trigger.sql (trigger de création de profil automatique)
--   3. Exécuter CE FICHIER dans HeidiSQL
-- Ce script est IDEMPOTENT : INSERT IGNORE + DELETE ciblé en début de script.
-- Il peut être rejoué sans risque si les UUIDs fixes sont déjà en base.
-- Date de référence des données : 26/03/2026
-- =============================================================================

USE `agheal`;
SET foreign_key_checks = 0;

-- ─────────────────────────────────────────────────────────────
-- 0. NETTOYAGE (suppression des anciennes données de test)
--    Basé sur les préfixes UUID fixes utilisés dans ce script.
--    Cela permet de rejouer le script même si une exécution partielle
--    a déjà inséré des données.
-- ─────────────────────────────────────────────────────────────
DELETE FROM `registrations`  WHERE `session_id` LIKE 'ses-0000-%';
DELETE FROM `registrations`  WHERE `id` LIKE 'reg-0000-%';
DELETE FROM `sessions`       WHERE `id` LIKE 'ses-0000-%';
DELETE FROM `payments_history` WHERE `user_id` LIKE 'usr-%';
DELETE FROM `user_groups`    WHERE `user_id`  LIKE 'usr-%';
DELETE FROM `user_roles`     WHERE `user_id`  LIKE 'usr-%';
DELETE FROM `profiles`       WHERE `id`       LIKE 'usr-%';
DELETE FROM `users`          WHERE `id`       LIKE 'usr-%';
DELETE FROM `groups`         WHERE `id`       LIKE 'grp-0000-%';
DELETE FROM `session_types`  WHERE `id`       LIKE 'sty-0000-%';
DELETE FROM `locations`      WHERE `id`       LIKE 'loc-0000-%';


-- ─────────────────────────────────────────────────────────────
-- 1. LIEUX
--    Colonne `city` présente dans init.sql, on l'inclut.
-- ─────────────────────────────────────────────────────────────
INSERT INTO `locations` (`id`, `name`, `address`, `city`, `notes`) VALUES
('loc-0000-0001-0000-0000-000000000001', 'Salle Principale',  '12 Rue Gambetta', 'Strasbourg', 'Grande salle, parquet, miroirs'),
('loc-0000-0002-0000-0000-000000000002', 'Parking Extérieur', 'Place de la Gare', 'Strasbourg', 'Zone balisée, tenue sportive obligatoire'),
('loc-0000-0003-0000-0000-000000000003', 'Salle Annexe B',    '14 Rue Gambetta', 'Strasbourg', 'Petite salle, idéale yoga');


-- ─────────────────────────────────────────────────────────────
-- 2. TYPES DE SÉANCES (Activités)
-- ─────────────────────────────────────────────────────────────
INSERT INTO `session_types` (`id`, `name`, `description`) VALUES
('sty-0000-0001-0000-0000-000000000001', 'Gym Douce',       'Exercices en douceur adaptés à tous, idéaux pour les seniors.'),
('sty-0000-0002-0000-0000-000000000002', 'Pilates',         'Travail sur la posture et le gainage en profondeur.'),
('sty-0000-0003-0000-0000-000000000003', 'Marche Nordique', 'Marche sportive avec bâtons en extérieur.'),
('sty-0000-0004-0000-0000-000000000004', 'Renforcement',    'Exercices de musculation au poids du corps.'),
('sty-0000-0005-0000-0000-000000000005', 'Yoga',            'Séance de yoga flow, respiration et étirements.');


-- ─────────────────────────────────────────────────────────────
-- 3. GROUPES
--    La table groups a un id CHAR(36) — on utilise des UUIDs fixes.
-- ─────────────────────────────────────────────────────────────
INSERT INTO `groups` (`id`, `name`, `details`) VALUES
('grp-0000-0001-0000-0000-000000000001', 'Seniors',      'Adhérents de 60 ans et plus'),
('grp-0000-0002-0000-0000-000000000002', 'Cardio',       'Groupe orienté séances cardio-vasculaires'),
('grp-0000-0003-0000-0000-000000000003', 'Débutants',    'Nouveaux adhérents (moins de 6 mois)');


-- ─────────────────────────────────────────────────────────────
-- 4. COMPTES UTILISATEURS
--    ⚠️  Colonnes correctes dans `users` : id, email, password_hash, email_confirmed_at
--    Le trigger `after_user_insert` crée automatiquement le profil ET le rôle 'adherent'.
--    On fait un UPDATE du profil créé par le trigger, puis on gère les rôles manuellement.
--    Mot de passe pour TOUS les comptes de test : password
--    Hash bcrypt de 'password' : $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
-- ─────────────────────────────────────────────────────────────

-- ── Admin ─────────────────────────────────────────────────────
INSERT INTO `users` (`id`, `email`, `password_hash`, `email_confirmed_at`) VALUES
('usr-admin-0000-0000-0000-000000000001', 'admin@agheal-adaptmovement.fr',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-01-01 00:00:00');

INSERT IGNORE INTO `profiles` (`id`, `email`, `first_name`, `last_name`, `payment_status`, `statut_compte`) VALUES
('usr-admin-0000-0000-0000-000000000001', 'admin@agheal-adaptmovement.fr', 'Admin', 'AGHeal', 'a_jour', 'actif');

INSERT IGNORE INTO `user_roles` (`user_id`, `role`) VALUES
('usr-admin-0000-0000-0000-000000000001', 'admin');

-- ── Coach 1 : Guillaume ───────────────────────────────────────
INSERT INTO `users` (`id`, `email`, `password_hash`, `email_confirmed_at`) VALUES
('usr-guil--0000-0000-0000-000000000002', 'guillaume@agheal.fr',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-01-01 00:00:00');

INSERT IGNORE INTO `profiles` (`id`, `email`, `first_name`, `last_name`, `age`, `payment_status`, `statut_compte`, `medical_certificate_date`) VALUES
('usr-guil--0000-0000-0000-000000000002', 'guillaume@agheal.fr', 'Guillaume', 'Trautmann', 38, 'a_jour', 'actif', '2026-10-01');

INSERT IGNORE INTO `user_roles` (`user_id`, `role`) VALUES
('usr-guil--0000-0000-0000-000000000002', 'coach'),
('usr-guil--0000-0000-0000-000000000002', 'adherent');

-- ── Coach 2 : Amandine ────────────────────────────────────────
INSERT INTO `users` (`id`, `email`, `password_hash`, `email_confirmed_at`) VALUES
('usr-aman--0000-0000-0000-000000000003', 'amandine@adaptmovement.fr',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-01-01 00:00:00');

INSERT IGNORE INTO `profiles` (`id`, `email`, `first_name`, `last_name`, `age`, `payment_status`, `statut_compte`, `medical_certificate_date`) VALUES
('usr-aman--0000-0000-0000-000000000003', 'amandine@adaptmovement.fr', 'Amandine', 'Motsch', 35, 'a_jour', 'actif', '2026-08-15');

INSERT IGNORE INTO `user_roles` (`user_id`, `role`) VALUES
('usr-aman--0000-0000-0000-000000000003', 'coach'),
('usr-aman--0000-0000-0000-000000000003', 'adherent');

-- ── Adhérents (8 adhérents) ───────────────────────────────────
INSERT INTO `users` (`id`, `email`, `password_hash`, `email_confirmed_at`) VALUES
('usr-marie-0000-0000-0000-000000000004', 'marie.dupont@email.fr',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-01-05 00:00:00'),
('usr-jean--0000-0000-0000-000000000005', 'jean.michel@email.fr',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-01-10 00:00:00'),
('usr-sylv--0000-0000-0000-000000000006', 'sylvie.martin@email.fr',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-01-12 00:00:00'),
('usr-paul--0000-0000-0000-000000000007', 'paul.leblanc@email.fr',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-02-01 00:00:00'),
('usr-clai--0000-0000-0000-000000000008', 'claire.renard@email.fr',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-02-15 00:00:00'),
('usr-marc--0000-0000-0000-000000000009', 'marc.pierre@email.fr',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-01-20 00:00:00'),
('usr-nath--0000-0000-0000-00000000000c', 'nathalie.simon@email.fr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-02-28 00:00:00'),
('usr-andr--0000-0000-0000-00000000000d', 'andre.fontaine@email.fr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-03-01 00:00:00');

INSERT IGNORE INTO `profiles` (`id`, `email`, `first_name`, `last_name`, `age`, `payment_status`, `statut_compte`, `medical_certificate_date`) VALUES
('usr-marie-0000-0000-0000-000000000004', 'marie.dupont@email.fr',   'Marie',    'Dupont',   67, 'a_jour',     'actif',  '2026-02-10'),
('usr-jean--0000-0000-0000-000000000005', 'jean.michel@email.fr',    'Jean',     'Michel',   72, 'en_attente', 'actif',  '2025-07-01'),
('usr-sylv--0000-0000-0000-000000000006', 'sylvie.martin@email.fr',  'Sylvie',   'Martin',   58, 'a_jour',     'actif',  '2026-09-20'),
('usr-paul--0000-0000-0000-000000000007', 'paul.leblanc@email.fr',   'Paul',     'Leblanc',  45, 'a_jour',     'actif',  '2026-11-05'),
('usr-clai--0000-0000-0000-000000000008', 'claire.renard@email.fr',  'Claire',   'Renard',   63, 'a_jour',     'actif',  '2026-04-12'),
('usr-marc--0000-0000-0000-000000000009', 'marc.pierre@email.fr',    'Marc',     'Pierre',   55, 'en_attente', 'bloque', NULL),
('usr-nath--0000-0000-0000-00000000000c', 'nathalie.simon@email.fr', 'Nathalie', 'Simon',    61, 'a_jour',     'actif',  '2026-06-30'),
('usr-andr--0000-0000-0000-00000000000d', 'andre.fontaine@email.fr', 'Andre',    'Fontaine', 78, 'a_jour',     'actif',  '2025-12-01');

INSERT IGNORE INTO `user_roles` (`user_id`, `role`) VALUES
('usr-marie-0000-0000-0000-000000000004', 'adherent'),
('usr-jean--0000-0000-0000-000000000005', 'adherent'),
('usr-sylv--0000-0000-0000-000000000006', 'adherent'),
('usr-paul--0000-0000-0000-000000000007', 'adherent'),
('usr-clai--0000-0000-0000-000000000008', 'adherent'),
('usr-marc--0000-0000-0000-000000000009', 'adherent'),
('usr-nath--0000-0000-0000-00000000000c', 'adherent'),
('usr-andr--0000-0000-0000-00000000000d', 'adherent');

-- Assignation aux groupes (group_id = UUID CHAR(36))
INSERT IGNORE INTO `user_groups` (`user_id`, `group_id`) VALUES
('usr-marie-0000-0000-0000-000000000004', 'grp-0000-0001-0000-0000-000000000001'),
('usr-jean--0000-0000-0000-000000000005', 'grp-0000-0001-0000-0000-000000000001'),
('usr-nath--0000-0000-0000-00000000000c', 'grp-0000-0001-0000-0000-000000000001'),
('usr-andr--0000-0000-0000-00000000000d', 'grp-0000-0001-0000-0000-000000000001'),
('usr-paul--0000-0000-0000-000000000007', 'grp-0000-0002-0000-0000-000000000002'),
('usr-sylv--0000-0000-0000-000000000006', 'grp-0000-0002-0000-0000-000000000002'),
('usr-clai--0000-0000-0000-000000000008', 'grp-0000-0003-0000-0000-000000000003');


-- ─────────────────────────────────────────────────────────────
-- 5. SÉANCES
--    ⚠️  Les IDs doivent faire exactement 36 caractères (CHAR(36)).
--    Format utilisé : ses-0000-XXXX-YYYY-ZZZZ-NNNNNNNNNNNN (36 chars)
--    Passées : pour historique + stats
--    AUJOURD'HUI (26/03/2026) : pour tester l'appel en temps réel
--    Futures : pour tester l'inscription au planning
-- ─────────────────────────────────────────────────────────────

INSERT INTO `sessions`
    (`id`, `title`, `date`, `start_time`, `end_time`, `status`, `max_people`, `min_people`, `type_id`, `location_id`, `created_by`, `limit_registration_7_days`)
VALUES
-- ── Séances PASSÉES ──────────────────────────────────────────
('ses-0000-p01--0000-0000-000000000001', 'Gym Douce Lundi matin', '2026-03-04', '09:00:00', '10:00:00', 'published', 12, 3, 'sty-0000-0001-0000-0000-000000000001', 'loc-0000-0001-0000-0000-000000000001', 'usr-guil--0000-0000-0000-000000000002', 1),
('ses-0000-p02--0000-0000-000000000002', 'Pilates Mercredi',      '2026-03-06', '10:30:00', '11:30:00', 'published', 10, 3, 'sty-0000-0002-0000-0000-000000000002', 'loc-0000-0003-0000-0000-000000000003', 'usr-aman--0000-0000-0000-000000000003', 1),
('ses-0000-p03--0000-0000-000000000003', 'Marche Nordique Jeudi', '2026-03-12', '14:00:00', '15:30:00', 'published', 15, 5, 'sty-0000-0003-0000-0000-000000000003', 'loc-0000-0002-0000-0000-000000000002', 'usr-guil--0000-0000-0000-000000000002', 1),
('ses-0000-p04--0000-0000-000000000004', 'Gym Douce Lundi matin', '2026-03-11', '09:00:00', '10:00:00', 'published', 12, 3, 'sty-0000-0001-0000-0000-000000000001', 'loc-0000-0001-0000-0000-000000000001', 'usr-guil--0000-0000-0000-000000000002', 1),
('ses-0000-p05--0000-0000-000000000005', 'Pilates Mercredi',      '2026-03-13', '10:30:00', '11:30:00', 'published', 10, 3, 'sty-0000-0002-0000-0000-000000000002', 'loc-0000-0003-0000-0000-000000000003', 'usr-aman--0000-0000-0000-000000000003', 1),
('ses-0000-p06--0000-0000-000000000006', 'Renforcement Vendredi', '2026-03-14', '17:00:00', '18:00:00', 'published',  8, 2, 'sty-0000-0004-0000-0000-000000000004', 'loc-0000-0001-0000-0000-000000000001', 'usr-guil--0000-0000-0000-000000000002', 0),
('ses-0000-p07--0000-0000-000000000007', 'Gym Douce Lundi matin', '2026-03-18', '09:00:00', '10:00:00', 'published', 12, 3, 'sty-0000-0001-0000-0000-000000000001', 'loc-0000-0001-0000-0000-000000000001', 'usr-guil--0000-0000-0000-000000000002', 1),
('ses-0000-p08--0000-0000-000000000008', 'Yoga du Mardi',         '2026-03-17', '18:30:00', '19:30:00', 'published', 10, 3, 'sty-0000-0005-0000-0000-000000000005', 'loc-0000-0003-0000-0000-000000000003', 'usr-aman--0000-0000-0000-000000000003', 0),
('ses-0000-p09--0000-0000-000000000009', 'Marche Nordique Jeudi', '2026-03-19', '14:00:00', '15:30:00', 'published', 15, 5, 'sty-0000-0003-0000-0000-000000000003', 'loc-0000-0002-0000-0000-000000000002', 'usr-guil--0000-0000-0000-000000000002', 1),
('ses-0000-p10--0000-0000-000000000010', 'Gym Douce Lundi matin', '2026-03-25', '09:00:00', '10:00:00', 'published', 12, 3, 'sty-0000-0001-0000-0000-000000000001', 'loc-0000-0001-0000-0000-000000000001', 'usr-guil--0000-0000-0000-000000000002', 1),
-- ═══════════════════════════════════════════════════════════════
-- ⭐ SÉANCE DU JOUR 26/03/2026 → TESTER L'APPEL ICI
--    5 inscrits non pointés. Connexion : guillaume@agheal.fr / password
--    Accès : Planification > séance du 26/03 > onglet Présences
-- ═══════════════════════════════════════════════════════════════
('ses-0000-now--0000-0000-000000000011', 'Pilates Mercredi',      '2026-03-26', '10:30:00', '11:30:00', 'published', 10, 3, 'sty-0000-0002-0000-0000-000000000002', 'loc-0000-0003-0000-0000-000000000003', 'usr-aman--0000-0000-0000-000000000003', 1),
-- ── Séances FUTURES (inscriptions ouvertes J-7) ───────────────
('ses-0000-f01--0000-0000-000000000012', 'Renforcement Vendredi', '2026-03-28', '17:00:00', '18:00:00', 'published',  8, 2, 'sty-0000-0004-0000-0000-000000000004', 'loc-0000-0001-0000-0000-000000000001', 'usr-guil--0000-0000-0000-000000000002', 0),
('ses-0000-f02--0000-0000-000000000013', 'Yoga du Mardi',         '2026-03-31', '18:30:00', '19:30:00', 'published', 10, 3, 'sty-0000-0005-0000-0000-000000000005', 'loc-0000-0003-0000-0000-000000000003', 'usr-aman--0000-0000-0000-000000000003', 0),
-- ── Séances FUTURES hors J-7 (inscription bloquée adhérents) ──
('ses-0000-f03--0000-0000-000000000014', 'Gym Douce Lundi matin', '2026-04-06', '09:00:00', '10:00:00', 'published', 12, 3, 'sty-0000-0001-0000-0000-000000000001', 'loc-0000-0001-0000-0000-000000000001', 'usr-guil--0000-0000-0000-000000000002', 1),
('ses-0000-f04--0000-0000-000000000015', 'Pilates Mercredi',      '2026-04-08', '10:30:00', '11:30:00', 'published', 10, 3, 'sty-0000-0002-0000-0000-000000000002', 'loc-0000-0003-0000-0000-000000000003', 'usr-aman--0000-0000-0000-000000000003', 1);


-- ─────────────────────────────────────────────────────────────
-- 6. INSCRIPTIONS + PRÉSENCES (historique + séance du jour)
-- ─────────────────────────────────────────────────────────────

-- p01 : 5 inscrits, 4 présents
INSERT INTO `registrations` (`id`, `session_id`, `user_id`, `attended`, `attended_at`) VALUES
(UUID(), 'ses-0000-p01--0000-0000-000000000001', 'usr-marie-0000-0000-0000-000000000004', 1, '2026-03-04 09:03:00'),
(UUID(), 'ses-0000-p01--0000-0000-000000000001', 'usr-jean--0000-0000-0000-000000000005', 1, '2026-03-04 09:07:00'),
(UUID(), 'ses-0000-p01--0000-0000-000000000001', 'usr-nath--0000-0000-0000-00000000000c', 1, '2026-03-04 09:02:00'),
(UUID(), 'ses-0000-p01--0000-0000-000000000001', 'usr-andr--0000-0000-0000-00000000000d', 1, '2026-03-04 09:10:00'),
(UUID(), 'ses-0000-p01--0000-0000-000000000001', 'usr-clai--0000-0000-0000-000000000008', 0, NULL);

-- p02 : 4 inscrits, 3 présents
INSERT INTO `registrations` (`id`, `session_id`, `user_id`, `attended`, `attended_at`) VALUES
(UUID(), 'ses-0000-p02--0000-0000-000000000002', 'usr-sylv--0000-0000-0000-000000000006', 1, '2026-03-06 10:32:00'),
(UUID(), 'ses-0000-p02--0000-0000-000000000002', 'usr-clai--0000-0000-0000-000000000008', 1, '2026-03-06 10:35:00'),
(UUID(), 'ses-0000-p02--0000-0000-000000000002', 'usr-paul--0000-0000-0000-000000000007', 1, '2026-03-06 10:30:00'),
(UUID(), 'ses-0000-p02--0000-0000-000000000002', 'usr-nath--0000-0000-0000-00000000000c', 0, NULL);

-- p03 : 3 inscrits, 2 présents
INSERT INTO `registrations` (`id`, `session_id`, `user_id`, `attended`, `attended_at`) VALUES
(UUID(), 'ses-0000-p03--0000-0000-000000000003', 'usr-paul--0000-0000-0000-000000000007', 1, '2026-03-12 14:02:00'),
(UUID(), 'ses-0000-p03--0000-0000-000000000003', 'usr-sylv--0000-0000-0000-000000000006', 1, '2026-03-12 14:05:00'),
(UUID(), 'ses-0000-p03--0000-0000-000000000003', 'usr-clai--0000-0000-0000-000000000008', 0, NULL);

-- p04 : 5 inscrits, 5 présents
INSERT INTO `registrations` (`id`, `session_id`, `user_id`, `attended`, `attended_at`) VALUES
(UUID(), 'ses-0000-p04--0000-0000-000000000004', 'usr-marie-0000-0000-0000-000000000004', 1, '2026-03-11 09:05:00'),
(UUID(), 'ses-0000-p04--0000-0000-000000000004', 'usr-jean--0000-0000-0000-000000000005', 1, '2026-03-11 09:00:00'),
(UUID(), 'ses-0000-p04--0000-0000-000000000004', 'usr-nath--0000-0000-0000-00000000000c', 1, '2026-03-11 09:08:00'),
(UUID(), 'ses-0000-p04--0000-0000-000000000004', 'usr-andr--0000-0000-0000-00000000000d', 1, '2026-03-11 09:15:00'),
(UUID(), 'ses-0000-p04--0000-0000-000000000004', 'usr-sylv--0000-0000-0000-000000000006', 1, '2026-03-11 09:01:00');

-- p07 : 4 inscrits, 3 présents
INSERT INTO `registrations` (`id`, `session_id`, `user_id`, `attended`, `attended_at`) VALUES
(UUID(), 'ses-0000-p07--0000-0000-000000000007', 'usr-marie-0000-0000-0000-000000000004', 1, '2026-03-18 09:02:00'),
(UUID(), 'ses-0000-p07--0000-0000-000000000007', 'usr-nath--0000-0000-0000-00000000000c', 1, '2026-03-18 09:10:00'),
(UUID(), 'ses-0000-p07--0000-0000-000000000007', 'usr-andr--0000-0000-0000-00000000000d', 1, '2026-03-18 09:04:00'),
(UUID(), 'ses-0000-p07--0000-0000-000000000007', 'usr-jean--0000-0000-0000-000000000005', 0, NULL);

-- p09 : 3 inscrits, 2 présents
INSERT INTO `registrations` (`id`, `session_id`, `user_id`, `attended`, `attended_at`) VALUES
(UUID(), 'ses-0000-p09--0000-0000-000000000009', 'usr-paul--0000-0000-0000-000000000007', 1, '2026-03-19 14:03:00'),
(UUID(), 'ses-0000-p09--0000-0000-000000000009', 'usr-sylv--0000-0000-0000-000000000006', 1, '2026-03-19 14:08:00'),
(UUID(), 'ses-0000-p09--0000-0000-000000000009', 'usr-marie-0000-0000-0000-000000000004', 0, NULL);

-- ⭐ SÉANCE DU JOUR 26/03/2026 : 5 inscrits, AUCUN pointé
INSERT INTO `registrations` (`id`, `session_id`, `user_id`, `attended`, `attended_at`) VALUES
('reg-0000-now1-0000-0000-000000000001', 'ses-0000-now--0000-0000-000000000011', 'usr-marie-0000-0000-0000-000000000004', 0, NULL),
('reg-0000-now2-0000-0000-000000000002', 'ses-0000-now--0000-0000-000000000011', 'usr-jean--0000-0000-0000-000000000005', 0, NULL),
('reg-0000-now3-0000-0000-000000000003', 'ses-0000-now--0000-0000-000000000011', 'usr-nath--0000-0000-0000-00000000000c', 0, NULL),
('reg-0000-now4-0000-0000-000000000004', 'ses-0000-now--0000-0000-000000000011', 'usr-andr--0000-0000-0000-00000000000d', 0, NULL),
('reg-0000-now5-0000-0000-000000000005', 'ses-0000-now--0000-0000-000000000011', 'usr-sylv--0000-0000-0000-000000000006', 0, NULL);

-- Inscriptions futures (pour agenda des adhérents)
INSERT INTO `registrations` (`id`, `session_id`, `user_id`, `attended`, `attended_at`) VALUES
(UUID(), 'ses-0000-f01--0000-0000-000000000012', 'usr-sylv--0000-0000-0000-000000000006', 0, NULL),
(UUID(), 'ses-0000-f01--0000-0000-000000000012', 'usr-clai--0000-0000-0000-000000000008', 0, NULL),
(UUID(), 'ses-0000-f02--0000-0000-000000000013', 'usr-paul--0000-0000-0000-000000000007', 0, NULL),
(UUID(), 'ses-0000-f02--0000-0000-000000000013', 'usr-nath--0000-0000-0000-00000000000c', 0, NULL);


-- ─────────────────────────────────────────────────────────────
-- 7. RÈGLEMENTS (payments_history)
--    ⚠️  Colonne correcte : `received_by` (pas coach_id)
--    ⚠️  ENUM `payment_method` : 'espece' (sans 's'), 'cheque', 'virement'
-- ─────────────────────────────────────────────────────────────
INSERT INTO `payments_history` (`id`, `user_id`, `received_by`, `amount`, `payment_method`, `payment_date`, `comment`) VALUES
(UUID(), 'usr-marie-0000-0000-0000-000000000004', 'usr-guil--0000-0000-0000-000000000002', 250.00, 'cheque',   '2026-01-10', 'Cotisation annuelle 2026'),
(UUID(), 'usr-jean--0000-0000-0000-000000000005', 'usr-guil--0000-0000-0000-000000000002', 250.00, 'espece',   '2026-01-15', 'Cotisation annuelle 2026'),
(UUID(), 'usr-sylv--0000-0000-0000-000000000006', 'usr-aman--0000-0000-0000-000000000003', 250.00, 'virement', '2026-01-20', 'Cotisation annuelle 2026'),
(UUID(), 'usr-paul--0000-0000-0000-000000000007', 'usr-guil--0000-0000-0000-000000000002', 250.00, 'cheque',   '2026-02-03', 'Cotisation annuelle 2026'),
(UUID(), 'usr-clai--0000-0000-0000-000000000008', 'usr-aman--0000-0000-0000-000000000003', 180.00, 'espece',   '2026-02-20', 'Demi-cotisation (adhesion en cours annee)'),
(UUID(), 'usr-nath--0000-0000-0000-00000000000c', 'usr-guil--0000-0000-0000-000000000002', 250.00, 'virement', '2026-03-01', 'Cotisation annuelle 2026'),
(UUID(), 'usr-andr--0000-0000-0000-00000000000d', 'usr-aman--0000-0000-0000-000000000003', 250.00, 'cheque',   '2026-03-10', 'Cotisation annuelle 2026');


SET foreign_key_checks = 1;

-- ─────────────────────────────────────────────────────────────
-- RÉCAPITULATIF DES COMPTES DE TEST (mot de passe : password)
-- ─────────────────────────────────────────────────────────────
--  ROLE  | EMAIL                             | NOM
-- --------|-----------------------------------|-------------------
--  admin  | admin@agheal-adaptmovement.fr     | Admin AGHeal
--  coach  | guillaume@agheal.fr               | Guillaume Trautmann
--  coach  | amandine@adaptmovement.fr         | Amandine Motsch
--  adh.   | marie.dupont@email.fr             | Marie Dupont (67 ans)
--  adh.   | jean.michel@email.fr              | Jean Michel (72 ans, paiement en attente)
--  adh.   | sylvie.martin@email.fr            | Sylvie Martin (58 ans)
--  adh.   | paul.leblanc@email.fr             | Paul Leblanc (45 ans)
--  adh.   | claire.renard@email.fr            | Claire Renard (63 ans)
--  adh.   | marc.pierre@email.fr              | Marc Pierre (BLOQUÉ, paiement en attente)
--  adh.   | nathalie.simon@email.fr           | Nathalie Simon (61 ans)
--  adh.   | andre.fontaine@email.fr           | Andre Fontaine (78 ans, certif expiré)
--
-- ⭐ SÉANCE DU JOUR (26/03/2026) POUR TESTER L'APPEL :
--    "Pilates Mercredi" 26/03 10h30
--    Connexion : amandine@adaptmovement.fr → Planification → Présences
--    5 adhérents inscrits, AUCUN pointé.
--

SELECT CONCAT(
    'Seed OK : ',
    (SELECT COUNT(*) FROM users WHERE id LIKE 'usr-%'), ' users / ',
    (SELECT COUNT(*) FROM sessions WHERE id LIKE 'ses-0000-%'), ' seances / ',
    (SELECT COUNT(*) FROM registrations), ' inscriptions / ',
    (SELECT COUNT(*) FROM payments_history), ' paiements'
) AS Resultat;
