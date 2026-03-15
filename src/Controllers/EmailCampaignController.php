<?php
// src/Controllers/EmailCampaignController.php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';

class EmailCampaignController {

    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Pour Admin/Coach : Liste toutes les campagnes
     */
    public function index() {
        Auth::requireRole(['admin', 'coach']);

        try {
            $stmt = $this->db->query("
                SELECT c.*, p.first_name, p.last_name 
                FROM email_campaigns c 
                LEFT JOIN profiles p ON c.author_id = p.id
                ORDER BY c.created_at DESC
            ");
            $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['data' => $campaigns]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la récupération des campagnes d\'e-mails']);
        }
    }

    /**
     * Pour Admin/Coach : Créer une nouvelle campagne d'e-mails
     */
    public function create() {
        $user = Auth::requireAuth();
        Auth::requireRole(['admin', 'coach']);

        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['subject']) || !isset($data['content']) || !isset($data['target_type']) || !isset($data['scheduled_at'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Données incomplètes']);
            return;
        }

        $subject = $data['subject'];
        $content = $data['content'];
        $targetType = $data['target_type'];
        $targetId = ($targetType === 'all') ? null : ($data['target_id'] ?? null);
        $scheduledAt = $data['scheduled_at'];
        $authorId = $user['sub'] ?? null;

        if ($targetType !== 'all' && empty($targetId)) {
            http_response_code(400);
            echo json_encode(['error' => 'L\'ID de la cible est requis pour une campagne ciblée.']);
            return;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO email_campaigns (author_id, subject, content, target_type, target_id, scheduled_at, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$authorId, $subject, $content, $targetType, $targetId, $scheduledAt]);

            $id = $this->db->lastInsertId();

            // Ajouter dans l'historique immuable
            $stmtHistory = $this->db->prepare("
                INSERT INTO message_history (author_id, message_type, target_type, target_id, subject, content)
                VALUES (?, 'email', ?, ?, ?, ?)
            ");
            $stmtHistory->execute([$authorId, $targetType, $targetId, $subject, $content]);

            // Renvoyer l'objet créé
            $fetchStmt = $this->db->prepare("
                SELECT c.*, p.first_name, p.last_name 
                FROM email_campaigns c 
                LEFT JOIN profiles p ON c.author_id = p.id
                WHERE c.id = ?
            ");
            $fetchStmt->execute([$id]);
            $campaign = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'message' => 'Campagne d\'e-mails enregistrée avec succès',
                'data' => $campaign
            ]);
        } catch (PDOException $e) {
            error_log($e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de l\'enregistrement de la campagne d\'e-mails']);
        }
    }

    /**
     * Pour Admin/Coach : Supprimer une campagne (si elle n'est pas encore envoyée)
     */
    public function delete($id) {
        Auth::requireRole(['admin', 'coach']);
        
        try {
            // Optionnel : on pourrait interdire de supprimer si status === 'sent', mais ce n'est pas gênant 
            // pour faire du ménage
            $stmt = $this->db->prepare("DELETE FROM email_campaigns WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['message' => 'Campagne supprimée avec succès']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la suppression de la campagne']);
        }
    }
}
