<?php

declare(strict_types=1);

namespace App\ProviderSimulation;

/**
 * Tarifa ficticia y determinista que comparten los tres simuladores.
 * No pretende modelar tarifas reales (brief §1.1): solo dar precios distintos según los datos.
 */
final class SimulatedTariff
{
    private const BASE_BY_VEHICLE = ['SEDAN' => 42_00, 'HATCHBACK' => 38_00, 'SUV' => 55_00, 'VAN' => 61_00];

    public static function cents(string $birthDate, string $vehicleType, bool $commercial, float $providerFactor): int
    {
        $base = self::BASE_BY_VEHICLE[strtoupper($vehicleType)] ?? 50_00;
        $age = (new \DateTimeImmutable('today'))->diff(new \DateTimeImmutable($birthDate))->y;
        $ageFactor = match (true) {
            $age < 25 => 1.6,
            $age < 35 => 1.15,
            $age < 65 => 1.0,
            default => 1.25,
        };

        return (int) round($base * $ageFactor * ($commercial ? 1.3 : 1.0) * $providerFactor);
    }

    /** @param array<mixed> $payload */
    public static function fingerprint(array $payload): string
    {
        return md5(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
