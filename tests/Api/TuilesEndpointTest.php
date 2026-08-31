<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Service\TuilesRga;
use App\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `GET /api/v1/tuiles/{z}/{x}/{y}.pbf` — SPEC §6bis.
 *
 * Les coordonnées de tuiles ci-dessous ne sont pas devinées : ce sont celles
 * qui contiennent les carrés du zonage synthétique, calculées par la formule
 * des tuiles web. Le carré « fort » de la fixture couvre 2,35→2,45 E et
 * 48,60→48,70 N ; la tuile 14/8301/5649 tombe dedans.
 */
final class TuilesEndpointTest extends ApiTestCase
{
    /** Une tuile de la zone forte du zonage synthétique. */
    private const TUILE_ZONE = '/api/v1/tuiles/14/8301/5649.pbf';

    /** Paris intra-muros : la carte officielle n'y dessine rien (SPEC §3). */
    private const TUILE_VIDE = '/api/v1/tuiles/14/8298/5635.pbf';

    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerZonageSynthetique();
        $this->creerPartenaire();
    }

    public function testUneTuileDeZoneExposeeEstUnProtobufNonVide(): void
    {
        $this->client->request('GET', self::TUILE_ZONE, ['key' => self::CLE]);
        $reponse = $this->client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertSame('application/vnd.mapbox-vector-tile', $reponse->headers->get('Content-Type'));
        self::assertNotSame('', (string) $reponse->getContent());

        // Le nom de la couche est le contrat avec le style du client : s'il
        // change, la carte devient blanche sans la moindre erreur.
        self::assertStringContainsString(TuilesRga::COUCHE, (string) $reponse->getContent());
    }

    /**
     * La moitié du territoire n'a aucun polygone : une tuile vide est la
     * réponse NORMALE, pas une panne. 204 le dit sans ambiguïté — et reste
     * mise en cache comme les autres.
     */
    public function testUneTuileSansZoneRepond204(): void
    {
        $this->client->request('GET', self::TUILE_VIDE, ['key' => self::CLE]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Le millésime voyage dans l'URL, ce qui rend la tuile immuable. Un client
     * qui en annonce un autre — page ouverte avant une bascule — ne doit rien
     * garder : sa carte serait celle de l'ancien zonage.
     */
    public function testLeMillesimeAnnonceGouverneLeCache(): void
    {
        $this->client->request('GET', self::TUILE_ZONE, ['key' => self::CLE, 'm' => self::MILLESIME_DEMO]);
        $cache = (string) $this->client->getResponse()->headers->get('Cache-Control');

        self::assertStringContainsString('immutable', $cache);
        self::assertStringContainsString('max-age=31536000', $cache);
        self::assertSame(self::MILLESIME_DEMO, $this->client->getResponse()->headers->get('X-RGA-Millesime'));

        $this->client->request('GET', self::TUILE_ZONE, ['key' => self::CLE, 'm' => '1999']);

        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tuilesHorsDomaine(): iterable
    {
        // En deçà de z5, la France entière tient dans une tuile.
        yield 'zoom trop faible' => ['/api/v1/tuiles/3/4/2.pbf'];
        // Au-delà de z15, le client sur-zoome la dernière tuile reçue.
        yield 'zoom trop fort' => ['/api/v1/tuiles/17/66412/45195.pbf'];
        // x et y doivent tenir dans la grille du zoom demandé.
        yield 'x hors grille' => ['/api/v1/tuiles/5/32/11.pbf'];
        yield 'y hors grille' => ['/api/v1/tuiles/5/16/32.pbf'];
    }

    #[DataProvider('tuilesHorsDomaine')]
    public function testUneTuileHorsDomaineEstRefusee(string $url): void
    {
        $this->client->request('GET', $url, ['key' => self::CLE]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testLaCleEstExigeeCommePourLeVerdict(): void
    {
        $this->client->request('GET', self::TUILE_ZONE);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', self::TUILE_ZONE, ['key' => 'pk_inconnue']);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Le piège de ce produit : une tuile vide et une tuile « pas de données »
     * se ressemblent à l'écran. Zonage absent, il faut donc un 503 — sinon la
     * carte peindrait « aucune exposition » sur la France entière, en succès
     * (SPEC §3, même règle que pour le verdict).
     */
    public function testZonageAbsentRepond503EtNonUneTuileVide(): void
    {
        $this->supprimerZonage();

        $this->client->request('GET', self::TUILE_ZONE, ['key' => self::CLE]);

        self::assertResponseStatusCodeSame(503);

        $this->chargerZonageSynthetique();
    }

    /**
     * L'invariant qui compte : aux échelles où le visiteur regarde vraiment sa
     * parcelle, la carte lit la MÊME géométrie que le verdict. La
     * généralisation ne sert qu'à se repérer de loin, jamais à situer un
     * terrain.
     */
    public function testAuxZoomsUtilesLaCarteLitLaGeometrieExacte(): void
    {
        $tuiles = static::getContainer()->get(TuilesRga::class);

        for ($z = 12; $z <= TuilesRga::ZOOM_MAX; ++$z) {
            self::assertSame('rga_zone_courante', $tuiles->vue($z), "zoom $z");
        }

        // En dessous, on assume de dessiner simplifié — et c'est écrit sous la
        // carte, dans le widget.
        self::assertSame('rga_zone_courante_g', $tuiles->vue(11));
        self::assertSame('rga_zone_courante_g', $tuiles->vue(8));
        self::assertSame('rga_zone_courante_gg', $tuiles->vue(7));
        self::assertSame('rga_zone_courante_gg', $tuiles->vue(TuilesRga::ZOOM_MIN));
    }
}
