<?php
// src/Database.php
// Wrapper PDO sans namespace — accessible depuis tous les contrôleurs via require_once

// ─── Wrapper PDO ─────────────────────────────────────────────────────────────
class Database
{
    private static ?\PDO $pdo = null;

    /**
     * Retourne l'instance PDO (singleton).
     * IMPORTANT : retourne l'objet Database lui-même (wrapper), pas le PDO brut.
     */
    public static function getInstance(): self
    {
        if (self::$pdo === null) {
            $config = require __DIR__ . '/Config/database.php';

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );

            try {
                self::$pdo = new \PDO($dsn, $config['username'], $config['password'], [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (\PDOException $e) {
                error_log('Database connection failed: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'error' => 'Connexion base de données échouée',
                    'details' => $e->getMessage() // Pour le débogage temporaire
                ]);
                exit;
            }
        }

        // Retourne un wrapper pour que ->query($sql, $params) fonctionne
        return new self();
    }

    /**
     * Exécute une requête SQL avec paramètres et retourne le PDOStatement.
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Retourne l'ID du dernier insert.
     */
    public function lastInsertId(): string|bool
    {
        return self::$pdo->lastInsertId();
    }

    /**
     * Démarre une transaction.
     */
    public function beginTransaction(): bool
    {
        return self::$pdo->beginTransaction();
    }

    /**
     * Valide une transaction.
     */
    public function commit(): bool
    {
        return self::$pdo->commit();
    }

    /**
     * Annule une transaction.
     */
    public function rollBack(): bool
    {
        return self::$pdo->rollBack();
    }

    /**
     * Prépare une requête SQL (pour compatibilité avec l'ancien code).
     */
    public function prepare(string $sql): \PDOStatement
    {
        return self::$pdo->prepare($sql);
    }
}
