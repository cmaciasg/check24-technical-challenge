<?php

declare(strict_types=1);

namespace App\ProviderSimulation;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Decide y construye la respuesta de un provider simulado, sin esperar la latencia (eso lo hace quien sirve HTTP). */
final readonly class SimulatedProviderGateway
{
    /** @param iterable<SimulatedProvider> $providers */
    public function __construct(
        #[AutowireIterator(SimulatedProvider::TAG)]
        private iterable $providers,
        private ProviderOutcomePicker $picker,
    ) {
    }

    /** @param array<mixed> $payload */
    public function handle(string $providerId, array $payload): ?SimulatedResponse
    {
        foreach ($this->providers as $provider) {
            if ($provider->id() === $providerId) {
                $outcome = $this->picker->pick($providerId);

                // Un error HTTP es igual para cualquier partner; el resto lo resuelve cada simulador.
                return SimulatedOutcomeKind::HTTP_ERROR === $outcome->kind
                    ? new SimulatedResponse(500, '{"error": "internal_error"}', $outcome->latencyMs, $outcome->kind)
                    : $provider->respond($payload, $outcome);
            }
        }

        return null;
    }
}
