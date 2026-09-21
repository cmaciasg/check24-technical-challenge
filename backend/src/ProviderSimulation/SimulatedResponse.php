<?php

declare(strict_types=1);

namespace App\ProviderSimulation;

final class SimulatedResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly int $latencyMs,
        public readonly SimulatedOutcomeKind $kind,
    ) {
    }
}
