<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rétention minimale (SPEC §5 et §9).
 *
 * L'éditeur est sous-traitant du partenaire au sens de l'article 28 : les
 * données personnelles sont RELAYÉES, pas conservées. Purge à J+30 après
 * livraison réussie (cron, infra/simulateur-purge.cron).
 *
 * ⚠️ `payload` doit être chiffré au repos avant toute mise en production. Le
 * chiffrement est livré avec le lot 3 ; d'ici là, aucun lead réel ne doit
 * transiter par cette table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'lead')]
#[ORM\Index(name: 'idx_lead_statut_delivered', columns: ['statut', 'delivered_at'])]
class Lead
{
    public const STATUT_PENDING = 'pending';
    public const STATUT_DELIVERED = 'delivered';
    public const STATUT_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Partner::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Partner $partner;

    #[ORM\ManyToOne(targetEntity: Simulation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Simulation $simulation = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column(length: 20)]
    private string $statut = self::STATUT_PENDING;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $tentatives = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $payload */
    public function __construct(Partner $partner, ?Simulation $simulation, array $payload)
    {
        $this->partner = $partner;
        $this->simulation = $simulation;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return null === $this->id ? null : (int) $this->id;
    }

    public function getPartner(): Partner
    {
        return $this->partner;
    }

    public function getSimulation(): ?Simulation
    {
        return $this->simulation;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getTentatives(): int
    {
        return $this->tentatives;
    }

    public function incrementerTentatives(): self
    {
        ++$this->tentatives;

        return $this;
    }

    public function marquerLivre(): self
    {
        $this->statut = self::STATUT_DELIVERED;
        $this->deliveredAt = new \DateTimeImmutable();

        return $this;
    }

    public function marquerEchoue(): self
    {
        $this->statut = self::STATUT_FAILED;

        return $this;
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
