<?php

declare(strict_types=1);

namespace App\Model;

use App\Exception\ApiProblemException;

/**
 * Couple latitude/longitude validé.
 *
 * Le piège du domaine (SPEC §10) : la BAN renvoie `geometry.coordinates` sous
 * la forme `[lon, lat]`, et l'API Géorisques attend `latlon=lon,lat` —
 * longitude d'abord dans les deux cas. Une inversion produit des coordonnées
 * parfaitement valides qui désignent un point ailleurs sur la planète : elle ne
 * se voit pas, elle se teste. D'où la détection explicite ci-dessous, et le
 * test qui verrouille l'ordre.
 */
final readonly class Coordonnees
{
    /** France métropolitaine, Corse comprise — enveloppe large, volontairement. */
    private const METRO_LON_MIN = -5.3;
    private const METRO_LON_MAX = 9.7;
    private const METRO_LAT_MIN = 41.2;
    private const METRO_LAT_MAX = 51.2;

    private function __construct(
        public float $lat,
        public float $lon,
    ) {
    }

    public static function depuis(?string $lat, ?string $lon): self
    {
        if (null === $lat || null === $lon || '' === $lat || '' === $lon) {
            throw ApiProblemException::parametresInvalides('Les paramètres `lat` et `lon` sont requis.');
        }

        if (!is_numeric($lat) || !is_numeric($lon)) {
            throw ApiProblemException::parametresInvalides('`lat` et `lon` doivent être des nombres décimaux.');
        }

        $latF = (float) $lat;
        $lonF = (float) $lon;

        if ($latF < -90 || $latF > 90) {
            throw ApiProblemException::parametresInvalides('`lat` doit être comprise entre -90 et 90.');
        }

        if ($lonF < -180 || $lonF > 180) {
            throw ApiProblemException::parametresInvalides('`lon` doit être comprise entre -180 et 180.');
        }

        // Hors de France mais dedans une fois permutées : c'est une inversion,
        // pas une adresse à l'étranger. Refuser plutôt que répondre
        // « hors périmètre » à quelqu'un dont le terrain est en Essonne.
        if (!self::dansLaMetropole($latF, $lonF) && self::dansLaMetropole($lonF, $latF)) {
            throw ApiProblemException::coordonneesInversees();
        }

        return new self($latF, $lonF);
    }

    public static function dansLaMetropole(float $lat, float $lon): bool
    {
        return $lat >= self::METRO_LAT_MIN && $lat <= self::METRO_LAT_MAX
            && $lon >= self::METRO_LON_MIN && $lon <= self::METRO_LON_MAX;
    }

    public function estDansLaMetropole(): bool
    {
        return self::dansLaMetropole($this->lat, $this->lon);
    }
}
