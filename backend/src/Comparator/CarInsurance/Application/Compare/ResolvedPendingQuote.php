<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Application\Compare;

/** PendingQuote que ya lleva el resultado dentro: para lo que se resuelve sin esperar nada. */
final readonly class ResolvedPendingQuote implements PendingQuote
{
    public function __construct(private ProviderResult $result)
    {
    }

    public function collect(): ProviderResult
    {
        return $this->result;
    }
}
