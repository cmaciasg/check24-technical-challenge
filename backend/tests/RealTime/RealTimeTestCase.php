<?php

declare(strict_types=1);

namespace App\Tests\RealTime;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Suite contra el stack real levantado con docker-compose.test.yml (make test-realtime):
 * servidor real, llamadas HTTP reales, tiempo real. Solo lo que no se puede probar con mocks.
 */
abstract class RealTimeTestCase extends TestCase
{
    protected const TIMEOUT_MS = 2000;

    protected function baseUrl(): string
    {
        return rtrim((string) (getenv('REALTIME_BASE_URL') ?: 'http://localhost:8080'), '/');
    }

    protected function marginMs(): int
    {
        return (int) (getenv('TEST_TIMING_MARGIN_MS') ?: 150);
    }

    /** @return array{status: int, elapsedMs: float, body: array<string, mixed>} */
    protected function compare(): array
    {
        $client = HttpClient::create();
        $startedAt = hrtime(true);

        try {
            $response = $client->request('POST', $this->baseUrl().'/comparison', [
                'json' => ['birthDate' => '1990-05-14', 'vehicleType' => 'SEDAN', 'usageType' => 'PRIVATE'],
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (\Throwable $e) {
            self::fail(sprintf('The test stack is not reachable at %s (start it with `make test-realtime`): %s', $this->baseUrl(), $e->getMessage()));
        }

        return ['status' => $status, 'elapsedMs' => (hrtime(true) - $startedAt) / 1_000_000, 'body' => $body];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function statusesById(array $body): array
    {
        $byId = [];
        foreach ($body['providerStatuses'] as $status) {
            $byId[$status['providerId']] = $status;
        }

        return $byId;
    }
}
