<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Exception\ApiProblemException;
use App\Service\PartnerResolver;
use App\Service\TuilesRga;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /embed?key=…` — l'application affichée dans l'iframe (SPEC §6 et §7).
 *
 * Le chargeur `public/widget.js` est un fichier statique, servi directement par
 * le serveur web sans passer par PHP : c'est du cache pur, et il n'a rien à
 * calculer — il déduit la clé et l'URL de base de son propre `src`.
 *
 * Cette page-ci, en revanche, mérite un contrôleur : elle valide la clé avant
 * d'afficher quoi que ce soit, et surtout elle pose sa CSP `frame-ancestors`
 * à partir de `partner.origines_autorisees`. C'est ce que §8 appelait « à
 * terme, générer cette directive depuis la table partner » : un nouveau
 * partenaire n'impose plus d'éditer le Caddyfile du VPS.
 */
final class WidgetController
{
    public function __construct(
        private readonly PartnerResolver $partners,
        private readonly TuilesRga $tuiles,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.environment%')]
        private readonly string $environnement,
    ) {
    }

    #[Route('/embed', name: 'embed', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        try {
            $partner = $this->partners->resoudre($request->query->get('key'));
        } catch (ApiProblemException $e) {
            // Une page, pas un JSON : on est dans une iframe, et un corps
            // `application/problem+json` s'y afficherait tel quel. Le site hôte,
            // lui, ne perd rien — le chargeur n'ayant jamais reçu le signal
            // « prêt », son repli statique est resté en place (SPEC §7).
            return $this->page(
                '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
                .'<meta name="robots" content="noindex, nofollow"><title>Simulateur indisponible</title></head>'
                .'<body style="font:16px system-ui;margin:0;padding:16px;color:#55606a">'
                .'<p>Ce simulateur n’est pas configuré pour ce site.</p></body></html>',
                $e->statut,
            );
        }

        $html = (string) file_get_contents($this->projectDir.'/templates/embed.html');

        // L'URL de base n'est écrite en dur nulle part : elle se déduit de la
        // requête en cours, parce que le sous-domaine changera (SPEC §1).
        $config = json_encode([
            'cle' => $partner->getPublicKey(),
            'api' => $request->getSchemeAndHttpHost(),
            // Revalidé en lecture : la base reste éditable à la main, et cette
            // chaîne finit dans le CSSOM de l'iframe. Ici on ignore en silence
            // — c'est `Partner::setTheme()` qui refuse bruyamment, à l'écriture.
            'theme' => Partner::normaliserTheme($partner->getTheme()),
            // L'introduction pédagogique est affichée par défaut : sans elle,
            // le champ de saisie ne dit pas au visiteur POURQUOI il devrait
            // s'en servir. `&intro=0` la coupe pour une intégration en colonne
            // étroite, ou sous un texte qui dit déjà la même chose.
            'intro' => '0' !== $request->query->get('intro'),
            // Le millésime est inscrit dans la page, pas déduit par le client :
            // c'est lui qui rend l'URL des tuiles immuable, donc cachable pour
            // un an.
            //
            // `null` dès que la carte n'est pas servable — zonage absent, ou
            // vues de généralisation pas encore construites après une mise à
            // jour du code. La carte ne s'affiche alors pas du tout, ce qui
            // vaut infiniment mieux qu'un fond de plan sans zones à côté d'un
            // verdict « exposition forte ».
            'millesime' => $this->tuiles->carteDisponible() ? $this->tuiles->millesime() : null,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG);

        $reponse = $this->page(str_replace('{{CONFIG}}', $config, $html));

        // NE PAS poser X-Frame-Options : le produit existe pour être embarqué.
        // C'est frame-ancestors qui restreint, et lui seul.
        $reponse->headers->set('Content-Security-Policy', $this->frameAncestors($partner->getOriginesAutorisees()));
        $reponse->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $reponse->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $reponse;
    }

    /**
     * @param string[] $origines
     */
    private function frameAncestors(array $origines): string
    {
        // En dev seulement : la page d'exemple est servie par la même origine
        // que l'API, et sans 'self' le navigateur refuserait de l'afficher — on
        // croirait à un bug du widget. Pas en test, pour que la suite vérifie
        // exactement ce qui partira en production.
        if ('dev' === $this->environnement) {
            $origines[] = "'self'";
        }

        // Aucune origine déclarée : personne ne doit pouvoir embarquer ce
        // partenaire. `'none'` est le défaut sûr, et il se voit tout de suite.
        return 'frame-ancestors '.($origines ? implode(' ', $origines) : "'none'");
    }

    private function page(string $html, int $statut = Response::HTTP_OK): Response
    {
        $reponse = new Response($html, $statut);
        $reponse->headers->set('Content-Type', 'text/html; charset=utf-8');

        return $reponse;
    }
}
