<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Application\Compare;

use App\Comparator\CarInsurance\Domain\Model\ComparisonOffer;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderId;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderName;

final class ProviderResult
{
    private function __construct(
        public readonly ProviderId $providerId,
        public readonly ProviderName $providerName,
        public readonly bool $success,
        public readonly ?ComparisonOffer $offer,
        public readonly ?ProviderFailureReason $failureReason,
        public readonly int $elapsedMs,
    ) {
    }

    public static function success(ProviderId $id, ProviderName $name, ComparisonOffer $offer, int $elapsedMs): self
    {
        return new self($id, $name, true, $offer, null, $elapsedMs);
    }

    public static function failure(ProviderId $id, ProviderName $name, ProviderFailureReason $reason, int $elapsedMs): self
    {
        return new self($id, $name, false, null, $reason, $elapsedMs);
    }
}
