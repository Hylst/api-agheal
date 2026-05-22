<?php
// src/Repositories/BaseRepository.php
//
// Classe abstraite parent de tous les Repos. Mutualise les helpers PDO
// pour eviter le copier-coller dans chaque Repo. Permet aussi un point
// unique de mock pour les tests PHPUnit (cf tests/Support/RepositoryTestCase.php).
//
// Abstract pour qu'on ne l'instancie pas directement : pas de sens sans
// une entite metier rattachee.

namespace App\Repositories;

use Database;
use PDOStatement;

abstract class BaseRepository
{
    /**
     * Wrapper Database (Singleton).
     * \Database avec backslash : Database.php n'a pas de namespace, donc
     * depuis App\Repositories on doit aller chercher la classe a la racine.
     */
    protected \Database $db;

    public function __construct()
    {
        // Tous les Repos partagent la meme instance PDO via le Singleton.
        // Une seule connexion par requete HTTP meme avec 10 Repos differents.
        $this->db = \Database::getInstance();
    }

    /** Execute parametrise + renvoie le PDOStatement (pour fetch manuel ou rowCount). */
    protected function query(string $sql, array $params = []): PDOStatement
    {
        return $this->db->query($sql, $params);
    }

    /**
     * SELECT toutes lignes. Renvoie [] si rien (jamais null, evite des checks
     * cote appelant).
     */
    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * SELECT une seule ligne. Renvoie null si rien (plus propre que le false
     * que renvoie PDO par defaut).
     */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row ?: null;
    }

    /**
     * INSERT/UPDATE/DELETE muet, renvoie le nb de lignes affectees.
     * ex: if ($this->execute("DELETE FROM x WHERE id = ?", [$id]) === 0) { ... }
     */
    protected function execute(string $sql, array $params = []): int
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    // Transactions exposees en public (pas protected) car des Controllers
    // peuvent orchestrer une transaction qui couvre plusieurs Repos.
    // ex : creation paiement = INSERT payments + UPDATE profiles (2 Repos).

    public function beginTransaction(): void  { $this->db->beginTransaction(); }
    public function commit(): void            { $this->db->commit(); }
    public function rollBack(): void          { $this->db->rollBack(); }

    /** ID auto-incremente du dernier INSERT (tables INT AI). */
    public function lastInsertId(): string    { return $this->db->lastInsertId(); }
}
