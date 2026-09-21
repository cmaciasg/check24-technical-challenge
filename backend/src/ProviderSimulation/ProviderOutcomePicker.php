<?php

declare(strict_types=1);

namespace App\ProviderSimulation;

/** Punto de sustitución del azar del simulador (providers.md §3.4). */
interface ProviderOutcomePicker
{
    public function pick(string $providerId): SimulatedOutcome;
}
