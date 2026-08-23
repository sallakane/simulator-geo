<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le zonage n'est pas chargé dans l'environnement de test : on vérifie ici que
 * l'absence de donnée se signale (503 + `degraded`) au lieu de passer pour un
 * service sain. Le cas nominal se teste avec les points de référence extraits
 * de la donnée elle-même (SPEC §10).
 */
final class HealthControllerTest extends WebTestCase
{
    public function testZonageAbsentSignaleUneDegradation(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/health');

        self::assertResponseStatusCodeSame(503);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('degraded', $payload['status']);
        self::assertSame(0, $payload['polygones']);
    }
}
