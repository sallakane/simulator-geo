<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ApiProblemException;
use App\Service\PartnerResolver;
use App\Service\TuilesRga;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /api/v1/tuiles/{z}/{x}/{y}.pbf` — la couche d'exposition de la carte
 * (SPEC §6bis).
 *
 * Un endpoint à part entière, et non un paramètre de `/zonage`, pour une raison
 * simple : ce sont deux régimes opposés. Le verdict est une réponse unique,
 * personnelle, jamais mise en cache. Une tuile est un objet public, identique
 * pour tout le monde, et immuable pour un millésime donné — donc mise en cache
 * partout où c'est possible : disque du serveur, navigateur, et tout ce qu'il y
 * a entre les deux.
 */
final class TuilesController
{
    public function __construct(
        private readonly PartnerResolver $partners,
        private readonly TuilesRga $tuiles,
        #[Target('tuiles_ip')]
        private readonly RateLimiterFactoryInterface $limiteurIp,
    ) {
    }

    /**
     * `{z}` `{x}` `{y}` sont contraints par la route elle-même : rien
     * d'invalide n'atteint le contrôleur, et surtout rien qui ne soit un entier
     * n'atteint le chemin du cache disque.
     */
    #[Route(
        '/api/v1/tuiles/{z}/{x}/{y}.pbf',
        name: 'tuiles',
        requirements: ['z' => '\d{1,2}', 'x' => '\d{1,6}', 'y' => '\d{1,6}'],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, int $z, int $x, int $y): Response
    {
        $this->partners->resoudre($request->query->get('key'));

        // Budget distinct de celui du verdict : afficher UNE carte demande une
        // vingtaine de tuiles. Les compter dans `zonage_ip` (30/min) couperait
        // la carte au deuxième déplacement — et le visiteur verrait une carte
        // trouée sans savoir pourquoi.
        $limite = $this->limiteurIp->create($request->getClientIp() ?? 'inconnu')->consume();

        if (!$limite->isAccepted()) {
            throw ApiProblemException::quotaDepasse(
                max(1, $limite->getRetryAfter()->getTimestamp() - time()),
            );
        }

        if (!$this->tuiles->zoomValide($z) || $x >= 1 << $z || $y >= 1 << $z) {
            throw ApiProblemException::parametresInvalides(sprintf(
                'Tuile hors domaine : le zoom doit être compris entre %d et %d, x et y inférieurs à 2^z.',
                TuilesRga::ZOOM_MIN,
                TuilesRga::ZOOM_MAX,
            ));
        }

        try {
            $tuile = $this->tuiles->tuile($z, $x, $y);
        } catch (\RuntimeException) {
            // Le zonage est injoignable. Un 204 « tuile vide » peindrait
            // « aucune exposition » sur la région, en succès — c'est exactement
            // le mensonge crédible que le reste du produit refuse (SPEC §3).
            throw ApiProblemException::zonageIndisponible();
        }

        // Une tuile vide est la réponse normale sur la moitié du territoire :
        // la carte officielle ne dessine que les zones exposées. 204 plutôt
        // qu'un corps vide en 200 — le client sait qu'il n'a rien à rendre, et
        // met tout de même la réponse en cache.
        $reponse = '' === $tuile
            ? new Response('', Response::HTTP_NO_CONTENT)
            : new Response($tuile, Response::HTTP_OK, ['Content-Type' => 'application/vnd.mapbox-vector-tile']);

        return $this->cache($reponse, $request);
    }

    /**
     * Une tuile est immuable *pour un millésime donné*, et l'URL ne dit pas le
     * millésime : c'est `/embed` qui l'inscrit dans le gabarit d'URL au moment
     * où il construit la page, via `?m=`.
     *
     * D'où la règle : le client qui annonce le bon millésime obtient un cache
     * d'un an ; celui qui en annonce un autre — page ouverte avant une bascule,
     * intermédiaire distrait — obtient `no-store`. Il ne conservera pas une
     * carte périmée, et il repartira du bon pied au prochain chargement.
     */
    private function cache(Response $reponse, Request $request): Response
    {
        $millesime = $this->tuiles->millesime();
        $annonce = $request->query->getString('m');

        $reponse->headers->set('X-RGA-Millesime', (string) $millesime);

        if (null !== $millesime && '' !== $annonce && $annonce !== $millesime) {
            $reponse->headers->set('Cache-Control', 'no-store');

            return $reponse;
        }

        $reponse->setPublic();
        $reponse->setMaxAge(31536000);
        $reponse->headers->addCacheControlDirective('immutable');

        return $reponse;
    }
}
