<?php

declare(strict_types=1);

namespace App\Tests\Unit\Comparator\CarInsurance\Infra\Observability;

use App\Comparator\CarInsurance\Application\Compare\ComparisonOutcome;
use App\Comparator\CarInsurance\Application\Compare\ProviderFailureReason;
use App\Comparator\CarInsurance\Domain\ValueObjects\UsageType;
use App\Comparator\CarInsurance\Domain\ValueObjects\VehicleType;
use App\Comparator\CarInsurance\Infra\Observability\PrometheusComparisonMetrics;
use App\Tests\Support\FakeProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

#[Group('TC-O1')]
final class PrometheusComparisonMetricsTest extends TestCase
{
    private CollectorRegistry $registry;
    private PrometheusComparisonMetrics $metrics;

    protected function setUp(): void
    {
        $this->registry = new CollectorRegistry(new InMemory(), false);
        $this->metrics = new PrometheusComparisonMetrics($this->registry, [
            FakeProvider::succeeding('provider-a', 1),
            FakeProvider::succeeding('provider-b', 1),
        ]);
    }

    public function testCountsComparisonsByOutcome(): void
    {
        $this->metrics->comparisonFinished(ComparisonOutcome::PARTIAL, 2.0, 0.01);
        $this->metrics->comparisonFinished(ComparisonOutcome::PARTIAL, 1.5, 0.02);
        $this->metrics->requestRejected();
        $this->metrics->requestFailed();

        $text = $this->render();
        self::assertStringContainsString('comparison_requests_total{outcome="partial"} 2', $text);
        self::assertStringContainsString('comparison_requests_total{outcome="invalid"} 1', $text);
        self::assertStringContainsString('comparison_requests_total{outcome="error"} 1', $text);
        self::assertStringContainsString('comparison_duration_seconds_count 2', $text);
        self::assertStringContainsString('comparison_overhead_seconds_count 2', $text);
    }

    public function testSkipsOverheadWhenThereIsNone(): void
    {
        $this->metrics->comparisonFinished(ComparisonOutcome::EMPTY, 0.1, null);

        self::assertStringNotContainsString('comparison_overhead_seconds_count', $this->render());
    }

    public function testRecordsProviderCallsAndDurationsByResult(): void
    {
        $this->metrics->providerCallFinished(FakeProvider::succeeding('provider-a', 1, 150)->quote(\App\Tests\Support\QuoteRequests::valid()));
        $this->metrics->providerCallFinished(FakeProvider::failing('provider-c', ProviderFailureReason::TIMEOUT, 2001)->quote(\App\Tests\Support\QuoteRequests::valid()));

        $text = $this->render();
        self::assertStringContainsString('provider_calls_total{provider="provider-a",outcome="ok"} 1', $text);
        self::assertStringContainsString('provider_calls_total{provider="provider-c",outcome="timeout"} 1', $text);
        self::assertStringContainsString('provider_call_duration_seconds_count{provider="provider-a",result="ok"} 1', $text);
        self::assertStringContainsString('provider_call_duration_seconds_count{provider="provider-c",result="failed"} 1', $text);
        self::assertStringContainsString('provider_call_duration_seconds_sum{provider="provider-c",result="failed"} 2.001', $text);
    }

    public function testCountsQuotesByVehicleAndUsageTypeAndCampaignDiscount(): void
    {
        $this->metrics->quoteRequested(VehicleType::SUV, UsageType::COMMERCIAL);
        $this->metrics->campaignDiscountApplied(425);
        $this->metrics->campaignDiscountApplied(75);

        $text = $this->render();
        self::assertStringContainsString('comparison_quotes_total{vehicle_type="SUV",usage_type="COMMERCIAL"} 1', $text);
        self::assertStringContainsString('campaign_discount_cents_total 500', $text);
    }

    public function testInitializesEveryKnownSeriesAtZero(): void
    {
        $this->metrics->initializeSeries();

        $text = $this->render();
        foreach (ComparisonOutcome::cases() as $outcome) {
            self::assertStringContainsString(sprintf('comparison_requests_total{outcome="%s"} 0', $outcome->value), $text);
        }
        foreach (['ok', 'timeout', 'http_error', 'malformed_response', 'unknown_error'] as $outcome) {
            self::assertStringContainsString(sprintf('provider_calls_total{provider="provider-b",outcome="%s"} 0', $outcome), $text);
        }
        self::assertSame(8, substr_count($text, 'comparison_quotes_total{'), 'four vehicle types x two usage types');
        self::assertStringContainsString('campaign_discount_cents_total 0', $text);
    }

    public function testInitializingDoesNotResetExistingValues(): void
    {
        $this->metrics->requestFailed();
        $this->metrics->initializeSeries();

        self::assertStringContainsString('comparison_requests_total{outcome="error"} 1', $this->render());
    }

    private function render(): string
    {
        return (new RenderTextFormat())->render($this->registry->getMetricFamilySamples());
    }
}
