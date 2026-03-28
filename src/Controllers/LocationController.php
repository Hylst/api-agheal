<?php
// src/Controllers/LocationController.php
namespace App\Controllers;

use Database;
use Auth;

class LocationController
{
    /**
     * GET /locations
     */
    public function index(): void
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM locations ORDER BY name ASC");
        $locations = $stmt->fetchAll();

        http_response_code(200);
        echo json_encode($locations);
    }

    /**
     * POST /locations
     */
    public function create(): void
    {
        Auth::requireRole(['admin', 'coach']);

        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');

        if (empty($name)) {
            http_response_code(422);
            echo json_encode(['error' => 'Le nom du lieu est requis']);
            return;
        }

        $db = Database::getInstance();
        $db->query(
            "INSERT INTO locations (name, address, city) VALUES (?, ?, ?)",
            [$name, $data['address'] ?? null, $data['city'] ?? null]
        );

        $id = $db->lastInsertId();

        http_response_code(201);
        echo json_encode(['id' => $id, 'name' => $name, 'message' => 'Lieu créé']);
    }

    /**
     * PUT /locations/{id}
     */
    public function update(string $id): void
    {
        Auth::requireRole(['admin', 'coach']);

        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');

        if (empty($name)) {
            http_response_code(422);
            echo json_encode(['error' => 'Le nom du lieu est requis']);
            return;
        }

        $db = Database::getInstance();
        $db->query(
            "UPDATE locations SET name = ?, address = ?, city = ? WHERE id = ?",
            [$name, $data['address'] ?? null, $data['city'] ?? null, $id]
        );

        http_response_code(200);
        echo json_encode(['message' => 'Lieu mis à jour']);
    }

    /**
     * DELETE /locations/{id}
     */
    public function delete(string $id): void
    {
        Auth::requireRole(['admin']);

        $db = Database::getInstance();
        $db->query("DELETE FROM locations WHERE id = ?", [$id]);

        http_response_code(200);
        echo json_encode(['message' => 'Lieu supprimé']);
    }
}
