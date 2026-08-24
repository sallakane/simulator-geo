<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Partner;
use App\Tests\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le lien vers le formulaire du partenaire, et la mesure de ce qu'on peut
 * réellement observer — SPEC §5, §6 et §9.
 */
final class ConversionTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerZonageSynthetique();
        $this->creerPartenaire();
    }

    public function testLaReponseEmporteDeQuoiConstruireLeLien(): void
    {
        $reponse = $this->zonage(48.65, 2.40);

        self::assertSame(self::FORMULAIRE, $reponse['conversion']['url']);
        self::assertSame('description_de_la_demande', $reponse['conversion']['champs']['message']);
        // Le résumé rend le lead qualifié : sans lui, le partenaire reçoit une
        // adresse et doit rappeler pour savoir quelle mission s'applique.
        self::assertStringContainsString('forte', $reponse['conversion']['resume']);
        self::assertStringContainsString('G1 PGC', $reponse['conversion']['resume']);
        self::assertStringContainsString('2026', $reponse['conversion']['resume']);
    }

    /**
     * L'adresse ne doit JAMAIS repartir de l'API : elle est restée dans le
     * navigateur d'un bout à l'autre. C'est ce qui garde nos journaux propres
     * (SPEC §9).
     */
    public function testLApiNeRenvoieAucuneAdresse(): void
    {
        $reponse = $this->zonage(48.65, 2.40);

        self::assertArrayNotHasKey('adresse', $reponse['conversion']);
        self::assertStringNotContainsString('rue', $reponse['conversion']['resume']);
    }

    /**
     * Hors périmètre aussi : le visiteur doit pouvoir demander conseil. Aucune
     * réponse n'est un cul-de-sac (SPEC §1).
     */
    public function testMemeHorsPerimetreOnPeutConvertir(): void
    {
        $reponse = $this->zonage(48.8566, 2.3522);

        self::assertSame('hors_perimetre', $reponse['statut']);
        self::assertSame(self::FORMULAIRE, $reponse['conversion']['url']);
    }

    public function testPartenaireSansFormulaireNObtientPasDeLien(): void
    {
        // Par l'entité et non en SQL brut : le partenaire est déjà dans
        // l'identity map de Doctrine, un UPDATE direct ne serait pas relu.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getRepository(Partner::class)->findOneBy(['publicKey' => self::CLE])?->setLeadEndpoint(null);
        $em->flush();

        $reponse = $this->zonage(48.65, 2.40);

        // Pas de bloc `conversion` : le widget saura qu'il ne doit pas
        // fabriquer un lien, et se rabattra sur un signal à la page hôte.
        self::assertArrayNotHasKey('conversion', $reponse);
    }

    public function testLeClicEstEnregistre(): void
    {
        $simulationId = $this->zonage(48.65, 2.40)['simulation_id'];
        self::assertFalse((bool) $this->db()->fetchOne('SELECT converti FROM simulation'));

        $this->conversion(['key' => self::CLE, 'simulation_id' => $simulationId]);

        self::assertResponseStatusCodeSame(204);
        self::assertTrue((bool) $this->db()->fetchOne('SELECT converti FROM simulation'));
    }

    /**
     * Sans ce contrôle, l'endpoint deviendrait un moyen de dénombrer les
     * simulations des partenaires voisins.
     */
    public function testOnNeMarquePasLaSimulationDunAutrePartenaire(): void
    {
        $simulationId = $this->zonage(48.65, 2.40)['simulation_id'];

        $this->creerPartenaire2();
        $this->conversion(['key' => 'pk_autre', 'simulation_id' => $simulationId]);

        self::assertResponseStatusCodeSame(400);
        self::assertFalse((bool) $this->db()->fetchOne('SELECT converti FROM simulation'));
    }

    public function testCleInconnueRefusee(): void
    {
        $this->conversion(['key' => 'pk_inexistante', 'simulation_id' => 1]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCorpsInvalideRefuse(): void
    {
        $this->client->request('POST', '/api/v1/conversion', [], [], [], 'pas du json');

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    private function conversion(array $corps): void
    {
        // Envoyé par navigator.sendBeacon : text/plain, donc requête « simple »
        // au sens CORS — pas de préflight avant que la page ne s'en aille.
        $this->client->request('POST', '/api/v1/conversion', [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], json_encode($corps, \JSON_THROW_ON_ERROR));
    }

    private function creerPartenaire2(): void
    {
        $this->db()->executeStatement(
            "INSERT INTO partner (public_key, nom, actif, origines_autorisees, lead_champs, created_at)
             VALUES ('pk_autre', 'Autre', true, '[]', '{}', now())"
        );
    }
}
