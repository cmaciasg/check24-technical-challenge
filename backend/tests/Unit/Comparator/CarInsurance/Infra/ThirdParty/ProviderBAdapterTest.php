<?php

declare(strict_types=1);

namespace App\Tests\Unit\Comparator\CarInsurance\Infra\ThirdParty;

use App\Comparator\CarInsurance\Application\Compare\ProviderFailureReason;
use App\Comparator\CarInsurance\Application\Compare\ProviderPort;
use App\Comparator\CarInsurance\Domain\ValueObjects\CoverageLevel;
use App\Comparator\CarInsurance\Domain\ValueObjects\UsageType;
use App\Comparator\CarInsurance\Domain\ValueObjects\VehicleType;
use App\Comparator\CarInsurance\Infra\ThirdParty\ProviderB\ProviderBAdapter;
use App\Tests\Support\QuoteRequests;
use App\Tests\Support\SteppingClock;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProviderBAdapterTest extends AdapterTestCase
{
    protected function providerDirectory(): string
    {
        return 'ProviderB';
    }

    protected function createAdapter(MockHttpClient $client, ?ClockInterface $clock = null): ProviderPort
    {
        return new ProviderBAdapter($client, self::TIMEOUT_MS, 'http://backend', $clock ?? new SteppingClock(100));
    }

    public function testDeclaresItsIdentity(): void
    {
        $adapter = $this->createAdapter(new MockHttpClient());

        self::assertSame('provider-b', $adapter->id()->value);
        self::assertSame('Provider B', $adapter->name()->value);
    }

    public function testTranslatesTheQuoteRequestToTheProviderPayload(): void
    {
        $this->quoteFixture('success.json');

        self::assertSame([
            'driver_profile' => ['date_of_birth' => '1990-05-14'],
            'car_info' => ['category' => 'sedan', 'commercial_use' => false],
        ], $this->sentPayload);
    }

    #[DataProvider('vehicleCategories')]
    public function testTranslatesEveryVehicleType(VehicleType $vehicleType, string $category): void
    {
        $this->sendRequestFor($vehicleType, UsageType::PRIVATE);

        self::assertSame($category, $this->sentPayload['car_info']['category']);
    }

    /** @return iterable<string, array{VehicleType, string}> */
    public static function vehicleCategories(): iterable
    {
        yield 'sedan' => [VehicleType::SEDAN, 'sedan'];
        yield 'hatchback' => [VehicleType::HATCHBACK, 'hatchback'];
        yield 'suv' => [VehicleType::SUV, 'suv'];
        yield 'van' => [VehicleType::VAN, 'van'];
    }

    public function testTranslatesUsageTypeToTheCommercialUseFlag(): void
    {
        $this->sendRequestFor(VehicleType::SEDAN, UsageType::COMMERCIAL);
        self::assertTrue($this->sentPayload['car_info']['commercial_use']);

        $this->sendRequestFor(VehicleType::SEDAN, UsageType::PRIVATE);
        self::assertFalse($this->sentPayload['car_info']['commercial_use']);
    }

    public function testTranslatesASuccessfulResponse(): void
    {
        // premium_cents ya viene en céntimos: se copia tal cual.
        $this->assertOffer($this->quoteFixture('success.json'), 'B-9182', 4890, CoverageLevel::STANDARD);
    }

    public function testMapsEveryCoverageTier(): void
    {
        $this->assertOffer($this->quoteFixture('success-basic.json'), 'B-0001', 3100, CoverageLevel::BASIC);
        $this->assertOffer($this->quoteFixture('success-premium.json'), 'B-0002', 9900, CoverageLevel::PREMIUM);
    }

    #[DataProvider('malformedFixtures')]
    public function testMalformedResponsesBecomeAMalformedFailure(string $fixture): void
    {
        $this->assertFailure($this->quoteFixture($fixture), ProviderFailureReason::MALFORMED_RESPONSE);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedFixtures(): iterable
    {
        yield 'missing quote' => ['malformed-missing-quote.json'];
        yield 'cents as text' => ['malformed-cents-as-text.json'];
        yield 'unknown tier' => ['malformed-unknown-tier.json'];
    }

    private function sendRequestFor(VehicleType $vehicleType, UsageType $usageType): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->sentPayload = json_decode($options['body'], true);

            return new MockResponse($this->fixture('success.json'));
        });

        $this->createAdapter($client)->start(QuoteRequests::valid(vehicleType: $vehicleType, usageType: $usageType))->collect();
    }
}
