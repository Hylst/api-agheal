<?php
// src/Repositories/SessionRepository.php
namespace App\Repositories;

use PDO;
use Exception;

/**
 * Accès aux données de la table sessions et registrations.
 * Gère la logique complexe des requêtes pour laisser le contrôleur très clean.
 */
class SessionRepository extends BaseRepository
{
    /**
     * Retourne toutes les séances, filtrées optionnellement et enrichies
     * avec le nombre de participants ou le détail complet des inscriptions.
     * 
     * @param string $status  ex: 'published', 'all'
     * @param string $include ex: 'registrations' ou ''
     * @param string|null $currentUserId L'ID de l'utilisateur courant pour indiquer s'il est inscrit
     */
    public function findAllWithDetails(string $status, string $include, ?string $currentUserId): array
    {
        $whereClause = ($status === 'all') ? '1=1' : "s.status = ?";
        $params = ($status === 'all') ? [] : [$status];

        // 1. Pour la vue publique, masque complètement les séances terminées (date/heure dépassée)
        if ($status !== 'all') {
            $whereClause .= " AND CONCAT(s.date, ' ', s.end_time) >= NOW()";
        }

        // 2. Récupère les séances de base
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

        // 3. Récupère intelligemment les données d'inscription selon le contexte
        $registrationsMap = [];
        $userRegistrations = [];

        if ($include === 'registrations') {
            // Contexte Coach/Admin: On veut le détail des noms des inscrits (RGPD protected par le contrôleur)
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
            // Contexte Publique: on a juste besoin du COUNT pour calculer les places restantes
            $countSql = "SELECT session_id, COUNT(*) as cnt FROM registrations GROUP BY session_id";
            $countStmt = $this->query($countSql);
            while($count = $countStmt->fetch()) {
                $registrationsMap[$count['session_id']] = (int)$count['cnt'];
            }
            
            // Pour afficher le bouton "Se désinscrire" à la bonne personne
            if ($currentUserId) {
                $userRegSql = "SELECT session_id FROM registrations WHERE user_id = ?";
                $userRegStmt = $this->query($userRegSql, [$currentUserId]);
                $userRegistrations = $userRegStmt->fetchAll(PDO::FETCH_COLUMN);
            }
        }

        // 4. Assemble tout dans une structure de tableau formatée pour le Frontend
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
                // Compte sécurisé des inscriptions
                $count = is_int($registrationsMap[$session['id']] ?? null) 
                    ? $registrationsMap[$session['id']] 
                    : (is_array($registrationsMap[$session['id']] ?? null) ? count($registrationsMap[$session['id']]) : 0);
                    
                $session['registrations'] = [
                    ['count' => $count, 'is_user_registered' => $isRegistered]
                ];
            }
            
            // On retire les colonnes temporaires
            unset($session['session_type_name'], $session['location_name']);
            return $session;
        }, $sessions);
    }

    /**
     * Insère une ou plusieurs séances en transaction "tout ou rien" (ACID).
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
     * Emails des adhérents actifs ayant activé la feature "Me notifier par email des nouvelles séances".
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
     * Met à jour une séance dynamique.
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
     * Supprime une séance et ses inscriptions associées. 
     */
    public function delete(string $id): void
    {
        $this->execute("DELETE FROM registrations WHERE session_id = ?", [$id]);
        $this->execute("DELETE FROM sessions WHERE id = ?", [$id]);
    }
}
