<?php

declare(strict_types=1);

namespace App\ProviderSimulation;

/** Perfil de comportamiento de un provider simulado (parámetros app.providers.simulation.*). */
final readonly class SimulationProfile
{
    /**
     * @param array{int, int} $normalLatencyMs
     * @param array{int, int} $spikeLatencyMs
     */
    public function __construct(
        public array $normalLatencyMs,
        public array $spikeLatencyMs,
        public float $spikePercent,
        public float $httpErrorPercent,
        public float $malformedPercent,
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
