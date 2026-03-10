<?php
// src/Controllers/AuthController.php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';

use Firebase\JWT\JWT;

class AuthController
{
    /**
     * POST /auth/login
     */
    public function login(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email et mot de passe requis']);
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
        $email     = trim($data['email'] ?? '');
        $password  = $data['password'] ?? '';
        $firstName = $data['first_name'] ?? $data['data']['first_name'] ?? '';
        $lastName  = $data['last_name']  ?? $data['data']['last_name']  ?? '';

        if (empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email et mot de passe requis']);
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
        $email = trim($data['email'] ?? '');

        if (empty($email)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email requis']);
            return;
        }

        // Pour la sécurité, toujours retourner 200 même si l'email n'existe pas
        // (évite l'énumération d'emails)
        $db = Database::getInstance();
        $user = $db->query("SELECT id FROM users WHERE email = ?", [$email])->fetch();

        if ($user) {
            // TODO: Envoyer email de réinitialisation via EmailService
            error_log("Password reset requested for: $email (user: {$user['id']})");
        }

        http_response_code(200);
        echo json_encode(['message' => 'Si cet email existe, un lien de réinitialisation a été envoyé']);
    }
}
