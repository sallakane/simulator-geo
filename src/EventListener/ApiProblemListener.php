<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ApiProblemException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Toutes les erreurs de l'API suivent la RFC 7807 (SPEC §6).
 *
 * Le widget tourne sur des sites tiers : une page d'erreur HTML de Symfony y
 * arriverait comme du bruit non analysable. Une forme unique et documentée,
 * c'est ce qui permet au widget de dégrader proprement.
 */
#[AsEventListener(event: 'kernel.exception')]
final readonly class ApiProblemListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof ApiProblemException) {
            return;
        }

        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

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

        $event->setResponse($reponse);
    }
}
