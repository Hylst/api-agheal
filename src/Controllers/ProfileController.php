<?php
// src/Controllers/ProfileController.php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Helpers/Sanitizer.php';

class ProfileController
{
    /**
     * GET /profiles/me
     */
    public function me(): void
    {
        $currentUser = Auth::requireAuth();
        $userId = $currentUser['sub'];

        $db = \Database::getInstance();
        $stmt = $db->query("SELECT * FROM profiles WHERE id = ?", [$userId]);
        $profile = $stmt->fetch();

        if (!$profile) {
            http_response_code(404);
            echo json_encode(['error' => 'Profil introuvable']);
            return;
        }

        // Récupérer les rôles depuis la table user_roles
        $roles = $db->query(
            "SELECT role FROM user_roles WHERE user_id = ?",
            [$userId]
        )->fetchAll(\PDO::FETCH_COLUMN);

        // Récupérer l'email depuis la table users
        $userRow = $db->query(
            "SELECT email FROM users WHERE id = ?",
            [$userId]
        )->fetch();

        // Retourner un objet complet attendu par le frontend useAuth
        $userData = array_merge($profile, [
            'email' => $userRow['email'] ?? $currentUser['email'] ?? '',
            'roles' => $roles ?: ['adherent'],
        ]);

        http_response_code(200);
        echo json_encode(['user' => $userData]);
    }

    /**
     * GET /profiles/{id}
     */
    public function show(string $id): void
    {
        Auth::requireAuth();

        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM profiles WHERE id = ?", [$id]);
        $profile = $stmt->fetch();

        if (!$profile) {
            http_response_code(404);
            echo json_encode(['error' => 'Profil introuvable']);
            return;
        }

        http_response_code(200);
        echo json_encode($profile);
    }

    /**
     * PUT /profiles/{id}
     */
    public function update(string $id): void
    {
        $currentUser = Auth::requireAuth();

        // Seul l'utilisateur lui-même ou un admin peut modifier
        if ($currentUser['sub'] !== $id && !in_array('admin', $currentUser['roles'] ?? [])) {
            http_response_code(403);
            echo json_encode(['error' => 'Accès refusé']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        $allowed = [
            'first_name', 'last_name', 'phone', 'organization',
            'remarks_health', 'additional_info', 'age', 'avatar_base64',
        ];

        $updates = [];
        $values = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                // Sanitize les champs texte libre pour éviter XSS stocké
                $textFields = ['first_name','last_name','phone','organization','remarks_health','additional_info'];
                $value = in_array($field, $textFields, true)
                    ? Sanitizer::text($data[$field], 255)
                    : $data[$field];
                $updates[] = "`$field` = ?";
                $values[]  = $value;
            }
        }

        if (empty($updates)) {
            http_response_code(422);
            echo json_encode(['error' => 'Aucun champ valide fourni']);
            return;
        }

        $values[] = $id;
        $db = Database::getInstance();
        $db->query(
            "UPDATE profiles SET " . implode(', ', $updates) . " WHERE id = ?",
            $values
        );

        http_response_code(200);
        echo json_encode(['message' => 'Profil mis à jour']);
    }

    /**
     * PUT /profiles/me/notifications
     */
    public function updateNotifications(): void
    {
        $currentUser = Auth::requireAuth();
        $userId = $currentUser['sub'];

        $data = json_decode(file_get_contents('php://input'), true);

        $allowed = [
            'notify_session_reminder_email',
            'notify_session_reminder_push',
            'notify_new_sessions_email',
            'notify_new_sessions_push',
            'notify_scheduled_sessions_email',
            'notify_scheduled_sessions_push',
            'notify_renewal_reminder_email',
            'notify_renewal_reminder_push',
            'notify_medical_certif_email',
            'notify_medical_certif_push',
            'notify_expired_payment_email',
            'notify_expired_payment_push',
            'notify_renewal_verify_email',
            'notify_renewal_verify_push',
        ];

        $updates = [];
        $values = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "`$field` = ?";
                // Convertir booléens JSON en tinyint MySQL
                $values[] = $data[$field] ? 1 : 0;
            }
        }

        if (empty($updates)) {
            http_response_code(422);
            echo json_encode(['error' => 'Aucun champ de notification fourni']);
            return;
        }

        $values[] = $userId;
        $db = Database::getInstance();
        $db->query(
            "UPDATE profiles SET " . implode(', ', $updates) . " WHERE id = ?",
            $values
        );

        http_response_code(200);
        echo json_encode(['message' => 'Préférences de notification mises à jour']);
    }

    /**
     * GET /profiles/{id}/groups
     */
    public function getGroups(string $id): void
    {
        Auth::requireAuth();

        $db = Database::getInstance();
        $sql = "
            SELECT g.id, g.name
            FROM user_groups ug
            JOIN `groups` g ON g.id = ug.group_id
            WHERE ug.user_id = ?
            ORDER BY g.name
        ";
        $stmt = $db->query($sql, [$id]);
        $groups = $stmt->fetchAll();

        http_response_code(200);
        echo json_encode($groups);
    }
}
