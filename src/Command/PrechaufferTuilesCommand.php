<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TuilesRga;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Précalcule les tuiles des zooms bas (SPEC §6bis).
 *
 * Toutes les tuiles ne se valent pas. À z14, une tuile coûte 20 ms et pèse
 * 300 octets : la calculer à la demande ne se voit pas. À z5, elle coûte 850 ms
 * et pèse 590 Ko — et c'est la PREMIÈRE que voit quiconque dézoome.
 *
 * Or les tuiles de zoom bas se comptent : 4 à z5, 16 à z6, une soixantaine à
 * z7. Les précalculer une fois après chaque bascule de millésime coûte quelques
 * minutes et supprime l'unique moment où la carte serait lente.
 *
 * À lancer APRÈS `--bascule`, jamais avant : le cache est rangé par millésime,
 * et préchauffer celui qui n'est pas en service remplirait un répertoire que
 * personne ne lira.
 */
#[AsCommand(
    name: 'app:tuiles:prechauffer',
    description: 'Précalcule les tuiles des zooms bas pour le millésime en service',
)]
final class PrechaufferTuilesCommand extends Command
{
    /**
     * France métropolitaine, marge comprise. Les tuiles hors de cette emprise
     * sont vides : les calculer coûterait le même temps pour ne rien contenir.
     */
    private const OUEST = -5.3;
    private const EST = 9.7;
    private const SUD = 41.2;
    private const NORD = 51.2;

    public function __construct(private readonly TuilesRga $tuiles)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('jusqu-a', 'z', InputOption::VALUE_REQUIRED,
            'Zoom maximal à précalculer (au-delà, le calcul à la demande suffit)', '8');
        $this->addOption('purger', null, InputOption::VALUE_NONE,
            'Vide le cache du millésime en service avant de le reconstruire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $millesime = $this->tuiles->millesime();

        if (null === $millesime) {
            $io->error('Aucun millésime en service : rga_zone_courante est vide ou absente.');
            $io->writeln('Charger la donnée d’abord — <info>./bin/charger-rga.sh</info> (SPEC §4).');

            return Command::FAILURE;
        }

        $zoomMax = min((int) $input->getOption('jusqu-a'), TuilesRga::ZOOM_MAX);

        if ($zoomMax < TuilesRga::ZOOM_MIN) {
            $io->error(sprintf('Le zoom maximal doit valoir au moins %d.', TuilesRga::ZOOM_MIN));

            return Command::FAILURE;
        }

        if ($input->getOption('purger')) {
            $io->writeln(sprintf('Purge du cache du millésime %s : %d tuiles supprimées.',
                $millesime, $this->tuiles->purger($millesime)));
        }

        $io->title(sprintf('Préchauffage du millésime %s, zooms %d à %d', $millesime, TuilesRga::ZOOM_MIN, $zoomMax));

        $total = 0;
        $octets = 0;
        $debut = microtime(true);

        for ($z = TuilesRga::ZOOM_MIN; $z <= $zoomMax; ++$z) {
            [$xMin, $yMin] = $this->tuileDe(self::OUEST, self::NORD, $z);
            [$xMax, $yMax] = $this->tuileDe(self::EST, self::SUD, $z);

            $compte = ($xMax - $xMin + 1) * ($yMax - $yMin + 1);
            $io->writeln(sprintf('z=%d — %d tuiles (%s)', $z, $compte, $this->tuiles->vue($z)));
            $io->progressStart($compte);

            for ($x = $xMin; $x <= $xMax; ++$x) {
                for ($y = $yMin; $y <= $yMax; ++$y) {
                    try {
                        $octets += \strlen($this->tuiles->tuile($z, $x, $y));
                    } catch (\RuntimeException $e) {
                        $io->progressFinish();
                        $io->error(sprintf('Tuile %d/%d/%d : %s', $z, $x, $y, $e->getMessage()));

                        return Command::FAILURE;
                    }

                    ++$total;
                    $io->progressAdvance();
                }
            }

            $io->progressFinish();
        }

        $io->success(sprintf('%d tuiles, %.1f Mo, en %.0f s.',
            $total, $octets / 1048576, microtime(true) - $debut));

        return Command::SUCCESS;
    }

    /**
     * Coordonnées de tuile web mercator. La formule est celle de la
     * spécification des tuiles ; l'écrire ici évite un aller-retour en base
     * pour un calcul qui ne dépend d'aucune donnée.
     *
     * @return array{int, int}
     */
    private function tuileDe(float $lon, float $lat, int $z): array
    {
        $n = 1 << $z;
        $latRad = deg2rad(max(-85.05112878, min(85.05112878, $lat)));

        return [
            (int) max(0, min($n - 1, floor(($lon + 180) / 360 * $n))),
            (int) max(0, min($n - 1, floor((1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $n))),
        ];
    }
}
