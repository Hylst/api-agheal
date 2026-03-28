<?php
// src/Controllers/AttendanceController.php
namespace App\Controllers;

use Database;
use Auth;

class AttendanceController
{
    /** Répertoire des fichiers de log physiques (relatif à la racine du projet) */
    private const LOG_DIR = __DIR__ . '/../../logs/sessions';

    // =========================================================================
    // GET /sessions/{sessionId}/attendance
    // =========================================================================
    /**
     * Retourne la liste des inscrits + statut de présence pour une séance.
     * Inclut les infos complètes du coach (créateur de la séance).
     * Accès : coach / admin seulement.
     */
    public function getAttendance(string $sessionId): void
    {
        $currentUser = Auth::requireAuth();
        $role = $currentUser['role'] ?? 'adherent';

        if (!in_array($role, ['coach', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Accès refusé']);
            return;
        }

        $db = Database::getInstance();

        // Session + infos coach (created_by)
        $stmt = $db->query("
            SELECT
                s.id, s.title, s.date, s.start_time, s.end_time,
                s.min_people, s.max_people, s.status, s.created_by,
                st.name AS type_name,
                l.name AS location_name,
                CONCAT(p.first_name, ' ', p.last_name) AS coach_name,
                p.email AS coach_email
            FROM sessions s
            LEFT JOIN session_types st ON st.id = s.type_id
            LEFT JOIN locations l ON l.id = s.location_id
            LEFT JOIN profiles p ON p.id = s.created_by
            WHERE s.id = ?
        ", [$sessionId]);
        $session = $stmt->fetch();

        if (!$session) {
            http_response_code(404);
            echo json_encode(['error' => 'Séance introuvable']);
            return;
        }

        // Inscrits + statut présence
        $stmt = $db->query("
            SELECT
                r.id as registration_id,
                r.user_id,
                r.attended,
                r.attended_at,
                r.created_at as registered_at,
                p.first_name,
                p.last_name,
                p.email
            FROM registrations r
            JOIN profiles p ON p.id = r.user_id
            WHERE r.session_id = ?
            ORDER BY p.last_name ASC, p.first_name ASC
        ", [$sessionId]);
        $rows = $stmt->fetchAll();

        $attendees = array_map(fn($row) => [
            'registration_id' => $row['registration_id'],
            'user_id'         => $row['user_id'],
            'first_name'      => $row['first_name'],
            'last_name'       => $row['last_name'],
            'email'           => $row['email'],
            'attended'        => (bool) $row['attended'],
            'attended_at'     => $row['attended_at'],
            'registered_at'   => $row['registered_at'],
            'is_walk_in'      => false,
        ], $rows);

        http_response_code(200);
        echo json_encode([
            'session'          => $session,
            'attendees'        => $attendees,
            'count_registered' => count($attendees),
            'count_attended'   => count(array_filter($attendees, fn($a) => $a['attended'])),
        ]);
    }

    // =========================================================================
    // PUT /sessions/{sessionId}/attendance
    // =========================================================================
    /**
     * Met à jour le statut de présence en batch.
     * Gère le walk-in (INSERT si non inscrit).
     * Après enregistrement : écrit dans logs (BDD) + fichier physique.
     *
     * Body: { "attendances": [{ "user_id": "uuid", "attended": true|false }] }
     */
    public function updateAttendance(string $sessionId): void
    {
        $currentUser = Auth::requireAuth();
        $role = $currentUser['role'] ?? 'adherent';
        $coachId = $currentUser['sub'];

        if (!in_array($role, ['coach', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Accès refusé']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $attendances = $data['attendances'] ?? [];

        if (empty($attendances) || !is_array($attendances)) {
            http_response_code(422);
            echo json_encode(['error' => 'Le champ "attendances" est requis et doit être un tableau']);
            return;
        }

        $db = Database::getInstance();

        // Récupérer les infos complètes de la séance (pour le log)
        $stmt = $db->query("
            SELECT
                s.id, s.title, s.date, s.start_time, s.end_time,
                st.name AS type_name,
                l.name AS location_name,
                CONCAT(cp.first_name, ' ', cp.last_name) AS coach_name
            FROM sessions s
            LEFT JOIN session_types st ON st.id = s.type_id
            LEFT JOIN locations l ON l.id = s.location_id
            LEFT JOIN profiles cp ON cp.id = s.created_by
            WHERE s.id = ?
        ", [$sessionId]);
        $session = $stmt->fetch();

        if (!$session) {
            http_response_code(404);
            echo json_encode(['error' => 'Séance introuvable']);
            return;
        }

        $updatedCount = 0;
        $insertedCount = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($attendances as $entry) {
            $userId  = $entry['user_id'] ?? null;
            $attended = isset($entry['attended']) ? (bool) $entry['attended'] : false;

            if (!$userId) continue;

            $stmt = $db->query(
                "SELECT id FROM registrations WHERE session_id = ? AND user_id = ?",
                [$sessionId, $userId]
            );

            if ($stmt->fetch()) {
                $db->query(
                    "UPDATE registrations SET attended = ?, attended_at = ? WHERE session_id = ? AND user_id = ?",
                    [$attended ? 1 : 0, $attended ? $now : null, $sessionId, $userId]
                );
                $updatedCount++;
            } else {
                // Walk-in
                $db->query(
                    "INSERT INTO registrations (session_id, user_id, attended, attended_at) VALUES (?, ?, ?, ?)",
                    [$sessionId, $userId, $attended ? 1 : 0, $attended ? $now : null]
                );
                $insertedCount++;
            }
        }

        // ── ENREGISTREMENT DU LOG (BDD + fichier) ─────────────────────────────

        // Récupérer l'état final complet pour le snapshot
        $stmt = $db->query("
            SELECT
                r.user_id,
                r.attended,
                r.attended_at,
                r.created_at as registered_at,
                p.first_name,
                p.last_name,
                p.email
            FROM registrations r
            JOIN profiles p ON p.id = r.user_id
            WHERE r.session_id = ?
            ORDER BY p.last_name ASC
        ", [$sessionId]);
        $finalState = $stmt->fetchAll();

        $attendedList = array_filter($finalState, fn($r) => (bool)$r['attended']);
        $registeredList = $finalState;

        $logDetails = [
            'session_id'     => $sessionId,
            'session_title'  => $session['title'],
            'session_date'   => $session['date'],
            'session_time'   => $session['start_time'] . ' - ' . $session['end_time'],
            'session_type'   => $session['type_name'],
            'location'       => $session['location_name'],
            'coach_name'     => $session['coach_name'],
            'coach_id'       => $session['coach_id'] ?? null,
            'pointed_by_id'  => $coachId,
            'pointed_at'     => $now,
            'count_registered' => count($registeredList),
            'count_attended'   => count($attendedList),
            'registered'     => array_values(array_map(fn($r) => [
                'user_id'      => $r['user_id'],
                'name'         => $r['first_name'] . ' ' . $r['last_name'],
                'email'        => $r['email'],
            ], $registeredList)),
            'attended'       => array_values(array_map(fn($r) => [
                'user_id'      => $r['user_id'],
                'name'         => $r['first_name'] . ' ' . $r['last_name'],
                'email'        => $r['email'],
                'attended_at'  => $r['attended_at'],
            ], $attendedList)),
            'walk_ins_added' => $insertedCount,
        ];

        // 1. Log en base de données (table logs)
        try {
            $db->query(
                "INSERT INTO logs (user_id, action, details) VALUES (?, ?, ?)",
                [$coachId, 'attendance_saved', json_encode($logDetails, JSON_UNESCAPED_UNICODE)]
            );
        } catch (\Throwable $e) {
            // Non bloquant
            error_log('[AttendanceController] Erreur log BDD : ' . $e->getMessage());
        }

        // 2. Log fichier physique
        $this->writeFileLog($session['date'], $sessionId, $logDetails);

        http_response_code(200);
        echo json_encode([
            'message'        => 'Présences mises à jour',
            'updated'        => $updatedCount,
            'walk_ins_added' => $insertedCount,
            'count_attended' => count($attendedList),
        ]);
    }

    // =========================================================================
    // GET /sessions/{sessionId}/attendance/candidates
    // =========================================================================
    /**
     * Recherche de membres non-inscrits à la séance (pour walk-in).
     * Paramètre GET ?q= pour filtrer (min 2 chars recommandé côté client).
     */
    public function getCandidates(string $sessionId): void
    {
        $currentUser = Auth::requireAuth();
        $role = $currentUser['role'] ?? 'adherent';

        if (!in_array($role, ['coach', 'admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Accès refusé']);
            return;
        }

        $db = Database::getInstance();
        $search = trim($_GET['q'] ?? '');

        $sql = "
            SELECT p.id, p.first_name, p.last_name, p.email
            FROM profiles p
            WHERE p.role IN ('adherent', 'coach', 'admin')
              AND p.id NOT IN (
                    SELECT user_id FROM registrations WHERE session_id = ?
              )
        ";
        $params = [$sessionId];

        if ($search !== '') {
            $like = '%' . $search . '%';
            $sql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.email LIKE ?)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= " ORDER BY p.last_name ASC, p.first_name ASC LIMIT 20";

        $stmt = $db->query($sql, $params);
        $candidates = $stmt->fetchAll();

        http_response_code(200);
        echo json_encode(['candidates' => $candidates]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Écrit un fichier JSON de log dans logs/sessions/YYYY-MM/
     * Format : sessions-YYYY-MM-DD-{sessionId}.json
     */
    private function writeFileLog(string $sessionDate, string $sessionId, array $details): void
    {
        try {
            $month  = substr($sessionDate, 0, 7); // "YYYY-MM"
            $dir    = self::LOG_DIR . '/' . $month;

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Un fichier par séance (écrasé/mis à jour à chaque pointage)
            $filename = $dir . '/session-' . $sessionDate . '-' . substr($sessionId, 0, 8) . '.json';
            $content  = json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents($filename, $content);
        } catch (\Throwable $e) {
            error_log('[AttendanceController] Erreur écriture fichier log : ' . $e->getMessage());
        }
    }
}
