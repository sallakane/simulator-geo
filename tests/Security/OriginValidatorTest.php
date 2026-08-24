<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Partner;
use App\Exception\ApiProblemException;
use App\Security\OriginValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OriginValidatorTest extends TestCase
{
    private OriginValidator $validator;
    private Partner $partner;

    protected function setUp(): void
    {
        $this->validator = new OriginValidator();
        $this->partner = (new Partner('pk_x', 'Test'))
            ->setOriginesAutorisees(['https://exemple.fr', 'https://*.exemple.fr']);
    }

    public function testOrigineExacte(): void
    {
        self::assertSame('https://exemple.fr', $this->validator->verifier($this->partner, 'https://exemple.fr'));
    }

    public function testSousDomaine(): void
    {
        self::assertSame('https://www.exemple.fr', $this->validator->verifier($this->partner, 'https://www.exemple.fr'));
    }

    public function testAbsenceDOrigineToleree(): void
    {
        // curl, supervision, navigation directe : `Origin` n'est pas un contrôle
        // d'accès, c'est ce qui autorise le NAVIGATEUR à lire la réponse.
        self::assertNull($this->validator->verifier($this->partner, null));
    }

    /** @return iterable<string, array{string}> */
    public static function origineHostiles(): iterable
    {
        yield 'autre domaine' => ['https://exemple.fr.attaquant.com'];
        yield 'suffixe collé' => ['https://notexemple.fr'];
        yield 'schéma non chiffré' => ['http://exemple.fr'];
        yield 'domaine proche' => ['https://exemple.fr.co'];
    }

    #[DataProvider('origineHostiles')]
    public function testOrigineRefusee(string $origine): void
    {
        $this->expectException(ApiProblemException::class);

        $this->validator->verifier($this->partner, $origine);
    }
}
