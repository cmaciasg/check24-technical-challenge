<?php

declare(strict_types=1);

namespace App\ProviderSimulation;

/** Perfil de comportamiento de un provider simulado (parámetros app.providers.simulation.*). */
final class SimulationProfile
{
    /**
     * @param array{int, int} $normalLatencyMs
     * @param array{int, int} $spikeLatencyMs
     */
    public function __construct(
        public readonly array $normalLatencyMs,
        public readonly array $spikeLatencyMs,
        public readonly float $spikePercent,
        public readonly float $httpErrorPercent,
        public readonly float $malformedPercent,
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        return new self(
            $config['normal_latency_ms'],
            $config['spike_latency_ms'] ?? $config['normal_latency_ms'],
            (float) ($config['spike_percent'] ?? 0),
            (float) ($config['http_error_percent'] ?? 0),
            (float) ($config['malformed_percent'] ?? 0),
        );
    }
}
