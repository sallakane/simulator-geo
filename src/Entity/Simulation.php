<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mesure, sans donnée personnelle (SPEC §5).
 *
 * Aucun nom, aucun e-mail, aucune adresse complète : seulement de quoi calculer
 * un taux de conversion par zone et par partenaire. Les coordonnées sont
 * arrondies à 4 décimales, soit ~11 m — assez pour une statistique par commune,
 * pas assez pour désigner une parcelle.
 */
#[ORM\Entity]
#[ORM\Table(name: 'simulation')]
#[ORM\Index(name: 'idx_simulation_partner_date', columns: ['partner_id', 'created_at'])]
class Simulation
{
    public const PRECISION_DECIMALES = 4;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Partner::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Partner $partner;

    #[ORM\Column(type: Types::DECIMAL, precision: 9, scale: 6)]
    private string $lat;

    #[ORM\Column(type: Types::DECIMAL, precision: 9, scale: 6)]
    private string $lon;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $codeInsee = null;

    /** null = hors périmètre : la carte ne couvre pas ce point (SPEC §3). */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $niveauCode = null;

    /** Passe à true si une demande de devis suit (lot 3). */
    #[ORM\Column]
    private bool $converti = false;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Partner $partner, float $lat, float $lon, ?int $niveauCode)
    {
        $this->partner = $partner;
        $this->lat = number_format($lat, self::PRECISION_DECIMALES, '.', '');
        $this->lon = number_format($lon, self::PRECISION_DECIMALES, '.', '');
        $this->niveauCode = $niveauCode;
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

    public function getNiveauCode(): ?int
    {
        return $this->niveauCode;
    }

    public function getCodeInsee(): ?string
    {
        return $this->codeInsee;
    }

    public function setCodeInsee(?string $codeInsee): self
    {
        $this->codeInsee = $codeInsee;

        return $this;
    }

    public function isConverti(): bool
    {
        return $this->converti;
    }

    public function marquerConverti(): self
    {
        $this->converti = true;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
