<?php

declare(strict_types=1);

namespace App\Tests\RealTime;

use PHPUnit\Framework\Attributes\Group;

/**
 * Requiere el stack con SIMULATION_FORCE=provider-c=spike y
 * SIMULATION_LATENCY_MS=provider-b=1800,provider-c=3000 (lo levanta `make test-realtime`).
 */
final class TimeoutCeilingTest extends RealTimeTestCase
{
    #[Group('TC-2')]
    #[Group('TC-1')]
    public function testTheTotalTimeIsBoundedByTheTimeoutNotByTheSlowestProvider(): void
    {
        $result = $this->compare();

        self::assertSame(200, $result['status']);
        self::assertLessThan(
            self::TIMEOUT_MS + $this->marginMs(),
            $result['elapsedMs'],
            'The transport must cut the slow provider at the timeout; a 3000 ms spike must not be waited for.',
        );
        // Y las tres llamadas son concurrentes: en serie serían ≈ 150 + 1800 + 2000 ms.
        self::assertGreaterThan(1700, $result['elapsedMs'], 'Provider B is forced to 1800 ms, so a faster answer means the setup is wrong.');

        $statuses = self::statusesById($result['body']);
        self::assertSame('failed', $statuses['provider-c']['status']);
        self::assertSame('timeout', $statuses['provider-c']['reason']);
    }

    #[Group('TC-2')]
    #[Group('TC-1')]
    public function testTheOtherProvidersAreNotDelayedByTheSlowOne(): void
    {
        $statuses = self::statusesById($this->compare()['body']);

        self::assertSame('ok', $statuses['provider-a']['status']);
        self::assertSame('ok', $statuses['provider-b']['status']);
        self::assertGreaterThanOrEqual(80, $statuses['provider-a']['elapsedMs']);
        self::assertLessThanOrEqual(200 + $this->marginMs(), $statuses['provider-a']['elapsedMs']);
        self::assertGreaterThanOrEqual(1200, $statuses['provider-b']['elapsedMs']);
        self::assertLessThanOrEqual(1800 + $this->marginMs(), $statuses['provider-b']['elapsedMs']);
    }

    #[Group('TC-2')]
    public function testTheOffersOfTheProvidersThatAnsweredAreStillReturned(): void
    {
        $offers = $this->compare()['body']['offers'];

        self::assertSame(['provider-a', 'provider-b'], array_column($offers, 'providerId'));
    }
}
