<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Infra\ThirdParty\ProviderB;

use App\Comparator\CarInsurance\Domain\Model\ComparisonOffer;
use App\Comparator\CarInsurance\Domain\Model\QuoteRequest;
use App\Comparator\CarInsurance\Domain\ValueObjects\CoverageLevel;
use App\Comparator\CarInsurance\Domain\ValueObjects\Price;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderId;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderName;
use App\Comparator\CarInsurance\Domain\ValueObjects\UsageType;
use App\Comparator\CarInsurance\Domain\ValueObjects\VehicleType;
use App\Comparator\CarInsurance\Infra\ThirdParty\HttpProviderAdapter;
use App\Comparator\CarInsurance\Infra\ThirdParty\MalformedProviderResponse;
use App\Comparator\CarInsurance\Infra\ThirdParty\ProviderPayload;

final class ProviderBAdapter extends HttpProviderAdapter
{
    public const ID = 'provider-b';
    public const NAME = 'Provider B';

    private const TIERS = [
        'STD' => CoverageLevel::STANDARD,
        'BSC' => CoverageLevel::BASIC,
        'PRM' => CoverageLevel::PREMIUM,
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
            'driver_profile' => ['date_of_birth' => $request->birthDate->toIsoString()],
            'car_info' => [
                'category' => $this->category($request->vehicleType),
                'commercial_use' => UsageType::COMMERCIAL === $request->usageType,
            ],
        ];
    }

    protected function buildOffer(array $response): ComparisonOffer
    {
        $tier = ProviderPayload::string($response, 'result', 'quote', 'tier');

        return new ComparisonOffer(
            $this->id(),
            $this->name(),
            ProviderPayload::string($response, 'result', 'quote', 'ref'),
            Price::ofCents(
                ProviderPayload::nonNegativeInt($response, 'result', 'quote', 'premium_cents'),
                ProviderPayload::string($response, 'result', 'quote', 'currency'),
            ),
            self::TIERS[$tier] ?? throw new MalformedProviderResponse('Unknown tier.'),
        );
    }

    private function category(VehicleType $type): string
    {
        return match ($type) {
            VehicleType::SEDAN => 'sedan',
            VehicleType::HATCHBACK => 'hatchback',
            VehicleType::SUV => 'suv',
            VehicleType::VAN => 'van',
        };
    }
}
