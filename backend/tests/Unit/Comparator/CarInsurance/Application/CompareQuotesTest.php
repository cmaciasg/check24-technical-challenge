<?php

declare(strict_types=1);

namespace App\Tests\Unit\Comparator\CarInsurance\Application;

use App\Comparator\CarInsurance\Application\Campaign\CampaignPricingService;
use App\Comparator\CarInsurance\Application\Compare\CompareQuotes;
use App\Comparator\CarInsurance\Application\Compare\ComparisonOutcome;
use App\Comparator\CarInsurance\Application\Compare\ComparisonService;
use App\Comparator\CarInsurance\Application\Compare\ProviderFailureReason;
use App\Comparator\CarInsurance\Domain\ValueObjects\UsageType;
use App\Comparator\CarInsurance\Domain\ValueObjects\VehicleType;
use App\Tests\Support\FakeProvider;
use App\Tests\Support\InMemoryComparisonMetrics;
use App\Tests\Support\QuoteRequests;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SteppingClock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class CompareQuotesTest extends TestCase
{
    private InMemoryComparisonMetrics $metrics;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->metrics = new InMemoryComparisonMetrics();
        $this->logger = new RecordingLogger();
    }

    #[Group('TC-O1')]
    public function testCompleteComparison(): void
    {
        $result = $this->compare([
            FakeProvider::succeeding('provider-a', 4250, 150),
            FakeProvider::succeeding('provider-b', 4890, 1500),
            FakeProvider::succeeding('provider-c', 3990, 300),
        ]);

        self::assertCount(3, $result->offers);
        self::assertCount(3, $result->providerResults);
        self::assertSame(ComparisonOutcome::COMPLETE, $this->metrics->finished[0][0]);
        self::assertSame([[VehicleType::SEDAN, UsageType::PRIVATE]], $this->metrics->quoteRequests);
        self::assertCount(3, $this->metrics->providerCalls);
    }

    #[Group('TC-O1')]
    public function testPartialComparison(): void
    {
        $result = $this->compare([
            FakeProvider::succeeding('provider-a', 4250),
            FakeProvider::succeeding('provider-b', 4890),
            FakeProvider::failing('provider-c', ProviderFailureReason::TIMEOUT),
        ]);

        self::assertCount(2, $result->offers);
        self::assertCount(3, $result->providerResults);
        self::assertSame(ComparisonOutcome::PARTIAL, $this->metrics->finished[0][0]);
    }

    #[Group('TC-O1')]
    #[Group('TC-3')]
    public function testEmptyComparisonWhenEveryProviderFails(): void
    {
        $result = $this->compare([
            FakeProvider::failing('provider-a', ProviderFailureReason::TIMEOUT),
            FakeProvider::failing('provider-b', ProviderFailureReason::HTTP_ERROR),
            FakeProvider::failing('provider-c', ProviderFailureReason::MALFORMED_RESPONSE),
        ]);

        self::assertSame([], $result->offers);
        self::assertCount(3, $result->providerResults);
        self::assertSame(ComparisonOutcome::EMPTY, $this->metrics->finished[0][0]);
    }

    #[Group('TC-O1')]
    public function testRecordsEveryProviderCallIncludingFailures(): void
    {
        $this->compare([
            FakeProvider::succeeding('provider-a', 4250, 150),
            FakeProvider::failing('provider-c', ProviderFailureReason::HTTP_ERROR, 210),
        ]);

        $recorded = array_map(
            static fn ($r): array => [$r->providerId->value, $r->success, $r->failureReason?->value, $r->elapsedMs],
            $this->metrics->providerCalls,
        );
        self::assertSame([['provider-a', true, null, 150], ['provider-c', false, 'http_error', 210]], $recorded);
    }

    #[Group('TC-O1')]
    public function testRecordsDurationAndOwnOverheadWithAFixedClock(): void
    {
        // El reloj avanza 2000 ms entre las dos lecturas; el provider más lento tardó 1500 ms.
        $this->compare([FakeProvider::succeeding('provider-a', 4250, 150), FakeProvider::succeeding('provider-b', 4890, 1500)], clockStepMs: 2000);

        [, $duration, $overhead] = $this->metrics->finished[0];
        self::assertEqualsWithDelta(2.0, $duration, 0.0001);
        self::assertEqualsWithDelta(0.5, $overhead, 0.0001);
    }

    #[Group('TC-O1')]
    public function testOverheadNeverGoesNegative(): void
    {
        $this->compare([FakeProvider::failing('provider-c', ProviderFailureReason::TIMEOUT, 5000)], clockStepMs: 2000);

        self::assertEqualsWithDelta(0.0, $this->metrics->finished[0][2], 0.0001);
    }

    #[Group('TC-O1')]
    public function testNoOverheadIsObservedWithoutProviderResults(): void
    {
        $this->compare([]);

        self::assertNull($this->metrics->finished[0][2]);
        self::assertSame(ComparisonOutcome::EMPTY, $this->metrics->finished[0][0]);
    }

    #[Group('TC-O1')]
    public function testSumsTheCampaignDiscountOnlyWhenTheCampaignIsActive(): void
    {
        $providers = [FakeProvider::succeeding('provider-a', 10000), FakeProvider::succeeding('provider-b', 5000)];

        $this->compare($providers, campaignActive: true);
        self::assertSame(1500, $this->metrics->discountCents, '10 % of 10000 + 10 % of 5000');

        $this->metrics = new InMemoryComparisonMetrics();
        $this->compare($providers, campaignActive: false);
        self::assertSame(0, $this->metrics->discountCents);
    }

    #[Group('TC-P5')]
    public function testAppliesTheCampaignToTheReturnedOffers(): void
    {
        $result = $this->compare([FakeProvider::succeeding('provider-a', 4250)], campaignActive: true);

        self::assertSame(3825, $result->offers[0]->discountedPrice?->amountCents);
    }

    public function testLogsOneSummaryLineWithoutPersonalData(): void
    {
        $this->compare([
            FakeProvider::succeeding('provider-a', 4250),
            FakeProvider::failing('provider-c', ProviderFailureReason::TIMEOUT, 2001),
        ], clockStepMs: 2004, campaignActive: true);

        $completed = $this->logger->withEvent('comparison.completed');
        self::assertCount(1, $completed);
        self::assertSame([
            'outcome' => 'partial',
            'duration_ms' => 2004,
            'offers_count' => 1,
            'providers_ok' => 1,
            'providers_failed' => 1,
            'campaign_active' => true,
        ], $completed[0]['context']);
    }

    public function testLogsAWarningPerFailedProviderAndNothingForSuccessfulOnes(): void
    {
        $this->compare([
            FakeProvider::succeeding('provider-a', 4250),
            FakeProvider::failing('provider-c', ProviderFailureReason::TIMEOUT, 2001),
        ]);

        $failed = $this->logger->withEvent('provider.call.failed');
        self::assertCount(1, $failed);
        self::assertSame('warning', $failed[0]['level']);
        self::assertSame(['provider_id' => 'provider-c', 'reason' => 'timeout', 'elapsed_ms' => 2001], $failed[0]['context']);
    }

    public function testAnUnknownErrorIsLoggedAtErrorLevel(): void
    {
        $this->compare([FakeProvider::failing('provider-c', ProviderFailureReason::UNKNOWN_ERROR)]);

        self::assertSame('error', $this->logger->withEvent('provider.call.failed')[0]['level']);
    }

    /** @param list<FakeProvider> $providers */
    private function compare(array $providers, int $clockStepMs = 1000, bool $campaignActive = false): \App\Comparator\CarInsurance\Application\Compare\CompareQuotesResult
    {
        $compareQuotes = new CompareQuotes(
            new ComparisonService($providers),
            new CampaignPricingService($campaignActive, 'PERCENTAGE', 10),
            $this->metrics,
            $this->logger,
            new SteppingClock($clockStepMs),
        );

        return $compareQuotes(QuoteRequests::valid());
    }
}
