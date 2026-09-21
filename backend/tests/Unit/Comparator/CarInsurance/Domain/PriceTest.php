<?php

declare(strict_types=1);

namespace App\Tests\Unit\Comparator\CarInsurance\Domain;

use App\Comparator\CarInsurance\Domain\ValueObjects\Price;
use PHPUnit\Framework\TestCase;

final class PriceTest extends TestCase
{
    public function testDefaultsToEuros(): void
    {
        self::assertSame('EUR', Price::ofCents(100)->currencyCode);
    }

    public function testAcceptsZero(): void
    {
        self::assertSame(0, Price::ofCents(0)->amountCents);
    }

    public function testRejectsNegativeAmounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Price::ofCents(-1);
    }

    public function testRejectsUnsupportedCurrencies(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Price::ofCents(100, 'USD');
    }
}
