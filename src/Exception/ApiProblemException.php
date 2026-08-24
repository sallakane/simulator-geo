<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * Erreur d'API au format RFC 7807 (`application/problem+json`, SPEC §6).
 *
 * Ne sert QUE pour les vraies erreurs. Le cas « hors périmètre » n'en est pas
 * une : il se renvoie en 200 avec son propre message (SPEC §1 et §6).
 */
final class ApiProblemException extends \RuntimeException
{
    /** @param array<string, mixed> $extra */
    private function __construct(
        public readonly int $statut,
        public readonly string $type,
        public readonly string $titre,
        string $detail,
        public readonly array $extra = [],
    ) {
        parent::__construct($detail);
    }

    public static function parametresInvalides(string $detail): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            'https://api.zonage/problemes/parametres-invalides',
            'Paramètres invalides',
            $detail,
        );
    }

    public static function coordonneesInversees(): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            'https://api.zonage/problemes/coordonnees-inversees',
            'Coordonnées inversées',
            'Les coordonnées semblent permutées : `lat` et `lon` ont été échangées. '
            .'Rappel : la Base Adresse Nationale renvoie `coordinates = [lon, lat]`.',
        );
    }

    public static function cleInconnue(): self
    {
        // Volontairement indistinct de « clé inactive » : la réponse ne doit pas
        // permettre d'énumérer les clés valides.
        return new self(
            Response::HTTP_FORBIDDEN,
            'https://api.zonage/problemes/cle-refusee',
            'Clé refusée',
            'La clé partenaire est inconnue ou inactive.',
        );
    }

    public static function origineNonAutorisee(): self
    {
        return new self(
            Response::HTTP_FORBIDDEN,
            'https://api.zonage/problemes/origine-non-autorisee',
            'Origine non autorisée',
            "L'origine de la requête ne figure pas dans la liste blanche du partenaire.",
        );
    }

    public static function quotaDepasse(int $retryAfter): self
    {
        return new self(
            Response::HTTP_TOO_MANY_REQUESTS,
            'https://api.zonage/problemes/quota-depasse',
            'Quota dépassé',
            'Trop de requêtes. Réessayez dans quelques secondes.',
            ['retry_after' => $retryAfter],
        );
    }

    public static function zonageIndisponible(): self
    {
        // Une base vide répondrait « hors périmètre » pour la France entière :
        // il faut une panne visible, pas une réponse plausible et fausse.
        return new self(
            Response::HTTP_SERVICE_UNAVAILABLE,
            'https://api.zonage/problemes/zonage-indisponible',
            'Zonage indisponible',
            "Le zonage n'est pas chargé. Le service ne peut pas répondre pour l'instant.",
        );
    }
}
