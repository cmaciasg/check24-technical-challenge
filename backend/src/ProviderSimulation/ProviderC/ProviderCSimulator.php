<?php

declare(strict_types=1);

namespace App\ProviderSimulation\ProviderC;

use App\ProviderSimulation\SimulatedOutcome;
use App\ProviderSimulation\SimulatedOutcomeKind;
use App\ProviderSimulation\SimulatedProvider;
use App\ProviderSimulation\SimulatedResponse;
use App\ProviderSimulation\SimulatedTariff;

/** Provider C: picos de latencia y fallos de status o de formato; lista anidada, importe como texto. */
final class ProviderCSimulator implements SimulatedProvider
{
    public function id(): string
    {
        return 'provider-c';
    }

    public function respond(array $payload, SimulatedOutcome $outcome): SimulatedResponse
    {
        return match ($outcome->kind) {
            SimulatedOutcomeKind::MALFORMED => new SimulatedResponse(200, $this->malformedBody($payload), $outcome->latencyMs, $outcome->kind),
            default => new SimulatedResponse(200, $this->validBody($payload), $outcome->latencyMs, $outcome->kind),
        };
    }

    /** @param array<mixed> $payload */
    private function validBody(array $payload): string
    {
        $cents = SimulatedTariff::cents(
            (string) ($payload['customer']['dob'] ?? ''),
            (string) ($payload['asset']['vehicleType'] ?? ''),
            'COMMERCIAL' === ($payload['asset']['usageType'] ?? ''),
            0.92,
        );

        return json_encode(['quotes' => [[
            'id' => 'C-'.substr(SimulatedTariff::fingerprint($payload), 0, 4),
            'amount' => number_format($cents / 100, 2, '.', ''),
            'currency' => 'EUR',
            'level' => 'BASIC',
        ]]], JSON_THROW_ON_ERROR);
    }

    /** @param array<mixed> $payload */
    private function malformedBody(array $payload): string
    {
        return match (random_int(0, 2)) {
            0 => '{"quotes": []}',
            1 => '{"quotes": [{"id": "C-0000", "currency": "EUR", "level": "BASIC"}]}',
            default => substr($this->validBody($payload), 0, 30),
        };
    }
}
