<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Partner;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Socle des tests d'API.
 *
 * Le zonage utilisé ici est SYNTHÉTIQUE (tests/fixtures/zonage-synthetique.sql) :
 * quatre carrés jointifs dans l'Essonne, rien au-dessus de Paris. Il permet de
 * verrouiller le comportement sans dépendre des 600 Mo de donnée officielle.
 * Le jeu de test de référence, lui, s'extrait de la donnée réelle (SPEC §10).
 */
abstract class ApiTestCase extends WebTestCase
{
    protected const CLE = 'pk_test';
    protected const ORIGINE = 'https://exemple-partenaire.fr';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $this->db()->executeStatement('TRUNCATE lead, simulation, partner RESTART IDENTITY CASCADE');

        // Les compteurs de débit survivent d'un test à l'autre (cache
        // partagé) : sans remise à zéro, le 15e test se prendrait le 429 du
        // précédent.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    protected function chargerZonageSynthetique(): void
    {
        $sql = file_get_contents(__DIR__.'/fixtures/zonage-synthetique.sql');
        self::assertIsString($sql);
        $this->db()->executeStatement($sql);
    }

    protected function supprimerZonage(): void
    {
        $this->db()->executeStatement('DROP VIEW IF EXISTS rga_zone_courante');
        $this->db()->executeStatement('DROP TABLE IF EXISTS rga_zone_synthetique');
    }

    protected function creerPartenaire(bool $actif = true): Partner
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $partner = new Partner(self::CLE, 'Partenaire de test');
        $partner->setActif($actif)->setOriginesAutorisees([self::ORIGINE, 'https://*.exemple-partenaire.fr']);

        $em->persist($partner);
        $em->flush();

        return $partner;
    }

    /** @return array<string, mixed> */
    protected function zonage(float $lat, float $lon, array $options = []): array
    {
        $cle = $options['key'] ?? self::CLE;
        $entetes = isset($options['origin']) ? ['HTTP_ORIGIN' => $options['origin']] : [];

        $this->client->request('GET', '/api/v1/zonage', [
            'key' => $cle,
            'lat' => (string) $lat,
            'lon' => (string) $lon,
        ], [], $entetes);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
