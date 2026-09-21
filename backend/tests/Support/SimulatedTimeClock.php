<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Clock\ClockInterface;

/** Reloj real al que un test puede sumar tiempo: permite simular que una espera ha vencido sin esperarla. */
final class SimulatedTimeClock implements ClockInterface
{
    private int $offsetMs = 0;

    public function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify(sprintf('+%d milliseconds', $this->offsetMs));
    }

    public function advance(int $ms): void
    {
        $this->offsetMs += $ms;
    }
}
