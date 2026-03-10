<?php
// src/Controllers/SessionTypeController.php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';

class SessionTypeController
{
    /**
     * GET /session-types
     */
    public function index(): void
    {
        $db = Database::getInstance();
        $sql = "
            SELECT st.*, l.name as location_name
            FROM session_types st
            LEFT JOIN locations l ON l.id = st.default_location_id
            ORDER BY st.name
        ";
        $stmt = $db->query($sql);
        $types = $stmt->fetchAll();

        // Embeds location object for frontend convenience
        foreach ($types as &$type) {
            if ($type['default_location_id']) {
                $type['locations'] = ['name' => $type['location_name']];
            } else {
                $type['locations'] = null;
            }
            unset($type['location_name']);
        }

        http_response_code(200);
        echo json_encode($types);
    }

    /**
     * POST /session-types
     */
    public function create(): void
    {
        Auth::requireRole(['admin', 'coach']);

        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');

        if (empty($name)) {
            http_response_code(422);
            echo json_encode(['error' => 'Le nom est requis']);
            return;
        }

        $db = Database::getInstance();
        $db->query(
            "INSERT INTO session_types (name, description, default_location_id) VALUES (?, ?, ?)",
            [$name, $data['description'] ?? null, $data['default_location_id'] ?? null]
        );

        $id = $db->lastInsertId();

        http_response_code(201);
        echo json_encode(['id' => $id, 'name' => $name, 'message' => 'Activité créée']);
    }

    /**
     * PUT /session-types/{id}
     */
    public function update(int $id): void
    {
        Auth::requireRole(['admin', 'coach']);

        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');

        if (empty($name)) {
            http_response_code(422);
            echo json_encode(['error' => 'Le nom est requis']);
            return;
        }

        $db = Database::getInstance();
        $db->query(
            "UPDATE session_types SET name = ?, description = ?, default_location_id = ? WHERE id = ?",
            [$name, $data['description'] ?? null, $data['default_location_id'] ?? null, $id]
        );

        http_response_code(200);
        echo json_encode(['message' => 'Activité mise à jour']);
    }

    /**
     * DELETE /session-types/{id}
     */
    public function delete(int $id): void
    {
        Auth::requireRole(['admin', 'coach']);

        $db = Database::getInstance();
        $db->query("DELETE FROM session_types WHERE id = ?", [$id]);

        http_response_code(200);
        echo json_encode(['message' => 'Activité supprimée']);
    }
}
