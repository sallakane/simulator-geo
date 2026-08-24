<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\Coordonnees;
use App\Service\ZonageResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rejoue le jeu de test de référence contre le zonage EN SERVICE (SPEC §10).
 *
 * Ce n'est pas un test unitaire, et c'est délibéré : il doit tourner là où la
 * vraie donnée est chargée — sur le poste après `make rga`, et sur le VPS après
 * chaque mise à jour de millésime (§4.4, étape 3). PHPUnit, lui, tourne sur un
 * zonage synthétique et ne verrait jamais la donnée officielle.
 *
 * Les points ne sont pas écrits à la main : ils sont extraits de la donnée
 * elle-même par `bin/charger-rga.sh --points`. Une adresse supposée être en
 * zone forte est une croyance ; un ST_PointOnSurface est un fait.
 */
#[AsCommand(
    name: 'app:zonage:verifier',
    description: 'Rejoue tests/fixtures/points-reference.json contre rga_zone_courante',
)]
final class VerifierPointsReferenceCommand extends Command
{
    public function __construct(
        private readonly ZonageResolver $zonage,
        // Le jeu de points vit dans tests/fixtures/ (SPEC §10) : il faut la
        // racine du projet pour le lire.
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fichier', 'f', InputOption::VALUE_REQUIRED,
            'Jeu de points à rejouer', 'tests/fixtures/points-reference.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $chemin = $this->projectDir.'/'.$input->getOption('fichier');

        if (!is_file($chemin)) {
            $io->error("Jeu de référence absent : $chemin");
            $io->writeln('Le générer depuis la donnée en service : <info>make points</info>');

            return Command::FAILURE;
        }

        $points = json_decode((string) file_get_contents($chemin), true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($points) || [] === $points) {
            $io->error('Jeu de référence vide ou illisible.');

            return Command::FAILURE;
        }

        $lignes = [];
        $echecs = 0;

        foreach ($points as $point) {
            $attendu = (int) $point['niveau_code'];
            $resultat = $this->zonage->resoudre(
                Coordonnees::depuis((string) $point['lat'], (string) $point['lon'])
            );
            $obtenu = $resultat->niveauCode;
            $ok = $obtenu === $attendu;
            $echecs += $ok ? 0 : 1;

            $lignes[] = [
                $ok ? '<info>ok</info>' : '<error>ÉCHEC</error>',
                $point['lat'].', '.$point['lon'],
                $attendu,
                $obtenu ?? '—',
                $point['millesime'] ?? '?',
                $resultat->millesime ?? ($resultat->motif ?? '—'),
            ];
        }

        $io->table(['', 'Point', 'Attendu', 'Obtenu', 'Millésime attendu', 'Millésime servi'], $lignes);

        if ($echecs > 0) {
            // Un écart ici veut dire que le zonage servi n'est pas celui qu'on
            // croit : mauvais millésime, chargement partiel, ou vue basculée sur
            // la mauvaise table. Aucune de ces situations ne se voit en HTTP.
            $io->error("$echecs point(s) de référence en échec sur ".\count($points).'.');

            return Command::FAILURE;
        }

        $io->success(\count($points).' points de référence vérifiés.');

        return Command::SUCCESS;
    }
}
