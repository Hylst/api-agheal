<?php
// src/Repositories/AttendanceRepository.php
namespace App\Repositories;

use App\Repositories\BaseRepository;
use PDO;

class AttendanceRepository extends BaseRepository
{
    /**
     * Récupère les infos complètes d'une séance ainsi que le nom du coach.
     */
    public function getSessionDetails(string $sessionId): ?array
    {
        $sql = "
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
        ";
        
        $stmt = $this->db->query($sql, [$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        return $session ?: null;
    }

    /**
     * Récupère la liste de tous les inscrits à une séance avec leur statut de présence.
     */
    public function getSessionAttendees(string $sessionId): array
    {
        $sql = "
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
        ";

        return $this->fetchAll($sql, [$sessionId]);
    }

    /**
     * Recherche des potentiels participants (non-inscrits) pour la séance (Walk-in).
     * @param string $sessionId
     * @param string $search optionnel, recherche par nom, prénom, email
     */
    public function getCandidates(string $sessionId, string $search = ''): array
    {
        $sql = "
            SELECT p.id, p.first_name, p.last_name, p.email
            FROM profiles p
            WHERE (p.role = 'adherent' OR p.role = 'coach' OR p.role = 'admin')
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

        return $this->fetchAll($sql, $params);
    }

    /**
     * Traite un lot de pointage (presence/absence) et gère l'ajout (Walk-in) si non inscrit.
     * Retourne le nombre d'éléments mis à jour et insérés.
     */
    public function processBatchAttendance(string $sessionId, array $attendances): array
    {
        $updatedCount = 0;
        $insertedCount = 0;
        $now = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();

            foreach ($attendances as $entry) {
                $userId  = $entry['user_id'] ?? null;
                $attended = isset($entry['attended']) ? (bool) $entry['attended'] : false;

                if (!$userId) continue;

                $stmt = $this->db->query(
                    "SELECT id FROM registrations WHERE session_id = ? AND user_id = ?",
                    [$sessionId, $userId]
                );

                if ($stmt->fetch()) {
                    // La personne est déjà inscrite, on met juste à jour son statut
                    $this->db->query(
                        "UPDATE registrations SET attended = ?, attended_at = ? WHERE session_id = ? AND user_id = ?",
                        [$attended ? 1 : 0, $attended ? $now : null, $sessionId, $userId]
                    );
                    $updatedCount++;
                } else {
                    // La personne N'EST PAS inscrite : C'est un "Walk-in" (ajout dernière minute)
                    $this->db->query(
                        "INSERT INTO registrations (session_id, user_id, attended, attended_at) VALUES (?, ?, ?, ?)",
                        [$sessionId, $userId, $attended ? 1 : 0, $attended ? $now : null]
                    );
                    $insertedCount++;
                }
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e; // Renvoi de l'explosition pour le contrôleur
        }

        return ['updated' => $updatedCount, 'inserted' => $insertedCount];
    }

    /**
     * Conserve une trace écrite (log) dans la table MySQL `logs`.
     */
    public function saveLogToDatabase(string $coachId, array $logDetails): void
    {
        try {
            $this->db->query(
                "INSERT INTO logs (user_id, action, details) VALUES (?, ?, ?)",
                [$coachId, 'attendance_saved', json_encode($logDetails, JSON_UNESCAPED_UNICODE)]
            );
        } catch (\Throwable $e) {
            // Un crash du log ne doit pas bloquer ou annuler le pointage
            error_log('[AttendanceRepository] Erreur d\'écriture de log en BDD : ' . $e->getMessage());
        }
    }
}
