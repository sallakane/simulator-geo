<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Simulation;
use App\Exception\ApiProblemException;
use App\Model\Coordonnees;
use App\Security\OriginValidator;
use App\Service\ObligationMapper;
use App\Service\LienDevis;
use App\Service\PartnerResolver;
use App\Service\ZonageResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /api/v1/zonage` — SPEC §6.
 *
 * Deux réponses seulement, toutes deux en 200 :
 *   · une zone trouvée, avec l'obligation qui en découle ;
 *   · « hors périmètre », qui est un cas FONCTIONNEL et non une erreur — le
 *     widget doit l'afficher comme une information, jamais comme une panne.
 */
final class ZonageController
{
    public function __construct(
        private readonly PartnerResolver $partners,
        private readonly OriginValidator $origines,
        private readonly ZonageResolver $zonage,
        private readonly ObligationMapper $obligations,
        private readonly LienDevis $lienDevis,
        private readonly EntityManagerInterface $em,
        #[Target('zonage_ip')]
        private readonly RateLimiterFactoryInterface $limiteurIp,
        #[Target('zonage_cle')]
        private readonly RateLimiterFactoryInterface $limiteurCle,
    ) {
    }

    #[Route('/api/v1/zonage', name: 'zonage', methods: ['GET', 'OPTIONS'])]
    public function __invoke(Request $request): Response
    {
        $partner = $this->partners->resoudre($request->query->get('key'));
        $origine = $this->origines->verifier($partner, $request->headers->get('Origin'));

        // Préflight : la clé et l'origine viennent d'être validées, il n'y a
        // rien d'autre à faire que répondre.
        if ($request->isMethod('OPTIONS')) {
            return $this->cors(new Response('', Response::HTTP_NO_CONTENT), $origine, true);
        }

        $this->limiter($this->limiteurIp->create($request->getClientIp() ?? 'inconnu'));
        $this->limiter($this->limiteurCle->create($partner->getPublicKey()));

        $point = Coordonnees::depuis(
            $request->query->getString('lat') ?: null,
            $request->query->getString('lon') ?: null,
        );

        $codeInsee = $this->codeInsee($request->query->getString('insee'));
        $resultat = $this->zonage->resoudre($point, $codeInsee);

        // Mesure sans donnée personnelle : coordonnées arrondies, rien d'autre
        // (SPEC §5). Écrite même hors périmètre — savoir combien de visiteurs
        // tombent sur ce message fait partie de ce qu'on veut mesurer.
        $simulation = new Simulation($partner, $point->lat, $point->lon, $resultat->niveauCode);
        $simulation->setCodeInsee($codeInsee);
        $this->em->persist($simulation);
        $this->em->flush();

        // Présent dans les DEUX cas : hors périmètre, le visiteur doit lui
        // aussi pouvoir demander conseil. Aucune réponse n'est un cul-de-sac
        // (SPEC §1).
        $conversion = $this->lienDevis->pour($partner, $resultat->niveauCode, $resultat->millesime);

        if ($resultat->estHorsPerimetre()) {
            $charge = [
                'statut' => 'hors_perimetre',
                'motif' => $resultat->motif,
                'message' => $this->obligations->messageHorsPerimetre((string) $resultat->motif),
                'simulation_id' => $simulation->getId(),
            ];
        } else {
            $niveau = (int) $resultat->niveauCode;
            $charge = [
                'statut' => 'ok',
                'zone' => $this->obligations->zone($niveau),
                'obligation' => $this->obligations->obligation($niveau),
                'millesime' => $resultat->millesime,
                'simulation_id' => $simulation->getId(),
            ];
        }

        if (null !== $conversion) {
            $charge['conversion'] = $conversion;
        }

        return $this->cors(new JsonResponse($charge), $origine);
    }

    /**
     * Code commune facultatif, transmis par le widget depuis la Base Adresse
     * Nationale. Il rend la détection de Paris exacte (SPEC §3).
     *
     * Une valeur malformée est ignorée, jamais rejetée : elle n'est qu'un
     * complément, et refuser la requête priverait l'utilisateur d'une réponse
     * qu'on sait parfaitement calculer sans elle.
     */
    private function codeInsee(string $brut): ?string
    {
        return preg_match('/^[0-9][0-9AB][0-9]{3}$/', $brut) ? $brut : null;
    }

    private function limiter(\Symfony\Component\RateLimiter\LimiterInterface $limiteur): void
    {
        $limite = $limiteur->consume();

        if (!$limite->isAccepted()) {
            throw ApiProblemException::quotaDepasse(
                max(1, $limite->getRetryAfter()->getTimestamp() - time()),
            );
        }
    }

    /**
     * Liste blanche explicite, jamais `*` (SPEC §8). `Vary: Origin` est
     * indispensable : sans lui, un cache renverrait à un site l'en-tête calculé
     * pour un autre.
     */
    private function cors(Response $reponse, ?string $origine, bool $preflight = false): Response
    {
        $reponse->headers->set('Vary', 'Origin');

        if (null !== $origine) {
            $reponse->headers->set('Access-Control-Allow-Origin', $origine);
        }

        if ($preflight) {
            $reponse->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
            $reponse->headers->set('Access-Control-Max-Age', '600');
        }

        return $reponse;
    }
}
