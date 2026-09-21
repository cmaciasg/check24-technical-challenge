<?php

declare(strict_types=1);

namespace App\ProviderSimulation\ProviderB;

use App\ProviderSimulation\SimulatedOutcome;
use App\ProviderSimulation\SimulatedOutcomeKind;
use App\ProviderSimulation\SimulatedProvider;
use App\ProviderSimulation\SimulatedResponse;
use App\ProviderSimulation\SimulatedTariff;

/** Provider B: siempre lento pero por debajo del timeout; envelope anidado, snake_case, importe en céntimos. */
final class ProviderBSimulator implements SimulatedProvider
{
    public function id(): string
    {
        return 'provider-b';
    }

    public function respond(array $payload, SimulatedOutcome $outcome): SimulatedResponse
    {
        if (SimulatedOutcomeKind::MALFORMED === $outcome->kind) {
            return new SimulatedResponse(200, '{"truncated": ', $outcome->latencyMs, $outcome->kind);
        }

        $cents = SimulatedTariff::cents(
            (string) ($payload['driver_profile']['date_of_birth'] ?? ''),
            (string) ($payload['car_info']['category'] ?? ''),
            true === ($payload['car_info']['commercial_use'] ?? false),
            1.12,
        );
        $fingerprint = SimulatedTariff::fingerprint($payload);

        return new SimulatedResponse(200, json_encode([
            'result' => ['quote' => [
                'ref' => 'B-'.str_pad((string) (hexdec(substr($fingerprint, 0, 4)) % 10000), 4, '0', STR_PAD_LEFT),
                'premium_cents' => $cents,
                'currency' => 'EUR',
                'tier' => 'PRM',
            ]],
            'meta' => ['processedInMs' => $outcome->latencyMs],
        ], JSON_THROW_ON_ERROR), $outcome->latencyMs, $outcome->kind);
    }
}
