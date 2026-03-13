<?php
// src/Controllers/ClientController.php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';

class ClientController
{
    /**
     * GET /clients
     * Retourne tous les profils d'adhérents avec leurs groupes
     */
    public function index(): void
    {
        Auth::requireRole(['admin', 'coach']);

        $db = Database::getInstance();

        $sql = "
            SELECT 
                p.id,
                p.first_name,
                p.last_name,
                p.phone,
                p.organization,
                p.remarks_health,
                p.additional_info,
                p.coach_remarks,
                p.avatar_base64,
                p.statut_compte,
                p.created_at,
                p.age,
                p.payment_status,
                p.renewal_date,
                u.email,
                (
                    SELECT JSON_ARRAYAGG(
                        JSON_OBJECT('id', g.id, 'name', g.name)
                    )
                    FROM user_groups ug
                    JOIN groups g ON g.id = ug.group_id
                    WHERE ug.user_id = p.id
                ) as groups
            FROM profiles p
            LEFT JOIN users u ON u.id = p.id
            JOIN user_roles ur ON ur.user_id = p.id AND ur.role IN ('adherent', 'coach')
            GROUP BY p.id, u.email
            ORDER BY p.last_name, p.first_name
        ";

        $stmt = $db->query($sql);
        $clients = $stmt->fetchAll();

        foreach ($clients as &$client) {
            $client['groups'] = json_decode($client['groups'] ?? '[]', true) ?: [];
        }

        http_response_code(200);
        echo json_encode($clients);
    }

    /**
     * PUT /clients/{id}
     * Met à jour les champs d'un adhérent (coach_remarks, payment_status, renewal_date)
     */
    public function update(string $id): void
    {
        Auth::requireRole(['admin', 'coach']);

        $data = json_decode(file_get_contents('php://input'), true);

        $allowed = ['coach_remarks', 'payment_status', 'renewal_date'];
        $updates = [];
        $values = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "`$field` = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($updates)) {
            http_response_code(422);
            echo json_encode(['error' => 'Aucun champ valide à mettre à jour']);
            return;
        }

        $values[] = $id;
        $db = Database::getInstance();
        $db->query(
            "UPDATE profiles SET " . implode(', ', $updates) . " WHERE id = ?",
            $values
        );

        // Historisation si le statut passe à 'a_jour'
        if (isset($data['payment_status']) && $data['payment_status'] === 'a_jour') {
            $currentUser = Auth::requireAuth();
            $db->query(
                "INSERT INTO payments_history (user_id, payment_date, renewal_date, received_by) VALUES (?, CURRENT_DATE, ?, ?)",
                [$id, $data['renewal_date'] ?? null, $currentUser['sub']]
            );
        }

        http_response_code(200);
        echo json_encode(['message' => 'Client mis à jour']);
    }

    /**
     * PUT /clients/{id}/groups
     * Resynchronise les groupes d'un adhérent (max 3)
     */
    public function setGroups(string $id): void
    {
        Auth::requireRole(['admin', 'coach']);

        $data = json_decode(file_get_contents('php://input'), true);
        $groupIds = $data['group_ids'] ?? [];
        $assignedBy = $data['assigned_by'] ?? null;

        if (!is_array($groupIds)) {
            http_response_code(422);
            echo json_encode(['error' => 'group_ids doit être un tableau']);
            return;
        }

        if (count($groupIds) > 3) {
            http_response_code(422);
            echo json_encode(['error' => 'Un adhérent ne peut appartenir qu\'à 3 groupes maximum']);
            return;
        }

        $db = Database::getInstance();

        // Supprimer les anciens groupes
        $db->query("DELETE FROM user_groups WHERE user_id = ?", [$id]);

        // Insérer les nouveaux
        foreach ($groupIds as $groupId) {
            $db->query(
                "INSERT INTO user_groups (user_id, group_id, assigned_by) VALUES (?, ?, ?)",
                [$id, (int)$groupId, $assignedBy]
            );
        }

        http_response_code(200);
        echo json_encode(['message' => 'Groupes mis à jour', 'group_ids' => $groupIds]);
    }
}
