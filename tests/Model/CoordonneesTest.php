<?php

declare(strict_types=1);

namespace App\Tests\Model;

use App\Exception\ApiProblemException;
use App\Model\Coordonnees;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoordonneesTest extends TestCase
{
    public function testOrdreDesParametres(): void
    {
        // Verrouille l'ordre : `lat` d'abord dans notre API, alors que la BAN
        // renvoie [lon, lat] et que Géorisques attend lon,lat (SPEC §10).
        $point = Coordonnees::depuis('48.6500', '2.4000');

        self::assertSame(48.65, $point->lat);
        self::assertSame(2.40, $point->lon);
    }

    public function testPermutationDetectee(): void
    {
        $this->expectException(ApiProblemException::class);

        Coordonnees::depuis('2.3522', '48.8566');
    }

    public function testUnPointVraimentEtrangerNestPasPrisPourUnePermutation(): void
    {
        // New York : hors de France, et hors de France une fois permuté. Ce
        // n'est pas une inversion, c'est une adresse hors périmètre — elle doit
        // arriver jusqu'à la résolution pour obtenir son message dédié.
        $point = Coordonnees::depuis('40.71', '-74.01');

        self::assertFalse($point->estDansLaMetropole());
    }

    public function testCorseDansLaMetropole(): void
    {
        self::assertTrue(Coordonnees::depuis('42.18', '9.10')->estDansLaMetropole());
    }

    /** @return iterable<string, array{string|null, string|null}> */
    public static function entreesInvalides(): iterable
    {
        yield 'lat absente' => [null, '2.40'];
        yield 'lon absente' => ['48.65', null];
        yield 'lat vide' => ['', '2.40'];
        yield 'texte' => ['quarante-huit', '2.40'];
        yield 'lat hors bornes' => ['91.0', '2.40'];
        yield 'lon hors bornes' => ['48.65', '181.0'];
    }

    #[DataProvider('entreesInvalides')]
    public function testEntreesInvalidesRefusees(?string $lat, ?string $lon): void
    {
        $this->expectException(ApiProblemException::class);

        Coordonnees::depuis($lat, $lon);
    }
}
