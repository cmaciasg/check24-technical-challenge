<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Domain\Model;

use App\Comparator\CarInsurance\Domain\ValueObjects\CoverageLevel;
use App\Comparator\CarInsurance\Domain\ValueObjects\Price;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderId;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderName;

final class ComparisonOffer
{
    public function __construct(
        public readonly ProviderId $providerId,
        public readonly ProviderName $providerName,
        public readonly string $providerQuoteReference,
        public readonly Price $price,
        public readonly CoverageLevel $coverageLevel,
    ) {
    }
}
