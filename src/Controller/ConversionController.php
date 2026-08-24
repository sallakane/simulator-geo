<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Simulation;
use App\Exception\ApiProblemException;
use App\Service\PartnerResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /api/v1/conversion` — SPEC §5 et §6.
 *
 * Le lead partant chez le partenaire, on ne peut plus savoir qu'une demande a
 * été **envoyée** : seulement qu'elle a été **entamée**. C'est ce que cet
 * endpoint enregistre, et rien de plus.
 *
 * Mesurer ce qu'on observe réellement plutôt que ce qu'on aimerait mesurer :
 * un taux de clic honnête vaut mieux qu'un taux de conversion inventé. Le
 * partenaire, lui, connaît ses soumissions — et le `simulation_id` transmis
 * dans son formulaire permettra de recoller les deux le jour où il le voudra.
 *
 * Aucune donnée personnelle ici : un identifiant de simulation, rien d'autre.
 */
final class ConversionController
{
    public function __construct(
        private readonly PartnerResolver $partners,
        private readonly EntityManagerInterface $em,
        #[Target('conversion_ip')]
        private readonly RateLimiterFactoryInterface $limiteur,
    ) {
    }

    #[Route('/api/v1/conversion', name: 'conversion', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        // Appelé par `navigator.sendBeacon` juste avant que le navigateur quitte
        // la page : corps en text/plain, donc requête « simple » au sens CORS —
        // pas de préflight, et rien à attendre côté client.
        $corps = json_decode($request->getContent(), true);

        if (!\is_array($corps)) {
            throw ApiProblemException::parametresInvalides('Corps JSON attendu.');
        }

        $partner = $this->partners->resoudre(\is_string($corps['key'] ?? null) ? $corps['key'] : null);

        $limite = $this->limiteur->create($request->getClientIp() ?? 'inconnu')->consume();
        if (!$limite->isAccepted()) {
            throw ApiProblemException::quotaDepasse(max(1, $limite->getRetryAfter()->getTimestamp() - time()));
        }

        $id = filter_var($corps['simulation_id'] ?? null, \FILTER_VALIDATE_INT);
        if (false === $id) {
            throw ApiProblemException::parametresInvalides('`simulation_id` doit être un entier.');
        }

        $simulation = $this->em->getRepository(Simulation::class)->find($id);

        // Simulation inconnue, ou appartenant à un autre partenaire : on ne dit
        // pas laquelle des deux. Sinon cet endpoint devient un moyen de
        // dénombrer les simulations des voisins.
        if (null === $simulation || $simulation->getPartner()->getId() !== $partner->getId()) {
            throw ApiProblemException::parametresInvalides('Simulation inconnue.');
        }

        $simulation->marquerConverti();
        $this->em->flush();

        // 204 : le client n'attend rien, et sendBeacon ignore le corps.
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
