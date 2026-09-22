<?php

declare(strict_types=1);

namespace App\ProviderSimulation;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Endpoint interno que simula a un partner externo por HTTP real de loopback (architecture.md §3). */
final readonly class SimulatedProviderController
{
    public function __construct(private SimulatedProviderGateway $gateway)
    {
    }

    #[Route('/_internal/simulated-providers/{providerId}/quote', name: 'simulated_provider_quote', methods: ['POST'])]
    public function __invoke(string $providerId, Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        $simulated = $this->gateway->handle($providerId, $payload);
        if (!$simulated instanceof \App\ProviderSimulation\SimulatedResponse) {
            return new JsonResponse(['error' => 'unknown_provider'], Response::HTTP_NOT_FOUND);
        }

        usleep($simulated->latencyMs * 1000);

        return new Response($simulated->body, $simulated->status, ['Content-Type' => 'application/json']);
    }
}
