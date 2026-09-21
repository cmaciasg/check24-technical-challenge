<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\ProviderSimulation\ProviderOutcomePicker;
use App\ProviderSimulation\SimulatedOutcome;
use App\ProviderSimulation\SimulatedOutcomeKind;

/** Fija el resultado exacto de cada provider simulado; los no forzados responden con normalidad. */
final class ForcedOutcomePicker implements ProviderOutcomePicker
{
    /** Cuántas veces se ha consultado a algún provider simulado (espía: cero si nunca se llegó a los providers). */
    public int $picks = 0;

    /** @var array<string, SimulatedOutcomeKind> */
    private array $forced = [];

    public function force(string $providerId, SimulatedOutcomeKind $kind): void
    {
        $this->forced[$providerId] = $kind;
    }

    public function forceAll(SimulatedOutcomeKind $kind): void
    {
        foreach (['provider-a', 'provider-b', 'provider-c'] as $providerId) {
            $this->force($providerId, $kind);
        }
    }

    public function pick(string $providerId): SimulatedOutcome
    {
        ++$this->picks;

        return new SimulatedOutcome($this->forced[$providerId] ?? SimulatedOutcomeKind::NORMAL, 0);
    }
}
