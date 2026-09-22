<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Application\Compare;

use App\Comparator\CarInsurance\Domain\Model\ComparisonOffer;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderId;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderName;

final readonly class ProviderResult
{
    private function __construct(
        public ProviderId $providerId,
        public ProviderName $providerName,
        public bool $success,
        public ?ComparisonOffer $offer,
        public ?ProviderFailureReason $failureReason,
        public int $elapsedMs,
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
