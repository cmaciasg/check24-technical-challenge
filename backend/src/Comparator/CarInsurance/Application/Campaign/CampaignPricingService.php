<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Application\Campaign;

use App\Comparator\CarInsurance\Domain\Model\ComparisonOffer;
use App\Comparator\CarInsurance\Domain\ValueObjects\CampaignDiscount;
use App\Comparator\CarInsurance\Domain\ValueObjects\DiscountType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Aplica la campaña de marketing por igual a todas las ofertas, sin distinguir provider. */
final class CampaignPricingService
{
    public function __construct(
        #[Autowire('%app.marketing.campaign.active%')]
        private readonly bool $active,
        #[Autowire('%app.marketing.campaign.discount_type%')]
        private readonly string $discountType,
        #[Autowire('%app.marketing.campaign.discount_value%')]
        private readonly int|float $discountValue,
    ) {
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @param list<ComparisonOffer> $offers
     *
     * @return list<PricedOffer>
     */
    public function apply(array $offers): array
    {
        if (!$this->active) {
            return array_map(static fn (ComparisonOffer $offer): PricedOffer => new PricedOffer($offer, null), $offers);
        }

        $discount = CampaignDiscount::fromType(DiscountType::from($this->discountType), $this->discountValue);

        return array_map(
            static fn (ComparisonOffer $offer): PricedOffer => new PricedOffer($offer, $offer->price->withDiscount($discount)),
            $offers,
        );
    }
}
