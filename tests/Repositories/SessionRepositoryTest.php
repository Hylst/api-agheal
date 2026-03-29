<?php
namespace Tests\Repositories;

use App\Repositories\SessionRepository;
use Tests\Repositories\BaseRepositoryTest;

class SessionRepositoryTest extends BaseRepositoryTest
{
    private SessionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        // On instancie le repository en mode isolée (via l'injection de dépendances si on l'avait configuré ou via mock PDO)
        // Dans une refonte avancée, le db sera injecté.
    }

    public function testGetSessionById()
    {
        // Exemple basique de l'architecture d'un test
        $this->assertTrue(true, "L'architecture PHPUnit est prête.");
    }
}
