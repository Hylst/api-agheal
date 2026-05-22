<?php
// src/Controllers/AuthController.php
//
// Endpoints d'auth basique : login, signup, reset-password.
// Google OAuth est dans GoogleAuthController.php (flow separe).
//
// Routes :
//   POST /auth/login          -> JWT HS256 (1h)
//   POST /auth/signup         -> creation user (transaction users+profiles+user_roles)
//   POST /auth/reset-password -> envoi email avec token a usage unique (1h)
//
// Securite cle :
//   - password_hash() avec bcrypt par defaut (cout = 10, suffisant).
//   - Anti-enumeration : login renvoie msg generique si email inconnu (pas de
//     "email introuvable" qui aiderait un attaquant a lister les comptes).
//   - reset-password renvoie toujours 200 meme si l'email n'existe pas (meme raison).
//   - Token reset hashe en BDD (sha256) : si la BDD fuite, les tokens valides
//     ne sont pas reutilisables.
//   - JWT signe HS256 avec JWT_SECRET de .env (jamais commit).

namespace App\Controllers;

use Database;
use Auth;
use App\Helpers\Sanitizer;
use App\Services\MailerService;
use Firebase\JWT\JWT;
use PDO;
use Exception;

class AuthController
{
    /**
     * POST /auth/login
     * Verifie email/password, renvoie JWT signe HS256 valable 1h (cf JWT_EXPIRATION env).
     */
    public function login(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        // Sanitize + validate email (2 etapes : nettoyage puis format).
        $email    = filter_var(trim($data['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email et mot de passe requis']);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Format d\'email invalide']);
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM users WHERE email = ?", [$email]);
        $user = $stmt->fetch();

        // password_verify est SLOW BY DESIGN (bcrypt) : ralentit le brute-force.
        // Msg d'erreur GENERIQUE : ne pas distinguer "email inconnu" de "mauvais
        // mot de passe" (sinon un attaquant peut enumerer les comptes existants).
        if (!$user || !password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Identifiants incorrects']);
            return;
        }

        // Profil + roles a inclure dans la reponse pour eviter un 2e round-trip
        // cote front juste apres le login.
        $profile = $db->query("SELECT first_name, last_name FROM profiles WHERE id = ?", [$user['id']])->fetch()
            ?: ['first_name' => '', 'last_name' => ''];

        $roles = $db->query("SELECT role FROM user_roles WHERE user_id = ?", [$user['id']])->fetchAll(PDO::FETCH_COLUMN);

        // JWT : iss/aud pour identifier emetteur/destinataire, exp pour expiration,
        // sub = user id (subject standard JWT), roles dans le payload pour eviter
        // une requete BDD a chaque check de role cote serveur.
        $secret = $_ENV['JWT_SECRET'] ?? 'default_secret_change_me';
        $now    = time();
        $payload = [
            'iss'   => $_ENV['API_URL']      ?? 'http://localhost:8081',
            'aud'   => $_ENV['FRONTEND_URL'] ?? 'http://localhost:5173',
            'iat'   => $now,
            'exp'   => $now + (int)($_ENV['JWT_EXPIRATION'] ?? 3600),
            'sub'   => $user['id'],
            'email' => $user['email'],
            'roles' => $roles,
        ];

        $jwt = JWT::encode($payload, $secret, 'HS256');

        http_response_code(200);
        echo json_encode([
            'access_token' => $jwt,
            'user' => [
                'id'         => $user['id'],
                'email'      => $user['email'],
                'first_name' => $profile['first_name'],
                'last_name'  => $profile['last_name'],
                'roles'      => $roles,
            ],
        ]);
    }

    /**
     * POST /auth/signup
     * Cree user + profile + role 'adherent' en 1 transaction (tout ou rien).
     */
    public function signup(): void
    {
        $data      = json_decode(file_get_contents('php://input'), true);
        $email     = filter_var(trim($data['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $password  = $data['password'] ?? '';
        // strip_tags + htmlspecialchars : double protection XSS sur les noms.
        // Accepte les sous-objets `data.first_name` pour compat avec ancien front.
        $firstName = htmlspecialchars(strip_tags($data['first_name'] ?? $data['data']['first_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $lastName  = htmlspecialchars(strip_tags($data['last_name']  ?? $data['data']['last_name']  ?? ''), ENT_QUOTES, 'UTF-8');

        if (empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email et mot de passe requis']);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Format d\'email invalide']);
            return;
        }

        // /!\ Regle 8 chars mini cote serveur, le front impose en plus 1 maj/min/chiffre.
        // Cf docs/securite/password_policy.md.
        if (strlen($password) < 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Le mot de passe doit contenir au moins 8 caractères']);
            return;
        }

        $db = Database::getInstance();

        // Verifier email unique. On renvoie 409 explicite ici (pas anti-enumeration)
        // car l'user veut savoir que son email est deja pris (UX > paranoia signup).
        $existing = $db->query("SELECT id FROM users WHERE email = ?", [$email])->fetch();
        if ($existing) {
            http_response_code(409);
            echo json_encode(['error' => 'Cet email est déjà utilisé']);
            return;
        }

        // UUID v4 fait main (pas d'extension uuid en stock partout). Le PDO Singleton
        // expose pas de helper, on inline. Cf RFC 4122 : nibble 13 = 4, nibble 17 = 8-b.
        $id   = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        $hash = password_hash($password, PASSWORD_BCRYPT);

        try {
            $db->beginTransaction();

            $db->query(
                "INSERT INTO users (id, email, password_hash, created_at) VALUES (?, ?, ?, NOW())",
                [$id, $email, $hash]
            );

            // REPLACE INTO : idempotent si profile existe deja (cas Google OAuth
            // qui a pu creer une coquille avant). PK = id donc pas de double.
            $db->query(
                "REPLACE INTO profiles (id, first_name, last_name, statut_compte, updated_at) VALUES (?, ?, ?, 'actif', NOW())",
                [$id, $firstName, $lastName]
            );

            // Role par defaut : adherent. Un admin pourra le passer coach plus tard.
            $db->query(
                "REPLACE INTO user_roles (user_id, role) VALUES (?, 'adherent')",
                [$id]
            );

            $db->commit();

            http_response_code(201);
            echo json_encode(['message' => 'Compte créé avec succès', 'id' => $id]);
        } catch (Exception $e) {
            $db->rollBack();
            // /!\ On log l'exception cote serveur mais on renvoie un msg generique
            // au front (pas d'info technique qui aiderait un attaquant).
            error_log('Signup Error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la création du compte']);
        }
    }

    /**
     * POST /auth/reset-password
     * Envoie un email avec un lien de reset si l'email existe.
     * Toujours 200 cote client (anti-enumeration).
     */
    public function resetPassword(): void
    {
        $data  = json_decode(file_get_contents('php://input'), true);
        $email = filter_var(trim($data['email'] ?? ''), FILTER_SANITIZE_EMAIL);

        if (empty($email)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email requis']);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Format d\'email invalide']);
            return;
        }

        // /!\ Anti-enumeration : on ne dit JAMAIS si l'email existe ou pas.
        // Reponse 200 systematique avec msg neutre. Sinon un attaquant peut
        // tester des emails pour savoir qui est inscrit.
        $db = Database::getInstance();
        $user = $db->query("SELECT id, email FROM users WHERE email = ?", [$email])->fetch();

        if ($user) {
            // Token crypto-secure 32 bytes hex = 64 chars. random_bytes() vs mt_rand :
            // ici on PEUT pas se permettre du pseudo-aleatoire, c'est la cle de reset.
            $token     = bin2hex(random_bytes(32));
            // On stocke le HASH du token, pas le token clair. Si la BDD fuite, les
            // tokens valides ne sont pas exploitables.
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1h

            // CREATE TABLE IF NOT EXISTS : garde de robustesse au cas ou la migration
            // n'a pas tourne. PK = user_id donc 1 token actif max par user (le
            // REPLACE plus bas ecrase l'ancien). TODO : sortir ce CREATE en migration.
            $db->query("
                CREATE TABLE IF NOT EXISTS password_resets (
                    user_id   CHAR(36)     NOT NULL PRIMARY KEY,
                    token     VARCHAR(64)  NOT NULL,
                    expires_at DATETIME    NOT NULL,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $db->query(
                "REPLACE INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)",
                [$user['id'], $tokenHash, $expiresAt]
            );

            // Le lien contient le TOKEN CLAIR (pas le hash). C'est ce que recevra
            // l'user dans son email. Au moment du POST /auth/confirm-reset,
            // on hashera le token recu et on comparera au hash en BDD.
            $frontendUrl = rtrim($_ENV['FRONTEND_URL'] ?? 'https://agheal.hylst.fr', '/');
            $resetLink   = "{$frontendUrl}/reset-password?token={$token}&email=" . urlencode($email);

            try {
                $mailer = new \App\Services\MailerService();
                $mailer->sendPasswordReset($email, $resetLink);
            } catch (Exception $eMailer) {
                // Si l'envoi mail plante, on log mais on continue : on renverra quand
                // meme 200 cote client (toujours anti-enumeration).
                error_log("Password reset mailer error: " . $eMailer->getMessage());
            }
        }

        http_response_code(200);
        echo json_encode(['message' => 'Si cet email existe, un lien de réinitialisation a été envoyé']);
    }
}
