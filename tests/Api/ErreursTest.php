<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;

/**
 * « Toutes les erreurs suivent RFC 7807 » (SPEC §6) — y compris celles qu'on
 * n'a pas prévues.
 */
final class ErreursTest extends ApiTestCase
{
    /**
     * Découvert en répétition de production : sans traitement, une table
     * disparue faisait renvoyer une page HTML sur une route d'API. Le widget
     * tourne sur des sites tiers, il ne sait rien faire d'une page HTML.
     */
    public function testUnePanneImprevueResteDuProblemJson(): void
    {
        $this->creerPartenaire();
        $this->db()->executeStatement('ALTER TABLE partner RENAME TO partner_indisponible');

        try {
            $this->client->request('GET', '/api/v1/zonage', [
                'key' => self::CLE, 'lat' => '48.65', 'lon' => '2.40',
            ]);

            self::assertResponseStatusCodeSame(500);
            self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

            $corps = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertSame('Erreur interne', $corps['title']);
            // Aucun détail interne : un message d'erreur PHP renvoyé à un site
            // tiers est une fuite d'information.
            self::assertStringNotContainsString('SQLSTATE', $corps['detail']);
            self::assertStringNotContainsString('partner', $corps['detail']);
        } finally {
            $this->db()->executeStatement('ALTER TABLE partner_indisponible RENAME TO partner');
        }
    }
}
