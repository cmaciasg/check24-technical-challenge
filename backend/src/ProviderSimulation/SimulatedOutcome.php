<?php

declare(strict_types=1);

namespace App\ProviderSimulation;

/** Qué hará un provider simulado en una llamada concreta, y cuánto tardará en hacerlo. */
final readonly class SimulatedOutcome
{
    public function __construct(
        public SimulatedOutcomeKind $kind,
        public int $latencyMs,
    ) {
    }
}
