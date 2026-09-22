<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\ProviderSimulation\SimulatedOutcomeKind;
use App\ProviderSimulation\SimulatedProviderGateway;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Cliente HTTP que atiende la petición llamando al simulador dentro del mismo proceso.
 * Un pico de latencia se traduce en una excepción inmediata y en un reloj adelantado hasta el techo: nadie espera nada.
 */
final readonly class InProcessProviderHttpClient implements HttpClientInterface
{
    private MockHttpClient $inner;

    public function __construct(SimulatedProviderGateway $gateway, SimulatedTimeClock $clock, #[Autowire('%app.providers.timeout_ms%')] int $timeoutMs)
    {
        $this->inner = new MockHttpClient(static function (string $method, string $url, array $options) use ($gateway, $clock, $timeoutMs): MockResponse {
            preg_match('#/simulated-providers/([^/]+)/quote$#', $url, $matches);
            $simulated = $gateway->handle($matches[1] ?? '', json_decode($options['body'], true));
            if (!$simulated instanceof \App\ProviderSimulation\SimulatedResponse) {
                return new MockResponse('', ['http_code' => 404]);
            }
            if (SimulatedOutcomeKind::SPIKE === $simulated->kind) {
                $clock->advance($timeoutMs);
                throw new TimeoutException('Simulated latency spike.');
            }

            return new MockResponse($simulated->body, ['http_code' => $simulated->status]);
        });
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return $this;
    }
}
