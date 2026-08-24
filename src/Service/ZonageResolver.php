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

    public function resoudre(Coordonnees $point): ZonageResult
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

        return ZonageResult::horsPerimetre($this->motif($point));
    }

    /**
     * Aucun polygone : reste à dire POURQUOI, parce que le message et l'appel à
     * l'action en dépendent (SPEC §1).
     */
    private function motif(Coordonnees $point): string
    {
        if (!$point->estDansLaMetropole()) {
            return ZonageResult::MOTIF_HORS_METROPOLE;
        }

        // La carte ne couvre pas la ville de Paris : ce n'est pas un trou dans
        // la donnée, c'est le périmètre officiel. Approximation par enveloppe
        // en attendant une couche communale — à remplacer par le code INSEE dès
        // que le widget le transmettra.
        if ($point->lon >= self::PARIS_LON_MIN && $point->lon <= self::PARIS_LON_MAX
            && $point->lat >= self::PARIS_LAT_MIN && $point->lat <= self::PARIS_LAT_MAX) {
            return ZonageResult::MOTIF_PARIS;
        }

        return ZonageResult::MOTIF_NON_COUVERT;
    }
}
