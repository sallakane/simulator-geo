<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ApiProblemException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Toutes les erreurs de l'API suivent la RFC 7807 (SPEC §6).
 *
 * Le widget tourne sur des sites tiers : une page d'erreur HTML de Symfony y
 * arriverait comme du bruit non analysable. Une forme unique et documentée,
 * c'est ce qui permet au widget de dégrader proprement.
 */
#[AsEventListener(event: 'kernel.exception')]
final class ApiProblemListener
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();

        $reponse = $exception instanceof ApiProblemException
            ? $this->probleme($exception)
            : $this->pannePrevue($exception);

        $event->setResponse($reponse);
    }

    private function probleme(ApiProblemException $exception): JsonResponse
    {
        $corps = [
            'type' => $exception->type,
            'title' => $exception->titre,
            'status' => $exception->statut,
            'detail' => $exception->getMessage(),
        ] + $exception->extra;

        $reponse = new JsonResponse($corps, $exception->statut);
        $reponse->headers->set('Content-Type', 'application/problem+json');

        if (isset($exception->extra['retry_after'])) {
            $reponse->headers->set('Retry-After', (string) $exception->extra['retry_after']);
        }

        return $reponse;
    }

    /**
     * Tout le reste : base injoignable, table disparue, bogue. Sans ce
     * traitement, Symfony renvoie une page HTML sur une route d'API — vérifié
     * en répétition de production, en supprimant la table `partner`.
     *
     * Le détail part dans les journaux, pas dans la réponse : un message
     * d'erreur PHP renvoyé à un site tiers est une fuite d'information.
     */
    private function pannePrevue(\Throwable $exception): JsonResponse
    {
        $statut = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : Response::HTTP_INTERNAL_SERVER_ERROR;

        if ($statut >= 500) {
            $this->logger->error('Erreur non prévue sur une route d\'API', ['exception' => $exception]);
        }

        $reponse = new JsonResponse([
            'type' => 'https://api.zonage/problemes/erreur-interne',
            'title' => 'Erreur interne',
            'status' => $statut,
            'detail' => 'Le service ne peut pas répondre pour le moment.',
        ], $statut);
        $reponse->headers->set('Content-Type', 'application/problem+json');

        return $reponse;
    }
}
