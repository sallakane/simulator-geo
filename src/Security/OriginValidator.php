<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Partner;
use App\Exception\ApiProblemException;

/**
 * Liste blanche d'origines, issue de `partner.origines_autorisees`. Jamais `*`
 * (SPEC §8).
 *
 * Le widget s'exécute dans une iframe servie par ce domaine : les appels
 * portent donc l'origine du service lui-même, et non celle du site hôte. Un
 * appel sans en-tête `Origin` (curl, supervision, navigation directe) n'est pas
 * refusé : `Origin` n'est pas un contrôle d'accès, c'est ce qui permet au
 * navigateur d'autoriser la lecture de la réponse.
 */
final readonly class OriginValidator
{
    public function verifier(Partner $partner, ?string $origine): ?string
    {
        if (null === $origine || '' === $origine) {
            return null;
        }

        foreach ($partner->getOriginesAutorisees() as $autorisee) {
            if ($this->correspond($origine, $autorisee)) {
                return $origine;
            }
        }

        throw ApiProblemException::origineNonAutorisee();
    }

    private function correspond(string $origine, string $motif): bool
    {
        if (strcasecmp($origine, $motif) === 0) {
            return true;
        }

        // Un seul joker admis, en tête de domaine : https://*.exemple.fr.
        // Pas de expression régulière libre en base — une liste blanche qui
        // peut matcher n'importe quoi n'est plus une liste blanche.
        if (!str_contains($motif, '://*.')) {
            return false;
        }

        [$schema, $reste] = explode('://*.', $motif, 2);

        return str_starts_with(strtolower($origine), strtolower($schema.'://'))
            && str_ends_with(strtolower($origine), '.'.strtolower($reste));
    }
}
