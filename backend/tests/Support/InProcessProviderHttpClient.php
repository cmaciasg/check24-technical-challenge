<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\ProviderSimulation\SimulatedOutcomeKind;
use App\ProviderSimulation\SimulatedProviderGateway;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Cliente HTTP que atiende la petición llamando al simulador dentro del mismo proceso.
 * Un pico de latencia se traduce en una TimeoutException inmediata: nadie espera nada.
 */
final class InProcessProviderHttpClient implements HttpClientInterface
{
    private readonly MockHttpClient $inner;

    public function __construct(SimulatedProviderGateway $gateway)
    {
        $this->inner = new MockHttpClient(static function (string $method, string $url, array $options) use ($gateway): MockResponse {
            preg_match('#/simulated-providers/([^/]+)/quote$#', $url, $matches);
            $simulated = $gateway->handle($matches[1] ?? '', json_decode($options['body'], true));
            if (null === $simulated) {
                return new MockResponse('', ['http_code' => 404]);
            }
            if (SimulatedOutcomeKind::SPIKE === $simulated->kind) {
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
