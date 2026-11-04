<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Application\Campaign;

use DateTimeImmutable;

use App\Comparator\CarInsurance\Domain\Model\ComparisonOffer;
use App\Comparator\CarInsurance\Domain\ValueObjects\Price;

final readonly class PricedOffer
{
    public function __construct(
        public ComparisonOffer $offer,
        public ?Price $discountedPrice,
    ) {
    }
}
