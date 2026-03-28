<?php
// src/Repositories/UserRepository.php
namespace App\Repositories;

/**
 * Accès aux données des utilisateurs (tables users + user_roles).
 */
class UserRepository extends BaseRepository
{
    /** Trouve un utilisateur par email avec son hash de mot de passe. */
    public function findByEmail(string $email): ?array
    {
        return $this->fetchOne(
            "SELECT u.id, u.email, u.password_hash,
                    p.first_name, p.last_name, p.statut_compte
             FROM users u
             JOIN profiles p ON p.id = u.id
             WHERE u.email = ?",
            [$email]
        );
    }

    /** Trouve un utilisateur par ID. */
    public function findById(string $id): ?array
    {
        return $this->fetchOne("SELECT id, email FROM users WHERE id = ?", [$id]);
    }

    /** Récupère les rôles d'un utilisateur. */
    public function getRoles(string $userId): array
    {
        return $this->query(
            "SELECT role FROM user_roles WHERE user_id = ?",
            [$userId]
        )->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** Crée un utilisateur et son profil dans une transaction. */
    public function create(string $id, string $email, string $passwordHash,
                           string $firstName, string $lastName): void
    {
        $this->execute(
            "INSERT INTO users (id, email, password_hash) VALUES (?, ?, ?)",
            [$id, $email, $passwordHash]
        );
        $this->execute(
            "INSERT INTO profiles (id, first_name, last_name, statut_compte)
             VALUES (?, ?, ?, 'actif')",
            [$id, $firstName, $lastName]
        );
        $this->execute(
            "INSERT INTO user_roles (user_id, role) VALUES (?, 'adherent')",
            [$id]
        );
    }

    /** Ajoute un rôle si absent. Retourne false si déjà présent. */
    public function addRole(string $userId, string $role): bool
    {
        $exists = $this->fetchOne(
            "SELECT 1 FROM user_roles WHERE user_id = ? AND role = ?",
            [$userId, $role]
        );
        if ($exists) return false;
        $this->execute("INSERT INTO user_roles (user_id, role) VALUES (?, ?)", [$userId, $role]);
        return true;
    }

    /** Supprime un rôle. */
    public function removeRole(string $userId, string $role): void
    {
        $this->execute("DELETE FROM user_roles WHERE user_id = ? AND role = ?", [$userId, $role]);
    }

    /** Retourne tous les utilisateurs avec leurs rôles pour l'admin. */
    public function getAllWithRoles(): array
    {
        $users = $this->fetchAll("
            SELECT
                p.id, p.first_name, p.last_name, p.phone,
                p.statut_compte, p.created_at, p.payment_status,
                p.renewal_date, u.email,
                JSON_ARRAYAGG(JSON_OBJECT('role', ur.role)) AS user_roles
            FROM profiles p
            LEFT JOIN users u ON u.id = p.id
            LEFT JOIN user_roles ur ON ur.user_id = p.id
            GROUP BY p.id, u.email
            ORDER BY p.last_name, p.first_name
        ");

        foreach ($users as &$user) {
            $roles = json_decode($user['user_roles'] ?? '[]', true);
            $user['user_roles'] = array_values(
                array_filter($roles, fn($r) => $r['role'] !== null)
            );
        }
        return $users;
    }

    /** Retourne les coaches et admins actifs. */
    public function getCoaches(): array
    {
        return $this->fetchAll("
            SELECT DISTINCT p.id, p.first_name, p.last_name, u.email
            FROM profiles p
            LEFT JOIN users u ON u.id = p.id
            JOIN user_roles ur ON ur.user_id = p.id AND ur.role IN ('admin', 'coach')
            WHERE p.statut_compte = 'actif'
            ORDER BY p.last_name, p.first_name
        ");
    }

    /** Change le statut d'un compte. */
    public function updateStatus(string $userId, string $status): void
    {
        $this->execute(
            "UPDATE profiles SET statut_compte = ? WHERE id = ?",
            [$status, $userId]
        );
    }

    /** Upsert du token de réinitialisation de mot de passe. */
    public function upsertPasswordReset(string $userId, string $tokenHash, string $expiresAt): void
    {
        $this->execute(
            "REPLACE INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)",
            [$userId, $tokenHash, $expiresAt]
        );
    }
}
