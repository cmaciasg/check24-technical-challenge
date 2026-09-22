<?php

declare(strict_types=1);

namespace App\ProviderSimulation;

/**
 * Implementación real del picker: decide el resultado con las probabilidades del perfil.
 * El generador de números se inyecta para poder probar las fronteras sin azar (TC-6).
 */
final readonly class RandomProviderOutcomePicker implements ProviderOutcomePicker
{
    private const ROLL_MAX = 9999;

    /** @var \Closure(int, int): int */
    private \Closure $randomInt;

    public function __construct(
        private SimulationProfiles $profiles,
        ?\Closure $randomInt = null,
    ) {
        $this->randomInt = $randomInt ?? random_int(...);
    }

    public function pick(string $providerId): SimulatedOutcome
    {
        $profile = $this->profiles->forProvider($providerId);
        // roll en centésimas de punto porcentual: 0..9999 equivale a 0,00 %..99,99 %
        $roll = ($this->randomInt)(0, self::ROLL_MAX);

        $spikeEnd = $profile->spikePercent * 100;
        $httpErrorEnd = $spikeEnd + $profile->httpErrorPercent * 100;
        $malformedEnd = $httpErrorEnd + $profile->malformedPercent * 100;

        $kind = match (true) {
            $roll < $spikeEnd => SimulatedOutcomeKind::SPIKE,
            $roll < $httpErrorEnd => SimulatedOutcomeKind::HTTP_ERROR,
            $roll < $malformedEnd => SimulatedOutcomeKind::MALFORMED,
            default => SimulatedOutcomeKind::NORMAL,
        };

        $range = SimulatedOutcomeKind::SPIKE === $kind ? $profile->spikeLatencyMs : $profile->normalLatencyMs;

        return new SimulatedOutcome($kind, ($this->randomInt)($range[0], $range[1]));
    }
}
