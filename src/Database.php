<?php
// src/Database.php
//
// Wrapper PDO + pattern Singleton. La connexion MariaDB est creee une seule
// fois par requete HTTP puis reutilisee par tous les Repos.
//
// Pourquoi Singleton ? Ouvrir une connexion PDO coute cher (TCP + auth).
// Une requete HTTP touche 5-10 Repos => 5-10 connexions sinon. Avec le
// Singleton, une seule, partagee. Liberation auto en fin de process PHP.
//
// On retourne le wrapper, pas le PDO brut. Permet une API simplifiee
// (query, transaction, etc.) et facilite le mock en tests PHPUnit.
//
// Pas de namespace : raison historique, fichier d'avant le refactoring PSR-4
// (cf CHANGELOG v1.9.1). Charge via classmap dans composer.json.

class Database
{
    /** Instance PDO unique, partagee. */
    private static ?\PDO $pdo = null;

    /**
     * Retourne un wrapper Database. Init la connexion si pas encore faite.
     */
    public static function getInstance(): self
    {
        if (self::$pdo === null) {
            $config = require __DIR__ . '/Config/database.php';

            // DSN MySQL/MariaDB standard.
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );

            try {
                self::$pdo = new \PDO($dsn, $config['username'], $config['password'], [
                    // Toute erreur SQL devient une exception PHP : on veut try/catch propre.
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,

                    // Resultats en tableaux assoc par defaut ($row['colonne']).
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,

                    // CRITIQUE : on desactive l'emulation des prepared statements.
                    // Sinon PDO simule cote PHP et concatene avant envoi MySQL
                    // => on perd la vraie protection anti-injection.
                    // false = vrais prepared (2 round-trips BDD mais protection reelle).
                    \PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (\PDOException $e) {
                // BDD down ou mal configuree : log serveur + 500 propre.
                // 'details' devrait etre masque en prod (cf APP_ENV dans index.php).
                error_log('Database connection failed: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'error'   => 'Connexion base de donnees echouee',
                    'details' => $e->getMessage(),
                ]);
                exit;
            }
        }

        return new self();
    }

    /**
     * Execute une requete parametrisee.
     * Toujours passer les valeurs via $params (jamais en concat) : regle d'or
     * anti-injection.
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** ID auto-incremente du dernier INSERT (pour tables INT AI). */
    public function lastInsertId(): string|bool
    {
        return self::$pdo->lastInsertId();
    }

    /** Demarre une transaction. */
    public function beginTransaction(): bool
    {
        return self::$pdo->beginTransaction();
    }

    /** Valide la transaction en cours. */
    public function commit(): bool
    {
        return self::$pdo->commit();
    }

    /** Annule la transaction en cours. A appeler dans un catch. */
    public function rollBack(): bool
    {
        return self::$pdo->rollBack();
    }

    /** True si une transaction est ouverte (evite le BEGIN imbrique). */
    public function inTransaction(): bool
    {
        return self::$pdo->inTransaction();
    }

    /** Prepare sans executer. Pour les cas batch (meme requete N fois). */
    public function prepare(string $sql): \PDOStatement
    {
        return self::$pdo->prepare($sql);
    }
}
