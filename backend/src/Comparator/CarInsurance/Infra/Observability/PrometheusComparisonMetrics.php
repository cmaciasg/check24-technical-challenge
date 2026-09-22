<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Infra\Observability;

use App\Comparator\CarInsurance\Application\Compare\ComparisonMetrics;
use App\Comparator\CarInsurance\Application\Compare\ComparisonOutcome;
use App\Comparator\CarInsurance\Application\Compare\ProviderFailureReason;
use App\Comparator\CarInsurance\Application\Compare\ProviderPort;
use App\Comparator\CarInsurance\Application\Compare\ProviderResult;
use App\Comparator\CarInsurance\Domain\ValueObjects\UsageType;
use App\Comparator\CarInsurance\Domain\ValueObjects\VehicleType;
use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\Histogram;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Único sitio con nombres de métrica, etiquetas y tramos (observability.md §3).
 * Las etiquetas salen siempre de enums o de ids de provider: conjuntos cerrados.
 */
final readonly class PrometheusComparisonMetrics implements ComparisonMetrics
{
    private const PROVIDER_OK = 'ok';

    /** @param iterable<ProviderPort> $providers */
    public function __construct(
        private CollectorRegistry $registry,
        #[AutowireIterator(ProviderPort::TAG)]
        private iterable $providers,
    ) {
    }

    public function quoteRequested(VehicleType $vehicleType, UsageType $usageType): void
    {
        $this->quotes()->inc([$vehicleType->value, $usageType->value]);
    }

    public function providerCallFinished(ProviderResult $result): void
    {
        $provider = $result->providerId->value;
        $outcome = $result->failureReason->value ?? self::PROVIDER_OK;

        $this->providerCalls()->inc([$provider, $outcome]);
        $this->providerDuration()->observe($result->elapsedMs / 1000, [$provider, $result->success ? 'ok' : 'failed']);
    }

    public function campaignDiscountApplied(int $discountCents): void
    {
        $this->campaignDiscount()->incBy($discountCents);
    }

    public function comparisonFinished(ComparisonOutcome $outcome, float $durationSeconds, ?float $overheadSeconds): void
    {
        $this->requests()->inc([$outcome->value]);
        $this->duration()->observe($durationSeconds);
        if (null !== $overheadSeconds) {
            $this->overhead()->observe($overheadSeconds);
        }
    }

    public function requestRejected(): void
    {
        $this->requests()->inc([ComparisonOutcome::INVALID->value]);
    }

    public function requestFailed(): void
    {
        $this->requests()->inc([ComparisonOutcome::ERROR->value]);
    }

    public function initializeSeries(): void
    {
        foreach (ComparisonOutcome::cases() as $outcome) {
            $this->requests()->incBy(0, [$outcome->value]);
        }

        $providerOutcomes = [self::PROVIDER_OK, ...array_map(static fn (ProviderFailureReason $r): string => $r->value, ProviderFailureReason::cases())];
        foreach ($this->providers as $provider) {
            foreach ($providerOutcomes as $outcome) {
                $this->providerCalls()->incBy(0, [$provider->id()->value, $outcome]);
            }
        }

        foreach (VehicleType::cases() as $vehicleType) {
            foreach (UsageType::cases() as $usageType) {
                $this->quotes()->incBy(0, [$vehicleType->value, $usageType->value]);
            }
        }

        $this->campaignDiscount()->incBy(0);
    }

    private function requests(): Counter
    {
        return $this->registry->getOrRegisterCounter('', 'comparison_requests_total', 'Comparisons by outcome.', ['outcome']);
    }

    private function providerCalls(): Counter
    {
        return $this->registry->getOrRegisterCounter('', 'provider_calls_total', 'Provider calls by provider and outcome.', ['provider', 'outcome']);
    }

    private function quotes(): Counter
    {
        return $this->registry->getOrRegisterCounter('', 'comparison_quotes_total', 'Valid quote requests by vehicle and usage type.', ['vehicle_type', 'usage_type']);
    }

    private function campaignDiscount(): Counter
    {
        return $this->registry->getOrRegisterCounter('', 'campaign_discount_cents_total', 'Cents of campaign discount over the offers shown.');
    }

    private function duration(): Histogram
    {
        return $this->registry->getOrRegisterHistogram('', 'comparison_duration_seconds', 'Duration of a valid comparison.', [], [0.5, 1, 1.25, 1.5, 1.75, 2, 2.15, 2.3, 2.5, 3, 5]);
    }

    private function providerDuration(): Histogram
    {
        return $this->registry->getOrRegisterHistogram('', 'provider_call_duration_seconds', 'Duration of a provider call.', ['provider', 'result'], [0.1, 0.2, 0.3, 0.4, 0.5, 1, 1.5, 1.8, 1.9, 2, 2.5]);
    }

    private function overhead(): Histogram
    {
        return $this->registry->getOrRegisterHistogram('', 'comparison_overhead_seconds', 'Own time of a comparison, outside the providers.', [], [0.01, 0.025, 0.05, 0.1, 0.15, 0.25, 0.5, 1]);
    }
}
