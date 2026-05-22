<?php
// src/Controllers/GoogleAuthController.php
//
// Flow Google OAuth 2.0 (Authorization Code Grant).
//
// Sequence :
//   1. GET /auth/google           -> on construit l'URL Google + state CSRF
//                                    + cookie httponly, on redirige.
//   2. Google authentifie l'user et redirige vers GOOGLE_REDIRECT_URI avec ?code=...&state=...
//   3. GET /auth/google/callback  -> on verifie le state (anti-CSRF),
//                                    echange le code contre un access token Google,
//                                    recupere email + nom, upsert le user en BDD,
//                                    genere un JWT AGHeal et redirige vers le front.
//
// Choix d'archi :
//   - State anti-CSRF signe avec JWT_SECRET (HMAC) plutot que stocke en session.
//     Pas de session PHP demarree = stateless = plus simple en prod multi-instance.
//   - Cookie oauth_state secure + httponly + samesite=Lax + 5min de vie.
//   - JWT AGHeal genere a la fin = meme format que login classique. Le front
//     ne fait pas la difference entre un login Google et un login email/password.

namespace App\Controllers;

use Database;
use Auth;
use League\OAuth2\Client\Provider\Google;
use Firebase\JWT\JWT;

class GoogleAuthController
{
    /** Instance du provider Google OAuth2 configuree depuis .env. */
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
     * Genere l'URL d'autorisation Google et redirige l'user.
     */
    public function redirect(): void
    {
        $provider = $this->getProvider();

        // State anti-CSRF : random + HMAC signe avec JWT_SECRET. On le mettra
        // dans l'URL (que Google nous renverra) ET dans un cookie. Au callback,
        // on compare les 2 : si different, c'est un CSRF.
        $rawState = bin2hex(random_bytes(16));
        $state    = $rawState . '.' . hash_hmac('sha256', $rawState, $_ENV['JWT_SECRET']);

        $authUrl = $provider->getAuthorizationUrl([
            'state'  => $state,
            'scope'  => ['openid', 'email', 'profile'], // minimum vital
        ]);

        // Cookie : secure (HTTPS only), httponly (pas accessible en JS),
        // samesite=Lax (envoye sur redirection cross-site -> OK pour OAuth callback).
        // 5min de vie : si l'user met plus, on degage.
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
     * Recoit le code Google, verifie le state, cree/connecte le user, retourne un JWT.
     */
    public function callback(): void
    {
        $frontendUrl = rtrim($_ENV['FRONTEND_URL'] ?? 'http://localhost:5173', '/');
        $errorUrl    = $frontendUrl . '/login?error=google_failed';

        // 1. Anti-CSRF : le state recu dans l'URL doit matcher celui du cookie.
        // Si quelqu'un essaye de forger la requete, il ne pourra pas mettre le bon
        // cookie (HttpOnly + signature HMAC).
        $receivedState = $_GET['state'] ?? '';
        $cookieState   = $_COOKIE['oauth_state'] ?? '';

        if (empty($receivedState) || $receivedState !== $cookieState) {
            header('Location: ' . $errorUrl . '&reason=state_mismatch', true, 302);
            exit;
        }

        // Cleanup cookie : single-use, on l'expire.
        setcookie('oauth_state', '', ['expires' => time() - 3600, 'path' => '/']);

        $code = $_GET['code'] ?? '';
        if (empty($code)) {
            header('Location: ' . $errorUrl . '&reason=no_code', true, 302);
            exit;
        }

        // 2. Echange du code contre un access token Google + recup des infos user.
        // Le getResourceOwner() fait l'appel a Google /userinfo derriere.
        try {
            $provider    = $this->getProvider();
            $token       = $provider->getAccessToken('authorization_code', ['code' => $code]);
            $googleUser  = $provider->getResourceOwner($token);
            $googleData  = $googleUser->toArray();
            $googleEmail = $googleData['email'] ?? null;
            $googleName  = $googleData['name']  ?? '';

            if (empty($googleEmail)) {
                // Cas tres rare (compte Google sans email) : on degage proprement.
                header('Location: ' . $errorUrl . '&reason=no_email', true, 302);
                exit;
            }
        } catch (\Exception $e) {
            // Erreur reseau, token expire, code deja consomme... on log pas le detail
            // cote client (reason= generique).
            header('Location: ' . $errorUrl . '&reason=token_exchange', true, 302);
            exit;
        }

        // 3. Upsert user. On regarde si l'email existe deja (un user inscrit en
        // email/password peut se reconnecter en Google avec le meme email = login,
        // pas creation).
        $db = Database::getInstance();

        $stmt = $db->query(
            "SELECT u.id FROM users u WHERE u.email = ? LIMIT 1",
            [$googleEmail]
        );
        $existingUser = $stmt->fetch();

        if ($existingUser) {
            // Login : on garde le user_id existant.
            $userId = $existingUser['id'];
        } else {
            // Signup via Google : on cree le user. password_hash vide (impossible
            // a matcher avec password_verify, donc pas de risque de connexion
            // email/password sur ce compte tant que le user n'a pas defini un mdp).
            $nameParts = explode(' ', $googleName, 2);
            $firstName = $nameParts[0] ?? '';
            $lastName  = $nameParts[1] ?? '';

            // UUID v4 fait main, idem AuthController::signup.
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

                // REPLACE car un trigger BDD peut avoir cree une ligne profile vide
                // au moment de l'INSERT users (cf init_trigger.sql).
                $db->query(
                    "REPLACE INTO profiles (id, first_name, last_name, statut_compte, updated_at)
                     VALUES (?, ?, ?, 'actif', NOW())",
                    [$userId, $firstName, $lastName]
                );

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

        // 4. Recup des roles (l'user peut etre coach ET adherent : roles cumulables).
        $rolesStmt = $db->query(
            "SELECT role FROM user_roles WHERE user_id = ?",
            [$userId]
        );
        $roles = array_column($rolesStmt->fetchAll(), 'role');

        // 5. JWT AGHeal : meme format que le login classique pour que le front
        // ne distingue pas Google vs email/password.
        $expiration = time() + (int)($_ENV['JWT_EXPIRATION'] ?? 3600);
        $payload = [
            'sub'   => $userId,
            'email' => $googleEmail,
            'roles' => $roles,
            'iat'   => time(),
            'exp'   => $expiration,
        ];

        $jwt = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');

        // 6. Redirect final vers le front avec le token en query string.
        // Le front (page GoogleCallback.tsx) le lit, le stocke et redirige vers /dashboard.
        // /!\ Token en URL = trace dans les logs reverse proxy. Acceptable pour un
        // JWT court (1h), pas pour un refresh token.
        $callbackUrl = $frontendUrl . '/auth/google/callback?token=' . urlencode($jwt);
        header('Location: ' . $callbackUrl, true, 302);
        exit;
    }
}
