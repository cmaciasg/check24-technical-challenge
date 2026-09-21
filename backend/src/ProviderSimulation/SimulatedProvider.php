<?php

declare(strict_types=1);

namespace App\ProviderSimulation;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(SimulatedProvider::TAG)]
interface SimulatedProvider
{
    public const TAG = 'app.provider_simulation.provider';

    public function id(): string;

    /** @param array<mixed> $payload petición en el formato propio de este provider */
    public function respond(array $payload, SimulatedOutcome $outcome): SimulatedResponse;
}
