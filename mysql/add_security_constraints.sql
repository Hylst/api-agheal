-- ============================================================
-- AGHeal – Contraintes et Triggers de Sécurité des Données
-- À exécuter sur la base MariaDB (complémentaire à init.sql)
-- VERSION CORRIGÉE : nettoyage des données existantes avant
-- l'ajout des contraintes CHECK, et vrai nom de table (payments_history)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 0. Nettoyage des données existantes avant ajout des CHECK
--    (évite l'erreur 4025 : CONSTRAINT Failed for existing rows)
-- ────────────────────────────────────────────────────────────

-- Sessions : s'assurer que tous les min/max sont valides
UPDATE sessions
SET min_people          = GREATEST(COALESCE(min_people, 1), 1),
    max_people          = GREATEST(COALESCE(max_people, min_people + 1), COALESCE(min_people, 1) + 1),
    min_people_blocking = GREATEST(COALESCE(min_people_blocking, 1), 1),
    max_people_blocking = GREATEST(COALESCE(max_people_blocking, min_people_blocking + 1), COALESCE(min_people_blocking, 1))
WHERE min_people IS NULL
   OR max_people IS NULL
   OR max_people < min_people
   OR min_people_blocking IS NULL
   OR min_people_blocking < 1
   OR max_people_blocking IS NULL
   OR max_people_blocking < min_people_blocking;

-- Sessions : fixer les statuts invalides → 'published' par défaut
UPDATE sessions
SET status = 'published'
WHERE status NOT IN ('draft', 'published', 'cancelled', 'completed')
   OR status IS NULL;

-- Sessions : fixer les dates invalides (avant 2020)
UPDATE sessions
SET date = '2020-01-01'
WHERE date < '2020-01-01' OR date IS NULL;

-- Sessions : fixer heures invalides (start >= end)
-- On ne peut pas corriger automatiquement, on écarte
UPDATE sessions
SET end_time = ADDTIME(start_time, '01:00:00')
WHERE start_time >= end_time OR start_time IS NULL OR end_time IS NULL;

-- Profils : statut_compte
UPDATE profiles
SET statut_compte = 'actif'
WHERE statut_compte NOT IN ('actif', 'bloque', 'inactif')
   OR statut_compte IS NULL;

-- Profils : payment_status
UPDATE profiles
SET payment_status = 'pending'
WHERE payment_status NOT IN ('paid', 'pending', 'overdue')
   OR payment_status IS NULL;

-- payments_history : méthode de paiement (NULL autorisé → on ne touche pas)
UPDATE payments_history
SET payment_method = NULL
WHERE payment_method IS NOT NULL
  AND payment_method NOT IN ('cash', 'cheque', 'virement', 'cb', 'autre');

-- payments_history : montant doit être > 0
UPDATE payments_history
SET amount = 0.01
WHERE amount <= 0 OR amount IS NULL;

-- payments_history : renewal_date >= payment_date
UPDATE payments_history
SET renewal_date = NULL
WHERE renewal_date IS NOT NULL AND renewal_date < payment_date;

-- ────────────────────────────────────────────────────────────
-- 1. CONTRAINTES CHECK sur les dates et heures (sessions)
-- ────────────────────────────────────────────────────────────

-- Supprimer les contraintes si elles existent déjà (idempotent)
ALTER TABLE sessions
    DROP CONSTRAINT IF EXISTS chk_session_date,
    DROP CONSTRAINT IF EXISTS chk_start_before_end,
    DROP CONSTRAINT IF EXISTS chk_min_max_people,
    DROP CONSTRAINT IF EXISTS chk_min_max_blocking,
    DROP CONSTRAINT IF EXISTS chk_status_enum;

ALTER TABLE sessions
    ADD CONSTRAINT chk_session_date
        CHECK (date >= '2020-01-01'),
    ADD CONSTRAINT chk_start_before_end
        CHECK (start_time < end_time),
    ADD CONSTRAINT chk_min_max_people
        CHECK (min_people > 0 AND max_people >= min_people),
    ADD CONSTRAINT chk_min_max_blocking
        CHECK (min_people_blocking > 0 AND max_people_blocking >= min_people_blocking),
    ADD CONSTRAINT chk_status_enum
        CHECK (status IN ('draft', 'published', 'cancelled', 'completed'));

-- ────────────────────────────────────────────────────────────
-- 2. CONTRAINTES CHECK sur les paiements
--    ⚠ Le vrai nom de table est payments_history (pas payments)
-- ────────────────────────────────────────────────────────────

ALTER TABLE payments_history
    DROP CONSTRAINT IF EXISTS chk_payment_amount_positive,
    DROP CONSTRAINT IF EXISTS chk_payment_method_enum,
    DROP CONSTRAINT IF EXISTS chk_renewal_after_payment;

ALTER TABLE payments_history
    ADD CONSTRAINT chk_payment_amount_positive
        CHECK (amount > 0),
    ADD CONSTRAINT chk_payment_method_enum
        CHECK (payment_method IS NULL OR payment_method IN ('cash', 'cheque', 'virement', 'cb', 'autre')),
    ADD CONSTRAINT chk_renewal_after_payment
        CHECK (renewal_date IS NULL OR renewal_date >= payment_date);

-- ────────────────────────────────────────────────────────────
-- 3. CONTRAINTES CHECK sur les profils
-- ────────────────────────────────────────────────────────────

ALTER TABLE profiles
    DROP CONSTRAINT IF EXISTS chk_statut_enum,
    DROP CONSTRAINT IF EXISTS chk_payment_status_enum,
    DROP CONSTRAINT IF EXISTS chk_certif_medic_future;

ALTER TABLE profiles
    ADD CONSTRAINT chk_statut_enum
        CHECK (statut_compte IN ('actif', 'bloque', 'inactif')),
    ADD CONSTRAINT chk_payment_status_enum
        CHECK (payment_status IN ('paid', 'pending', 'overdue')),
    ADD CONSTRAINT chk_certif_medic_future
        CHECK (certif_medic_expiry IS NULL OR certif_medic_expiry >= '2020-01-01');

-- ────────────────────────────────────────────────────────────
-- 4. TRIGGER : Interdire l'inscription à une séance passée
-- ────────────────────────────────────────────────────────────

DELIMITER $$

DROP TRIGGER IF EXISTS prevent_past_session_registration $$
CREATE TRIGGER prevent_past_session_registration
    BEFORE INSERT ON registrations
    FOR EACH ROW
BEGIN
    DECLARE v_session_date DATE;
    DECLARE v_session_time TIME;

    SELECT date, start_time
    INTO   v_session_date, v_session_time
    FROM   sessions
    WHERE  id = NEW.session_id;

    IF TIMESTAMP(v_session_date, v_session_time) < NOW() THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Inscription refusée : la séance est déjà passée';
    END IF;
END$$

-- ────────────────────────────────────────────────────────────
-- 5. TRIGGER : Interdire une séance dont start_time >= end_time
-- ────────────────────────────────────────────────────────────

DROP TRIGGER IF EXISTS prevent_invalid_session_times_insert $$
CREATE TRIGGER prevent_invalid_session_times_insert
    BEFORE INSERT ON sessions
    FOR EACH ROW
BEGIN
    IF NEW.start_time >= NEW.end_time THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Heure de début doit être antérieure à l''heure de fin';
    END IF;
    -- Tolérance : on n'empêche pas la création rétroactive par un admin
    -- Le contrôle métier est fait côté application
END$$

DROP TRIGGER IF EXISTS prevent_invalid_session_times_update $$
CREATE TRIGGER prevent_invalid_session_times_update
    BEFORE UPDATE ON sessions
    FOR EACH ROW
BEGIN
    IF NEW.start_time >= NEW.end_time THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Heure de début doit être antérieure à l''heure de fin';
    END IF;
END$$

-- ────────────────────────────────────────────────────────────
-- 6. TRIGGER : Interdire un payment_date dans le futur
-- ────────────────────────────────────────────────────────────

DROP TRIGGER IF EXISTS prevent_future_payment_date $$
CREATE TRIGGER prevent_future_payment_date
    BEFORE INSERT ON payments_history
    FOR EACH ROW
BEGIN
    IF NEW.payment_date > CURDATE() THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La date de règlement ne peut pas être dans le futur';
    END IF;
    IF NEW.amount <= 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Le montant du règlement doit être positif';
    END IF;
END$$

-- ────────────────────────────────────────────────────────────
-- 7. TRIGGER : date de renouvellement cohérente après date paiement
-- ────────────────────────────────────────────────────────────

DROP TRIGGER IF EXISTS payment_renewal_consistency $$
CREATE TRIGGER payment_renewal_consistency
    BEFORE INSERT ON payments_history
    FOR EACH ROW
BEGIN
    IF NEW.renewal_date IS NOT NULL AND NEW.renewal_date < NEW.payment_date THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La date de renouvellement ne peut pas précéder la date de règlement';
    END IF;
END$$

-- ────────────────────────────────────────────────────────────
-- 8. TABLE : password_resets (version statique idempotente)
-- ────────────────────────────────────────────────────────────

DELIMITER ;

CREATE TABLE IF NOT EXISTS password_resets (
    user_id    CHAR(36)     NOT NULL PRIMARY KEY,
    token      VARCHAR(64)  NOT NULL,
    expires_at DATETIME     NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
