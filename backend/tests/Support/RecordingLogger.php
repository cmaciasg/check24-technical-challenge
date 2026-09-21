<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, event: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'event' => (string) $message, 'context' => $context];
    }

    /** @return list<array{level: string, event: string, context: array<string, mixed>}> */
    public function withEvent(string $event): array
    {
        return array_values(array_filter($this->records, static fn (array $r): bool => $r['event'] === $event));
    }
}
