<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Infra\ThirdParty;

use App\Comparator\CarInsurance\Application\Compare\ProviderFailureReason;
use App\Comparator\CarInsurance\Application\Compare\ProviderPort;
use App\Comparator\CarInsurance\Application\Compare\ProviderResult;
use App\Comparator\CarInsurance\Domain\Model\ComparisonOffer;
use App\Comparator\CarInsurance\Domain\Model\QuoteRequest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Las cuatro responsabilidades de todo adapter (providers.md §2), en este orden:
 * traducir el QuoteRequest, invocar al provider, traducir la respuesta y no dejar
 * escapar ninguna excepción. Cada adapter concreto solo aporta el paso 1 y el 3.
 */
abstract class HttpProviderAdapter implements ProviderPort
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%app.providers.timeout_ms%')]
        private readonly int $timeoutMs,
        #[Autowire('%app.providers.base_url%')]
        private readonly string $baseUrl,
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

    final public function quote(QuoteRequest $request): ProviderResult
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->httpClient->request('POST', $this->endpoint(), [
                'json' => $this->buildPayload($request),
                // `timeout` corta por inactividad; `max_duration` lo hace cumplir el transporte
                // como techo total aunque otras respuestas se esperen antes que esta.
                'timeout' => $this->timeoutMs / 1000,
                'max_duration' => $this->timeoutMs / 1000,
            ]);

            // La petición ya está en vuelo: cede el control para que el resto de providers lancen la suya.
            if (null !== \Fiber::getCurrent()) {
                \Fiber::suspend();
            }

            if ($response->getStatusCode() >= 300 || $response->getStatusCode() < 200) {
                return ProviderResult::failure($this->id(), $this->name(), ProviderFailureReason::HTTP_ERROR, $this->transferMs($response, $startedAt));
            }

            $decoded = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new MalformedProviderResponse('The response body is not a JSON object.');
            }

            return ProviderResult::success($this->id(), $this->name(), $this->buildOffer($decoded), $this->transferMs($response, $startedAt));
        } catch (TransportExceptionInterface $e) {
            return $this->failure($this->isTimeout($e, $startedAt) ? ProviderFailureReason::TIMEOUT : ProviderFailureReason::UNKNOWN_ERROR, $startedAt);
        } catch (MalformedProviderResponse|\JsonException|\InvalidArgumentException) {
            return $this->failure(ProviderFailureReason::MALFORMED_RESPONSE, $startedAt);
        } catch (\Throwable) {
            return $this->failure(ProviderFailureReason::UNKNOWN_ERROR, $startedAt);
        }
    }

    private function endpoint(): string
    {
        return sprintf('%s/_internal/simulated-providers/%s/quote', rtrim($this->baseUrl, '/'), $this->id()->value);
    }

    private function isTimeout(TransportExceptionInterface $e, int $startedAt): bool
    {
        // Con `max_duration` curl informa el timeout como TransportException genérica ("Operation timed out").
        return $e instanceof TimeoutExceptionInterface
            || str_contains(strtolower($e->getMessage()), 'timed out')
            || $this->elapsedMs($startedAt) >= $this->timeoutMs;
    }

    private function failure(ProviderFailureReason $reason, int $startedAt): ProviderResult
    {
        return ProviderResult::failure($this->id(), $this->name(), $reason, $this->elapsedMs($startedAt));
    }

    /**
     * Las respuestas se esperan en orden, así que el reloj de pared de una respuesta rápida que se
     * resuelve tarde incluiría la espera de otra: se prefiere el tiempo que mide el propio transporte.
     */
    private function transferMs(ResponseInterface $response, int $startedAt): int
    {
        $totalTime = $response->getInfo('total_time');

        return is_float($totalTime) && $totalTime > 0 ? (int) round($totalTime * 1000) : $this->elapsedMs($startedAt);
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
