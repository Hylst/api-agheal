<?php
// src/Repositories/SessionRepository.php
namespace App\Repositories;

/**
 * Accès aux données de la table sessions et registrations.
 */
class SessionRepository extends BaseRepository
{
    /** Retourne toutes les séances avec leurs jointures (type, lieu, créateur, inscriptions). */
    public function findAll(): array
    {
        return $this->fetchAll("
            SELECT
                s.*,
                st.name  AS session_type_name,
                l.name   AS location_name,
                CONCAT(p.first_name,' ',p.last_name) AS coach_name,
                (SELECT COUNT(*) FROM registrations r WHERE r.session_id = s.id) AS registrations_count
            FROM sessions s
            LEFT JOIN session_types st ON st.id = s.type_id
            LEFT JOIN locations     l  ON l.id  = s.location_id
            LEFT JOIN profiles      p  ON p.id  = s.created_by
            ORDER BY s.date ASC, s.start_time ASC
        ");
    }

    /** Trouve une séance par ID. */
    public function findById(string $id): ?array
    {
        return $this->fetchOne("
            SELECT s.*,
                   st.name AS session_type_name,
                   l.name  AS location_name
            FROM sessions s
            LEFT JOIN session_types st ON st.id = s.type_id
            LEFT JOIN locations     l  ON l.id  = s.location_id
            WHERE s.id = ?
        ", [$id]);
    }

    /**
     * Insère une ou plusieurs séances en transaction.
     * @param array $sessions Tableau de tableaux de champs de séance.
     * @param string $createdBy UUID du coach/admin
     */
    public function createMany(array $sessions, string $createdBy): void
    {
        foreach ($sessions as $s) {
            $this->execute("
                INSERT INTO sessions
                    (title, date, start_time, end_time, type_id, location_id,
                     capacity, min_people, max_people,
                     min_people_blocking, max_people_blocking,
                     equipment_coach, equipment_clients, equipment_location,
                     status, description, created_at, created_by, limit_registration_7_days)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?)
            ", [
                $s['title'],
                $s['date'],
                $s['start_time'],
                $s['end_time'],
                $s['type_id']               ?? null,
                $s['location_id']           ?? null,
                $s['max_people']            ?? null,
                $s['min_people']            ?? 1,
                $s['max_people']            ?? 10,
                $s['min_people_blocking']   ?? 1,
                $s['max_people_blocking']   ?? 1,
                $s['equipment_coach']       ?? null,
                $s['equipment_clients']     ?? null,
                $s['equipment_location']    ?? null,
                $s['status']               ?? 'published',
                $s['description']          ?? null,
                $createdBy,
                !empty($s['limit_registration_7_days']) ? 1 : 0,
            ]);
        }
    }

    /** Met à jour les champs autorisés d'une séance. */
    public function update(string $id, array $fields): int
    {
        $allowed = [
            'title','date','start_time','end_time','type_id','location_id',
            'capacity','status','description',
            'min_people','max_people','min_people_blocking','max_people_blocking',
            'equipment_coach','equipment_clients','equipment_location',
            'limit_registration_7_days',
        ];

        $updates = [];
        $values  = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $fields)) {
                $updates[] = "`$field` = ?";
                $values[]  = $fields[$field];
            }
        }
        if (empty($updates)) return 0;
        $values[] = $id;

        return $this->execute(
            "UPDATE sessions SET " . implode(', ', $updates) . " WHERE id = ?",
            $values
        );
    }

    /** Supprime une séance et ses inscriptions associées. */
    public function delete(string $id): void
    {
        $this->execute("DELETE FROM registrations WHERE session_id = ?", [$id]);
        $this->execute("DELETE FROM sessions WHERE id = ?", [$id]);
    }

    /** Emails des adhérents actifs ayant activé notify_new_sessions_email. */
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
        ")->fetchAll(\PDO::FETCH_COLUMN);
    }
}
