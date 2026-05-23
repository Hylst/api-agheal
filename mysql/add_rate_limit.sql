-- add_rate_limit.sql
--
-- Table de tracking des tentatives d'authentification par IP.
-- Objectif : rate limiting sur /auth/login, /auth/reset-password, /auth/google
-- pour bloquer le brute-force.
--
-- Politique appliquee cote applicatif (cf RateLimitRepository) :
--   - fenetre glissante de 15 minutes
--   - 5 tentatives echouees max sur la fenetre
--   - au-dela : 429 Too Many Requests
--   - les tentatives reussies n'incrementent pas le compteur
--
-- Purge : prevue dans cron_daily.php (TODO) pour conserver < 30 jours.

CREATE TABLE IF NOT EXISTS rate_limit_attempts (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    ip_address    VARCHAR(45)  NOT NULL,                          -- IPv4 (15) ou IPv6 (39) + marge
    endpoint      VARCHAR(64)  NOT NULL,                          -- ex: 'auth.login'
    attempted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    succeeded     TINYINT(1)   NOT NULL DEFAULT 0,                -- 1 si tentative reussie, 0 sinon
    INDEX idx_ip_endpoint_time (ip_address, endpoint, attempted_at),
    INDEX idx_attempted_at (attempted_at)                          -- pour la purge cron
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
