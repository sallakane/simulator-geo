<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Supervision — SPEC §6 et §13.
 *
 * Le point de vigilance n'est pas « l'API répond-elle ». Une base vide répond
 * parfaitement : elle renvoie simplement « hors périmètre » pour la France
 * entière, sans la moindre erreur HTTP. C'est la panne la plus coûteuse de ce
 * produit et la plus silencieuse — d'où le 503 quand le zonage est absent,
 * plutôt qu'un 200 rassurant.
 */
final class HealthController
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[Route('/api/v1/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        try {
            // rga_zone_courante est une VUE, basculée à chaque millésime
            // (bin/charger-rga.sh --switch). Le code n'interroge jamais une
            // table millésimée directement — SPEC §4.4.
            $row = $this->db->fetchAssociative(
                'SELECT count(*) AS polygones, max(millesime) AS millesime FROM rga_zone_courante'
            ) ?: [];
        } catch (\Throwable) {
            // Base injoignable, ou vue absente parce que le zonage n'a pas
            // encore été chargé : les deux méritent une alerte, pas un 200.
            return new JsonResponse(
                ['status' => 'degraded', 'motif' => 'zonage_indisponible', 'polygones' => 0],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        $polygones = (int) ($row['polygones'] ?? 0);

        return new JsonResponse([
            'status' => $polygones > 0 ? 'ok' : 'degraded',
            'rga_millesime' => $row['millesime'] ?? null,
            'polygones' => $polygones,
        ], $polygones > 0 ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
