<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Model\ZonageResult;
use App\Service\ObligationMapper;
use PHPUnit\Framework\TestCase;

final class ObligationMapperTest extends TestCase
{
    private ObligationMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ObligationMapper();
    }

    public function testObligationSurExpositionMoyenneEtForte(): void
    {
        foreach ([ObligationMapper::NIVEAU_MOYEN, ObligationMapper::NIVEAU_FORT] as $niveau) {
            $obligation = $this->mapper->obligation($niveau);

            self::assertTrue($obligation['applicable']);
            self::assertSame('G1 PGC', $obligation['mission']);
            self::assertSame(30, $obligation['validite_annees']);
        }
    }

    public function testAucuneObligationMaisUneSuiteEnDessous(): void
    {
        foreach ([ObligationMapper::NIVEAU_NUL, ObligationMapper::NIVEAU_FAIBLE] as $niveau) {
            $obligation = $this->mapper->obligation($niveau);

            self::assertFalse($obligation['applicable']);
            // Aucune réponse ne doit être un cul-de-sac : même sans obligation,
            // une mission reste proposée (SPEC §1).
            self::assertNotSame('', $obligation['mission']);
            self::assertNotSame('', $obligation['resume']);
        }
    }

    public function testChaqueMotifHorsPerimetreALeSienDeMessage(): void
    {
        $messages = array_map(
            fn (string $motif) => $this->mapper->messageHorsPerimetre($motif),
            [ZonageResult::MOTIF_PARIS, ZonageResult::MOTIF_HORS_METROPOLE],
        );

        self::assertSame($messages, array_unique($messages), 'un message générique gaspillerait le trafic');
        self::assertStringContainsString('Paris', $messages[0]);
    }

    public function testNiveauInconnuRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->mapper->obligation(7);
    }
}
