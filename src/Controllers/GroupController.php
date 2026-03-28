<?php
// src/Controllers/GroupController.php
namespace App\Controllers;

use Database;
use Auth;

class GroupController
{
    /**
     * GET /groups
     */
    public function index(): void
    {
        Auth::requireAuth();

        $db = Database::getInstance();
        $sql = "
            SELECT g.*, 
                   COUNT(ug.user_id) as member_count
            FROM `groups` g
            LEFT JOIN user_groups ug ON ug.group_id = g.id
            GROUP BY g.id
            ORDER BY g.name
        ";
        $stmt = $db->query($sql);
        $groups = $stmt->fetchAll();

        http_response_code(200);
        echo json_encode($groups);
    }

    /**
     * POST /groups
     */
    public function create(): void
    {
        Auth::requireRole(['admin', 'coach']);

        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');

        if (empty($name)) {
            http_response_code(422);
            echo json_encode(['error' => 'Le nom du groupe est requis']);
            return;
        }

        $db = Database::getInstance();
        $db->query(
            "INSERT INTO `groups` (name, details, remarks, created_by) VALUES (?, ?, ?, ?)",
            [
                $name,
                $data['details'] ?? null,
                $data['remarks'] ?? null,
                $data['created_by'] ?? null,
            ]
        );

        $id = $db->lastInsertId();

        http_response_code(201);
        echo json_encode(['id' => $id, 'name' => $name, 'message' => 'Groupe créé']);
    }

    /**
     * PUT /groups/{id}
     */
    public function update(string $id): void
    {
        Auth::requireRole(['admin', 'coach']);

        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');

        if (empty($name)) {
            http_response_code(422);
            echo json_encode(['error' => 'Le nom du groupe est requis']);
            return;
        }

        $db = Database::getInstance();
        $db->query(
            "UPDATE `groups` SET name = ?, details = ?, remarks = ? WHERE id = ?",
            [$name, $data['details'] ?? null, $data['remarks'] ?? null, $id]
        );

        http_response_code(200);
        echo json_encode(['message' => 'Groupe mis à jour']);
    }

    /**
     * DELETE /groups/{id}
     */
    public function delete(string $id): void
    {
        Auth::requireRole(['admin']);

        $db = Database::getInstance();
        // Dissocier d'abord les membres
        $db->query("DELETE FROM user_groups WHERE group_id = ?", [$id]);
        $db->query("DELETE FROM `groups` WHERE id = ?", [$id]);

        http_response_code(200);
        echo json_encode(['message' => 'Groupe supprimé']);
    }
}
