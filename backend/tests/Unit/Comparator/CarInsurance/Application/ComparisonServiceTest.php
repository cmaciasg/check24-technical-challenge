<?php

declare(strict_types=1);

namespace App\Tests\Unit\Comparator\CarInsurance\Application;

use App\Comparator\CarInsurance\Application\Compare\ComparisonService;
use App\Comparator\CarInsurance\Application\Compare\PendingQuote;
use App\Comparator\CarInsurance\Application\Compare\ProviderFailureReason;
use App\Comparator\CarInsurance\Application\Compare\ProviderPort;
use App\Comparator\CarInsurance\Application\Compare\ProviderResult;
use App\Comparator\CarInsurance\Domain\Model\QuoteRequest;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderId;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderName;
use App\Tests\Support\FakePendingQuote;
use App\Tests\Support\FakeProvider;
use App\Tests\Support\QuoteRequests;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ComparisonServiceTest extends TestCase
{
    #[Group('TC-1')]
    public function testAFailingProviderDoesNotStopTheOthers(): void
    {
        $service = new ComparisonService([
            FakeProvider::succeeding('provider-a', 4250),
            FakeProvider::succeeding('provider-b', 4890),
            FakeProvider::failing('provider-c', ProviderFailureReason::HTTP_ERROR),
        ]);

        $results = $service->compare(QuoteRequests::valid());

        self::assertCount(3, $results);
        self::assertTrue($results[0]->success);
        self::assertTrue($results[1]->success);
        self::assertFalse($results[2]->success);
        self::assertSame(ProviderFailureReason::HTTP_ERROR, $results[2]->failureReason);
        self::assertSame(4250, $results[0]->offer?->price->amountCents);
        self::assertSame(4890, $results[1]->offer?->price->amountCents);
    }

    #[Group('TC-3')]
    public function testReturnsThreeFailuresWithoutThrowingWhenEveryProviderFails(): void
    {
        $service = new ComparisonService([
            FakeProvider::failing('provider-a', ProviderFailureReason::TIMEOUT),
            FakeProvider::failing('provider-b', ProviderFailureReason::MALFORMED_RESPONSE),
            FakeProvider::failing('provider-c', ProviderFailureReason::UNKNOWN_ERROR),
        ]);

        $results = $service->compare(QuoteRequests::valid());

        self::assertCount(3, $results);
        self::assertSame([false, false, false], array_map(static fn (ProviderResult $r): bool => $r->success, $results));
    }

    public function testReturnsOneResultPerProviderInRegistrationOrder(): void
    {
        $service = new ComparisonService([
            FakeProvider::succeeding('provider-a', 1),
            FakeProvider::succeeding('provider-b', 2),
        ]);

        $ids = array_map(static fn (ProviderResult $r): string => $r->providerId->value, $service->compare(QuoteRequests::valid()));

        self::assertSame(['provider-a', 'provider-b'], $ids);
    }

    public function testEveryProviderIsQueriedExactlyOnce(): void
    {
        $providers = [FakeProvider::succeeding('provider-a', 1), FakeProvider::succeeding('provider-b', 2)];

        (new ComparisonService($providers))->compare(QuoteRequests::valid());

        self::assertSame([1, 1], array_map(static fn (FakeProvider $p): int => $p->calls, $providers));
    }

    public function testWorksWithNoProviders(): void
    {
        self::assertSame([], (new ComparisonService([]))->compare(QuoteRequests::valid()));
    }

    public function testAllRequestsAreInFlightBeforeAnyResponseIsAwaited(): void
    {
        $log = [];
        $providers = [];
        foreach (['provider-a', 'provider-b', 'provider-c'] as $id) {
            $providers[] = new class($id, static function (string $event) use (&$log): void {
                $log[] = $event;
            }) implements ProviderPort {
                public function __construct(private readonly string $id, private readonly \Closure $record)
                {
                }

                public function id(): ProviderId
                {
                    return ProviderId::fromString($this->id);
                }

                public function name(): ProviderName
                {
                    return ProviderName::fromString($this->id);
                }

                public function start(QuoteRequest $request): PendingQuote
                {
                    ($this->record)('launched '.$this->id);

                    return new FakePendingQuote(function (): ProviderResult {
                        ($this->record)('resolved '.$this->id);

                        return ProviderResult::failure($this->id(), $this->name(), ProviderFailureReason::TIMEOUT, 0);
                    });
                }
            };
        }

        (new ComparisonService($providers))->compare(QuoteRequests::valid());

        self::assertSame([
            'launched provider-a', 'launched provider-b', 'launched provider-c',
            'resolved provider-a', 'resolved provider-b', 'resolved provider-c',
        ], $log);
    }
}
