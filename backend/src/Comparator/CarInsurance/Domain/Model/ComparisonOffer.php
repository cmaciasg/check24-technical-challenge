<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Domain\Model;

use App\Comparator\CarInsurance\Domain\ValueObjects\CoverageLevel;
use App\Comparator\CarInsurance\Domain\ValueObjects\Price;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderId;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderName;

final readonly class ComparisonOffer
{
    public function __construct(
        public ProviderId $providerId,
        public ProviderName $providerName,
        public string $providerQuoteReference,
        public Price $price,
        public CoverageLevel $coverageLevel,
    ) {
    }
}
