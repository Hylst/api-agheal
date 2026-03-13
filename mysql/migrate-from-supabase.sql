-- ============================================================
-- Migration des utilisateurs Supabase -> MySQL local AGHeal
-- Généré le 2026-02-22
-- Source: Supabase project vqjhphaggmizlkssasoj (agheal)
--
-- TOUS LES MOTS DE PASSE sont "password123" (bcrypt)
-- Les utilisateurs devront changer leur mot de passe à la
-- première connexion ou utiliser la fonction reset.
-- ============================================================
USE agheal;

-- Désactiver les foreign keys temporairement pour faciliter l'import
SET FOREIGN_KEY_CHECKS = 0;

-- Nettoyer les données de test préexistantes si besoin
DELETE FROM user_roles WHERE user_id IN (
    'c0a80101-0000-0000-0000-000000000001',
    'c0a80101-0000-0000-0000-000000000002'
);
DELETE FROM profiles WHERE id IN (
    'c0a80101-0000-0000-0000-000000000001',
    'c0a80101-0000-0000-0000-000000000002'
);
DELETE FROM users WHERE id IN (
    'c0a80101-0000-0000-0000-000000000001',
    'c0a80101-0000-0000-0000-000000000002'
);

-- ============================================================
-- 1. TABLE users (authentification)
--    password = "password123" pour tous
-- ============================================================
INSERT INTO users (id, email, password_hash, email_confirmed_at, created_at) VALUES

-- Admin
('00000000-0000-0000-0000-000000000001',
 'admin@agheal-adaptmovement.fr',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 NOW(), NOW()),

-- Coachs
('11111111-1111-1111-1111-111111111111',
 'guillaume@agheal.fr',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 NOW(), NOW()),

('22222222-2222-2222-2222-222222222222',
 'amandine@adaptmovement.fr',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 NOW(), NOW()),

-- Adhérents
('33333333-3333-3333-3333-333333333333',
 'marie.dupont@email.fr',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 NOW(), NOW()),

('44444444-4444-4444-4444-444444444444',
 'jean.michel@email.fr',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 NOW(), NOW()),

('55555555-5555-5555-5555-555555555555',
 'sophie.leroy@email.fr',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 NOW(), NOW())

ON DUPLICATE KEY UPDATE
    email = VALUES(email),
    email_confirmed_at = VALUES(email_confirmed_at);

-- ============================================================
-- 2. TABLE profiles
--    Supabase avait: full_name (pas first/last), role dans profiles
--    MySQL local a: first_name + last_name séparés (sans role)
-- ============================================================
INSERT INTO profiles (id, first_name, last_name, email, phone, statut_compte, created_at) VALUES

('00000000-0000-0000-0000-000000000001',
 'Administrateur', 'Principal',
 'admin@agheal-adaptmovement.fr', '+33600000001', 'actif', NOW()),

('11111111-1111-1111-1111-111111111111',
 'Guillaume', 'Martin',
 'guillaume@agheal.fr', '+33600000002', 'actif', NOW()),

('22222222-2222-2222-2222-222222222222',
 'Amandine', 'Dubois',
 'amandine@adaptmovement.fr', '+33600000003', 'actif', NOW()),

('33333333-3333-3333-3333-333333333333',
 'Marie', 'Dupont',
 'marie.dupont@email.fr', '+33600000004', 'actif', NOW()),

('44444444-4444-4444-4444-444444444444',
 'Jean', 'Michel',
 'jean.michel@email.fr', '+33600000005', 'actif', NOW()),

('55555555-5555-5555-5555-555555555555',
 'Sophie', 'Leroy',
 'sophie.leroy@email.fr', '+33600000006', 'actif', NOW())

ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name   = VALUES(last_name),
    email       = VALUES(email),
    phone       = VALUES(phone),
    statut_compte = VALUES(statut_compte);

-- ============================================================
-- 3. TABLE user_roles
-- ============================================================
INSERT INTO user_roles (user_id, role) VALUES
('00000000-0000-0000-0000-000000000001', 'admin'),
('00000000-0000-0000-0000-000000000001', 'coach'),
('11111111-1111-1111-1111-111111111111', 'coach'),
('22222222-2222-2222-2222-222222222222', 'coach'),
('33333333-3333-3333-3333-333333333333', 'adherent'),
('44444444-4444-4444-4444-444444444444', 'adherent'),
('55555555-5555-5555-5555-555555555555', 'adherent')

ON DUPLICATE KEY UPDATE role = VALUES(role);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Vérification
-- ============================================================
SELECT u.email, GROUP_CONCAT(r.role) as roles, p.first_name, p.last_name
FROM users u
JOIN profiles p ON p.id = u.id
LEFT JOIN user_roles r ON r.user_id = u.id
GROUP BY u.id, u.email, p.first_name, p.last_name
ORDER BY u.email;
