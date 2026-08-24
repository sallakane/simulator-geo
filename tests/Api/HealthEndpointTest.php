<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;

final class HealthEndpointTest extends ApiTestCase
{
    public function testZonageChargeRenvoieMillesimeEtNombreDePolygones(): void
    {
        $this->chargerZonageSynthetique();

        $this->client->request('GET', '/api/v1/health');
        $reponse = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertResponseIsSuccessful();
        self::assertSame('ok', $reponse['status']);
        self::assertSame('demo', $reponse['rga_millesime']);
        self::assertSame(4, $reponse['polygones']);
    }

    /**
     * Supervision : `polygones > 0` est le vrai indicateur (SPEC §13). Une base
     * vide répond parfaitement — elle répond simplement « hors périmètre »
     * partout.
     */
    public function testZonageAbsentSignaleUneDegradation(): void
    {
        $this->supprimerZonage();

        $this->client->request('GET', '/api/v1/health');
        $reponse = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertResponseStatusCodeSame(503);
        self::assertSame('degraded', $reponse['status']);
        self::assertSame(0, $reponse['polygones']);
    }
}
