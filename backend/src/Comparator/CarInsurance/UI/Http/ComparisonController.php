<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\UI\Http;

use App\Comparator\CarInsurance\Application\Campaign\PricedOffer;
use App\Comparator\CarInsurance\Application\Compare\CompareQuotes;
use App\Comparator\CarInsurance\Application\Compare\ComparisonMetrics;
use App\Comparator\CarInsurance\Application\Compare\ProviderResult;
use App\Comparator\CarInsurance\Domain\Exception\InvalidQuoteRequest;
use App\Comparator\CarInsurance\Domain\Model\QuoteRequest;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Adaptador de entrada: solo traduce HTTP. Construye el QuoteRequest, invoca el caso de uso y serializa. */
final class ComparisonController
{
    public function __construct(
        private readonly CompareQuotes $compareQuotes,
        private readonly ComparisonMetrics $metrics,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/comparison', name: 'comparison', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $quoteRequest = QuoteRequest::fromPayload($this->decodeBody($request), \DateTimeImmutable::createFromInterface($this->clock->now()));
        } catch (InvalidQuoteRequest $e) {
            $this->metrics->requestRejected();
            $this->logger->info('comparison.rejected', ['field' => $e->field, 'rule' => $e->ruleCode]);

            return new JsonResponse(['error' => 'invalid_request', 'field' => $e->field, 'rule' => $e->ruleCode], Response::HTTP_BAD_REQUEST);
        }

        $result = ($this->compareQuotes)($quoteRequest);

        return new JsonResponse([
            'offers' => array_map($this->serializeOffer(...), $result->offers),
            'providerStatuses' => array_map($this->serializeStatus(...), $result->providerResults),
        ]);
    }

    /** @return array<string, mixed> */
    private function decodeBody(Request $request): array
    {
        $decoded = json_decode($request->getContent());
        if (!$decoded instanceof \stdClass) {
            throw InvalidQuoteRequest::invalidJson();
        }

        return json_decode((string) json_encode($decoded), true);
    }

    /** @return array<string, mixed> */
    private function serializeOffer(PricedOffer $priced): array
    {
        $offer = $priced->offer;
        $serialized = [
            'providerId' => $offer->providerId->value,
            'providerName' => $offer->providerName->value,
            'providerQuoteReference' => $offer->providerQuoteReference,
            'priceAmountCents' => $offer->price->amountCents,
        ];

        if (null !== $priced->discountedPrice) {
            $serialized['discountedPriceAmountCents'] = $priced->discountedPrice->amountCents;
            $serialized['campaignApplied'] = true;
        }

        return $serialized + [
            'currencyCode' => $offer->price->currencyCode,
            'coverageLevel' => $offer->coverageLevel->value,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeStatus(ProviderResult $result): array
    {
        $status = [
            'providerId' => $result->providerId->value,
            'providerName' => $result->providerName->value,
            'status' => $result->success ? 'ok' : 'failed',
        ];

        if (null !== $result->failureReason) {
            $status['reason'] = $result->failureReason->value;
        }

        return $status + ['elapsedMs' => $result->elapsedMs];
    }
}
