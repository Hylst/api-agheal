<?php
namespace Tests\Repositories;

use App\Repositories\RefreshTokenRepository;
use Tests\Support\RepositoryTestCase;

/**
 * Tests de RefreshTokenRepository (smoke + constantes).
 *
 * Les tests fonctionnels (issue, findValid, revoke, rotation) necessitent une
 * connexion BDD de test. Ils sont a ecrire quand l'infra PHPUnit integration
 * sera montee (cf RepositoryTestCase commente).
 *
 * Pour valider manuellement le flow :
 *   1. POST /auth/login -> recupere access_token + refresh_token
 *   2. POST /auth/refresh avec refresh_token -> nouveau couple
 *   3. POST /auth/refresh avec l'ancien refresh -> 401 (revoque par rotation)
 */
class RefreshTokenRepositoryTest extends RepositoryTestCase
{
    public function testTtlParDefaut30Jours(): void
    {
        $this->assertSame(
            2592000,
            RefreshTokenRepository::DEFAULT_TTL_SECONDS,
            'TTL par defaut attendu : 30 jours.'
        );
        $this->assertSame(30, RefreshTokenRepository::DEFAULT_TTL_SECONDS / 86400);
    }

    public function testRepositoryEstChargeable(): void
    {
        // Smoke : la classe se charge correctement via PSR-4 et etend BaseRepository.
        $this->assertTrue(
            class_exists(RefreshTokenRepository::class),
            'RefreshTokenRepository doit etre autoloade.'
        );
        $this->assertTrue(
            is_subclass_of(RefreshTokenRepository::class, \App\Repositories\BaseRepository::class),
            'RefreshTokenRepository doit etendre BaseRepository.'
        );
    }
}
