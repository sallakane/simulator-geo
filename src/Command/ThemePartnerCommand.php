<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PartnerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Change l'habillage du widget d'un partenaire (SPEC §7).
 *
 * Une commande à part, parce que c'est le seul réglage qu'on retouche APRÈS
 * l'intégration : le client regarde le widget sur son site et demande un ton
 * plus foncé. Sans elle, ce geste de dix secondes se ferait par un UPDATE à la
 * main en production — sur la table qui porte les clés de tous les partenaires.
 *
 * Deux couleurs, jamais plus : l'accent (appels à l'action, focus) et
 * l'encre (titres). Tout le reste de la palette en dérive côté CSS. C'est ce
 * qui permet au widget de s'accorder à chaque site hôte sans qu'aucune couleur
 * de client ne soit écrite dans le code (SPEC §1, §15).
 */
#[AsCommand(name: 'app:partner:theme', description: 'Change les couleurs du widget d’un partenaire')]
final class ThemePartnerCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerRepository $partners,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('cle', InputArgument::REQUIRED, 'Clé publique du partenaire')
            ->addArgument('theme', InputArgument::REQUIRED,
                'Accent, et éventuellement encre : « #6f0006 », « #6f0006,#021349 », '
                .'ou « - » pour revenir au widget neutre');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cle = (string) $input->getArgument('cle');

        $partner = $this->partners->findByPublicKey($cle);

        if (null === $partner) {
            $io->error("Aucun partenaire avec la clé $cle.");

            return Command::FAILURE;
        }

        $demande = (string) $input->getArgument('theme');
        $avant = $partner->getTheme();

        try {
            // « - » plutôt qu'une chaîne vide : un argument vide se perd dans un
            // shell, et effacer un thème doit être un geste explicite.
            $partner->setTheme('-' === $demande ? null : $demande);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->em->flush();

        $io->success("Thème de « {$partner->getNom()} » mis à jour.");
        $io->definitionList(
            ['Avant' => $avant ?? '(aucun — widget neutre)'],
            ['Après' => $partner->getTheme() ?? '(aucun — widget neutre)'],
        );

        // Le widget est servi par /embed à chaque affichage : rien à purger,
        // rien à redéployer. Le dire évite qu'on aille chercher un cache.
        $io->note('Effet immédiat au prochain affichage du widget : /embed n’est pas mis en cache.');

        return Command::SUCCESS;
    }
}
