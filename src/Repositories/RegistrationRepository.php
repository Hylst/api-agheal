<?php
// src/Repositories/RegistrationRepository.php
namespace App\Repositories;

use App\Repositories\BaseRepository;
use DateTime;
use Exception;
use PDO;

class RegistrationRepository extends BaseRepository
{
    /**
     * Récupère toutes les inscriptions d'un utilisateur avec le détail des séances.
     */
    public function findUserRegistrations(string $userId): array
    {
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

        $rows = $this->fetchAll($sql, [$userId]);

        // Formate les données pour correspondre à la structure attendue par le frontend (identique à Supabase)
        return array_map(function ($row) {
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
    }

    /**
     * Inscrit un utilisateur à une séance avec vérification des règles de gestion en base.
     * Les transactions garantissent que si 2 personnes cliquent en même temps, il n'y a pas de problème.
     * Lance une Exception avec code d'erreur HTTP si une règle n'est pas respectée.
     */
    public function registerUser(string $userId, string $sessionId): void
    {
        try {
            // Démarre une transaction pour bloquer la ligne de la séance pendant la vérification
            $this->db->beginTransaction();

            // 1. Récupère les infos de la séance ET VÉRROUILLE LA LIGNE (FOR UPDATE) 
            // pour éviter que quelqu'un d'autre s'inscrive au même instant s'il ne reste qu'une place.
            $stmt = $this->db->query(
                "SELECT id, date, limit_registration_7_days, capacity, max_people, max_people_blocking 
                 FROM sessions 
                 WHERE id = ? AND status = 'published'
                 FOR UPDATE",
                [$sessionId]
            );
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                throw new Exception('Séance introuvable ou non disponible', 404);
            }

            // 2. Règle métier : Vérifie la limite d'inscription à 7 jours
            if (!empty($session['limit_registration_7_days'])) {
                $sessionDate = new DateTime($session['date']);
                $now = new DateTime();
                $now->setTime(0, 0, 0); // Comparer à partir de minuit
                
                $interval = $now->diff($sessionDate);
                if ($interval->invert === 0 && $interval->days > 7) {
                    throw new Exception('Inscription impossible à plus de 7 jours en avance', 403);
                }
            }

            // 3. Règle métier : Vérification des doublons (déjà inscrit ?)
            $stmt = $this->db->query(
                "SELECT id FROM registrations WHERE user_id = ? AND session_id = ?",
                [$userId, $sessionId]
            );
            if ($stmt->rowCount() > 0) {
                throw new Exception('Vous êtes déjà inscrit à cette séance', 409);
            }

            // 4. Règle métier : Reste-t-il de la place ?
            // On prend max_people, ou l'ancien champ capacity si missing
            $limit = $session['max_people'] ?? $session['capacity'];
            $isBlocking = (bool)($session['max_people_blocking'] ?? 1); // Bloquant par défaut
            
            if ($limit !== null && $isBlocking) {
                $stmt = $this->db->query(
                    "SELECT COUNT(*) as cnt FROM registrations WHERE session_id = ?",
                    [$sessionId]
                );
                $count = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($count['cnt'] >= $limit) {
                    throw new Exception('Cette séance est complète (limite atteinte)', 409);
                }
            }

            // 5. Tout est bon, on l'inscrit !
            $this->db->query(
                "INSERT INTO registrations (user_id, session_id) VALUES (?, ?)",
                [$userId, $sessionId]
            );

            // On valide la transaction
            $this->db->commit();

        } catch (Exception $e) {
            // En cas d'erreur (règle non respectée ou crash SQL), on annule la transaction
            $this->db->rollBack();
            // On relance l'exception pour que le contrôleur s'en charge
            throw $e;
        }
    }

    /**
     * Désinscrit un utilisateur d'une séance.
     */
    public function unregisterUser(string $userId, string $sessionId): void
    {
        $this->db->query(
            "DELETE FROM registrations WHERE user_id = ? AND session_id = ?",
            [$userId, $sessionId]
        );
    }
}
