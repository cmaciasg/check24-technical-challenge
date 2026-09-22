<?php

declare(strict_types=1);

namespace App\ProviderSimulation;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Permite fijar por variable de entorno el resultado y la latencia de cada provider
 * (stack de test con servidor real, testing.md §3.4), sin tocar código:
 *
 *   SIMULATION_FORCE="provider-c=spike,provider-a=normal"   (normal|spike|http_error|malformed)
 *   SIMULATION_LATENCY_MS="provider-b=1800,provider-c=3000"
 *
 * Sin variables definidas delega en el picker real.
 */
#[AsDecorator(decorates: RandomProviderOutcomePicker::class)]
final readonly class EnvironmentOverridePicker implements ProviderOutcomePicker
{
    /** @var array<string, string> */
    private array $forcedKinds;
    /** @var array<string, string> */
    private array $forcedLatencies;

    public function __construct(
        private ProviderOutcomePicker $inner,
        private SimulationProfiles $profiles,
        #[Autowire('%env(string:SIMULATION_FORCE)%')]
        string $force,
        #[Autowire('%env(string:SIMULATION_LATENCY_MS)%')]
        string $latency,
    ) {
        $this->forcedKinds = $this->parse($force);
        $this->forcedLatencies = $this->parse($latency);
    }

    public function pick(string $providerId): SimulatedOutcome
    {
        $picked = $this->inner->pick($providerId);
        $kind = isset($this->forcedKinds[$providerId])
            ? SimulatedOutcomeKind::from($this->forcedKinds[$providerId])
            : $picked->kind;

        $latencyMs = match (true) {
            isset($this->forcedLatencies[$providerId]) => (int) $this->forcedLatencies[$providerId],
            $kind === $picked->kind => $picked->latencyMs,
            SimulatedOutcomeKind::SPIKE === $kind => $this->profiles->forProvider($providerId)->spikeLatencyMs[0],
            default => $this->profiles->forProvider($providerId)->normalLatencyMs[0],
        };

        return new SimulatedOutcome($kind, $latencyMs);
    }

    /** @return array<string, string> */
    private function parse(string $value): array
    {
        $parsed = [];
        foreach (explode(',', $value) as $pair) {
            [$id, $setting] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ('' !== $id && '' !== $setting) {
                $parsed[$id] = $setting;
            }
        }

        return $parsed;
    }
}
