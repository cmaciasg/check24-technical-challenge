<?php

declare(strict_types=1);

namespace App\Tests\Unit\Comparator\CarInsurance\Infra\ThirdParty;

use App\Comparator\CarInsurance\Application\Compare\ProviderFailureReason;
use App\Comparator\CarInsurance\Application\Compare\ProviderPort;
use App\Comparator\CarInsurance\Domain\ValueObjects\CoverageLevel;
use App\Comparator\CarInsurance\Domain\ValueObjects\UsageType;
use App\Comparator\CarInsurance\Domain\ValueObjects\VehicleType;
use App\Comparator\CarInsurance\Infra\ThirdParty\ProviderA\ProviderAAdapter;
use App\Tests\Support\QuoteRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProviderAAdapterTest extends AdapterTestCase
{
    protected function providerDirectory(): string
    {
        return 'ProviderA';
    }

    protected function createAdapter(MockHttpClient $client): ProviderPort
    {
        return new ProviderAAdapter($client, self::TIMEOUT_MS, 'http://backend');
    }

    public function testDeclaresItsIdentity(): void
    {
        $adapter = $this->createAdapter(new MockHttpClient());

        self::assertSame('provider-a', $adapter->id()->value);
        self::assertSame('Provider A', $adapter->name()->value);
    }

    public function testTranslatesTheQuoteRequestToTheProviderPayload(): void
    {
        $this->quoteFixture('success.json');

        self::assertSame([
            'applicant' => ['birthDate' => '1990-05-14'],
            'vehicle' => ['type' => 'SEDAN', 'usage' => 'PRIVATE'],
        ], $this->sentPayload);
    }

    #[DataProvider('vehicleAndUsageCombinations')]
    public function testTranslatesEveryVehicleTypeAndUsage(VehicleType $vehicleType, UsageType $usageType): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->sentPayload = json_decode($options['body'], true);

            return new MockResponse($this->fixture('success.json'));
        });

        $this->createAdapter($client)->quote(QuoteRequests::valid(vehicleType: $vehicleType, usageType: $usageType));

        self::assertSame($vehicleType->value, $this->sentPayload['vehicle']['type']);
        self::assertSame($usageType->value, $this->sentPayload['vehicle']['usage']);
    }

    /** @return iterable<string, array{VehicleType, UsageType}> */
    public static function vehicleAndUsageCombinations(): iterable
    {
        foreach (VehicleType::cases() as $vehicleType) {
            foreach (UsageType::cases() as $usageType) {
                yield $vehicleType->value.' '.$usageType->value => [$vehicleType, $usageType];
            }
        }
    }

    public function testTranslatesASuccessfulResponse(): void
    {
        $this->assertOffer($this->quoteFixture('success.json'), 'A-7f3c1e', 4250, CoverageLevel::STANDARD);
    }

    public function testConvertsDecimalAmountsRoundingInsteadOfTruncating(): void
    {
        // (int) (19.99 * 100) daría 1998 por la coma flotante.
        $this->assertOffer($this->quoteFixture('success-19-99.json'), 'A-000001', 1999, CoverageLevel::BASIC);
    }

    public function testMapsEveryCoverageTier(): void
    {
        $this->assertOffer($this->quoteFixture('success-premium.json'), 'A-000002', 8000, CoverageLevel::PREMIUM);
    }

    #[DataProvider('malformedFixtures')]
    public function testMalformedResponsesBecomeAMalformedFailure(string $fixture): void
    {
        $this->assertFailure($this->quoteFixture($fixture), ProviderFailureReason::MALFORMED_RESPONSE);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedFixtures(): iterable
    {
        yield 'missing premium' => ['malformed-missing-premium.json'];
        yield 'unknown coverage tier' => ['malformed-unknown-tier.json'];
        yield 'negative amount' => ['malformed-negative-amount.json'];
        yield 'truncated json' => ['malformed-truncated.txt'];
    }
}
