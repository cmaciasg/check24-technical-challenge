<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Application\Campaign;

use App\Comparator\CarInsurance\Domain\Model\ComparisonOffer;
use App\Comparator\CarInsurance\Domain\ValueObjects\Price;

final class PricedOffer
{
    public function __construct(
        public readonly ComparisonOffer $offer,
        public readonly ?Price $discountedPrice,
    ) {
    }
}
