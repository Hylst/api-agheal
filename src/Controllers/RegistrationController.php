<?php
// src/Controllers/RegistrationController.php
namespace App\Controllers;

use Database;
use Auth;
use DateTime;

class RegistrationController
{
    /**
     * GET /registrations/me
     * Retourne les inscriptions de l'utilisateur connecté avec les données de séance
     */
    public function getMyRegistrations(): void
    {
        $currentUser = Auth::requireAuth();
        $userId = $currentUser['sub'];

        $db = Database::getInstance();

        $sql = "
            SELECT 
                r.id,
                r.created_at,
                s.id as session_id,
                s.title,
                s.date,
                s.start_time,
                s.end_time,
                st.name as session_type_name,
                l.name as location_name
            FROM registrations r
            JOIN sessions s ON s.id = r.session_id
            LEFT JOIN session_types st ON st.id = s.type_id
            LEFT JOIN locations l ON l.id = s.location_id
            WHERE r.user_id = ?
            ORDER BY r.created_at DESC
        ";

        $stmt = $db->query($sql, [$userId]);
        $rows = $stmt->fetchAll();

        // Build nested structure equivalent to Supabase select
        $registrations = array_map(function ($row) {
            return [
                'id'         => $row['id'],
                'created_at' => $row['created_at'],
                'sessions'   => [
                    'id'          => $row['session_id'],
                    'title'       => $row['title'],
                    'date'        => $row['date'],
                    'start_time'  => $row['start_time'],
                    'end_time'    => $row['end_time'],
                    'session_types' => $row['session_type_name']
                        ? ['name' => $row['session_type_name']]
                        : null,
                    'locations' => $row['location_name']
                        ? ['name' => $row['location_name']]
                        : null,
                ],
            ];
        }, $rows);

        http_response_code(200);
        echo json_encode($registrations);
    }

    /**
     * POST /registrations
     * Inscrit l'utilisateur connecté à une séance
     */
    public function register(): void
    {
        $currentUser = Auth::requireAuth();
        $userId = $currentUser['sub'];

        $data = json_decode(file_get_contents('php://input'), true);
        $sessionId = $data['session_id'] ?? null;

        if (!$sessionId) {
            http_response_code(422);
            echo json_encode(['error' => 'session_id est requis']);
            return;
        }

        $db = Database::getInstance();

        // Vérifie que la séance existe et est publiée
        $stmt = $db->query(
            "SELECT id, date, limit_registration_7_days, capacity, max_people, max_people_blocking 
             FROM sessions 
             WHERE id = ? AND status = 'published'",
            [$sessionId]
        );
        $session = $stmt->fetch();

        if (!$session) {
            http_response_code(404);
            echo json_encode(['error' => 'Séance introuvable ou non disponible']);
            return;
        }

        // Vérification limite des 7 jours si activée
        if (!empty($session['limit_registration_7_days'])) {
            $sessionDate = new DateTime($session['date']);
            $now = new DateTime();
            $now->setTime(0, 0, 0); // Comparer à partir de minuit
            
            $interval = $now->diff($sessionDate);
            if ($interval->invert === 0 && $interval->days > 7) {
                http_response_code(403);
                echo json_encode(['error' => 'Inscription impossible à plus de 7 jours en avance']);
                return;
            }
        }

        // Vérifie que l'utilisateur n'est pas déjà inscrit
        $stmt = $db->query(
            "SELECT id FROM registrations WHERE user_id = ? AND session_id = ?",
            [$userId, $sessionId]
        );
        if ($stmt->rowCount() > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'Vous êtes déjà inscrit à cette séance']);
            return;
        }

        // Détermine la limite de participants (priorité à max_people, fallback capacity)
        $limit = $session['max_people'] ?? $session['capacity'];
        $isBlocking = (bool)($session['max_people_blocking'] ?? 1); // Par défaut on bloque

        // Vérifie la capacité si une limite est définie ET qu'elle est bloquante
        if ($limit !== null && $isBlocking) {
            $stmt = $db->query(
                "SELECT COUNT(*) as cnt FROM registrations WHERE session_id = ?",
                [$sessionId]
            );
            $count = $stmt->fetch();
            if ($count['cnt'] >= $limit) {
                http_response_code(409);
                echo json_encode(['error' => 'Cette séance est complète (limite atteinte)']);
                return;
            }
        }

        $db->query(
            "INSERT INTO registrations (user_id, session_id) VALUES (?, ?)",
            [$userId, $sessionId]
        );

        http_response_code(201);
        echo json_encode(['message' => 'Inscription confirmée']);
    }

    /**
     * DELETE /registrations/{sessionId}
     * Désinscrit l'utilisateur connecté d'une séance
     */
    public function unregister(string $id): void
    {
        $sessionId = $id;
        $currentUser = Auth::requireAuth();
        $userId = $currentUser['sub'];

        $db = Database::getInstance();
        $db->query(
            "DELETE FROM registrations WHERE user_id = ? AND session_id = ?",
            [$userId, $sessionId]
        );

        http_response_code(200);
        echo json_encode(['message' => 'Désinscription effectuée']);
    }
}
