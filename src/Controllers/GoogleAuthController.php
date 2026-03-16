<?php
// src/Controllers/GoogleAuthController.php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';

use League\OAuth2\Client\Provider\Google;
use Firebase\JWT\JWT;

/**
 * Gère le flux OAuth 2.0 avec Google (inscription & connexion)
 */
class GoogleAuthController
{
    /** Retourne une instance configurée du provider Google OAuth2 */
    private function getProvider(): Google
    {
        return new Google([
            'clientId'     => $_ENV['GOOGLE_CLIENT_ID'],
            'clientSecret' => $_ENV['GOOGLE_CLIENT_SECRET'],
            'redirectUri'  => $_ENV['GOOGLE_REDIRECT_URI'],
        ]);
    }

    /**
     * GET /auth/google
     * Génère l'URL d'autorisation Google et redirige l'utilisateur
     */
    public function redirect(): void
    {
        $provider = $this->getProvider();

        // Génère un state anti-CSRF signé avec le JWT_SECRET (pas besoin de session)
        $rawState = bin2hex(random_bytes(16));
        $state    = $rawState . '.' . hash_hmac('sha256', $rawState, $_ENV['JWT_SECRET']);

        $authUrl = $provider->getAuthorizationUrl([
            'state'  => $state,
            'scope'  => ['openid', 'email', 'profile'],
        ]);

        // Stocke le state dans un cookie sécurisé pour validation au callback
        setcookie('oauth_state', $state, [
            'expires'  => time() + 300,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        header('Location: ' . $authUrl, true, 302);
        exit;
    }

    /**
     * GET /auth/google/callback
     * Reçoit le code Google, vérifie le state, crée/connecte l'utilisateur, retourne un JWT
     */
    public function callback(): void
    {
        $frontendUrl = rtrim($_ENV['FRONTEND_URL'] ?? 'http://localhost:5173', '/');
        $errorUrl    = $frontendUrl . '/login?error=google_failed';

        // ── Validation du state anti-CSRF ────────────────────────────────────
        $receivedState = $_GET['state'] ?? '';
        $cookieState   = $_COOKIE['oauth_state'] ?? '';

        if (empty($receivedState) || $receivedState !== $cookieState) {
            header('Location: ' . $errorUrl . '&reason=state_mismatch', true, 302);
            exit;
        }

        // Nettoie le cookie
        setcookie('oauth_state', '', ['expires' => time() - 3600, 'path' => '/']);

        $code = $_GET['code'] ?? '';
        if (empty($code)) {
            header('Location: ' . $errorUrl . '&reason=no_code', true, 302);
            exit;
        }

        // ── Échange du code contre un access token ───────────────────────────
        try {
            $provider    = $this->getProvider();
            $token       = $provider->getAccessToken('authorization_code', ['code' => $code]);
            $googleUser  = $provider->getResourceOwner($token);
            $googleData  = $googleUser->toArray();
            $googleEmail = $googleData['email'] ?? null;
            $googleName  = $googleData['name']  ?? '';

            if (empty($googleEmail)) {
                header('Location: ' . $errorUrl . '&reason=no_email', true, 302);
                exit;
            }
        } catch (\Exception $e) {
            header('Location: ' . $errorUrl . '&reason=token_exchange', true, 302);
            exit;
        }

        // ── Upsert de l'utilisateur en base ─────────────────────────────────
        $db = Database::getInstance();

        // Cherche un compte existant par email
        $stmt = $db->query(
            "SELECT u.id FROM users u WHERE u.email = ? LIMIT 1",
            [$googleEmail]
        );
        $existingUser = $stmt->fetch();

        if ($existingUser) {
            // Connexion : utilisateur existant
            $userId = $existingUser['id'];
        } else {
            // Inscription : crée le compte (sans mot de passe — Google uniquement)
            $nameParts = explode(' ', $googleName, 2);
            $firstName = $nameParts[0] ?? '';
            $lastName  = $nameParts[1] ?? '';

            // Générer un UUID v4 (car users.id n'est pas auto-incrémenté)
            $userId = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );

            try {
                $db->beginTransaction();

                $db->query(
                    "INSERT INTO users (id, email, password_hash, created_at) VALUES (?, ?, '', NOW())",
                    [$userId, $googleEmail]
                );

                // Upsert le profil associé (le trigger a peut-être déjà inséré une ligne vide)
                $db->query(
                    "REPLACE INTO profiles (id, first_name, last_name, statut_compte, updated_at)
                     VALUES (?, ?, ?, 'actif', NOW())",
                    [$userId, $firstName, $lastName]
                );

                // Role par défaut : adhérent (le trigger a peut-être déjà inséré)
                $db->query(
                    "REPLACE INTO user_roles (user_id, role) VALUES (?, 'adherent')",
                    [$userId]
                );

                $db->commit();
            } catch (\Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log("Google Auth Signup Error: " . $e->getMessage());
                header('Location: ' . $errorUrl . '&reason=db_error', true, 302);
                exit;
            }
        }

        // ── Récupère les rôles pour le JWT ───────────────────────────────────
        $rolesStmt = $db->query(
            "SELECT role FROM user_roles WHERE user_id = ?",
            [$userId]
        );
        $roles = array_column($rolesStmt->fetchAll(), 'role');

        // ── Génère le JWT AGHeal ─────────────────────────────────────────────
        $expiration = time() + (int)($_ENV['JWT_EXPIRATION'] ?? 3600);
        $payload = [
            'sub'   => $userId,
            'email' => $googleEmail,
            'roles' => $roles,
            'iat'   => time(),
            'exp'   => $expiration,
        ];

        $jwt = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');

        // ── Redirige vers le frontend avec le token ──────────────────────────
        $callbackUrl = $frontendUrl . '/auth/google/callback?token=' . urlencode($jwt);
        header('Location: ' . $callbackUrl, true, 302);
        exit;
    }
}
