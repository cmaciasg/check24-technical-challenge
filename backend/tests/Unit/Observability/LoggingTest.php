<?php

declare(strict_types=1);

namespace App\Tests\Unit\Observability;

use App\Observability\Logging\JsonLineFormatter;
use App\Observability\RequestIdHolder;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LoggingTest extends TestCase
{
    public function testFormatsOneJsonLineWithCommonFieldsAndContextAtTheFirstLevel(): void
    {
        $record = new LogRecord(
            new \DateTimeImmutable('2026-09-22 10:15:03.412', new \DateTimeZone('UTC')),
            'app',
            Level::Warning,
            'provider.call.failed',
            ['provider_id' => 'provider-c', 'reason' => 'timeout', 'elapsed_ms' => 2001],
            ['request_id' => 'abc-12345678'],
        );

        $line = (new JsonLineFormatter())->format($record);

        self::assertStringEndsWith("\n", $line);
        self::assertSame(1, substr_count($line, "\n"));
        self::assertSame([
            'timestamp' => '2026-09-22T10:15:03.412Z',
            'level' => 'WARNING',
            'event' => 'provider.call.failed',
            'request_id' => 'abc-12345678',
            'provider_id' => 'provider-c',
            'reason' => 'timeout',
            'elapsed_ms' => 2001,
        ], json_decode($line, true));
    }

    public function testConvertsTheTimestampToUtc(): void
    {
        $record = new LogRecord(new \DateTimeImmutable('2026-09-22 12:00:00.000', new \DateTimeZone('Europe/Madrid')), 'app', Level::Info, 'x', [], []);

        self::assertSame('2026-09-22T10:00:00.000Z', json_decode((new JsonLineFormatter())->format($record), true)['timestamp']);
    }

    public function testKeepsAnIncomingRequestIdWithTheAcceptedFormat(): void
    {
        self::assertSame('req-12345678', (new RequestIdHolder())->startRequest('req-12345678'));
    }

    #[DataProvider('rejectedRequestIds')]
    public function testGeneratesAUuidWhenTheIncomingRequestIdHasAnotherFormat(?string $incoming): void
    {
        $id = (new RequestIdHolder())->startRequest($incoming);

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
    }

    /** @return iterable<string, array{?string}> */
    public static function rejectedRequestIds(): iterable
    {
        yield 'absent' => [null];
        yield 'too short' => ['abc'];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'free text with spaces' => ['hello world 12345'];
        yield 'log injection attempt' => ["abcdefgh\n{\"level\":\"ERROR\"}"];
    }

    public function testTheRequestIdIsStableWithinARequest(): void
    {
        $holder = new RequestIdHolder();
        $holder->startRequest(null);

        self::assertSame($holder->current(), $holder->current());
    }
}
