<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Application\Compare;

use App\Comparator\CarInsurance\Application\Campaign\CampaignPricingService;
use App\Comparator\CarInsurance\Domain\Model\QuoteRequest;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class CompareQuotes
{
    public function __construct(
        private readonly ComparisonService $comparisonService,
        private readonly CampaignPricingService $campaignPricing,
        private readonly ComparisonMetrics $metrics,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(QuoteRequest $request): CompareQuotesResult
    {
        $startedAt = $this->microtime();
        $this->metrics->quoteRequested($request->vehicleType, $request->usageType);

        $results = $this->comparisonService->compare($request);
        foreach ($results as $result) {
            $this->metrics->providerCallFinished($result);
            $this->logFailure($result);
        }

        $offers = [];
        foreach ($results as $result) {
            if (null !== $result->offer) {
                $offers[] = $result->offer;
            }
        }

        $pricedOffers = $this->campaignPricing->apply($offers);
        $discountCents = $this->totalDiscountCents($pricedOffers);
        if ($this->campaignPricing->isActive()) {
            $this->metrics->campaignDiscountApplied($discountCents);
        }

        $outcome = ComparisonOutcome::fromResults($results);
        $durationSeconds = $this->microtime() - $startedAt;
        $this->metrics->comparisonFinished($outcome, $durationSeconds, $this->overheadSeconds($results, $durationSeconds));
        $this->logCompleted($outcome, $durationSeconds, $results, $pricedOffers);

        return new CompareQuotesResult($pricedOffers, $results);
    }

    /** @param list<\App\Comparator\CarInsurance\Application\Campaign\PricedOffer> $pricedOffers */
    private function totalDiscountCents(array $pricedOffers): int
    {
        $total = 0;
        foreach ($pricedOffers as $priced) {
            if (null !== $priced->discountedPrice) {
                $total += $priced->offer->price->amountCents - $priced->discountedPrice->amountCents;
            }
        }

        return $total;
    }

    /** @param list<ProviderResult> $results */
    private function overheadSeconds(array $results, float $durationSeconds): ?float
    {
        if ([] === $results) {
            return null;
        }

        $slowestMs = max(array_map(static fn (ProviderResult $r): int => $r->elapsedMs, $results));

        return max(0.0, $durationSeconds - $slowestMs / 1000);
    }

    private function logFailure(ProviderResult $result): void
    {
        if ($result->success || null === $result->failureReason) {
            return;
        }

        $context = [
            'provider_id' => $result->providerId->value,
            'reason' => $result->failureReason->value,
            'elapsed_ms' => $result->elapsedMs,
        ];

        ProviderFailureReason::UNKNOWN_ERROR === $result->failureReason
            ? $this->logger->error('provider.call.failed', $context)
            : $this->logger->warning('provider.call.failed', $context);
    }

    /**
     * @param list<ProviderResult>                                                     $results
     * @param list<\App\Comparator\CarInsurance\Application\Campaign\PricedOffer>      $pricedOffers
     */
    private function logCompleted(ComparisonOutcome $outcome, float $durationSeconds, array $results, array $pricedOffers): void
    {
        $succeeded = count($pricedOffers);

        $this->logger->info('comparison.completed', [
            'outcome' => $outcome->value,
            'duration_ms' => (int) round($durationSeconds * 1000),
            'offers_count' => $succeeded,
            'providers_ok' => $succeeded,
            'providers_failed' => count($results) - $succeeded,
            'campaign_active' => $this->campaignPricing->isActive(),
        ]);
    }

    private function microtime(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
