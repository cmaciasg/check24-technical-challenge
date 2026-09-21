<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Domain\Model;

use App\Comparator\CarInsurance\Domain\Exception\InvalidQuoteRequest;
use App\Comparator\CarInsurance\Domain\ValueObjects\DateOfBirth;
use App\Comparator\CarInsurance\Domain\ValueObjects\UsageType;
use App\Comparator\CarInsurance\Domain\ValueObjects\VehicleType;

final class QuoteRequest
{
    public function __construct(
        public readonly DateOfBirth $birthDate,
        public readonly VehicleType $vehicleType,
        public readonly UsageType $usageType,
    ) {
    }

    /**
     * Construye y valida a partir del cuerpo ya decodificado. Señala la primera
     * regla incumplida en el orden birthDate, vehicleType, usageType.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload, \DateTimeImmutable $today): self
    {
        $birthDate = DateOfBirth::fromIsoString(self::text($payload, 'birthDate'), $today);
        $vehicleType = VehicleType::tryFrom(self::text($payload, 'vehicleType'))
            ?? throw InvalidQuoteRequest::forField('vehicleType', 'not_in_catalog');
        $usageType = UsageType::tryFrom(self::text($payload, 'usageType'))
            ?? throw InvalidQuoteRequest::forField('usageType', 'not_in_catalog');

        return new self($birthDate, $vehicleType, $usageType);
    }

    /** @param array<string, mixed> $payload */
    private static function text(array $payload, string $field): string
    {
        if (!array_key_exists($field, $payload)) {
            throw InvalidQuoteRequest::forField($field, 'missing');
        }

        if (!is_string($payload[$field])) {
            throw InvalidQuoteRequest::forField($field, 'invalid_type');
        }

        return $payload[$field];
    }
}
