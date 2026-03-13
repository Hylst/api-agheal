-- Script de données de test pour AGHeal
USE agheal;

-- 1. Création d'un compte Coach/Admin
-- Password is 'password123'
INSERT INTO users (id, email, password_hash, email_confirmed_at) VALUES 
('c0a80101-0000-0000-0000-000000000001', 'coach@agheal.fr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NOW());

INSERT INTO profiles (id, first_name, last_name, email, statut_compte) VALUES
('c0a80101-0000-0000-0000-000000000001', 'Jean', 'Coach', 'coach@agheal.fr', 'actif')
ON DUPLICATE KEY UPDATE first_name = 'Jean', last_name = 'Coach';

INSERT INTO user_roles (user_id, role) VALUES 
('c0a80101-0000-0000-0000-000000000001', 'coach'),
('c0a80101-0000-0000-0000-000000000001', 'admin')
ON DUPLICATE KEY UPDATE role = role;

-- 2. Création d'un compte Adhérent
-- Password is 'password123'
INSERT INTO users (id, email, password_hash, email_confirmed_at) VALUES 
('c0a80101-0000-0000-0000-000000000002', 'user@agheal.fr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NOW());

INSERT INTO profiles (id, first_name, last_name, email, statut_compte) VALUES
('c0a80101-0000-0000-0000-000000000002', 'Alice', 'Adhérente', 'user@agheal.fr', 'actif')
ON DUPLICATE KEY UPDATE first_name = 'Alice', last_name = 'Adhérente';

-- 3. Lieux de test
INSERT INTO locations (id, name, address, notes) VALUES
('l0000000-0000-0000-0000-000000000001', 'Salle Antigravité', '123 Rue du Sport, Paris', 'Code porte 1234'),
('l0000000-0000-0000-0000-000000000002', 'Parc de la Santé', 'Avenue Verte, Lyon', 'Rendez-vous devant la fontaine');

-- 4. Types de séances
INSERT INTO session_types (id, name, description, default_location_id) VALUES
('t0000000-0000-0000-0000-000000000001', 'Yoga Aérien', 'Une séance de yoga dans les airs.', 'l0000000-0000-0000-0000-000000000001'),
('t0000000-0000-0000-0000-000000000002', 'Renforcement Musculaire', 'Entraînement complet du corps.', 'l0000000-0000-0000-0000-000000000002');

-- 5. Quelques séances de test (Demain et après-demain)
INSERT INTO sessions (id, title, type_id, location_id, date, start_time, end_time, status, max_people) VALUES
(UUID(), 'Yoga Matinal', 't0000000-0000-0000-0000-000000000001', 'l0000000-0000-0000-0000-000000000001', DATE_ADD(CURDATE(), INTERVAL 1 DAY), '09:00:00', '10:30:00', 'published', 10),
(UUID(), 'Pilates Aérien', 't0000000-0000-0000-0000-000000000001', 'l0000000-0000-0000-0000-000000000001', DATE_ADD(CURDATE(), INTERVAL 1 DAY), '18:00:00', '19:00:00', 'published', 8),
(UUID(), 'Full Body Workout', 't0000000-0000-0000-0000-000000000002', 'l0000000-0000-0000-0000-000000000002', DATE_ADD(CURDATE(), INTERVAL 2 DAY), '10:00:00', '11:00:00', 'published', 15);
