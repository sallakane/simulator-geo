<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Partner;
use App\Tests\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `GET /api/v1/zonage` — matrice de SPEC §6 et §10.
 */
final class ZonageEndpointTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerZonageSynthetique();
        $this->creerPartenaire();
    }

    /**
     * @return iterable<string, array{float, float, string, string}>
     */
    public static function pointsDuZonage(): iterable
    {
        yield 'exposition forte' => [48.65, 2.40, 'fort', 'G1 PGC'];
        yield 'exposition moyenne' => [48.65, 2.50, 'moyen', 'G1 PGC'];
        yield 'exposition faible' => [48.65, 2.60, 'faible', 'G2 AVP'];
        yield 'exposition nulle' => [48.65, 2.70, 'nul', 'G2 AVP'];
    }

    #[DataProvider('pointsDuZonage')]
    public function testChaqueNiveauRenvoieSaMission(float $lat, float $lon, string $cle, string $mission): void
    {
        $reponse = $this->zonage($lat, $lon);

        self::assertResponseIsSuccessful();
        self::assertSame('ok', $reponse['statut']);
        self::assertSame($cle, $reponse['zone']['cle']);
        self::assertSame($mission, $reponse['obligation']['mission']);
        self::assertSame('NF P 94-500', $reponse['obligation']['norme']);
        self::assertNotNull($reponse['simulation_id']);
    }

    public function testObligationSeulementAuDessusDuNiveauFaible(): void
    {
        self::assertTrue($this->zonage(48.65, 2.40)['obligation']['applicable'], 'fort');
        self::assertTrue($this->zonage(48.65, 2.50)['obligation']['applicable'], 'moyen');
        self::assertFalse($this->zonage(48.65, 2.60)['obligation']['applicable'], 'faible');
        self::assertFalse($this->zonage(48.65, 2.70)['obligation']['applicable'], 'nul');
    }

    /**
     * Sur une frontière, le point appartient à deux polygones. On retient le
     * plus exposé : se tromper vers l'obligation coûte un devis, se tromper
     * dans l'autre sens coûte un vice caché (SPEC §10).
     */
    public function testSurUneFrontiereLeNiveauLePlusExposeGagne(): void
    {
        $reponse = $this->zonage(48.65, 2.45);

        self::assertSame('fort', $reponse['zone']['cle']);
    }

    /**
     * Hors périmètre = cas fonctionnel, donc 200. Un 404 ferait afficher au
     * widget une panne là où il doit afficher une information (SPEC §1).
     */
    public function testParisEstHorsPerimetreEtNonUneErreur(): void
    {
        $reponse = $this->zonage(48.8566, 2.3522);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('hors_perimetre', $reponse['statut']);
        self::assertSame('paris', $reponse['motif']);
        self::assertNotSame('', $reponse['message']);
        self::assertNotNull($reponse['simulation_id'], 'la simulation est mesurée même hors périmètre');
    }

    public function testHorsMetropoleEstDistingueDuNonCouvert(): void
    {
        self::assertSame('hors_metropole', $this->zonage(40.71, -74.01)['motif'], 'New York');
        self::assertSame('non_couvert', $this->zonage(43.30, 5.40)['motif'], 'Marseille, hors zonage synthétique');
    }

    /**
     * La BAN renvoie `coordinates = [lon, lat]`. Une permutation donne des
     * coordonnées valides qui désignent un autre point du globe : elle ne se
     * voit pas, elle se teste (SPEC §10).
     */
    public function testCoordonneesInverseesRefuseesEn400(): void
    {
        $reponse = $this->zonage(2.3522, 48.8566);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertStringContainsString('permut', $reponse['detail']);
    }

    public function testCleInconnueRefusee(): void
    {
        $this->zonage(48.65, 2.40, ['key' => 'pk_inexistante']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCleInactiveRefuseeCommeUneCleInconnue(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $partner = $em->getRepository(Partner::class)->findOneBy(['publicKey' => self::CLE]);
        $partner?->setActif(false);
        $em->flush();

        $reponse = $this->zonage(48.65, 2.40);

        self::assertResponseStatusCodeSame(403);
        // Même message : distinguer les deux permettrait d'énumérer les clés.
        self::assertSame('Clé refusée', $reponse['title']);
    }

    public function testOrigineAutoriseeRecoitLesEntetesCors(): void
    {
        $this->zonage(48.65, 2.40, ['origin' => self::ORIGINE]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', self::ORIGINE);
        self::assertResponseHeaderSame('Vary', 'Origin');
    }

    public function testSousDomaineCouvertParLeJoker(): void
    {
        $this->zonage(48.65, 2.40, ['origin' => 'https://www.exemple-partenaire.fr']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', 'https://www.exemple-partenaire.fr');
    }

    public function testOrigineInconnueRefusee(): void
    {
        $this->zonage(48.65, 2.40, ['origin' => 'https://copie-du-site.example']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse(
            $this->client->getResponse()->headers->has('Access-Control-Allow-Origin'),
            'une réponse refusée ne doit pas porter d’en-tête CORS',
        );
    }

    /**
     * Une base vide répondrait « hors périmètre » pour la France entière, sans
     * la moindre erreur HTTP. C'est la panne la plus coûteuse de ce produit :
     * elle doit être bruyante.
     */
    public function testZonageAbsentRenvoieUnePanneEtNonHorsPerimetre(): void
    {
        $this->supprimerZonage();

        $reponse = $this->zonage(48.65, 2.40);

        self::assertResponseStatusCodeSame(503);
        self::assertSame('Zonage indisponible', $reponse['title']);
    }

    public function testChaqueAppelEstMesureSansDonneePersonnelle(): void
    {
        $this->zonage(48.6512345, 2.4098765);

        $ligne = $this->db()->fetchAssociative('SELECT * FROM simulation');

        self::assertIsArray($ligne);
        // Arrondi à 4 décimales (~11 m) : de quoi mesurer par commune, pas de
        // quoi désigner une parcelle (SPEC §5).
        self::assertSame('48.651200', $ligne['lat']);
        self::assertSame('2.409900', $ligne['lon']);
        self::assertEqualsCanonicalizing(
            ['id', 'partner_id', 'lat', 'lon', 'code_insee', 'niveau_code', 'converti', 'created_at'],
            array_keys($ligne),
            'aucune colonne susceptible de porter une donnée personnelle',
        );
    }
}
