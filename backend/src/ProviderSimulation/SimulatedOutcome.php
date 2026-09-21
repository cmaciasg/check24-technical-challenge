<?php

declare(strict_types=1);

namespace App\ProviderSimulation;

/** Qué hará un provider simulado en una llamada concreta, y cuánto tardará en hacerlo. */
final class SimulatedOutcome
{
    public function __construct(
        public readonly SimulatedOutcomeKind $kind,
        public readonly int $latencyMs,
    ) {
    }
}
