<?php
// src/Repositories/RegistrationRepository.php
//
// Gere les inscriptions adherent <-> seance.
// /!\ La methode registerUser() est critique : transaction + SELECT FOR UPDATE
// pour eviter l'oversell quand 2 personnes cliquent en meme temps. Ne pas
// toucher au FOR UPDATE.

namespace App\Repositories;

use App\Repositories\BaseRepository;
use DateTime;
use Exception;
use PDO;

class RegistrationRepository extends BaseRepository
{
    /**
     * Liste les inscriptions d'un user, avec details seance (titre/date/lieu/type).
     * Utilise par la page History.
     */
    public function findUserRegistrations(string $userId): array
    {
        // Tout en une seule requete avec jointures, plutot que N requetes.
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

        // On reformate en struct imbriquee pour que le front fasse
        // registration.sessions.title directement.
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
     * Inscrit un user a une seance. Lance une Exception avec code HTTP si KO :
     *   404 : seance introuvable / non publiee
     *   403 : hors fenetre J-7
     *   409 : deja inscrit OU seance complete
     *
     * Le verrou FOR UPDATE serialise les requetes concurrentes :
     * sans lui, Alice et Bob peuvent passer le check "9 < 10" en parallele
     * et finir a 11 inscrits sur 10 places. Avec lui, Bob attend le COMMIT
     * d'Alice, relit "10 < 10" => faux => 409 propre.
     */
    public function registerUser(string $userId, string $sessionId): void
    {
        try {
            $this->db->beginTransaction();

            // 1. Recup seance + verrou ligne (FOR UPDATE). filtre status='published'
            //    pour rejeter brouillon/annulee.
            $stmt = $this->db->query(
                "SELECT id, date, limit_registration_7_days, capacity, max_people, max_people_blocking
                 FROM sessions
                 WHERE id = ? AND status = 'published'
                 FOR UPDATE",
                [$sessionId]
            );
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                throw new Exception('Seance introuvable ou non disponible', 404);
            }

            // 2. Fenetre J-7 : si limit_registration_7_days = 1, on bloque > 7j.
            //    Utilise DateTime::diff() pour eviter les soucis de DST.
            if (!empty($session['limit_registration_7_days'])) {
                $sessionDate = new DateTime($session['date']);
                $now = new DateTime();
                $now->setTime(0, 0, 0);

                $interval = $now->diff($sessionDate);
                // invert=0 si seance future, 1 si passee. On bloque > 7j futur uniquement.
                if ($interval->invert === 0 && $interval->days > 7) {
                    throw new Exception('Inscription impossible a plus de 7 jours en avance', 403);
                }
            }

            // 3. Doublon ? UNIQUE(session_id, user_id) en BDD nous protegerait
            //    de toute facon, mais on prefere un msg clair (409).
            $stmt = $this->db->query(
                "SELECT id FROM registrations WHERE user_id = ? AND session_id = ?",
                [$userId, $sessionId]
            );
            if ($stmt->rowCount() > 0) {
                throw new Exception('Vous etes deja inscrit a cette seance', 409);
            }

            // 4. Reste-t-il une place ? capacity = ancien champ, garde pour
            //    retrocompat. max_people_blocking permet une seance "souple".
            $limit = $session['max_people'] ?? $session['capacity'];
            $isBlocking = (bool)($session['max_people_blocking'] ?? 1);

            if ($limit !== null && $isBlocking) {
                // COUNT sous le verrou de l'etape 1 => pas de race condition.
                $stmt = $this->db->query(
                    "SELECT COUNT(*) as cnt FROM registrations WHERE session_id = ?",
                    [$sessionId]
                );
                $count = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($count['cnt'] >= $limit) {
                    throw new Exception('Cette seance est complete (limite atteinte)', 409);
                }
            }

            // 5. OK on insert.
            $this->db->query(
                "INSERT INTO registrations (user_id, session_id) VALUES (?, ?)",
                [$userId, $sessionId]
            );

            $this->db->commit();

        } catch (Exception $e) {
            // Toute exception => rollback + relance pour que le controller
            // la transforme en reponse HTTP.
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Desinscription. Pas besoin de transaction, DELETE est atomique.
     * Si la ligne n'existe pas, rien ne se passe (pas d'erreur).
     */
    public function unregisterUser(string $userId, string $sessionId): void
    {
        $this->db->query(
            "DELETE FROM registrations WHERE user_id = ? AND session_id = ?",
            [$userId, $sessionId]
        );
    }
}
