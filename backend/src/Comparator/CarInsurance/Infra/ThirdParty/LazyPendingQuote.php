<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Infra\ThirdParty;

use App\Comparator\CarInsurance\Application\Compare\PendingQuote;
use App\Comparator\CarInsurance\Application\Compare\ProviderResult;

/** PendingQuote cuyo resultado se calcula al recogerlo, en la primera y única llamada a collect(). */
final readonly class LazyPendingQuote implements PendingQuote
{
    /** @param \Closure(): ProviderResult $collect */
    public function __construct(private \Closure $collect)
    {
    }

    public function collect(): ProviderResult
    {
        return ($this->collect)();
    }
}
