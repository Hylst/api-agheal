-- create_app_user.sql
-- Création de l'utilisateur applicatif `agheal_app` avec privilèges restreints.
-- À exécuter une fois, en tant que root MariaDB, après init.sql / init_trigger.sql / add_*.sql.
--
-- Pourquoi : la connexion applicative ne doit JAMAIS se faire en root.
-- L'utilisateur `agheal_app` n'a strictement que les droits nécessaires au runtime de l'API :
--   - SELECT / INSERT / UPDATE / DELETE sur toutes les tables d'agheal
--   - EXECUTE pour les éventuelles procédures stockées (actuellement aucune, mais on garde la marge)
-- Il n'a PAS :
--   - de DDL (CREATE / DROP / ALTER) — ces opérations restent réservées à root pour migrations contrôlées
--   - d'accès aux autres bases du serveur MariaDB (ex: mysql, information_schema en écriture)
--   - de privilège GRANT
--
-- Mot de passe : à définir avant exécution, jamais en clair dans le repo.
-- En dev local : copier le mot de passe dans .env (DB_USER=agheal_app, DB_PASSWORD=...)
-- En prod Coolify : injecter via les secrets Coolify, ne JAMAIS le committer.

-- Étape 1 : créer l'utilisateur (à exécuter une fois)
-- Remplacer 'CHANGE_ME_BEFORE_EXECUTION' par un mot de passe long et aléatoire.
CREATE USER IF NOT EXISTS 'agheal_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_BEFORE_EXECUTION';
CREATE USER IF NOT EXISTS 'agheal_app'@'%' IDENTIFIED BY 'CHANGE_ME_BEFORE_EXECUTION';
-- '%' nécessaire en prod Docker (connexions depuis un autre container du réseau interne)

-- Étape 2 : accorder uniquement les privilèges DML nécessaires sur la base agheal
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON `agheal`.* TO 'agheal_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON `agheal`.* TO 'agheal_app'@'%';

-- Étape 3 : refuser explicitement l'accès aux bases sensibles (par sécurité, même si non concerné par défaut)
-- (rien à faire — par défaut un user n'a pas accès aux autres bases sans GRANT explicite)

-- Étape 4 : appliquer les changements
FLUSH PRIVILEGES;

-- Vérification post-création (à exécuter manuellement pour audit) :
-- SHOW GRANTS FOR 'agheal_app'@'localhost';
-- SHOW GRANTS FOR 'agheal_app'@'%';
--
-- Sortie attendue : exactement deux lignes GRANT par hôte, et rien d'autre.
