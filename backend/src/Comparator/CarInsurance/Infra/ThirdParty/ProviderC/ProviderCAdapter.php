<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Infra\ThirdParty\ProviderC;

use App\Comparator\CarInsurance\Domain\Model\ComparisonOffer;
use App\Comparator\CarInsurance\Domain\Model\QuoteRequest;
use App\Comparator\CarInsurance\Domain\ValueObjects\CoverageLevel;
use App\Comparator\CarInsurance\Domain\ValueObjects\Price;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderId;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderName;
use App\Comparator\CarInsurance\Infra\ThirdParty\HttpProviderAdapter;
use App\Comparator\CarInsurance\Infra\ThirdParty\MalformedProviderResponse;
use App\Comparator\CarInsurance\Infra\ThirdParty\ProviderPayload;

final class ProviderCAdapter extends HttpProviderAdapter
{
    public const ID = 'provider-c';
    public const NAME = 'Provider C';

    private const LEVELS = [
        'BASIC' => CoverageLevel::BASIC,
        'MID' => CoverageLevel::STANDARD,
        'TOP' => CoverageLevel::PREMIUM,
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
            'customer' => ['dob' => $request->birthDate->toIsoString()],
            'asset' => [
                'vehicleType' => $request->vehicleType->value,
                'usageType' => $request->usageType->value,
            ],
        ];
    }

    protected function buildOffer(array $response): ComparisonOffer
    {
        $quotes = $response['quotes'] ?? null;
        if (!is_array($quotes) || [] === $quotes || !is_array($quotes[0] ?? null)) {
            throw new MalformedProviderResponse('The response has no quotes.');
        }

        $quote = $quotes[0];
        $level = ProviderPayload::string($quote, 'level');

        return new ComparisonOffer(
            $this->id(),
            $this->name(),
            ProviderPayload::string($quote, 'id'),
            Price::ofCents(
                ProviderPayload::decimalAsCents($quote, 'amount'),
                ProviderPayload::string($quote, 'currency'),
            ),
            self::LEVELS[$level] ?? throw new MalformedProviderResponse('Unknown level.'),
        );
    }
}
