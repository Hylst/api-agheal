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
            FROM groups g
            JOIN user_groups ug ON ug.group_id = g.id
            WHERE ug.user_id = ?
        ", [$userId]);
    }

    /** Met à jour les préférences de notifications. */
    public function updateNotifications(string $userId, array $prefs): void
    {
        $allowed = [
            'notify_session_reminder_email',
            'notify_new_sessions_email',
            'notify_payment_reminder_email',
        ];
        $sets   = [];
        $values = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $prefs)) {
                $sets[]   = "`$key` = ?";
                $values[] = (int)$prefs[$key];
            }
        }
        if (empty($sets)) return;
        $values[] = $userId;
        $this->execute("UPDATE profiles SET " . implode(', ', $sets) . " WHERE id = ?", $values);
    }
}
