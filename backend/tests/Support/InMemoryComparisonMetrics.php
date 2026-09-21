<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Comparator\CarInsurance\Application\Compare\ComparisonMetrics;
use App\Comparator\CarInsurance\Application\Compare\ComparisonOutcome;
use App\Comparator\CarInsurance\Application\Compare\ProviderResult;
use App\Comparator\CarInsurance\Domain\ValueObjects\UsageType;
use App\Comparator\CarInsurance\Domain\ValueObjects\VehicleType;

final class InMemoryComparisonMetrics implements ComparisonMetrics
{
    /** @var list<array{VehicleType, UsageType}> */
    public array $quoteRequests = [];
    /** @var list<ProviderResult> */
    public array $providerCalls = [];
    public int $discountCents = 0;
    /** @var list<array{ComparisonOutcome, float, ?float}> */
    public array $finished = [];
    public int $rejected = 0;
    public int $failed = 0;

    public function quoteRequested(VehicleType $vehicleType, UsageType $usageType): void
    {
        $this->quoteRequests[] = [$vehicleType, $usageType];
    }

    public function providerCallFinished(ProviderResult $result): void
    {
        $this->providerCalls[] = $result;
    }

    public function campaignDiscountApplied(int $discountCents): void
    {
        $this->discountCents += $discountCents;
    }

    public function comparisonFinished(ComparisonOutcome $outcome, float $durationSeconds, ?float $overheadSeconds): void
    {
        $this->finished[] = [$outcome, $durationSeconds, $overheadSeconds];
    }

    public function requestRejected(): void
    {
        ++$this->rejected;
    }

    public function requestFailed(): void
    {
        ++$this->failed;
    }

    public function initializeSeries(): void
    {
    }
}
