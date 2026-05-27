<?php
// src/Repositories/RateLimitRepository.php
//
// Rate limiting applicatif pour les endpoints d'auth sensibles.
//
// Politique : fenetre glissante de 15 min, 5 echecs max, sinon 429.
// Les tentatives reussies sont tracees mais ne comptent pas dans le seuil.
//
// Choix : implementation BDD (table rate_limit_attempts) plutot que cache memoire,
// pour persistance entre restart container et pour ne pas dependre d'un Redis.
// Suffisant a notre volume (qq dizaines de logins/jour).

namespace App\Repositories;

class RateLimitRepository extends BaseRepository
{
    /** Fenetre de calcul du seuil, en secondes. */
    public const WINDOW_SECONDS = 900;       // 15 minutes

    /** Nombre max d'echecs autorises dans la fenetre avant blocage. */
    public const MAX_FAILED_ATTEMPTS = 5;

    public function __construct()
    {
        parent::__construct();
        // Guard de migration : cree la table si elle n'existe pas encore.
        // Meme pattern que password_resets dans AuthController.
        // A retirer quand un vrai systeme de migrations sera en place.
        $this->db->query("
            CREATE TABLE IF NOT EXISTS rate_limit_attempts (
                id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
                ip_address   VARCHAR(45)   NOT NULL,
                endpoint     VARCHAR(64)   NOT NULL,
                attempted_at DATETIME      NOT NULL DEFAULT NOW(),
                succeeded    TINYINT(1)    NOT NULL DEFAULT 0,
                INDEX idx_ip_endpoint_at (ip_address, endpoint, attempted_at),
                INDEX idx_attempted_at   (attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * Enregistre une tentative (reussie ou non).
     *
     * @param string $ip       Adresse IP du client (deja resolue cote Controller).
     * @param string $endpoint Identifiant logique de l'endpoint, ex 'auth.login'.
     * @param bool   $succeeded TRUE si la tentative a abouti (login OK), FALSE sinon.
     */
    public function recordAttempt(string $ip, string $endpoint, bool $succeeded): void
    {
        $this->execute(
            "INSERT INTO rate_limit_attempts (ip_address, endpoint, succeeded) VALUES (?, ?, ?)",
            [$ip, $endpoint, $succeeded ? 1 : 0]
        );
    }

    /**
     * Compte les tentatives echouees pour ce couple (ip, endpoint) sur la fenetre.
     * Ne compte PAS les succes : un user qui se connecte normalement n'est pas bloque.
     */
    public function countRecentFailures(string $ip, string $endpoint, int $windowSeconds = self::WINDOW_SECONDS): int
    {
        $row = $this->fetchOne(
            "SELECT COUNT(*) AS n
             FROM rate_limit_attempts
             WHERE ip_address = ?
               AND endpoint   = ?
               AND succeeded  = 0
               AND attempted_at >= (NOW() - INTERVAL ? SECOND)",
            [$ip, $endpoint, $windowSeconds]
        );
        return (int)($row['n'] ?? 0);
    }

    /**
     * Verifie si l'IP est en blocage pour cet endpoint.
     * Renvoie le nombre de secondes restantes avant deblocage (0 si pas bloque).
     */
    public function getLockRemainingSeconds(string $ip, string $endpoint): int
    {
        $count = $this->countRecentFailures($ip, $endpoint);
        if ($count < self::MAX_FAILED_ATTEMPTS) {
            return 0;
        }

        // Trouve la plus ancienne tentative echouee dans la fenetre.
        // Le deblocage intervient quand cette tentative sort de la fenetre.
        $row = $this->fetchOne(
            "SELECT TIMESTAMPDIFF(SECOND, NOW(), attempted_at) + ? AS remaining
             FROM rate_limit_attempts
             WHERE ip_address = ?
               AND endpoint   = ?
               AND succeeded  = 0
               AND attempted_at >= (NOW() - INTERVAL ? SECOND)
             ORDER BY attempted_at ASC
             LIMIT 1",
            [self::WINDOW_SECONDS, $ip, $endpoint, self::WINDOW_SECONDS]
        );
        return max(1, (int)($row['remaining'] ?? 0));
    }

    /**
     * Purge des entrees plus vieilles que $olderThanSeconds (defaut 30 jours).
     * Appele par cron_daily.php.
     */
    public function cleanupOldEntries(int $olderThanSeconds = 2592000): int
    {
        return $this->execute(
            "DELETE FROM rate_limit_attempts WHERE attempted_at < (NOW() - INTERVAL ? SECOND)",
            [$olderThanSeconds]
        );
    }

    /**
     * Helper : resout l'IP client en tenant compte des proxies (Coolify/Traefik).
     * Whitelist des proxies de confiance via TRUSTED_PROXIES (CSV) dans .env.
     */
    public static function resolveClientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Si le remote est un proxy de confiance, on prend le 1er IP du X-Forwarded-For.
        $trusted = array_filter(array_map('trim', explode(',', $_ENV['TRUSTED_PROXIES'] ?? '')));
        if (in_array($remote, $trusted, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($forwarded[0]);
        }
        return $remote;
    }
}
