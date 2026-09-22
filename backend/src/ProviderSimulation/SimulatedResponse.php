<?php

declare(strict_types=1);

namespace App\ProviderSimulation;

final readonly class SimulatedResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public int $latencyMs,
        public SimulatedOutcomeKind $kind,
    ) {
    }
}
