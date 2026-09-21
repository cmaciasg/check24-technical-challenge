<?php

declare(strict_types=1);

namespace App\Tests\RealTime;

use PHPUnit\Framework\Attributes\Group;

/**
 * TC-4 (b): con el Provider B en su máximo documentado (1800 ms) el resultado es un éxito y no un
 * timeout: comprueba que el margen de 200 ms cubre el sobrecoste real de una llamada.
 * Requiere SIMULATION_LATENCY_MS=provider-b=1800.
 */
final class ProviderBMarginTest extends RealTimeTestCase
{
    #[Group('TC-4')]
    public function testProviderBAtItsMaximumLatencyDoesNotTimeOut(): void
    {
        $status = self::statusesById($this->compare()['body'])['provider-b'];

        self::assertSame('ok', $status['status']);
        self::assertArrayNotHasKey('reason', $status);
        self::assertGreaterThanOrEqual(1800, $status['elapsedMs']);
        self::assertLessThan(self::TIMEOUT_MS, $status['elapsedMs']);
    }
}
