<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Comparator\CarInsurance\Domain\Model\QuoteRequest;
use App\Comparator\CarInsurance\Domain\ValueObjects\DateOfBirth;
use App\Comparator\CarInsurance\Domain\ValueObjects\UsageType;
use App\Comparator\CarInsurance\Domain\ValueObjects\VehicleType;

final class QuoteRequests
{
    public const TODAY = '2026-09-22';

    public static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::TODAY.' 10:00:00', new \DateTimeZone('UTC'));
    }

    public static function valid(
        string $birthDate = '1990-05-14',
        VehicleType $vehicleType = VehicleType::SEDAN,
        UsageType $usageType = UsageType::PRIVATE,
    ): QuoteRequest {
        return new QuoteRequest(DateOfBirth::fromIsoString($birthDate, self::today()), $vehicleType, $usageType);
    }
}
