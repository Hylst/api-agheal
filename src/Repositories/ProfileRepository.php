<?php
// src/Repositories/ProfileRepository.php
namespace App\Repositories;

/**
 * Accès aux données de la table profiles.
 */
class ProfileRepository extends BaseRepository
{
    /** Retourne le profil complet d'un utilisateur par son ID. */
    public function findById(string $id): ?array
    {
        return $this->fetchOne("SELECT * FROM profiles WHERE id = ?", [$id]);
    }

    /** 
     * Retourne le profil complet d'un utilisateur + email + rôles.
     * Idéal pour construire le token JWT ou le frontend useAuth().
     */
    public function findWithDetails(string $id): ?array
    {
        $profile = $this->findById($id);
        if (!$profile) return null;

        // Récupérer les rôles
        $roles = $this->fetchAll(
            "SELECT role FROM user_roles WHERE user_id = ?",
            [$id]
        );
        $rolesList = array_column($roles, 'role');

        // Récupérer l'email (Table users)
        $userRow = $this->fetchOne("SELECT email FROM users WHERE id = ?", [$id]);

        return array_merge($profile, [
            'email' => $userRow['email'] ?? '',
            'roles' => $rolesList ?: ['adherent'],
        ]);
    }

    /** Met à jour les champs autorisés du profil. */
    public function update(string $userId, array $fields): void
    {
        if (empty($fields)) return;

        $sets   = array_map(fn($k) => "`$k` = ?", array_keys($fields));
        $values = array_values($fields);
        $values[] = $userId;

        $this->execute(
            "UPDATE profiles SET " . implode(', ', $sets) . " WHERE id = ?",
            $values
        );
    }

    /** Retourne les groupes d'un utilisateur. */
    public function getGroups(string $userId): array
    {
        return $this->fetchAll("
            SELECT g.id, g.name
            FROM `groups` g
            JOIN user_groups ug ON ug.group_id = g.id
            WHERE ug.user_id = ?
            ORDER BY g.name
        ", [$userId]);
    }

    /** Met à jour les préférences de notifications. */
    public function updateNotifications(string $userId, array $prefs): void
    {
        // Liste exacte des colonnes dans la DB pour les notifications
        $allowed = [
            'notify_session_reminder_email',
            'notify_session_reminder_push',
            'notify_new_sessions_email',
            'notify_new_sessions_push',
            'notify_scheduled_sessions_email',
            'notify_scheduled_sessions_push',
            'notify_renewal_reminder_email',
            'notify_renewal_reminder_push',
            'notify_medical_certif_email',
            'notify_medical_certif_push',
            'notify_expired_payment_email',
            'notify_expired_payment_push',
            'notify_renewal_verify_email',
            'notify_renewal_verify_push',
        ];
        
        $sets   = [];
        $values = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $prefs)) {
                $sets[]   = "`$key` = ?";
                $values[] = $prefs[$key] ? 1 : 0;
            }
        }
        if (empty($sets)) return;
        $values[] = $userId;
        $this->execute("UPDATE profiles SET " . implode(', ', $sets) . " WHERE id = ?", $values);
    }
}
