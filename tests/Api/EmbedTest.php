<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Partner;
use App\Tests\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `GET /embed` et le chargeur — SPEC §6 et §7.
 */
final class EmbedTest extends ApiTestCase
{
    public function testLIframeEstServieAvecSaConfiguration(): void
    {
        $this->creerPartenaire();

        $this->client->request('GET', '/embed', ['key' => self::CLE]);
        $corps = (string) $this->client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=utf-8');
        self::assertStringNotContainsString('{{CONFIG}}', $corps, 'la configuration doit être injectée');
        self::assertStringContainsString('"cle":"'.self::CLE.'"', $corps);
        // L'URL de base est déduite de la requête, jamais écrite en dur : le
        // sous-domaine changera (SPEC §1).
        self::assertStringContainsString('"api":"http://localhost"', $corps);
    }

    /**
     * Le produit existe pour être embarqué : `X-Frame-Options: DENY` le
     * tuerait. C'est `frame-ancestors` qui restreint, et il est calculé par
     * partenaire — ajouter un client n'impose plus d'éditer le Caddyfile du VPS
     * (SPEC §8).
     */
    public function testLaCspEstCalculeeDepuisLesOriginesDuPartenaire(): void
    {
        $this->creerPartenaire();

        $this->client->request('GET', '/embed', ['key' => self::CLE]);

        self::assertResponseHeaderSame(
            'Content-Security-Policy',
            'frame-ancestors '.self::ORIGINE.' https://*.exemple-partenaire.fr',
        );
        self::assertFalse($this->client->getResponse()->headers->has('X-Frame-Options'));
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
    }

    public function testPartenaireSansOrigineNEstEmbarquableNullePart(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new Partner('pk_sans_origine', 'Sans origine'));
        $em->flush();

        $this->client->request('GET', '/embed', ['key' => 'pk_sans_origine']);

        self::assertResponseHeaderSame('Content-Security-Policy', "frame-ancestors 'none'");
    }

    /**
     * Une iframe qui afficherait un `application/problem+json` brut serait
     * illisible. Et le site hôte ne perd rien : faute de signal « prêt », son
     * repli statique reste en place (SPEC §7).
     */
    public function testCleInconnueDonneUnePageEtNonDuJson(): void
    {
        $this->client->request('GET', '/embed', ['key' => 'pk_inexistante']);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=utf-8');
        self::assertStringContainsString('<p>', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Deux couleurs suffisent à accorder le widget au site hôte, et c'est ce
     * qui permet de n'écrire la charte d'aucun client dans le code (SPEC §1).
     */
    public function testLeThemeDuPartenaireEstTransmisAuWidget(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->creerPartenaire()->setTheme('#6f0006,#021349');
        $em->flush();

        $this->client->request('GET', '/embed', ['key' => self::CLE]);

        self::assertStringContainsString(
            '"theme":"#6f0006,#021349"',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /**
     * À l'écriture, un thème invalide est refusé bruyamment : silencieusement
     * ignoré, il se découvrirait en recette, sur le site du client.
     */
    public function testUnThemeInvalideEstRefuseALEcriture(): void
    {
        $partner = $this->creerPartenaire();

        $this->expectException(\InvalidArgumentException::class);
        $partner->setTheme('#6f0006,rgb(2 19 73)');
    }

    /**
     * Et à la lecture, il est ignoré : la base reste éditable à la main, et
     * cette chaîne finit dans le CSSOM de l'iframe. Un jeton douteux invalide
     * TOUT le thème — un widget à moitié peint est un défaut qu'on ne voit pas.
     */
    public function testUnThemeGlisseEnBaseALaMainEstIgnoreEnEntier(): void
    {
        $this->creerPartenaire();

        // Contournement délibéré de l'entité : on simule une valeur posée par
        // un UPDATE manuel, seul chemin par lequel elle peut encore arriver.
        $this->db()->executeStatement(
            'UPDATE partner SET theme = :t WHERE public_key = :k',
            ['t' => '#6f0006,red;}html{display:none', 'k' => self::CLE],
        );

        $this->client->request('GET', '/embed', ['key' => self::CLE]);
        $corps = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('"theme":null', $corps);
        self::assertStringNotContainsString('display:none', $corps);
    }

    /**
     * L'introduction explique au visiteur POURQUOI ce champ de saisie le
     * concerne. Elle est donc affichée par défaut, et se coupe explicitement.
     */
    public function testLIntroductionEstAfficheeParDefautEtCoupableParParametre(): void
    {
        $this->creerPartenaire();

        $this->client->request('GET', '/embed', ['key' => self::CLE]);
        self::assertStringContainsString('"intro":true', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/embed', ['key' => self::CLE, 'intro' => '0']);
        self::assertStringContainsString('"intro":false', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Le chargeur s'exécute sur des sites tiers : chaque kilo-octet est un
     * risque, et le budget de §7 est de 5 Ko. Un test le rappelle mieux qu'un
     * commentaire.
     */
    public function testLeChargeurResteSousSonBudget(): void
    {
        $chemin = \dirname(__DIR__, 2).'/public/widget.js';

        self::assertFileExists($chemin);
        self::assertLessThan(5 * 1024, filesize($chemin), 'widget.js dépasse 5 Ko');

        $source = (string) file_get_contents($chemin);
        // Aucun cookie, aucun stockage, aucune dépendance : le widget doit
        // pouvoir tourner avant tout consentement (SPEC §7).
        foreach (['document.cookie', 'localStorage', 'sessionStorage'] as $interdit) {
            self::assertStringNotContainsString($interdit, $source);
        }
    }
}
