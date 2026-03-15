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
     * GET /admin/coaches
     * Retourne les utilisateurs ayant le rôle coach ou admin (pour le sélecteur "Reçu par" dans Règlements)
     */
    public function getCoaches(): void
    {
        Auth::requireRole(['admin', 'coach']);

        $db = Database::getInstance();

        $sql = "
            SELECT DISTINCT
                p.id,
                p.first_name,
                p.last_name,
                u.email
            FROM profiles p
            LEFT JOIN users u ON u.id = p.id
            JOIN user_roles ur ON ur.user_id = p.id AND ur.role IN ('admin', 'coach')
            WHERE p.statut_compte = 'actif'
            ORDER BY p.last_name, p.first_name
        ";

        $stmt = $db->query($sql);
        $coaches = $stmt->fetchAll();

        http_response_code(200);
        echo json_encode(['coaches' => $coaches]);
    }

    /**
     * PUT /admin/users/{id}/status
     * Change le statut d'un compte (actif | bloque)
     */
    public function updateStatus(string $id): void
    {
        $payload = Auth::requireRole(['admin']);
        $currentUserId = $payload['sub'] ?? null;

        $data = json_decode(file_get_contents('php://input'), true);
        $status = $data['statut_compte'] ?? null;

        if (!in_array($status, ['actif', 'bloque'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Statut invalide. Valeurs acceptées : actif, bloque']);
            return;
        }

        // Sécurité critique : Empêcher un admin de se bloquer lui-même
        if ($status === 'bloque' && $id === $currentUserId) {
            http_response_code(403);
            echo json_encode(['error' => 'Vous ne pouvez pas bloquer votre propre compte administrateur']);
            return;
        }

        $db = Database::getInstance();
        $db->query(
            "UPDATE profiles SET statut_compte = ? WHERE id = ?",
            [$status, $id]
        );

        $this->logAdminAction($currentUserId, 'update_user_status', [
            'target_user_id' => $id,
            'new_status' => $status
        ]);

        http_response_code(200);
        echo json_encode(['message' => 'Statut mis à jour', 'statut_compte' => $status]);
    }

    /**
     * POST /admin/users/{id}/roles
     * Ajoute un rôle à un utilisateur
     */
    public function addRole(string $id): void
    {
        $payload = Auth::requireRole(['admin']);
        $currentUserId = $payload['sub'] ?? null;

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
            "SELECT 1 FROM user_roles WHERE user_id = ? AND role = ?",
            [$id, $role]
        );

        if ($stmt->rowCount() > 0) {
            http_response_code(200);
            echo json_encode(['message' => 'Ce rôle est déjà assigné']);
            return;
        }

        $db->query(
            "INSERT INTO user_roles (user_id, role) VALUES (?, ?)",
            [$id, $role]
        );

        $this->logAdminAction($currentUserId, 'add_user_role', [
            'target_user_id' => $id,
            'role' => $role
        ]);

        http_response_code(201);
        echo json_encode(['message' => 'Rôle ajouté', 'role' => $role]);
    }

    /**
     * DELETE /admin/users/{id}/roles/{role}
     * Retire un rôle d'un utilisateur
     */
    public function removeRole(string $id, string $role): void
    {
        $payload = Auth::requireRole(['admin']);
        $currentUserId = $payload['sub'] ?? null;

        if (!in_array($role, ['admin', 'coach', 'adherent'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Rôle invalide']);
            return;
        }

        // Sécurité métier : On ne peut pas retirer le rôle de base 'adherent'
        if ($role === 'adherent') {
            http_response_code(422);
            echo json_encode(['error' => 'Le rôle "adherent" est obligatoire et ne peut être retiré']);
            return;
        }

        // Sécurité critique : Empêcher un admin de se retirer son propre rôle admin (Lockout prevention)
        if ($role === 'admin' && $id === $currentUserId) {
            http_response_code(403);
            echo json_encode(['error' => 'Vous ne pouvez pas vous retirer votre propre rôle administrateur']);
            return;
        }

        $db = Database::getInstance();
        $db->query(
            "DELETE FROM user_roles WHERE user_id = ? AND role = ?",
            [$id, $role]
        );

        $this->logAdminAction($currentUserId, 'remove_user_role', [
            'target_user_id' => $id,
            'role' => $role
        ]);

        http_response_code(200);
        echo json_encode(['message' => 'Rôle retiré']);
    }

    /**
     * Enregistre une action administrative dans les logs
     */
    private function logAdminAction(?string $userId, string $action, array $details): void
    {
        try {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO logs (user_id, action, details) VALUES (?, ?, ?)",
                [$userId, $action, json_encode($details)]
            );
        } catch (\Exception $e) {
            // On ne bloque pas l'action principale si le log échoue
            error_log("Failed to log admin action: " . $e->getMessage());
        }
    }
}
