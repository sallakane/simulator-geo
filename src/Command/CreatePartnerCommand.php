<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
use App\Repository\PartnerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Création d'une intégration (SPEC §5).
 *
 * C'est le seul endroit où un client entre dans le système. Rien de spécifique
 * à un partenaire ne doit exister ailleurs — ni dans le code, ni dans la
 * configuration, ni dans un fichier de fixtures (SPEC §15).
 */
#[AsCommand(name: 'app:partner:create', description: 'Crée un partenaire et affiche sa clé publique')]
final class CreatePartnerCommand extends Command
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
            ->addArgument('nom', InputArgument::REQUIRED, 'Nom du partenaire')
            ->addOption('origine', 'o', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Origine autorisée, répétable (ex. https://exemple.fr ou https://*.exemple.fr)')
            ->addOption('formulaire', null, InputOption::VALUE_REQUIRED,
                'URL du formulaire de devis du partenaire, vers lequel le widget redirige')
            ->addOption('champ', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Correspondance nom_logique=champ_du_formulaire, répétable. '
                .'Noms logiques : rue, code_postal, ville, message, simulation')
            ->addOption('theme', null, InputOption::VALUE_REQUIRED,
                'Couleur d\'accent du widget, et éventuellement l\'encre des titres : '
                .'#6f0006 ou #6f0006,#021349. Sans thème, le widget reste neutre.')
            ->addOption('cle', null, InputOption::VALUE_REQUIRED, 'Clé publique imposée (tests) ; générée sinon');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // La clé est identifiante, pas secrète : elle voyage dans le HTML du
        // site hôte. Elle doit être imprévisible malgré tout, sans quoi
        // n'importe qui consommerait le quota d'un partenaire.
        $cle = $input->getOption('cle') ?? 'pk_'.bin2hex(random_bytes(16));

        if (null !== $this->partners->findByPublicKey($cle)) {
            $io->error("La clé $cle existe déjà.");

            return Command::FAILURE;
        }

        $origines = $input->getOption('origine');

        $champs = [];
        foreach ($input->getOption('champ') as $paire) {
            if (!str_contains($paire, '=')) {
                $io->error("Correspondance invalide : « $paire ». Attendu : nom_logique=champ_du_formulaire");

                return Command::FAILURE;
            }
            [$logique, $reel] = explode('=', $paire, 2);
            $champs[trim($logique)] = trim($reel);
        }

        $partner = new Partner($cle, $input->getArgument('nom'));
        $partner
            ->setOriginesAutorisees($origines)
            ->setLeadEndpoint($input->getOption('formulaire'))
            ->setLeadChamps($champs);

        try {
            $partner->setTheme($input->getOption('theme'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->em->persist($partner);
        $this->em->flush();

        $io->success("Partenaire « {$partner->getNom()} » créé.");
        $io->definitionList(
            ['Clé publique' => $cle],
            ['Origines' => $origines ? implode(', ', $origines) : '(aucune — les appels navigateur seront refusés)'],
            ['Formulaire' => $partner->getLeadEndpoint() ?? '(aucun — pas de redirection)'],
            ['Champs' => $champs ? json_encode($champs, \JSON_UNESCAPED_SLASHES) : '(aucun)'],
            ['Thème' => $partner->getTheme() ?? '(aucun — widget neutre)'],
        );

        if (null !== $partner->getLeadEndpoint() && [] === $champs) {
            $io->warning(
                'Formulaire renseigné sans aucune correspondance de champs : la redirection aura lieu '
                ."mais n'emportera aucun contexte, et le lead ne sera pas qualifié (SPEC §1)."
            );
        }

        if (!$origines) {
            $io->warning(
                "Sans origine autorisée, le widget ne pourra pas lire la réponse depuis le site hôte. "
                ."Ajouter l'origine avant l'intégration, et penser au bloc `frame-ancestors` du Caddyfile (SPEC §8)."
            );
        }

        return Command::SUCCESS;
    }
}
