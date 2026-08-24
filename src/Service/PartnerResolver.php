<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Exception\ApiProblemException;
use App\Repository\PartnerRepository;

/**
 * Résolution de la clé publique du widget (SPEC §5 et §8).
 */
final readonly class PartnerResolver
{
    public function __construct(private PartnerRepository $partners)
    {
    }

    public function resoudre(?string $cle): Partner
    {
        if (null === $cle || '' === $cle) {
            throw ApiProblemException::parametresInvalides('Le paramètre `key` est requis.');
        }

        $partner = $this->partners->findByPublicKey($cle);

        // Clé inconnue et clé désactivée renvoient la même erreur : distinguer
        // les deux permettrait de tester l'existence d'une clé.
        if (null === $partner || !$partner->isActif()) {
            throw ApiProblemException::cleInconnue();
        }

        return $partner;
    }
}
