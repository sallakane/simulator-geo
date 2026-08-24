<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiProblemException;
use App\Model\Coordonnees;
use App\Model\ZonageResult;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Cœur métier : point-dans-polygone sur la donnée hébergée (SPEC §3).
 *
 * SQL natif et non Doctrine ORM : Doctrine ne gère pas PostGIS, et cette requête
 * est le seul endroit où la performance compte vraiment. Elle doit rester une
 * recherche sur index GIST — d'où la reprojection faite AU CHARGEMENT
 * (bin/charger-rga.sh) : un ST_Transform ici rendrait l'index inutilisable.
 */
final readonly class ZonageResolver
{
    /** Paris intra-muros, bois de Boulogne et de Vincennes compris. */
    private const PARIS_LON_MIN = 2.2241;
    private const PARIS_LON_MAX = 2.4699;
    private const PARIS_LAT_MIN = 48.8156;
    private const PARIS_LAT_MAX = 48.9022;

    public function __construct(
        private Connection $db,
        private LoggerInterface $logger,
    ) {
    }

    public function resoudre(Coordonnees $point, ?string $codeInsee = null): ZonageResult
    {
        try {
            $ligne = $this->db->fetchAssociative(
                // ORDER BY niveau_code DESC : sur une frontière de polygones, le
                // point appartient à deux zones. On retient la plus exposée —
                // se tromper vers l'obligation d'étude coûte un devis, se
                // tromper dans l'autre sens coûte un vice caché (SPEC §10).
                'SELECT niveau_code, millesime
                   FROM rga_zone_courante
                  WHERE ST_Intersects(geom, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326))
                  ORDER BY niveau_code DESC
                  LIMIT 1',
                ['lat' => $point->lat, 'lon' => $point->lon],
            );
        } catch (\Throwable $e) {
            // Vue absente (zonage jamais chargé) ou base injoignable. Dans les
            // deux cas, répondre « hors périmètre » serait un mensonge crédible.
            $this->logger->error('Résolution de zone impossible', ['exception' => $e]);

            throw ApiProblemException::zonageIndisponible();
        }

        if (false !== $ligne && null !== ($ligne['niveau_code'] ?? null)) {
            return ZonageResult::zone((int) $ligne['niveau_code'], $ligne['millesime'] ?? null);
        }

        return $this->interpreterLAbsenceDePolygone($point, $codeInsee);
    }

    /**
     * Aucun polygone ne contient le point. Trois lectures possibles, et le
     * message comme l'appel à l'action en dépendent (SPEC §1).
     *
     * La carte officielle ne dessine QUE les zones exposées : 395 000 km²
     * couverts pour ~552 000 km² de métropole, et aucun polygone de niveau 0
     * (relevé du millésime 2026, cf. docs/donnees-rga.md). En France
     * métropolitaine, hors Paris, l'absence de polygone est donc une RÉPONSE —
     * « pas d'exposition identifiée » — et pas un trou dans la donnée.
     *
     * C'est aussi pourquoi bin/charger-rga.sh contrôle le nombre de polygones
     * chargé : une donnée partiellement chargée ferait répondre « pas de
     * risque » sur des régions entières, sans la moindre erreur HTTP.
     */
    private function interpreterLAbsenceDePolygone(Coordonnees $point, ?string $codeInsee): ZonageResult
    {
        if (!$point->estDansLaMetropole()) {
            return ZonageResult::horsPerimetre(ZonageResult::MOTIF_HORS_METROPOLE);
        }

        // La carte ne couvre pas la ville de Paris : ce n'est pas un trou dans
        // la donnée, c'est le périmètre officiel.
        if (null !== $codeInsee) {
            // Le widget transmet le `citycode` de la Base Adresse Nationale :
            // 75056 pour Paris commune, 75101 à 75120 pour les arrondissements.
            // C'est une réponse exacte, là où l'enveloppe ci-dessous ne peut
            // être qu'approchée.
            return str_starts_with($codeInsee, '751') || '75056' === $codeInsee
                ? ZonageResult::horsPerimetre(ZonageResult::MOTIF_PARIS)
                : ZonageResult::zone(0, $this->millesimeCourant());
        }

        // Sans code INSEE (appel direct à l'API), on retombe sur l'enveloppe de
        // Paris. Elle déborde sur les communes limitrophes : celles-ci étant
        // presque toutes couvertes par un polygone, le cas ne se présente qu'aux
        // rares endroits non exposés de la petite couronne.
        if ($point->lon >= self::PARIS_LON_MIN && $point->lon <= self::PARIS_LON_MAX
            && $point->lat >= self::PARIS_LAT_MIN && $point->lat <= self::PARIS_LAT_MAX) {
            return ZonageResult::horsPerimetre(ZonageResult::MOTIF_PARIS);
        }

        // Limite assumée : l'enveloppe métropolitaine est un rectangle, elle
        // déborde sur la Belgique, la Suisse, l'Italie et l'Espagne. Une adresse
        // frontalière étrangère recevrait « pas d'exposition identifiée » au
        // lieu de « hors périmètre ». Dans le parcours réel, les coordonnées
        // viennent de la Base Adresse Nationale, qui ne géocode que la France
        // (SPEC §7) ; à revoir si l'API est ouverte plus largement.
        return ZonageResult::zone(0, $this->millesimeCourant());
    }

    /**
     * Tous les polygones d'un millésime portent la même valeur : un LIMIT 1
     * sans tri suffit, et cette requête ne part que sur le chemin « exposition
     * nulle ».
     */
    private function millesimeCourant(): ?string
    {
        try {
            return $this->db->fetchOne('SELECT millesime FROM rga_zone_courante LIMIT 1') ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
