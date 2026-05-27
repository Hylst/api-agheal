<?php
// src/Repositories/RefreshTokenRepository.php
//
// Refresh tokens longue duree pour les sessions utilisateur.
//
// Cycle de vie :
//   1. login()   -> emet (access_token JWT 15 min) + (refresh_token 30j hash en BDD)
//   2. front     -> stocke les 2 cote client (refresh en localStorage)
//   3. front     -> a chaque 401, tente POST /auth/refresh avec le refresh_token
//   4. refresh() -> revoque l'ancien refresh + emet un nouveau couple (rotation)
//   5. logout    -> revokeAllForUser pour invalider tout
//
// Securite :
//   - Le token clair n'est JAMAIS stocke en BDD. On stocke le sha256 (CHAR 64).
//   - Rotation systematique : un refresh utilise est immediatement revoque.
//   - revoked_at != NULL ou expires_at < NOW => token inutilisable.
//   - FK ON DELETE CASCADE : la suppression d'un user revoque mecaniquement
//     tous ses refresh_tokens.

namespace App\Repositories;

class RefreshTokenRepository extends BaseRepository
{
    /** TTL par defaut d'un refresh token, en secondes (30 jours). */
    public const DEFAULT_TTL_SECONDS = 2592000;

    /**
     * Emet un nouveau refresh token pour un user.
     *
     * Renvoie un tableau ['token' => '<clair>', 'expires_at' => 'YYYY-MM-DD HH:MM:SS'].
     * Seul le hash est persiste : le clair n'apparait QUE dans la reponse HTTP
     * et ne doit JAMAIS etre logge.
     */
    public function issue(string $userId, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): array
    {
        // 32 bytes = 256 bits d'entropie = collision quasi-impossible en pratique.
        $clearToken = bin2hex(random_bytes(32));
        $hash       = hash('sha256', $clearToken);
        $expiresAt  = date('Y-m-d H:i:s', time() + $ttlSeconds);
        $id         = $this->generateUuidV4();

        $this->execute(
            "INSERT INTO refresh_tokens (id, user_id, token, expires_at, created_at)
             VALUES (?, ?, ?, ?, NOW())",
            [$id, $userId, $hash, $expiresAt]
        );

        return [
            'token'      => $clearToken,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Cherche un refresh token actif et le renvoie avec son user_id.
     * Renvoie null si introuvable, expire, ou revoque.
     */
    public function findValid(string $clearToken): ?array
    {
        $hash = hash('sha256', $clearToken);
        return $this->fetchOne(
            "SELECT id, user_id, expires_at, revoked_at
             FROM refresh_tokens
             WHERE token = ?
               AND revoked_at IS NULL
               AND expires_at > NOW()",
            [$hash]
        );
    }

    /**
     * Revoque un token par son id (typiquement apres rotation lors d'un refresh).
     * Idempotent : si deja revoque, ne touche pas a revoked_at.
     */
    public function revoke(string $tokenId): void
    {
        $this->execute(
            "UPDATE refresh_tokens
             SET revoked_at = NOW()
             WHERE id = ? AND revoked_at IS NULL",
            [$tokenId]
        );
    }

    /**
     * Revoque tous les tokens d'un user (logout total, changement de password).
     * Renvoie le nombre de tokens revoques.
     */
    public function revokeAllForUser(string $userId): int
    {
        return $this->execute(
            "UPDATE refresh_tokens
             SET revoked_at = NOW()
             WHERE user_id = ? AND revoked_at IS NULL",
            [$userId]
        );
    }

    /**
     * Purge des tokens expires depuis longtemps. Appele par cron_daily.php.
     * On garde les tokens revoques 30 jours apres expiration pour audit forensique.
     */
    public function cleanupExpired(int $olderThanSeconds = 2592000): int
    {
        return $this->execute(
            "DELETE FROM refresh_tokens
             WHERE expires_at < (NOW() - INTERVAL ? SECOND)",
            [$olderThanSeconds]
        );
    }

    /** UUID v4 inline (cf RFC 4122 nibble 13 = 4, nibble 17 = 8-b). */
    private function generateUuidV4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
