<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Application\Compare;

enum ComparisonOutcome: string
{
    case COMPLETE = 'complete';
    case PARTIAL = 'partial';
    case EMPTY = 'empty';
    case INVALID = 'invalid';
    case ERROR = 'error';

    /** @param list<ProviderResult> $results */
    public static function fromResults(array $results): self
    {
        $succeeded = count(array_filter($results, static fn (ProviderResult $r): bool => $r->success));

        return match (true) {
            0 === $succeeded => self::EMPTY,
            $succeeded === count($results) => self::COMPLETE,
            default => self::PARTIAL,
        };
    }
}
