<?php
// src/Repositories/SessionRepository.php
//
// Acces BDD pour les tables sessions et registrations.
// Mutualise les jointures (types, lieux, inscrits) pour garder les Controllers
// minces.
//
// Methodes phares :
//   findAllWithDetails() : liste seances + jointures + inscriptions (selon contexte)
//   createMultiple()     : insert N seances en transaction (cas duplication multi-semaines)
//   update()             : UPDATE dynamique avec allowlist (cf controller)

namespace App\Repositories;

use PDO;
use Exception;

class SessionRepository extends BaseRepository
{
    /**
     * Liste les seances enrichies.
     *
     * @param string $status       'published' (defaut public) ou 'all' (planning coach
     *                             qui veut voir brouillons + annulees).
     * @param string $include      'registrations' => detail nominatif des inscrits
     *                             (RGPD : check des droits cote controller, pas ici).
     * @param string|null $currentUserId  Pour flagger "vous etes inscrit" dans la liste
     *                                    publique. Null si pas connecte.
     */
    public function findAllWithDetails(string $status, string $include, ?string $currentUserId): array
    {
        $whereClause = ($status === 'all') ? '1=1' : "s.status = ?";
        $params = ($status === 'all') ? [] : [$status];

        // Vue publique : on masque les seances deja terminees, sinon les adherents
        // voient le planning d'hier. CONCAT(date, end_time) gere bien le DST.
        if ($status !== 'all') {
            $whereClause .= " AND CONCAT(s.date, ' ', s.end_time) >= NOW()";
        }

        // Seances de base + jointures sur types + lieux. LEFT JOIN car certaines
        // anciennes seances peuvent avoir un type_id/location_id devenus NULL.
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

        $sessions = $this->fetchAll($sql, $params);

        // 2 requetes possibles pour les inscriptions selon le contexte appelant.
        $registrationsMap = [];
        $userRegistrations = [];

        if ($include === 'registrations') {
            // Coach/Admin : on veut les noms (cf controller pour le check role).
            $regSql = "
                SELECT r.session_id, r.id, p.first_name, p.last_name
                FROM registrations r
                JOIN profiles p ON p.id = r.user_id
            ";
            $regStmt = $this->query($regSql);
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
            // Vue publique : juste le COUNT pour calculer les places restantes.
            // Pas de noms (RGPD : un adherent n'a pas a voir qui est inscrit).
            $countSql = "SELECT session_id, COUNT(*) as cnt FROM registrations GROUP BY session_id";
            $countStmt = $this->query($countSql);
            while($count = $countStmt->fetch()) {
                $registrationsMap[$count['session_id']] = (int)$count['cnt'];
            }

            // Pour afficher "Se desinscrire" sur les seances ou l'user actuel est inscrit.
            if ($currentUserId) {
                $userRegSql = "SELECT session_id FROM registrations WHERE user_id = ?";
                $userRegStmt = $this->query($userRegSql, [$currentUserId]);
                $userRegistrations = $userRegStmt->fetchAll(PDO::FETCH_COLUMN);
            }
        }

        // Reformatage en structure imbriquee attendue par le front (compat historique
        // avec l'ancien client : { session_types: {name}, locations: {name}, registrations: [...] }).
        return array_map(function ($session) use ($registrationsMap, $include, $userRegistrations) {
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
                // Le map est soit int (count), soit array (detail). On gere les 2.
                $count = is_int($registrationsMap[$session['id']] ?? null)
                    ? $registrationsMap[$session['id']]
                    : (is_array($registrationsMap[$session['id']] ?? null) ? count($registrationsMap[$session['id']]) : 0);

                $session['registrations'] = [
                    ['count' => $count, 'is_user_registered' => $isRegistered]
                ];
            }

            // Clean : on retire les colonnes plates qui sont remontees dans les sous-objets.
            unset($session['session_type_name'], $session['location_name']);
            return $session;
        }, $sessions);
    }

    /**
     * Insere N seances en transaction (tout ou rien).
     * Utilise quand le coach duplique une seance sur plusieurs semaines : si la 5e
     * INSERT plante, on rollback les 4 premieres. Coherence > performance.
     */
    public function createMultiple(array $sessionsPreparedData): void
    {
        try {
            $this->beginTransaction();

            foreach ($sessionsPreparedData as $data) {
                $this->execute(
                    "INSERT INTO sessions (
                        title, date, start_time, end_time, type_id, location_id, capacity,
                        min_people, max_people, min_people_blocking, max_people_blocking,
                        equipment_coach, equipment_clients, equipment_location, status,
                        description, created_at, created_by, limit_registration_7_days
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)",
                    $data
                );
            }

            $this->commit();
        } catch (Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * Liste les emails des adherents actifs qui ont opt-in pour la notif
     * "nouvelle seance ajoutee". Utilise par le cron daily.
     * /!\ Filtre `statut_compte = actif` important : evite de notifier les desactives.
     */
    public function getNewSessionsSubscribers(): array
    {
        return $this->query("
            SELECT p.email
            FROM profiles p
            JOIN user_roles ur ON p.id = ur.user_id
            WHERE ur.role = 'adherent'
              AND p.statut_compte = 'actif'
              AND p.notify_new_sessions_email = 1
              AND p.email IS NOT NULL AND p.email != ''
        ")->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * UPDATE dynamique : le controller construit $updates (ex: ['title = ?', 'capacity = ?'])
     * et $values, on assemble. L'allowlist des colonnes mutables est cote controller
     * pour bloquer mass-assignment.
     */
    public function update(string $id, array $updates, array $values): int
    {
        if (empty($updates)) return 0;

        $values[] = $id;
        return $this->execute(
            "UPDATE sessions SET " . implode(', ', $updates) . " WHERE id = ?",
            $values
        );
    }

    /**
     * Supprime une seance + ses inscriptions. Pas de FK CASCADE volontaire pour
     * garder le DELETE explicite et tracable.
     */
    public function delete(string $id): void
    {
        $this->execute("DELETE FROM registrations WHERE session_id = ?", [$id]);
        $this->execute("DELETE FROM sessions WHERE id = ?", [$id]);
    }
}
