<?php

declare(strict_types=1);

namespace App\Tests\Unit\Comparator\CarInsurance\Infra\ThirdParty;

use App\Comparator\CarInsurance\Application\Compare\ProviderFailureReason;
use App\Comparator\CarInsurance\Application\Compare\ProviderPort;
use App\Comparator\CarInsurance\Domain\ValueObjects\CoverageLevel;
use App\Comparator\CarInsurance\Infra\ThirdParty\ProviderC\ProviderCAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;

final class ProviderCAdapterTest extends AdapterTestCase
{
    protected function providerDirectory(): string
    {
        return 'ProviderC';
    }

    protected function createAdapter(MockHttpClient $client): ProviderPort
    {
        return new ProviderCAdapter($client, self::TIMEOUT_MS, 'http://backend');
    }

    public function testDeclaresItsIdentity(): void
    {
        $adapter = $this->createAdapter(new MockHttpClient());

        self::assertSame('provider-c', $adapter->id()->value);
        self::assertSame('Provider C', $adapter->name()->value);
    }

    public function testTranslatesTheQuoteRequestToTheProviderPayload(): void
    {
        $this->quoteFixture('success.json');

        self::assertSame([
            'customer' => ['dob' => '1990-05-14'],
            'asset' => ['vehicleType' => 'SEDAN', 'usageType' => 'PRIVATE'],
        ], $this->sentPayload);
    }

    public function testConvertsTheTextAmountToCents(): void
    {
        $this->assertOffer($this->quoteFixture('success.json'), 'C-551a', 3990, CoverageLevel::BASIC);
    }

    public function testConvertsDecimalAmountsRoundingInsteadOfTruncating(): void
    {
        $this->assertOffer($this->quoteFixture('success-19-99.json'), 'C-0001', 1999, CoverageLevel::STANDARD);
    }

    public function testMapsEveryCoverageLevel(): void
    {
        $this->assertOffer($this->quoteFixture('success-top.json'), 'C-0002', 12000, CoverageLevel::PREMIUM);
    }

    public function testErrorStatusWithAJsonBodyBecomesAnHttpError(): void
    {
        $this->assertFailure($this->quoteFixture('error.json', 500), ProviderFailureReason::HTTP_ERROR);
    }

    #[DataProvider('malformedFixtures')]
    public function testMalformedResponsesBecomeAMalformedFailure(string $fixture): void
    {
        $this->assertFailure($this->quoteFixture($fixture), ProviderFailureReason::MALFORMED_RESPONSE);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedFixtures(): iterable
    {
        yield 'empty quotes list' => ['malformed-empty-quotes.json'];
        yield 'missing amount' => ['malformed-missing-amount.json'];
        yield 'amount is not a decimal' => ['malformed-amount-not-decimal.json'];
        yield 'unknown level' => ['malformed-unknown-level.json'];
        yield 'truncated json' => ['malformed-truncated.txt'];
    }
}
