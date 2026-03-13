<?php
// src/Controllers/SessionController.php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';

class SessionController
{
    /**
     * GET /sessions[?status=draft|published|all&include=registrations]
     */
    public function index(): void
    {
        $status = $_GET['status'] ?? 'published';
        $include = $_GET['include'] ?? '';
        $db = Database::getInstance();

        $whereClause = ($status === 'all') ? '1=1' : "s.status = ?";
        $params = ($status === 'all') ? [] : [$status];

        $sql = "
            SELECT 
                s.*,
                st.name  as session_type_name,
                l.name   as location_name
            FROM sessions s
            LEFT JOIN session_types st ON st.id = s.type_id
            LEFT JOIN locations l     ON l.id  = s.location_id
            WHERE $whereClause
            ORDER BY s.date ASC, s.start_time ASC
        ";

        $stmt = $db->query($sql, $params);
        $sessions = $stmt->fetchAll();

        // Si on demande d'inclure les inscriptions (vue coach)
        $registrationsMap = [];
        $userRegistrations = [];
        $currentUserId = Auth::getUserId();

        if ($include === 'registrations') {
            Auth::requireRole(['admin', 'coach']);
            $regSql = "
                SELECT r.session_id, r.id, p.first_name, p.last_name
                FROM registrations r
                JOIN profiles p ON p.id = r.user_id
            ";
            $regStmt = $db->query($regSql);
            while($reg = $regStmt->fetch()) {
                $registrationsMap[$reg['session_id']][] = [
                    'id' => $reg['id'],
                    'profiles' => [
                        'first_name' => $reg['first_name'],
                        'last_name' => $reg['last_name']
                    ]
                ];
            }
        } else {
            // Sinon on compte juste les inscriptions
            $countSql = "SELECT session_id, COUNT(*) as cnt FROM registrations GROUP BY session_id";
            $countStmt = $db->query($countSql);
            while($count = $countStmt->fetch()) {
                $registrationsMap[$count['session_id']] = (int)$count['cnt'];
            }
            
            // Et on récupère les inscriptions de l'utilisateur actuel
            if ($currentUserId) {
                $userRegSql = "SELECT session_id FROM registrations WHERE user_id = ?";
                $userRegStmt = $db->query($userRegSql, [$currentUserId]);
                $userRegistrations = $userRegStmt->fetchAll(PDO::FETCH_COLUMN);
            }
        }

        // Structure imbriquée compatible frontend
        $sessions = array_map(function ($session) use ($registrationsMap, $include, $userRegistrations) {
            $session['session_types'] = $session['session_type_name']
                ? ['name' => $session['session_type_name']]
                : null;
            $session['locations'] = $session['location_name']
                ? ['name' => $session['location_name']]
                : null;
            
            $isRegistered = in_array($session['id'], $userRegistrations);

            if ($include === 'registrations') {
                $session['registrations'] = $registrationsMap[$session['id']] ?? [];
            } else {
                $count = is_int($registrationsMap[$session['id']] ?? null) 
                    ? $registrationsMap[$session['id']] 
                    : (is_array($registrationsMap[$session['id']] ?? null) ? count($registrationsMap[$session['id']]) : 0);
                $session['registrations'] = [
                    ['count' => $count, 'is_user_registered' => $isRegistered]
                ];
            }
            
            unset($session['session_type_name'], $session['location_name']);
            return $session;
        }, $sessions);

        http_response_code(200);
        echo json_encode($sessions);
    }

    /**
     * POST /sessions
     * Supporte l'insertion d'une session unique ou d'un tableau de sessions
     */
    public function create(): void
    {
        Auth::requireRole(['admin', 'coach']);

        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Données invalides']);
            return;
        }

        $sessionsToCreate = isset($data[0]) ? $data : [$data];
        $db = Database::getInstance();

        try {
            $db->beginTransaction();

            foreach ($sessionsToCreate as $session) {
                $required = ['title', 'date', 'start_time', 'end_time'];
                foreach ($required as $field) {
                    if (empty($session[$field])) {
                        throw new Exception("Le champ '$field' est requis pour toutes les séances");
                    }
                }

                $db->query(
                    "INSERT INTO sessions (title, date, start_time, end_time, type_id, location_id, capacity, min_people, max_people, min_people_blocking, max_people_blocking, equipment_coach, equipment_clients, equipment_location, status, description, created_at, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)",
                    [
                        $session['title'],
                        $session['date'],
                        $session['start_time'],
                        $session['end_time'],
                        $session['type_id']        ?? null,
                        $session['location_id']    ?? null,
                        $session['max_people']     ?? null, // Map capacity to max_people temporarily
                        $session['min_people']     ?? 1,
                        $session['max_people']     ?? 10,
                        isset($session['min_people_blocking']) ? (int)$session['min_people_blocking'] : 1,
                        isset($session['max_people_blocking']) ? (int)$session['max_people_blocking'] : 1,
                        $session['equipment_coach']    ?? null,
                        $session['equipment_clients']  ?? null,
                        $session['equipment_location'] ?? null,
                        $session['status']         ?? 'published',
                        $session['description']    ?? null,
                        Auth::getUserId()
                    ]
                );
            }

            $db->commit();
            http_response_code(201);
            echo json_encode(['message' => count($sessionsToCreate) . ' séance(s) créée(s)']);
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * PUT /sessions/{id}
     */
    public function update(string $id): void
    {
        Auth::requireRole(['admin', 'coach']);

        $data = json_decode(file_get_contents('php://input'), true);

        // Map capacity to max_people temporarily
        if (isset($data['capacity']) && !isset($data['max_people'])) {
            $data['max_people'] = $data['capacity'];
        }

        $allowed = [
            'title', 'date', 'start_time', 'end_time', 'type_id', 'location_id', 
            'capacity', 'status', 'description',
            'min_people', 'max_people', 'min_people_blocking', 'max_people_blocking',
            'equipment_coach', 'equipment_clients', 'equipment_location'
        ];
        $updates = [];
        $values  = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "`$field` = ?";
                $values[]  = $data[$field];
            }
        }

        if (empty($updates)) {
            http_response_code(422);
            echo json_encode(['error' => 'Aucun champ valide fourni']);
            return;
        }

        $values[] = $id;
        $db = Database::getInstance();
        $db->query("UPDATE sessions SET " . implode(', ', $updates) . " WHERE id = ?", $values);

        http_response_code(200);
        echo json_encode(['message' => 'Séance mise à jour']);
    }

    /**
     * DELETE /sessions/{id}
     */
    public function delete(string $id): void
    {
        Auth::requireRole(['admin', 'coach']);

        $db = Database::getInstance();
        // Supprimer d'abord les inscriptions liées
        $db->query("DELETE FROM registrations WHERE session_id = ?", [$id]);
        $db->query("DELETE FROM sessions WHERE id = ?", [$id]);

        http_response_code(200);
        echo json_encode(['message' => 'Séance supprimée']);
    }
}
