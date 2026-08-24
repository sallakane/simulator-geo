<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Résultat de la résolution spatiale.
 *
 * `niveauCode === null` n'est pas une erreur : c'est le cas fonctionnel
 * « hors périmètre » (Paris, DOM, hors métropole), qui a son propre message et
 * son propre appel à l'action. Aucune réponse ne doit être un cul-de-sac
 * (SPEC §1).
 */
final readonly class ZonageResult
{
    public const MOTIF_PARIS = 'paris';
    public const MOTIF_HORS_METROPOLE = 'hors_metropole';

    private function __construct(
        public ?int $niveauCode,
        public ?string $millesime,
        public ?string $motif,
    ) {
    }

    public static function zone(int $niveauCode, ?string $millesime): self
    {
        return new self($niveauCode, $millesime, null);
    }

    public static function horsPerimetre(string $motif): self
    {
        return new self(null, null, $motif);
    }

    public function estHorsPerimetre(): bool
    {
        return null === $this->niveauCode;
    }
}
