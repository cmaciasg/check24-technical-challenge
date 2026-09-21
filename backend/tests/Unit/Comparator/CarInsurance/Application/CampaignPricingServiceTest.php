<?php

declare(strict_types=1);

namespace App\Tests\Unit\Comparator\CarInsurance\Application;

use App\Comparator\CarInsurance\Application\Campaign\CampaignPricingService;
use App\Comparator\CarInsurance\Application\Campaign\PricedOffer;
use App\Comparator\CarInsurance\Domain\Model\ComparisonOffer;
use App\Comparator\CarInsurance\Domain\ValueObjects\CoverageLevel;
use App\Comparator\CarInsurance\Domain\ValueObjects\Price;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderId;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderName;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class CampaignPricingServiceTest extends TestCase
{
    #[Group('TC-P4')]
    public function testInactiveCampaignLeavesEveryOfferWithoutDiscountedPrice(): void
    {
        $priced = (new CampaignPricingService(false, 'PERCENTAGE', 10))->apply([$this->offer('provider-a', 4250), $this->offer('provider-b', 4890)]);

        self::assertCount(2, $priced);
        foreach ($priced as $pricedOffer) {
            self::assertNull($pricedOffer->discountedPrice);
        }
        self::assertSame(4250, $priced[0]->offer->price->amountCents);
    }

    #[Group('TC-P5')]
    public function testPercentageCampaign(): void
    {
        $priced = (new CampaignPricingService(true, 'PERCENTAGE', 10))->apply([$this->offer('provider-a', 4250)]);

        self::assertSame(3825, $priced[0]->discountedPrice?->amountCents);
        self::assertSame(4250, $priced[0]->offer->price->amountCents, 'The real provider price is never modified.');
    }

    #[Group('TC-P6')]
    public function testFixedAmountCampaignFloorsEachOfferAtZero(): void
    {
        $priced = (new CampaignPricingService(true, 'FIXED_AMOUNT', 4000))->apply([$this->offer('provider-a', 4250), $this->offer('provider-c', 3990)]);

        self::assertSame(250, $priced[0]->discountedPrice?->amountCents);
        self::assertSame(0, $priced[1]->discountedPrice?->amountCents);
    }

    #[Group('TC-P7')]
    public function testTheSameDiscountAppliesToEveryProvider(): void
    {
        $offers = [$this->offer('provider-a', 10000), $this->offer('provider-b', 10000), $this->offer('provider-c', 10000)];

        $priced = (new CampaignPricingService(true, 'PERCENTAGE', 10))->apply($offers);

        self::assertSame([9000, 9000, 9000], array_map(static fn (PricedOffer $p): ?int => $p->discountedPrice?->amountCents, $priced));
    }

    public function testNoOffersYieldsNoPricedOffers(): void
    {
        self::assertSame([], (new CampaignPricingService(true, 'PERCENTAGE', 10))->apply([]));
    }

    public function testReportsWhetherTheCampaignIsActive(): void
    {
        self::assertTrue((new CampaignPricingService(true, 'PERCENTAGE', 10))->isActive());
        self::assertFalse((new CampaignPricingService(false, 'PERCENTAGE', 10))->isActive());
    }

    private function offer(string $providerId, int $cents): ComparisonOffer
    {
        return new ComparisonOffer(
            ProviderId::fromString($providerId),
            ProviderName::fromString($providerId),
            'REF',
            Price::ofCents($cents),
            CoverageLevel::STANDARD,
        );
    }
}
