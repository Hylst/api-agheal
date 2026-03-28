<?php
// src/Controllers/HistoryController.php
namespace App\Controllers;

use Database;
use Auth;
use PDO;
use PDOException;

class HistoryController {

    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Pour Admin/Coach : Liste tout l'historique des communications (In-App et E-mails)
     */
    public function index() {
        Auth::requireRole(['admin', 'coach']);

        try {
            $stmt = $this->db->query("
                SELECT h.*, 
                       p.first_name AS author_first_name, 
                       p.last_name AS author_last_name,
                       target_user.first_name AS target_user_first_name,
                       target_user.last_name AS target_user_last_name,
                       target_group.name AS target_group_name
                FROM message_history h 
                LEFT JOIN profiles p ON h.author_id = p.id
                LEFT JOIN profiles target_user ON h.target_type = 'user' AND h.target_id = target_user.id
                LEFT JOIN groups target_group ON h.target_type = 'group' AND h.target_id = target_group.id
                ORDER BY h.created_at DESC
            ");
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['data' => $history]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la récupération de l\'historique des messages']);
        }
    }
}
