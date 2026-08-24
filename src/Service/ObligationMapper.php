<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\ZonageResult;

/**
 * Niveau d'exposition → obligation réglementaire et mission applicable.
 *
 * Tout le contenu réglementaire est ici, et nulle part ailleurs : c'est le
 * fichier à rouvrir quand la réglementation bouge.
 *
 * Ce que ce mapper ne contient PAS, volontairement : ni prix, ni délai, ni
 * périmètre d'intervention. Ces valeurs appartiennent au partenaire et n'ont
 * pas encore été fournies (SPEC §16) — inventer des valeurs plausibles serait
 * pire que les laisser absentes.
 */
final readonly class ObligationMapper
{
    public const NIVEAU_NUL = 0;
    public const NIVEAU_FAIBLE = 1;
    public const NIVEAU_MOYEN = 2;
    public const NIVEAU_FORT = 3;

    private const NORME = 'NF P 94-500';

    /** @return array{code:int, cle:string, libelle:string} */
    public function zone(int $niveauCode): array
    {
        return match ($niveauCode) {
            self::NIVEAU_FORT => ['code' => 3, 'cle' => 'fort', 'libelle' => 'Exposition forte'],
            self::NIVEAU_MOYEN => ['code' => 2, 'cle' => 'moyen', 'libelle' => 'Exposition moyenne'],
            self::NIVEAU_FAIBLE => ['code' => 1, 'cle' => 'faible', 'libelle' => 'Exposition faible'],
            self::NIVEAU_NUL => ['code' => 0, 'cle' => 'nul', 'libelle' => "Pas d'exposition identifiée"],
            default => throw new \InvalidArgumentException("Niveau d'exposition inconnu : $niveauCode"),
        };
    }

    /**
     * @return array{applicable:bool, mission:string, norme:string, validite_annees:int|null, resume:string}
     */
    public function obligation(int $niveauCode): array
    {
        return match ($niveauCode) {
            // Exposition moyenne ou forte : l'étude géotechnique préalable est
            // obligatoire depuis le 1er juillet 2026 pour la vente d'un terrain
            // non bâti constructible et pour les CCMI (SPEC §1).
            self::NIVEAU_FORT, self::NIVEAU_MOYEN => [
                'applicable' => true,
                'mission' => 'G1 PGC',
                'norme' => self::NORME,
                'validite_annees' => 30,
                'resume' => "Pour la vente d'un terrain non bâti constructible, l'étude géotechnique "
                    ."préalable G1 doit être annexée à la promesse ou à l'acte de vente.",
            ],
            // Pas d'obligation RGA — mais un projet de construction reste un
            // projet de construction. Ne jamais renvoyer un cul-de-sac.
            self::NIVEAU_FAIBLE => [
                'applicable' => false,
                'mission' => 'G2 AVP',
                'norme' => self::NORME,
                'validite_annees' => null,
                'resume' => "Aucune obligation liée au retrait-gonflement des argiles à ce niveau "
                    ."d'exposition. Une étude géotechnique de conception G2 reste nécessaire pour "
                    .'dimensionner les fondations.',
            ],
            self::NIVEAU_NUL => [
                'applicable' => false,
                'mission' => 'G2 AVP',
                'norme' => self::NORME,
                'validite_annees' => null,
                'resume' => "Pas de risque argile identifié sur cette parcelle. D'autres "
                    .'reconnaissances peuvent rester utiles selon la nature du sol et du projet.',
            ],
            default => throw new \InvalidArgumentException("Niveau d'exposition inconnu : $niveauCode"),
        };
    }

    /** Message affiché quand la carte ne couvre pas le point (SPEC §6). */
    public function messageHorsPerimetre(string $motif): string
    {
        return match ($motif) {
            ZonageResult::MOTIF_PARIS => "La carte d'exposition ne couvre pas la ville de Paris.",
            ZonageResult::MOTIF_HORS_METROPOLE => "La carte d'exposition couvre la France métropolitaine. "
                .'Ce point est en dehors de son périmètre.',
            // Hors métropole et Paris, l'absence de polygone est une réponse
            // (« exposition nulle »), pas un hors-périmètre : ce défaut ne
            // devrait jamais servir.
            default => "La carte d'exposition ne renvoie aucune zone pour ce point.",
        };
    }
}
