<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Comparator\CarInsurance\Application\Compare\ComparisonMetrics;
use App\Comparator\CarInsurance\Application\Compare\ComparisonOutcome;
use App\Comparator\CarInsurance\Application\Compare\ProviderResult;
use App\Comparator\CarInsurance\Domain\ValueObjects\UsageType;
use App\Comparator\CarInsurance\Domain\ValueObjects\VehicleType;
use App\Comparator\CarInsurance\Infra\Observability\PrometheusComparisonMetrics;

/** Delega en las métricas reales; puede lanzar una excepción cuyo mensaje cita datos personales. */
final class SwitchableFailingMetrics implements ComparisonMetrics
{
    public ?string $failWithMessage = null;

    public function __construct(private readonly PrometheusComparisonMetrics $inner)
    {
    }

    public function quoteRequested(VehicleType $vehicleType, UsageType $usageType): void
    {
        if (null !== $this->failWithMessage) {
            throw new \RuntimeException($this->failWithMessage);
        }
        $this->inner->quoteRequested($vehicleType, $usageType);
    }

    public function providerCallFinished(ProviderResult $result): void
    {
        $this->inner->providerCallFinished($result);
    }

    public function campaignDiscountApplied(int $discountCents): void
    {
        $this->inner->campaignDiscountApplied($discountCents);
    }

    public function comparisonFinished(ComparisonOutcome $outcome, float $durationSeconds, ?float $overheadSeconds): void
    {
        $this->inner->comparisonFinished($outcome, $durationSeconds, $overheadSeconds);
    }

    public function requestRejected(): void
    {
        $this->inner->requestRejected();
    }

    public function requestFailed(): void
    {
        $this->inner->requestFailed();
    }

    public function initializeSeries(): void
    {
        $this->inner->initializeSeries();
    }
}
