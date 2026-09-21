<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Infra\ThirdParty;

use App\Comparator\CarInsurance\Application\Compare\PendingQuote;
use App\Comparator\CarInsurance\Application\Compare\ProviderFailureReason;
use App\Comparator\CarInsurance\Application\Compare\ProviderPort;
use App\Comparator\CarInsurance\Application\Compare\ProviderResult;
use App\Comparator\CarInsurance\Application\Compare\ResolvedPendingQuote;
use App\Comparator\CarInsurance\Domain\Model\ComparisonOffer;
use App\Comparator\CarInsurance\Domain\Model\QuoteRequest;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Las cuatro responsabilidades de todo adapter (providers.md §2), en este orden:
 * traducir el QuoteRequest, invocar al provider, traducir la respuesta y no dejar
 * escapar ninguna excepción. Las dos primeras ocurren en start(), la tercera en collect()
 * y la cuarta en ambos. Cada adapter concreto solo aporta el paso 1 y el 3.
 */
abstract class HttpProviderAdapter implements ProviderPort
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%app.providers.timeout_ms%')]
        private readonly int $timeoutMs,
        #[Autowire('%app.providers.base_url%')]
        private readonly string $baseUrl,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return array<string, mixed> */
    abstract protected function buildPayload(QuoteRequest $request): array;

    /**
     * @param array<mixed> $response
     *
     * @throws MalformedProviderResponse
     */
    abstract protected function buildOffer(array $response): ComparisonOffer;

    final public function start(QuoteRequest $request): PendingQuote
    {
        $payload = $this->buildPayload($request);
        $startedAt = $this->clock->now();

        try {
            $response = $this->httpClient->request('POST', $this->endpoint(), [
                'json' => $payload,
                // `max_duration` es un techo total desde ahora que hace cumplir el transporte;
                // `timeout` solo corta por inactividad y no sirve como techo (providers.md §5.1).
                'max_duration' => $this->timeoutMs / 1000,
            ]);
        } catch (\Throwable $e) {
            return new ResolvedPendingQuote($this->failureFor($e, null, $startedAt));
        }

        return new LazyPendingQuote(fn (): ProviderResult => $this->collectResponse($response, $startedAt));
    }

    private function collectResponse(ResponseInterface $response, \DateTimeImmutable $startedAt): ProviderResult
    {
        try {
            if ($response->getStatusCode() >= 300 || $response->getStatusCode() < 200) {
                return ProviderResult::failure($this->id(), $this->name(), ProviderFailureReason::HTTP_ERROR, $this->elapsedMs($response, $this->elapsedSinceMs($startedAt)));
            }

            $decoded = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new MalformedProviderResponse('The response body is not a JSON object.');
            }

            $offer = $this->buildOffer($decoded);
        } catch (\Throwable $e) {
            return $this->failureFor($e, $response, $startedAt);
        }

        return ProviderResult::success($this->id(), $this->name(), $offer, $this->elapsedMs($response, $this->elapsedSinceMs($startedAt)));
    }

    private function failureFor(\Throwable $e, ?ResponseInterface $response, \DateTimeImmutable $startedAt): ProviderResult
    {
        $sinceMs = $this->elapsedSinceMs($startedAt);
        if ($e instanceof TransportExceptionInterface) {
            // Sin depender del texto de la excepción (providers.md §1.1): timeout si ya venció el techo.
            $reason = $sinceMs >= $this->timeoutMs ? ProviderFailureReason::TIMEOUT : ProviderFailureReason::UNKNOWN_ERROR;
        } elseif ($e instanceof MalformedProviderResponse || $e instanceof \JsonException || $e instanceof \InvalidArgumentException) {
            $reason = ProviderFailureReason::MALFORMED_RESPONSE;
        } else {
            $reason = ProviderFailureReason::UNKNOWN_ERROR;
        }

        return ProviderResult::failure($this->id(), $this->name(), $reason, $this->elapsedMs($response, $sinceMs));
    }

    private function endpoint(): string
    {
        return sprintf('%s/_internal/simulated-providers/%s/quote', rtrim($this->baseUrl, '/'), $this->id()->value);
    }

    /**
     * Las respuestas se recogen en orden, así que el reloj de la aplicación inflaría la latencia de una
     * respuesta rápida recogida tras una lenta: se usa el `total_time` del transporte y, si no lo informa
     * (ausente o no positivo), la diferencia con el reloj (providers.md §1.2).
     */
    private function elapsedMs(?ResponseInterface $response, float $sinceMs): int
    {
        $totalTime = null;
        try {
            $totalTime = $response?->getInfo('total_time');
        } catch (\Throwable) {
        }

        return is_float($totalTime) && $totalTime > 0 ? (int) round($totalTime * 1000) : (int) round($sinceMs);
    }

    private function elapsedSinceMs(\DateTimeImmutable $startedAt): float
    {
        return (float) $this->clock->now()->format('Uv') - (float) $startedAt->format('Uv');
    }
}
