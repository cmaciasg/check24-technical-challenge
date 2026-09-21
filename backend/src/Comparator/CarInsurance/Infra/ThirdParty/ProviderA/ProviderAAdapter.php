<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Infra\ThirdParty\ProviderA;

use App\Comparator\CarInsurance\Domain\Model\ComparisonOffer;
use App\Comparator\CarInsurance\Domain\Model\QuoteRequest;
use App\Comparator\CarInsurance\Domain\ValueObjects\CoverageLevel;
use App\Comparator\CarInsurance\Domain\ValueObjects\Price;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderId;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderName;
use App\Comparator\CarInsurance\Infra\ThirdParty\HttpProviderAdapter;
use App\Comparator\CarInsurance\Infra\ThirdParty\MalformedProviderResponse;
use App\Comparator\CarInsurance\Infra\ThirdParty\ProviderPayload;

final class ProviderAAdapter extends HttpProviderAdapter
{
    public const ID = 'provider-a';
    public const NAME = 'Provider A';

    private const COVERAGE_TIERS = [
        'BASIC' => CoverageLevel::BASIC,
        'STANDARD' => CoverageLevel::STANDARD,
        'PREMIUM' => CoverageLevel::PREMIUM,
    ];

    public function id(): ProviderId
    {
        return ProviderId::fromString(self::ID);
    }

    public function name(): ProviderName
    {
        return ProviderName::fromString(self::NAME);
    }

    protected function buildPayload(QuoteRequest $request): array
    {
        return [
            'applicant' => ['birthDate' => $request->birthDate->toIsoString()],
            'vehicle' => [
                'type' => $request->vehicleType->value,
                'usage' => $request->usageType->value,
            ],
        ];
    }

    protected function buildOffer(array $response): ComparisonOffer
    {
        $tier = ProviderPayload::string($response, 'coverageTier');

        return new ComparisonOffer(
            $this->id(),
            $this->name(),
            ProviderPayload::string($response, 'quoteReference'),
            Price::ofCents(
                ProviderPayload::decimalAsCents($response, 'premium', 'amount'),
                ProviderPayload::string($response, 'premium', 'currencyCode'),
            ),
            self::COVERAGE_TIERS[$tier] ?? throw new MalformedProviderResponse('Unknown coverage tier.'),
        );
    }
}
