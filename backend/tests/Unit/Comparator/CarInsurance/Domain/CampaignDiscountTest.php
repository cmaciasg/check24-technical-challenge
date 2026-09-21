<?php

declare(strict_types=1);

namespace App\Tests\Unit\Comparator\CarInsurance\Domain;

use App\Comparator\CarInsurance\Domain\ValueObjects\CampaignDiscount;
use App\Comparator\CarInsurance\Domain\ValueObjects\DiscountType;
use App\Comparator\CarInsurance\Domain\ValueObjects\Price;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class CampaignDiscountTest extends TestCase
{
    #[Group('TC-P5')]
    public function testPercentageDiscountOfTenPercent(): void
    {
        self::assertSame(3825, CampaignDiscount::percentage(10)->applyTo(4250));
    }

    #[Group('TC-P5')]
    public function testPercentageBoundaries(): void
    {
        self::assertSame(4250, CampaignDiscount::percentage(0)->applyTo(4250));
        self::assertSame(0, CampaignDiscount::percentage(100)->applyTo(4250));
    }

    #[Group('TC-P5')]
    public function testRoundsHalfCentUp(): void
    {
        // 1005 * 50 % = 502,5 céntimos -> 503
        self::assertSame(503, CampaignDiscount::percentage(50)->applyTo(1005));
        // 1001 * 90 % = 900,9 -> 901
        self::assertSame(901, CampaignDiscount::percentage(10)->applyTo(1001));
    }

    #[Group('TC-P6')]
    public function testFixedAmountIsSubtracted(): void
    {
        self::assertSame(4000, CampaignDiscount::fixedAmount(250)->applyTo(4250));
    }

    #[Group('TC-P6')]
    public function testFixedAmountGreaterThanThePriceFloorsAtZero(): void
    {
        self::assertSame(0, CampaignDiscount::fixedAmount(9999)->applyTo(4250));
    }

    #[DataProvider('invalidDiscounts')]
    public function testRejectsInvalidValues(\Closure $build): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $build();
    }

    /** @return iterable<string, array{\Closure}> */
    public static function invalidDiscounts(): iterable
    {
        yield 'negative percentage' => [static fn () => CampaignDiscount::percentage(-1)];
        yield 'percentage above 100' => [static fn () => CampaignDiscount::percentage(100.5)];
        yield 'negative fixed amount' => [static fn () => CampaignDiscount::fixedAmount(-1)];
    }

    public function testBuildsFromConfiguredType(): void
    {
        self::assertSame(3825, CampaignDiscount::fromType(DiscountType::PERCENTAGE, 10)->applyTo(4250));
        self::assertSame(4000, CampaignDiscount::fromType(DiscountType::FIXED_AMOUNT, 250)->applyTo(4250));
    }

    public function testPriceAppliesTheDiscountKeepingTheCurrency(): void
    {
        $discounted = Price::ofCents(4250)->withDiscount(CampaignDiscount::percentage(10));

        self::assertSame(3825, $discounted->amountCents);
        self::assertSame('EUR', $discounted->currencyCode);
    }
}
