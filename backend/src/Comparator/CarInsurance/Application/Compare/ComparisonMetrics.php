<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Application\Compare;

use App\Comparator\CarInsurance\Domain\ValueObjects\UsageType;
use App\Comparator\CarInsurance\Domain\ValueObjects\VehicleType;

/** Puerto de salida de observabilidad: registra hechos del dominio, sin nombres de métrica. */
interface ComparisonMetrics
{
    /** Una petición válida llega al caso de uso. */
    public function quoteRequested(VehicleType $vehicleType, UsageType $usageType): void;

    /** Un provider ha respondido (éxito o fallo). */
    public function providerCallFinished(ProviderResult $result): void;

    public function campaignDiscountApplied(int $discountCents): void;

    /** Fin de una comparación válida. El overhead lo calcula el llamador. */
    public function comparisonFinished(ComparisonOutcome $outcome, float $durationSeconds, ?float $overheadSeconds): void;

    /** 400, registrado fuera del caso de uso. */
    public function requestRejected(): void;

    /** 500, registrado fuera del caso de uso. */
    public function requestFailed(): void;

    /** Crea a 0 todas las series conocidas para que el primer incremento sea visible. */
    public function initializeSeries(): void;
}
