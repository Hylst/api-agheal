<?php
// src/Controllers/AppInfoController.php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';

class AppInfoController
{
    /**
     * GET /app-info
     */
    public function index(): void
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM app_info LIMIT 1");
        $info = $stmt->fetch();

        if (!$info) {
            // Créer une ligne vide si elle n'existe pas
            $db->query("INSERT INTO app_info (id, informations_complementaires) VALUES (1, '')");
            $info = ['informations_complementaires' => '', 'precisions' => '', 'communication_speciale' => ''];
        }

        http_response_code(200);
        echo json_encode($info);
    }

    /**
     * PUT /app-info
     */
    public function update(): void
    {
        Auth::requireRole(['admin', 'coach']);

        $data = json_decode(file_get_contents('php://input'), true);
        
        $db = Database::getInstance();
        
        $sql = "UPDATE app_info SET 
                informations_complementaires = ?, 
                precisions = ?, 
                communication_speciale = ?, 
                updated_at = NOW() 
                WHERE id = 1";
        
        $db->query($sql, [
            $data['informations_complementaires'] ?? '',
            $data['precisions'] ?? '',
            $data['communication_speciale'] ?? ''
        ]);

        http_response_code(200);
        echo json_encode(['message' => 'Informations mises à jour']);
    }
}
