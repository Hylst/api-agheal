<?php
// src/Repositories/BaseRepository.php
namespace App\Repositories;

use Database;
use PDOStatement;

/**
 * Base abstraite pour tous les repositories.
 * Centralise l'accès à Database et les opérations génériques.
 */
abstract class BaseRepository
{
    protected \Database $db;

    public function __construct()
    {
        $this->db = \Database::getInstance();
    }

    /** Exécute une requête paramétrisée et retourne le statement. */
    protected function query(string $sql, array $params = []): PDOStatement
    {
        return $this->db->query($sql, $params);
    }

    /** Retourne tous les résultats d'une requête. */
    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** Retourne une seule ligne. */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row ?: null;
    }

    /** Exécute sans retour de données (INSERT/UPDATE/DELETE). */
    protected function execute(string $sql, array $params = []): int
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    public function beginTransaction(): void  { $this->db->beginTransaction(); }
    public function commit(): void            { $this->db->commit(); }
    public function rollBack(): void          { $this->db->rollBack(); }
    public function lastInsertId(): string    { return $this->db->lastInsertId(); }
}
