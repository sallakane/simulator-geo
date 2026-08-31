<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tuiles vectorielles du zonage (SPEC §6bis) — la carte, pas le verdict.
 *
 * Deux choses à ne jamais confondre :
 *
 *   · `ZonageResolver` répond « votre terrain est en zone moyenne ». Il lit la
 *     géométrie EXACTE, et lui seul fait foi.
 *   · ce service-ci dessine. Il lit, selon le zoom, des géométries SIMPLIFIÉES
 *     — jusqu'à 750 m de tolérance quand on regarde la France entière.
 *
 * La conséquence est à assumer explicitement : à faible zoom, le trait dessiné
 * n'est pas la frontière réglementaire. C'est pourquoi la simplification est
 * calée sur la taille du pixel (bin/charger-rga.sh, §2.5) — ce qui est gommé
 * est ce qui n'était de toute façon pas visible — et pourquoi la carte affiche
 * toujours le verdict à côté d'elle, jamais à sa place.
 */
final class TuilesRga
{
    /**
     * En deçà de z5 la France entière tient dans une tuile, et personne n'a
     * besoin de voir l'Europe. Au-delà de z15 le client sur-zoome la dernière
     * tuile reçue : du vectoriel agrandi reste net, et c'est autant de tuiles
     * qu'on ne calcule pas.
     */
    public const ZOOM_MIN = 5;
    public const ZOOM_MAX = 15;

    /** Nom de la couche dans la tuile. Le style du client s'y réfère. */
    public const COUCHE = 'rga';

    /**
     * Marge, en unités de tuile (sur 4096), au-delà du bord. Sans elle, le
     * contour d'un polygone coupé par la bordure se dessine SUR la bordure, et
     * la carte se couvre d'un quadrillage de faux traits.
     */
    private const MARGE = 64;

    private ?string $millesime = null;

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/var/tuiles')]
        private readonly string $repertoireCache,
    ) {
    }

    /**
     * Millésime en service, mémorisé pour la durée de la requête : une page de
     * carte déclenche une vingtaine de tuiles, et elles parlent forcément du
     * même millésime.
     */
    public function millesime(): ?string
    {
        if (null !== $this->millesime) {
            return $this->millesime;
        }

        try {
            $valeur = $this->db->fetchOne('SELECT millesime FROM rga_zone_courante LIMIT 1');
        } catch (\Throwable) {
            return null;
        }

        return $this->millesime = (false === $valeur || null === $valeur) ? null : (string) $valeur;
    }

    /**
     * La carte est-elle SERVABLE ?
     *
     * Un millésime chargé ne suffit pas : il faut aussi les deux vues de
     * généralisation, qui n'existent qu'après `--generaliser` + `--bascule`.
     * Entre une mise à jour du code et cette construction, elles manquent.
     *
     * Sans ce contrôle, `/embed` annoncerait une carte que le serveur de
     * tuiles refuserait ensuite : le visiteur verrait un fond de plan SANS
     * aucune zone colorée, à côté d'un verdict « exposition forte ». Une carte
     * vide se lit « aucune exposition » — c'est le mensonge crédible que tout
     * le reste du produit refuse (SPEC §3).
     *
     * Mieux vaut donc pas de carte du tout : le verdict, lui, reste juste.
     */
    public function carteDisponible(): bool
    {
        if (null === $this->millesime()) {
            return false;
        }

        try {
            return 2 === (int) $this->db->fetchOne(
                "SELECT count(*) FROM pg_views
                  WHERE schemaname = current_schema()
                    AND viewname IN ('rga_zone_courante_g', 'rga_zone_courante_gg')"
            );
        } catch (\Throwable) {
            return false;
        }
    }

    public function zoomValide(int $z): bool
    {
        return $z >= self::ZOOM_MIN && $z <= self::ZOOM_MAX;
    }

    /**
     * Une tuile, en protobuf MVT. La chaîne vide est une réponse valide et
     * fréquente : elle veut dire « aucune zone exposée ici », ce qui est le cas
     * de la moitié du territoire.
     *
     * @throws \RuntimeException si le zonage est injoignable — une tuile vide
     *                           serait indiscernable d'une zone non exposée
     */
    public function tuile(int $z, int $x, int $y): string
    {
        $chemin = $this->chemin($z, $x, $y);

        if (null !== $chemin && is_file($chemin)) {
            $contenu = @file_get_contents($chemin);

            if (false !== $contenu) {
                return $contenu;
            }
        }

        $tuile = $this->calculer($z, $x, $y);

        if (null !== $chemin) {
            $this->deposer($chemin, $tuile);
        }

        return $tuile;
    }

    /**
     * La vue à lire dépend du zoom. Les trois basculent dans la même
     * transaction (bin/charger-rga.sh) : à aucun instant la carte ne peut
     * mélanger deux millésimes.
     */
    public function vue(int $z): string
    {
        return match (true) {
            $z >= 12 => 'rga_zone_courante',     // géométrie exacte
            $z >= 8 => 'rga_zone_courante_g',    // tolérance 110 m
            default => 'rga_zone_courante_gg',   // tolérance 2,2 km, surfaces > 20 km²
        };
    }

    private function calculer(int $z, int $x, int $y): string
    {
        $vue = $this->vue($z);

        try {
            // La tuile est construite en 3857 (projection des tuiles web) à
            // partir de géométries stockées en 4326. L'inverse — stocker en
            // 3857 — casserait la requête d'exposition, qui interroge en
            // degrés depuis la Base Adresse Nationale.
            //
            // Le && porte sur l'enveloppe reprojetée en 4326, calculée une fois
            // dans la CTE : c'est ce qui laisse l'index GIST faire son travail.
            $mvt = $this->db->fetchOne(
                <<<SQL
                WITH bornes AS (
                    SELECT ST_TileEnvelope(:z, :x, :y) AS tuile,
                           ST_Transform(ST_TileEnvelope(:z, :x, :y), 4326) AS emprise
                )
                SELECT ST_AsMVT(objets, :couche)
                  FROM (
                        SELECT zone.niveau_code AS n,
                               ST_AsMVTGeom(ST_Transform(zone.geom, 3857), bornes.tuile, 4096, :marge, true) AS geom
                          FROM {$vue} AS zone, bornes
                         WHERE zone.niveau_code IS NOT NULL
                           AND zone.geom && bornes.emprise
                       ) AS objets
                 WHERE objets.geom IS NOT NULL
                SQL,
                ['z' => $z, 'x' => $x, 'y' => $y, 'couche' => self::COUCHE, 'marge' => self::MARGE],
            );
        } catch (\Throwable $e) {
            // Même règle que pour le verdict : une panne doit se voir. Rendre
            // une tuile vide peindrait « aucune exposition » sur une région
            // entière, en 200, sans que rien ne l'indique.
            $this->logger->error('Tuile RGA impossible', ['exception' => $e, 'z' => $z, 'x' => $x, 'y' => $y]);

            throw new \RuntimeException('Tuile indisponible', 0, $e);
        }

        if (false === $mvt || null === $mvt) {
            return '';
        }

        // Doctrine rend le bytea de PostgreSQL sous forme de ressource de flux.
        return is_resource($mvt) ? (string) stream_get_contents($mvt) : (string) $mvt;
    }

    /**
     * Cache disque, arborescence `var/tuiles/<millesime>/z/x/y.pbf`.
     *
     * Le millésime est dans le CHEMIN, pas dans le nom du fichier : une bascule
     * n'invalide rien, elle change de répertoire. L'ancien devient orphelin et
     * se supprime à froid (docs/exploitation.md §5) — jamais pendant une
     * bascule, où il sert encore de retour arrière.
     */
    private function chemin(int $z, int $x, int $y): ?string
    {
        $millesime = $this->millesime();

        if (null === $millesime || !preg_match('/^[A-Za-z0-9_-]{1,10}$/', $millesime)) {
            return null;
        }

        return sprintf('%s/%s/%d/%d/%d.pbf', $this->repertoireCache, $millesime, $z, $x, $y);
    }

    /**
     * Écriture atomique : un fichier temporaire puis un `rename`. Sans cela,
     * deux visiteurs demandant la même tuile au même instant peuvent en laisser
     * une tronquée sur le disque — et une tuile tronquée est une carte trouée,
     * pour tout le monde, jusqu'à la prochaine purge.
     */
    private function deposer(string $chemin, string $tuile): void
    {
        $repertoire = \dirname($chemin);

        if (!is_dir($repertoire) && !@mkdir($repertoire, 0o775, true) && !is_dir($repertoire)) {
            return;
        }

        $temporaire = $chemin.'.'.bin2hex(random_bytes(6));

        if (false === @file_put_contents($temporaire, $tuile) || !@rename($temporaire, $chemin)) {
            @unlink($temporaire);
        }
    }

    /**
     * Le cache est un accélérateur, jamais une source de vérité : il doit
     * pouvoir être vidé à chaud sans rien casser.
     */
    public function purger(?string $millesime = null): int
    {
        $racine = null === $millesime ? $this->repertoireCache : $this->repertoireCache.'/'.$millesime;

        if (!is_dir($racine)) {
            return 0;
        }

        $supprimes = 0;
        $entrees = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entrees as $entree) {
            if ($entree->isDir()) {
                @rmdir($entree->getPathname());
                continue;
            }

            if (@unlink($entree->getPathname())) {
                ++$supprimes;
            }
        }

        @rmdir($racine);

        return $supprimes;
    }
}
