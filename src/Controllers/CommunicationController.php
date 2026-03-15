<?php
// src/Controllers/CommunicationController.php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';

class CommunicationController {

    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Pour Admin/Coach : Liste toutes les communications actives
     */
    public function index() {
        Auth::requireRole(['admin', 'coach']);

        try {
            $stmt = $this->db->query("
                SELECT c.*, p.first_name, p.last_name 
                FROM communications c 
                LEFT JOIN profiles p ON c.author_id = p.id
                ORDER BY c.created_at DESC
            ");
            $communications = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Force typage et nettoyage
            $communications = array_map(function($c) {
                $c['is_urgent'] = isset($c['is_urgent']) ? (bool)$c['is_urgent'] : false;
                return $c;
            }, $communications);

            echo json_encode(['data' => $communications]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la récupération des communications']);
        }
    }

    /**
     * Pour Adhérent (et autres) : Récupère uniquement les messages qui lui sont destinés
     */
    public function getMy() {
        $user = Auth::requireAuth();
        $userId = $user['sub'] ?? null;

        try {
            // Étape 1 : Obtenir les IDs des groupes de l'utilisateur
            $stmtGroups = $this->db->prepare("SELECT group_id FROM user_groups WHERE user_id = ?");
            $stmtGroups->execute([$userId]);
            $groupRow = $stmtGroups->fetchAll(PDO::FETCH_ASSOC);
            $groupIds = array_column($groupRow, 'group_id');

            // Préparation de la requête avec paramètres dynamiques
            $sql = "
                SELECT c.*, p.first_name, p.last_name 
                FROM communications c 
                LEFT JOIN profiles p ON c.author_id = p.id
                WHERE c.target_type = 'all'
                   OR (c.target_type = 'user' AND c.target_id = ?)
            ";
            
            $params = [$userId];

            if (count($groupIds) > 0) {
                // Utiliser des placeholders pour IN (...)
                $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
                $sql .= " OR (c.target_type = 'group' AND c.target_id IN ($placeholders))";
                $params = array_merge($params, $groupIds);
            }

            $sql .= " ORDER BY c.created_at DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $communications = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Formatage
            $communications = array_map(function($c) {
                $c['is_urgent'] = isset($c['is_urgent']) ? (bool)$c['is_urgent'] : false;
                return $c;
            }, $communications);

            echo json_encode(['data' => $communications]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la récupération de vos communications']);
        }
    }

    /**
     * Pour Admin/Coach : Créer ou mettre à jour une communication.
     * Upsert basé sur target_type et target_id (un seul message actif par cible)
     */
    public function save() {
        $user = Auth::requireAuth();
        Auth::requireRole(['admin', 'coach']);

        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['target_type']) || !isset($data['content'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Données incomplètes']);
            return;
        }

        $targetType = $data['target_type'];
        $targetId = ($targetType === 'all') ? null : ($data['target_id'] ?? null);
        $content = $data['content'];
        $isUrgent = isset($data['is_urgent']) ? (int)$data['is_urgent'] : 0;
        $authorId = $user['sub'] ?? null;

        if ($targetType !== 'all' && empty($targetId)) {
            http_response_code(400);
            echo json_encode(['error' => 'L\'ID de la cible est requis pour une communication ciblée.']);
            return;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO communications (author_id, target_type, target_id, content, is_urgent)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    content = VALUES(content),
                    is_urgent = VALUES(is_urgent),
                    author_id = VALUES(author_id),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$authorId, $targetType, $targetId, $content, $isUrgent]);

            // Récupérer la cible mise à jour 
            $fetchStmt = $this->db->prepare("
                SELECT c.*, p.first_name, p.last_name 
                FROM communications c 
                LEFT JOIN profiles p ON c.author_id = p.id
                WHERE c.target_type = ? " . ($targetId ? "AND c.target_id = ?" : "AND c.target_id IS NULL") . "
            ");
            
            if ($targetId) {
                $fetchStmt->execute([$targetType, $targetId]);
            } else {
                $fetchStmt->execute([$targetType]);
            }

            // Ajouter dans l'historique immuable
            $stmtHistory = $this->db->prepare("
                INSERT INTO message_history (author_id, message_type, target_type, target_id, content)
                VALUES (?, 'in_app', ?, ?, ?)
            ");
            $stmtHistory->execute([$authorId, $targetType, $targetId, $content]);

            $communication = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($communication) {
                $communication['is_urgent'] = isset($communication['is_urgent']) ? (bool)$communication['is_urgent'] : false;
                echo json_encode([
                    'message' => 'Communication enregistrée avec succès',
                    'data' => $communication
                ]);
            } else {
                echo json_encode([
                    'message' => 'Communication enregistrée avec succès',
                    'data' => [
                        'id' => $this->db->lastInsertId(),
                        'author_id' => $authorId,
                        'target_type' => $targetType,
                        'target_id' => $targetId,
                        'content' => $content,
                        'is_urgent' => (bool)$isUrgent,
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                ]);
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de l\'enregistrement de la communication']);
        }
    }

    /**
     * Pour Admin/Coach : Mettre à jour le contenu et l'urgence d'un message existant
     */
    public function update(int $id) {
        $user = Auth::requireAuth();
        Auth::requireRole(['admin', 'coach']);

        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['content'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Le contenu est requis']);
            return;
        }

        $content  = $data['content'];
        $isUrgent = isset($data['is_urgent']) ? (int)$data['is_urgent'] : 0;

        try {
            $stmt = $this->db->prepare("
                UPDATE communications
                SET content = ?, is_urgent = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$content, $isUrgent, $id]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Message introuvable']);
                return;
            }

            $fetchStmt = $this->db->prepare("
                SELECT c.*, p.first_name, p.last_name
                FROM communications c
                LEFT JOIN profiles p ON c.author_id = p.id
                WHERE c.id = ?
            ");
            $fetchStmt->execute([$id]);
            $communication = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            $communication['is_urgent'] = isset($communication['is_urgent']) ? (bool)$communication['is_urgent'] : false;

            echo json_encode([
                'message' => 'Message mis à jour avec succès',
                'data'    => $communication
            ]);
        } catch (PDOException $e) {
            error_log($e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la mise à jour']);
        }
    }

    /**
     * Pour Admin/Coach : Supprimer un message
     */
    public function delete($id) {
        Auth::requireRole(['admin', 'coach']);
        
        try {
            $stmt = $this->db->prepare("DELETE FROM communications WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['message' => 'Communication supprimée avec succès']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la suppression de la communication']);
        }
    }
}
