<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Application\Compare;

use App\Comparator\CarInsurance\Application\Campaign\PricedOffer;

final class CompareQuotesResult
{
    /**
     * @param list<PricedOffer>     $offers
     * @param list<ProviderResult>  $providerResults
     */
    public function __construct(
        public readonly array $offers,
        public readonly array $providerResults,
    ) {
    }
}
