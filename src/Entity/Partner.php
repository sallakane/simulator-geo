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

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $theme = null;

    /** URL du webform destinataire des leads (relais asynchrone, SPEC §6). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $leadEndpoint = null;

    /** Copie de secours si le relais échoue trois fois. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $leadEmail = null;

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

    public function setTheme(?string $theme): self
    {
        $this->theme = $theme;

        return $this;
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

    public function getLeadEmail(): ?string
    {
        return $this->leadEmail;
    }

    public function setLeadEmail(?string $email): self
    {
        $this->leadEmail = $email;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
