<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;

/**
 * Ce qu'il faut au widget pour envoyer le visiteur vers le formulaire de devis
 * DU PARTENAIRE, pré-rempli (SPEC §6 et §7).
 *
 * Décision d'architecture : **le lead ne transite pas par ce serveur.** Le
 * visiteur remplit le formulaire du partenaire, sur le domaine du partenaire.
 *
 * Ce n'est pas qu'une économie de code. Faire transiter nom, téléphone et
 * e-mail par notre infrastructure ferait de l'éditeur un sous-traitant au sens
 * de l'article 28 : contrat signé avant toute mise en production, chiffrement
 * au repos, purge, endpoint de suppression (SPEC §9). En redirigeant, aucune
 * donnée personnelle ne nous touche — et le partenaire garde son tunnel, ses
 * notifications et son destinataire, qui fonctionnent déjà.
 *
 * Rien de spécifique à un client ici : les noms de champs viennent de
 * `partner.lead_champs`.
 */
final readonly class LienDevis
{
    public function __construct(private ObligationMapper $obligations)
    {
    }

    /**
     * @return array{url: string, champs: array<string, string>, resume: string}|null
     */
    public function pour(Partner $partner, ?int $niveauCode, ?string $millesime): ?array
    {
        $url = $partner->getLeadEndpoint();

        if (null === $url || '' === $url) {
            return null;
        }

        return [
            'url' => $url,
            'champs' => $partner->getLeadChamps(),
            // Le widget y ajoutera l'adresse, qu'il est le seul à connaître :
            // elle vient de la Base Adresse Nationale, côté navigateur, et n'a
            // aucune raison de passer par nos journaux (SPEC §9).
            'resume' => $this->resume($niveauCode, $millesime),
        ];
    }

    /**
     * Le texte qui rend le lead qualifié : sans lui, le partenaire reçoit une
     * adresse et doit rappeler pour savoir quelle mission s'applique — soit
     * exactement le problème que ce produit résout (SPEC §1).
     */
    private function resume(?int $niveauCode, ?string $millesime): string
    {
        $carte = $millesime ? "carte $millesime, arrêté du 9 janvier 2026" : 'carte en vigueur';

        if (null === $niveauCode) {
            return "Le simulateur ne couvre pas cette adresse ($carte).";
        }

        $zone = $this->obligations->zone($niveauCode);
        $obligation = $this->obligations->obligation($niveauCode);

        $lignes = [
            "{$zone['libelle']} au retrait-gonflement des argiles ($carte).",
        ];

        $lignes[] = $obligation['applicable']
            ? "Étude géotechnique préalable {$obligation['mission']} applicable (norme {$obligation['norme']})."
            : "Pas d'obligation liée au retrait-gonflement des argiles. Mission {$obligation['mission']} "
                ."à envisager selon le projet (norme {$obligation['norme']}).";

        return implode("\n", $lignes);
    }
}
