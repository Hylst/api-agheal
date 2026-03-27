<?php
// src/Controllers/AuthController.php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Helpers/Sanitizer.php';
require_once __DIR__ . '/../Services/MailerService.php';

use Firebase\JWT\JWT;

class AuthController
{
    /**
     * POST /auth/login
     */
    public function login(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
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

        if (!$user || !password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Identifiants incorrects']);
            return;
        }

        // Récupérer profil et rôles
        $profile = $db->query("SELECT first_name, last_name FROM profiles WHERE id = ?", [$user['id']])->fetch()
            ?: ['first_name' => '', 'last_name' => ''];

        $roles = $db->query("SELECT role FROM user_roles WHERE user_id = ?", [$user['id']])->fetchAll(PDO::FETCH_COLUMN);

        // Générer JWT
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
     */
    public function signup(): void
    {
        $data      = json_decode(file_get_contents('php://input'), true);
        $email     = filter_var(trim($data['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $password  = $data['password'] ?? '';
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

        if (strlen($password) < 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Le mot de passe doit contenir au moins 8 caractères']);
            return;
        }

        $db = Database::getInstance();

        // Vérifier email unique
        $existing = $db->query("SELECT id FROM users WHERE email = ?", [$email])->fetch();
        if ($existing) {
            http_response_code(409);
            echo json_encode(['error' => 'Cet email est déjà utilisé']);
            return;
        }

        // Générer un UUID v4
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

            $db->query(
                "REPLACE INTO profiles (id, first_name, last_name, statut_compte, updated_at) VALUES (?, ?, ?, 'actif', NOW())",
                [$id, $firstName, $lastName]
            );

            $db->query(
                "REPLACE INTO user_roles (user_id, role) VALUES (?, 'adherent')",
                [$id]
            );

            $db->commit();

            http_response_code(201);
            echo json_encode(['message' => 'Compte créé avec succès', 'id' => $id]);
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Signup Error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la création du compte']);
        }
    }

    /**
     * POST /auth/reset-password
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

        // Pour la sécurité, toujours retourner 200 même si l'email n'existe pas
        // (évite l'énumération d'emails)
        $db = Database::getInstance();
        $user = $db->query("SELECT id, email FROM users WHERE email = ?", [$email])->fetch();

        if ($user) {
            // Générer un token sécurisé et l'enregistrer en base
            $token     = bin2hex(random_bytes(32)); // 64 chars, cryptographiquement aléatoire
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // Valide 1h

            // Créer la table si elle n'existe pas encore (guard de robustesse)
            $db->query("
                CREATE TABLE IF NOT EXISTS password_resets (
                    user_id   CHAR(36)     NOT NULL PRIMARY KEY,
                    token     VARCHAR(64)  NOT NULL,
                    expires_at DATETIME    NOT NULL,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Upsert (1 token à la fois par utilisateur)
            $db->query(
                "REPLACE INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)",
                [$user['id'], $tokenHash, $expiresAt]
            );

            // Construire le lien de reset
            $frontendUrl = rtrim($_ENV['FRONTEND_URL'] ?? 'https://agheal.hylst.fr', '/');
            $resetLink   = "{$frontendUrl}/reset-password?token={$token}&email=" . urlencode($email);

            // Envoyer l'email via MailerService
            try {
                $mailer = new \App\Services\MailerService();
                $mailer->sendPasswordReset($email, $resetLink);
            } catch (Exception $eMailer) {
                error_log("Password reset mailer error: " . $eMailer->getMessage());
            }
        }

        http_response_code(200);
        echo json_encode(['message' => 'Si cet email existe, un lien de réinitialisation a été envoyé']);
    }
}
