<?php

declare(strict_types=1);

namespace App\Tests\Unit\Comparator\CarInsurance\Domain;

use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderId;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderIdentityTest extends TestCase
{
    public function testProviderIdEquality(): void
    {
        self::assertTrue(ProviderId::fromString('provider-a')->equals(ProviderId::fromString('provider-a')));
        self::assertFalse(ProviderId::fromString('provider-a')->equals(ProviderId::fromString('provider-b')));
    }

    #[DataProvider('invalidIds')]
    public function testProviderIdRejectsInvalidValues(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProviderId::fromString($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['Provider-A'];
        yield 'starts with a digit' => ['1provider'];
        yield 'contains a space' => ['provider a'];
    }

    public function testProviderNameMustNotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProviderName::fromString('  ');
    }
}
