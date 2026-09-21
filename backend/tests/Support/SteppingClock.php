<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Clock\ClockInterface;

/** Reloj falso que avanza una cantidad fija en cada lectura: permite fijar la duración medida. */
final class SteppingClock implements ClockInterface
{
    private \DateTimeImmutable $current;

    public function __construct(private readonly int $stepMs, string $start = '2026-09-22 10:00:00')
    {
        $this->current = new \DateTimeImmutable($start, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        $now = $this->current;
        $this->current = $this->current->modify(sprintf('+%d milliseconds', $this->stepMs));

        return $now;
    }
}
