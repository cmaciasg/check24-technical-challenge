<?php

declare(strict_types=1);

namespace App\ProviderSimulation\ProviderA;

use App\ProviderSimulation\SimulatedOutcome;
use App\ProviderSimulation\SimulatedOutcomeKind;
use App\ProviderSimulation\SimulatedProvider;
use App\ProviderSimulation\SimulatedResponse;
use App\ProviderSimulation\SimulatedTariff;

/** Provider A: rápido y fiable; JSON plano con el importe en unidades decimales. */
final class ProviderASimulator implements SimulatedProvider
{
    public function id(): string
    {
        return 'provider-a';
    }

    public function respond(array $payload, SimulatedOutcome $outcome): SimulatedResponse
    {
        if (SimulatedOutcomeKind::MALFORMED === $outcome->kind) {
            return new SimulatedResponse(200, '{"truncated": ', $outcome->latencyMs, $outcome->kind);
        }

        $cents = SimulatedTariff::cents(
            (string) ($payload['applicant']['birthDate'] ?? ''),
            (string) ($payload['vehicle']['type'] ?? ''),
            'COMMERCIAL' === ($payload['vehicle']['usage'] ?? ''),
            1.0,
        );
        $fingerprint = SimulatedTariff::fingerprint($payload);

        return new SimulatedResponse(200, json_encode([
            'quoteReference' => 'A-'.substr($fingerprint, 0, 6),
            'premium' => ['amount' => $cents / 100, 'currencyCode' => 'EUR'],
            'coverageTier' => 'STANDARD',
        ], JSON_THROW_ON_ERROR), $outcome->latencyMs, $outcome->kind);
    }
}
