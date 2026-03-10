<?php
// src/Controllers/AdminController.php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';

class AdminController
{
    /**
     * GET /admin/users
     * Retourne tous les utilisateurs avec leurs rôles
     */
    public function getUsers(): void
    {
        Auth::requireRole(['admin']);

        $db = Database::getInstance();

        $sql = "
            SELECT 
                p.id,
                p.first_name,
                p.last_name,
                p.phone,
                p.statut_compte,
                p.created_at,
                p.payment_status,
                p.renewal_date,
                u.email,
                JSON_ARRAYAGG(
                    JSON_OBJECT('role', ur.role)
                ) as user_roles
            FROM profiles p
            LEFT JOIN users u ON u.id = p.id
            LEFT JOIN user_roles ur ON ur.user_id = p.id
            GROUP BY p.id, u.email
            ORDER BY p.last_name, p.first_name
        ";

        $stmt = $db->query($sql);
        $users = $stmt->fetchAll();

        // Decode JSON user_roles
        foreach ($users as &$user) {
            $roles = json_decode($user['user_roles'] ?? '[]', true);
            // Filter out null roles (user with no role yet)
            $user['user_roles'] = array_values(array_filter($roles, fn($r) => $r['role'] !== null));
        }

        http_response_code(200);
        echo json_encode($users);
    }

    /**
     * PUT /admin/users/{id}/status
     * Change le statut d'un compte (actif | bloque)
     */
    public function updateStatus(string $userId): void
    {
        Auth::requireRole(['admin']);

        $data = json_decode(file_get_contents('php://input'), true);
        $status = $data['statut_compte'] ?? null;

        if (!in_array($status, ['actif', 'bloque'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Statut invalide. Valeurs acceptées : actif, bloque']);
            return;
        }

        $db = Database::getInstance();
        $db->query(
            "UPDATE profiles SET statut_compte = ? WHERE id = ?",
            [$status, $userId]
        );

        http_response_code(200);
        echo json_encode(['message' => 'Statut mis à jour', 'statut_compte' => $status]);
    }

    /**
     * POST /admin/users/{id}/roles
     * Ajoute un rôle à un utilisateur
     */
    public function addRole(string $userId): void
    {
        Auth::requireRole(['admin']);

        $data = json_decode(file_get_contents('php://input'), true);
        $role = $data['role'] ?? null;

        if (!in_array($role, ['admin', 'coach', 'adherent'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Rôle invalide. Valeurs acceptées : admin, coach, adherent']);
            return;
        }

        $db = Database::getInstance();

        // Vérifie si le rôle existe déjà
        $stmt = $db->query(
            "SELECT id FROM user_roles WHERE user_id = ? AND role = ?",
            [$userId, $role]
        );

        if ($stmt->rowCount() > 0) {
            http_response_code(200);
            echo json_encode(['message' => 'Ce rôle est déjà assigné']);
            return;
        }

        $db->query(
            "INSERT INTO user_roles (user_id, role) VALUES (?, ?)",
            [$userId, $role]
        );

        http_response_code(201);
        echo json_encode(['message' => 'Rôle ajouté', 'role' => $role]);
    }

    /**
     * DELETE /admin/users/{id}/roles/{role}
     * Retire un rôle d'un utilisateur
     */
    public function removeRole(string $userId, string $role): void
    {
        Auth::requireRole(['admin']);

        if (!in_array($role, ['admin', 'coach', 'adherent'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Rôle invalide']);
            return;
        }

        $db = Database::getInstance();
        $db->query(
            "DELETE FROM user_roles WHERE user_id = ? AND role = ?",
            [$userId, $role]
        );

        http_response_code(200);
        echo json_encode(['message' => 'Rôle retiré']);
    }
}
