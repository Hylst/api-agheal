-- ============================================================
-- AGHeal – Contraintes et Triggers de Sécurité des Données
-- À exécuter sur la base MariaDB (complémentaire à init.sql)
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. CONTRAINTES CHECK sur les dates et heures (sessions)
-- ────────────────────────────────────────────────────────────

-- Vérifier que la date de séance n'est pas dans le passé extrême (garde-fou)
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
-- ────────────────────────────────────────────────────────────

ALTER TABLE payments
    ADD CONSTRAINT chk_payment_amount_positive
        CHECK (amount > 0),
    ADD CONSTRAINT chk_payment_method_enum
        CHECK (payment_method IN ('cash', 'cheque', 'virement', 'cb', 'autre')),
    ADD CONSTRAINT chk_renewal_after_payment
        CHECK (renewal_date IS NULL OR renewal_date >= payment_date);

-- ────────────────────────────────────────────────────────────
-- 3. CONTRAINTES CHECK sur les profils
-- ────────────────────────────────────────────────────────────

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
    DECLARE session_date DATE;
    DECLARE session_time TIME;

    SELECT date, start_time
    INTO   session_date, session_time
    FROM   sessions
    WHERE  id = NEW.session_id;

    IF TIMESTAMP(session_date, session_time) < NOW() THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Inscription refusée : la séance est déjà passée';
    END IF;
END$$

-- ────────────────────────────────────────────────────────────
-- 5. TRIGGER : Interdire une séance dont start_time >= end_time
--    (redondance trigger + CHECK pour couverture complète)
-- ────────────────────────────────────────────────────────────

DROP TRIGGER IF EXISTS prevent_invalid_session_times_insert $$
CREATE TRIGGER prevent_invalid_session_times_insert
    BEFORE INSERT ON sessions
    FOR EACH ROW
BEGIN
    IF NEW.start_time >= NEW.end_time THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Heure de début doit être antérieure à l\'heure de fin';
    END IF;
    IF NEW.date < CURDATE() - INTERVAL 1 DAY THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Impossible de créer une séance dans le passé';
    END IF;
END$$

DROP TRIGGER IF EXISTS prevent_invalid_session_times_update $$
CREATE TRIGGER prevent_invalid_session_times_update
    BEFORE UPDATE ON sessions
    FOR EACH ROW
BEGIN
    IF NEW.start_time >= NEW.end_time THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Heure de début doit être antérieure à l\'heure de fin';
    END IF;
END$$

-- ────────────────────────────────────────────────────────────
-- 6. TRIGGER : Interdire un payment_date dans le futur
-- ────────────────────────────────────────────────────────────

DROP TRIGGER IF EXISTS prevent_future_payment_date $$
CREATE TRIGGER prevent_future_payment_date
    BEFORE INSERT ON payments
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
    BEFORE INSERT ON payments
    FOR EACH ROW
BEGIN
    IF NEW.renewal_date IS NOT NULL AND NEW.renewal_date < NEW.payment_date THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La date de renouvellement ne peut pas précéder la date de règlement';
    END IF;
END$$

-- ────────────────────────────────────────────────────────────
-- 8. TABLE : password_resets (déjà créée dynamiquement via PHP)
--    Version statique pour les migrations propres
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS password_resets (
    user_id    CHAR(36)     NOT NULL PRIMARY KEY,
    token      VARCHAR(64)  NOT NULL,
    expires_at DATETIME     NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER ;
