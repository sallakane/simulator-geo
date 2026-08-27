<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PartnerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une intégration = un enregistrement. C'est cette table qui rend le produit
 * revendable : aucune valeur spécifique à un client ne doit exister ailleurs,
 * ni dans le code, ni dans la configuration (SPEC §5 et §15).
 */
#[ORM\Entity(repositoryClass: PartnerRepository::class)]
#[ORM\Table(name: 'partner')]
class Partner
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Exposée côté client dans le widget : identifiante, pas secrète. */
    #[ORM\Column(length: 40, unique: true)]
    private string $publicKey;

    #[ORM\Column(length: 120)]
    private string $nom;

    #[ORM\Column]
    private bool $actif = true;

    /**
     * Liste blanche explicite des origines autorisées, jamais `*` (SPEC §8).
     *
     * @var string[]
     */
    #[ORM\Column(type: Types::JSON)]
    private array $originesAutorisees = [];

    /**
     * Couleur d'accent du widget, éventuellement suivie de l'encre des titres :
     * `#6f0006` ou `#6f0006,#021349`. Tout le reste de la palette en dérive.
     *
     * Deux couleurs et pas une charte : c'est ce qui permet au widget de
     * s'accorder au site de CHAQUE partenaire sans qu'aucune couleur de client
     * ne soit écrite dans le code (SPEC §1, §15).
     */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $theme = null;

    /**
     * URL du formulaire de devis DU PARTENAIRE, vers lequel le widget redirige
     * avec le contexte en paramètres (SPEC §6).
     *
     * Vide = pas de redirection : l'appel à l'action se contente de prévenir la
     * page hôte, qui branche ce qu'elle veut.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $leadEndpoint = null;

    /**
     * Correspondance entre nos noms logiques et les champs du formulaire du
     * partenaire : `{"rue": "rue", "message": "description_de_la_demande"}`.
     *
     * C'est ce qui garde le code générique. Écrire ici « rue » ou
     * « description_de_la_demande » dans un service reviendrait à coder en dur
     * le formulaire d'un client (SPEC §15).
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $leadChamps = [];

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $publicKey, string $nom)
    {
        $this->publicKey = $publicKey;
        $this->nom = $nom;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

        return $this;
    }

    /** @return string[] */
    public function getOriginesAutorisees(): array
    {
        return $this->originesAutorisees;
    }

    /** @param string[] $origines */
    public function setOriginesAutorisees(array $origines): self
    {
        $this->originesAutorisees = array_values($origines);

        return $this;
    }

    public function getTheme(): ?string
    {
        return $this->theme;
    }

    /**
     * @throws \InvalidArgumentException si la valeur n'est pas un thème valide
     */
    public function setTheme(?string $theme): self
    {
        $normalise = self::normaliserTheme($theme);

        // Écriture : on refuse bruyamment. Un thème silencieusement ignoré se
        // découvrirait en recette, sur le site du client, au pire moment.
        if (null !== $theme && '' !== trim($theme) && null === $normalise) {
            throw new \InvalidArgumentException(
                "Thème invalide : « $theme ». Attendu une couleur hexadécimale, "
                .'éventuellement suivie d\'une seconde : #6f0006 ou #6f0006,#021349.'
            );
        }

        $this->theme = $normalise;

        return $this;
    }

    /**
     * Seule définition de ce qu'est un thème valide. Elle sert des deux côtés :
     * en écriture (ci-dessus, où l'erreur est levée) et en lecture par
     * `WidgetController`, où une valeur douteuse est simplement ignorée — la
     * base reste éditable à la main, et cette chaîne finit dans le CSSOM d'une
     * iframe.
     *
     * Un seul jeton douteux invalide TOUT le thème : un widget à moitié peint
     * est un défaut qu'on ne voit pas en recette.
     */
    public static function normaliserTheme(?string $theme): ?string
    {
        if (null === $theme || '' === trim($theme)) {
            return null;
        }

        $jetons = array_slice(array_map('trim', explode(',', $theme)), 0, 2);

        foreach ($jetons as $jeton) {
            if (1 !== preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $jeton)) {
                return null;
            }
        }

        return implode(',', $jetons);
    }

    public function getLeadEndpoint(): ?string
    {
        return $this->leadEndpoint;
    }

    public function setLeadEndpoint(?string $url): self
    {
        $this->leadEndpoint = $url;

        return $this;
    }

    /** @return array<string, string> */
    public function getLeadChamps(): array
    {
        return $this->leadChamps;
    }

    /** @param array<string, string> $champs */
    public function setLeadChamps(array $champs): self
    {
        $this->leadChamps = $champs;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
