<?php
namespace Tests\Support;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Classe de base abstraite pour les tests de Repositories.
 * Fournit l'infrastructure mock PDO pout être héritée par les tests concrets.
 */
abstract class RepositoryTestCase extends TestCase
{
    protected ?PDO $dbMock = null;

    protected function setUp(): void
    {
        // Décommentez pour activer le mock PDO :
        // $this->dbMock = $this->createMock(PDO::class);
    }
}
