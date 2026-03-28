<?php
// src/Controllers/AdminController.php
namespace App\Controllers;

use Database;
use Auth;
use App\Repositories\UserRepository;

class AdminController
{
    private UserRepository $users;

    public function __construct()
    {
        $this->users = new UserRepository();
    }

    /**
     * GET /admin/users
     * Retourne tous les utilisateurs avec leurs rôles
     */
    public function getUsers(): void
    {
        Auth::requireRole(['admin']);
        $users = $this->users->getAllWithRoles();
        http_response_code(200);
        echo json_encode($users);
    }

    /**
     * GET /admin/coaches
     * Retourne les utilisateurs ayant le rôle coach ou admin
     */
    public function getCoaches(): void
    {
        Auth::requireRole(['admin', 'coach']);
        $coaches = $this->users->getCoaches();
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

        $data   = json_decode(file_get_contents('php://input'), true);
        $status = $data['statut_compte'] ?? null;

        if (!in_array($status, ['actif', 'bloque'], true)) {
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

        $this->users->updateStatus($id, $status);
        $this->logAdminAction($currentUserId, 'update_user_status', [
            'target_user_id' => $id,
            'new_status'     => $status,
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
        $payload       = Auth::requireRole(['admin']);
        $currentUserId = $payload['sub'] ?? null;

        $data = json_decode(file_get_contents('php://input'), true);
        $role = $data['role'] ?? null;

        if (!in_array($role, ['admin', 'coach', 'adherent'], true)) {
            http_response_code(422);
            echo json_encode(['error' => 'Rôle invalide. Valeurs acceptées : admin, coach, adherent']);
            return;
        }

        $added = $this->users->addRole($id, $role);

        if (!$added) {
            http_response_code(200);
            echo json_encode(['message' => 'Ce rôle est déjà assigné']);
            return;
        }

        $this->logAdminAction($currentUserId, 'add_user_role', [
            'target_user_id' => $id,
            'role'           => $role,
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
        $payload       = Auth::requireRole(['admin']);
        $currentUserId = $payload['sub'] ?? null;

        if (!in_array($role, ['admin', 'coach', 'adherent'], true)) {
            http_response_code(422);
            echo json_encode(['error' => 'Rôle invalide']);
            return;
        }

        // Sécurité métier : le rôle adherent est obligatoire
        if ($role === 'adherent') {
            http_response_code(422);
            echo json_encode(['error' => 'Le rôle "adherent" est obligatoire et ne peut être retiré']);
            return;
        }

        // Sécurité critique : Lockout prevention
        if ($role === 'admin' && $id === $currentUserId) {
            http_response_code(403);
            echo json_encode(['error' => 'Vous ne pouvez pas vous retirer votre propre rôle administrateur']);
            return;
        }

        $this->users->removeRole($id, $role);
        $this->logAdminAction($currentUserId, 'remove_user_role', [
            'target_user_id' => $id,
            'role'           => $role,
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
            $db = \Database::getInstance();
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
