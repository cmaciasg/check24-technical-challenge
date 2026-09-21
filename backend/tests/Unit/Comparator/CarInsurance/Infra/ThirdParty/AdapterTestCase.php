<?php

declare(strict_types=1);

namespace App\Tests\Unit\Comparator\CarInsurance\Infra\ThirdParty;

use App\Comparator\CarInsurance\Application\Compare\ProviderFailureReason;
use App\Comparator\CarInsurance\Application\Compare\ProviderPort;
use App\Comparator\CarInsurance\Application\Compare\ProviderResult;
use App\Comparator\CarInsurance\Domain\ValueObjects\CoverageLevel;
use App\Tests\Support\QuoteRequests;
use App\Tests\Support\SteppingClock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tests de traducción de un adapter con respuestas de ejemplo (TC-5): deterministas, sin simulador,
 * sin temporizadores ni concurrencia.
 */
#[Group('TC-5')]
abstract class AdapterTestCase extends TestCase
{
    protected const TIMEOUT_MS = 2000;

    /** @var array<string, mixed> petición capturada por el cliente HTTP falso */
    protected array $sentPayload = [];
    protected string $sentUrl = '';

    abstract protected function providerDirectory(): string;

    abstract protected function createAdapter(MockHttpClient $client, ?ClockInterface $clock = null): ProviderPort;

    protected function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../../../../Fixtures/'.$this->providerDirectory().'/'.$name);
    }

    protected function quoteWithResponse(MockResponse|\Closure $response, ?ClockInterface $clock = null): ProviderResult
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->sentUrl = $url;
            $this->sentPayload = json_decode($options['body'], true);

            return $response instanceof \Closure ? $response() : $response;
        });

        return $this->createAdapter($client, $clock)->start(QuoteRequests::valid())->collect();
    }

    protected function quoteFixture(string $fixture, int $status = 200): ProviderResult
    {
        return $this->quoteWithResponse(new MockResponse($this->fixture($fixture), ['http_code' => $status]));
    }

    protected function assertOffer(ProviderResult $result, string $reference, int $cents, CoverageLevel $level): void
    {
        self::assertTrue($result->success, 'Expected a successful result, got '.($result->failureReason->value ?? '?'));
        $offer = $result->offer;
        self::assertNotNull($offer);
        self::assertSame($reference, $offer->providerQuoteReference);
        self::assertSame($cents, $offer->price->amountCents);
        self::assertSame('EUR', $offer->price->currencyCode);
        self::assertSame($level, $offer->coverageLevel);
        self::assertSame($result->providerId->value, $offer->providerId->value);
        self::assertSame($result->providerName->value, $offer->providerName->value);
    }

    protected function assertFailure(ProviderResult $result, ProviderFailureReason $reason): void
    {
        self::assertFalse($result->success);
        self::assertNull($result->offer);
        self::assertSame($reason, $result->failureReason);
    }

    public function testTransportFailureAfterTheTimeoutHasElapsedBecomesATimeoutFailure(): void
    {
        $result = $this->quoteWithResponse(static fn () => throw new TransportException('Operation timed out'), new SteppingClock(self::TIMEOUT_MS));

        $this->assertFailure($result, ProviderFailureReason::TIMEOUT);
    }

    public function testTimeoutClassificationDoesNotDependOnTheExceptionType(): void
    {
        $result = $this->quoteWithResponse(static fn () => throw new TimeoutException('Idle timeout reached.'), new SteppingClock(self::TIMEOUT_MS + 1));

        $this->assertFailure($result, ProviderFailureReason::TIMEOUT);
    }

    public function testTransportFailureBeforeTheTimeoutHasElapsedBecomesAnUnknownError(): void
    {
        $result = $this->quoteWithResponse(static fn () => throw new TransportException('Connection refused'), new SteppingClock(self::TIMEOUT_MS - 1));

        $this->assertFailure($result, ProviderFailureReason::UNKNOWN_ERROR);
    }

    public function testTimeoutLikeTextWithoutElapsedTimeIsNotATimeout(): void
    {
        $result = $this->quoteWithResponse(static fn () => throw new TransportException('Operation timed out'), new SteppingClock(10));

        $this->assertFailure($result, ProviderFailureReason::UNKNOWN_ERROR);
    }

    #[Group('TC-7')]
    public function testElapsedTimeIsTheTransportTotalTimeRegardlessOfCollectionOrder(): void
    {
        $slow = $this->createAdapter(new MockHttpClient(new MockResponse($this->fixture('success.json'), ['total_time' => 1.5])), new SteppingClock(1500))->start(QuoteRequests::valid());
        $fast = $this->createAdapter(new MockHttpClient(new MockResponse($this->fixture('success.json'), ['total_time' => 0.3])), new SteppingClock(1500))->start(QuoteRequests::valid());

        $slowResult = $slow->collect();
        $fastResult = $fast->collect();

        self::assertSame(1500, $slowResult->elapsedMs);
        self::assertSame(300, $fastResult->elapsedMs);
    }

    #[Group('TC-7')]
    public function testElapsedTimeFallsBackToTheClockWhenTheTransportReportsNoTotalTime(): void
    {
        $result = $this->quoteWithResponse(new MockResponse($this->fixture('success.json'), ['total_time' => 0.0]), new SteppingClock(250));

        self::assertSame(250, $result->elapsedMs);
    }

    public function testNon2xxStatusBecomesAnHttpError(): void
    {
        $result = $this->quoteWithResponse(new MockResponse('{"error": "internal_error"}', ['http_code' => 500]));

        $this->assertFailure($result, ProviderFailureReason::HTTP_ERROR);
    }

    public function testEmptyBodyWithErrorStatusBecomesAnHttpError(): void
    {
        $result = $this->quoteWithResponse(new MockResponse('', ['http_code' => 503]));

        $this->assertFailure($result, ProviderFailureReason::HTTP_ERROR);
    }

    public function testNonJsonBodyBecomesAMalformedResponse(): void
    {
        $result = $this->quoteWithResponse(new MockResponse('<html>gateway</html>', ['http_code' => 200]));

        $this->assertFailure($result, ProviderFailureReason::MALFORMED_RESPONSE);
    }

    public function testJsonScalarBecomesAMalformedResponse(): void
    {
        $result = $this->quoteWithResponse(new MockResponse('"ok"', ['http_code' => 200]));

        $this->assertFailure($result, ProviderFailureReason::MALFORMED_RESPONSE);
    }

    public function testCallsTheSimulatedEndpointOfItsOwnId(): void
    {
        $this->quoteFixture('success.json');

        self::assertStringEndsWith('/_internal/simulated-providers/'.$this->createAdapter(new MockHttpClient())->id()->value.'/quote', $this->sentUrl);
    }
}
