<?php
namespace Tests\Repositories;

use App\Repositories\RateLimitRepository;
use Tests\Support\RepositoryTestCase;

/**
 * Tests de RateLimitRepository.
 *
 * Couvre :
 *  - Constantes de configuration (seuils et fenetre).
 *  - resolveClientIp() : resolution IP cliente derriere proxy.
 *
 * Les tests BDD (recordAttempt, countRecentFailures, getLockRemainingSeconds)
 * necessiteraient une connexion MariaDB de test, non encore en place dans
 * l'infra PHPUnit (cf RepositoryTestCase commente). A ajouter quand l'infra
 * de test integration sera montee.
 */
class RateLimitRepositoryTest extends RepositoryTestCase
{
    protected function tearDown(): void
    {
        // Nettoie les superglobales modifiees par les tests.
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_ENV['TRUSTED_PROXIES']);
    }

    public function testConstantesDefautAlignéesPolitique(): void
    {
        $this->assertSame(900, RateLimitRepository::WINDOW_SECONDS, 'Fenetre attendue : 15 min.');
        $this->assertSame(5, RateLimitRepository::MAX_FAILED_ATTEMPTS, 'Seuil attendu : 5 echecs.');
    }

    public function testResolveClientIpRetourneRemoteAddrParDefaut(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
        $this->assertSame('203.0.113.42', RateLimitRepository::resolveClientIp());
    }

    public function testResolveClientIpIgnoreForwardedSiProxyNonListé(): void
    {
        // X-Forwarded-For present mais REMOTE_ADDR n'est pas un proxy de confiance.
        // On doit ignorer le header et garder REMOTE_ADDR.
        $_SERVER['REMOTE_ADDR'] = '198.51.100.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.99, 198.51.100.1';
        $_ENV['TRUSTED_PROXIES'] = '203.0.113.99';

        $this->assertSame('198.51.100.1', RateLimitRepository::resolveClientIp());
    }

    public function testResolveClientIpUtiliseForwardedSiProxyDeConfiance(): void
    {
        // REMOTE_ADDR est dans la whitelist : on prend le 1er IP de X-Forwarded-For.
        $_SERVER['REMOTE_ADDR'] = '172.18.0.2';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.42, 172.18.0.2';
        $_ENV['TRUSTED_PROXIES'] = '172.18.0.2,172.18.0.3';

        $this->assertSame('203.0.113.42', RateLimitRepository::resolveClientIp());
    }

    public function testResolveClientIpFallbackSiRemoteAddrAbsent(): void
    {
        // Cas limite : pas de REMOTE_ADDR (test CLI ou config server cassee).
        // On veut une valeur deterministe, pas une exception.
        unset($_SERVER['REMOTE_ADDR']);
        $this->assertSame('0.0.0.0', RateLimitRepository::resolveClientIp());
    }
}
