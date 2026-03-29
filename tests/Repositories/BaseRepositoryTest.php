<?php
namespace Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PDO;

class BaseRepositoryTest extends TestCase
{
    protected ?PDO $dbMock = null;

    protected function setUp(): void
    {
        // Exemple d'initialisation de Mock pour une instance Database
        // $this->dbMock = $this->createMock(PDO::class);
    }
}
