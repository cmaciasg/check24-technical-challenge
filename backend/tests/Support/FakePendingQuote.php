<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Comparator\CarInsurance\Application\Compare\PendingQuote;
use App\Comparator\CarInsurance\Application\Compare\ProviderResult;

/** PendingQuote de test cuyo resultado se calcula al recogerlo, en la primera y única llamada a collect(). */
final readonly class FakePendingQuote implements PendingQuote
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
