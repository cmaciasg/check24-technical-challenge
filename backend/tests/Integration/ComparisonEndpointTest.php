<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\ProviderSimulation\SimulatedOutcomeKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

final class ComparisonEndpointTest extends ApiTestCase
{
    public function testReturnsOffersAndStatusesForAValidRequest(): void
    {
        $this->postComparison();

        self::assertResponseStatusCodeSame(200);
        $response = $this->json();
        self::assertSame(['offers', 'providerStatuses'], array_keys($response));
        self::assertCount(3, $response['offers']);
        self::assertCount(3, $response['providerStatuses']);

        $offer = $response['offers'][0];
        self::assertSame(
            ['providerId', 'providerName', 'providerQuoteReference', 'priceAmountCents', 'currencyCode', 'coverageLevel'],
            array_keys($offer),
        );
        self::assertSame('Provider A', $offer['providerName']);
        self::assertIsInt($offer['priceAmountCents']);
        self::assertSame('EUR', $offer['currencyCode']);

        $status = $response['providerStatuses'][0];
        self::assertSame(['providerId', 'providerName', 'status', 'elapsedMs'], array_keys($status));
        self::assertSame('ok', $status['status']);
    }

    public function testEchoesAValidRequestIdHeaderAndGeneratesOneOtherwise(): void
    {
        $this->client->request('POST', '/comparison', server: ['HTTP_X_REQUEST_ID' => 'req-12345678'], content: json_encode(self::VALID_BODY, JSON_THROW_ON_ERROR));
        self::assertResponseHeaderSame('X-Request-Id', 'req-12345678');

        $this->postComparison();
        self::assertNotEmpty($this->client->getResponse()->headers->get('X-Request-Id'));
    }

    #[Group('TC-1')]
    public function testAFailingProviderDoesNotAffectTheOthers(): void
    {
        $this->picker->force('provider-c', SimulatedOutcomeKind::HTTP_ERROR);

        $this->postComparison();

        self::assertResponseStatusCodeSame(200);
        $response = $this->json();
        self::assertSame(['provider-a', 'provider-b'], array_column($response['offers'], 'providerId'));
        $statuses = self::statusesById($response);
        self::assertSame('ok', $statuses['provider-a']['status']);
        self::assertSame('ok', $statuses['provider-b']['status']);
        self::assertSame('failed', $statuses['provider-c']['status']);
        self::assertSame('http_error', $statuses['provider-c']['reason']);
        self::assertArrayNotHasKey('reason', $statuses['provider-a']);
    }

    /** @return iterable<string, array{SimulatedOutcomeKind, string}> */
    public static function forcedFailures(): iterable
    {
        yield 'latency spike' => [SimulatedOutcomeKind::SPIKE, 'timeout'];
        yield 'http error' => [SimulatedOutcomeKind::HTTP_ERROR, 'http_error'];
        yield 'malformed payload' => [SimulatedOutcomeKind::MALFORMED, 'malformed_response'];
    }

    #[Group('TC-1')]
    #[DataProvider('forcedFailures')]
    public function testEachFailureModeIsReportedWithItsReason(SimulatedOutcomeKind $kind, string $reason): void
    {
        $this->picker->force('provider-c', $kind);

        $this->postComparison();

        self::assertResponseStatusCodeSame(200);
        self::assertSame($reason, self::statusesById($this->json())['provider-c']['reason']);
    }

    #[Group('TC-3')]
    public function testReturns200WithNoOffersWhenEveryProviderFails(): void
    {
        $this->picker->force('provider-a', SimulatedOutcomeKind::HTTP_ERROR);
        $this->picker->force('provider-b', SimulatedOutcomeKind::MALFORMED);
        $this->picker->force('provider-c', SimulatedOutcomeKind::SPIKE);

        $this->postComparison();

        self::assertResponseStatusCodeSame(200);
        $response = $this->json();
        self::assertSame([], $response['offers']);
        self::assertSame(['failed', 'failed', 'failed'], array_column($response['providerStatuses'], 'status'));
    }

    #[Group('TC-P4')]
    public function testWithTheCampaignInactiveNoOfferCarriesADiscountedPrice(): void
    {
        $this->postComparison();

        foreach ($this->json()['offers'] as $offer) {
            self::assertArrayNotHasKey('discountedPriceAmountCents', $offer);
            self::assertArrayNotHasKey('campaignApplied', $offer);
        }
    }

    /** @param array<string, mixed>|string $body */
    #[Group('TC-P1')]
    #[DataProvider('invalidBodies')]
    public function testInvalidRequestsAreRejectedBeforeAnyProviderIsCalled(array|string $body, string $field, string $rule): void
    {
        $this->postComparison($body);

        self::assertResponseStatusCodeSame(400);
        $error = $this->json();
        self::assertSame($field, $error['field']);
        self::assertSame($rule, $error['rule']);
        self::assertSame(0, $this->picker->picks, 'No provider must be invoked for an invalid request.');
    }

    /** @return iterable<string, array{array<string, mixed>|string, string, string}> */
    public static function invalidBodies(): iterable
    {
        $valid = self::VALID_BODY;

        yield 'not json' => ['not json', 'body', 'invalid_json'];
        yield 'empty body' => ['', 'body', 'invalid_json'];
        yield 'json list' => ['[1, 2]', 'body', 'invalid_json'];
        yield 'json scalar' => ['"hello"', 'body', 'invalid_json'];
        yield 'empty object' => ['{}', 'birthDate', 'missing'];
        yield 'missing vehicleType' => [array_diff_key($valid, ['vehicleType' => 1]), 'vehicleType', 'missing'];
        yield 'birthDate not a string' => [['birthDate' => 19900514] + $valid, 'birthDate', 'invalid_type'];
        yield 'regional date format' => [['birthDate' => '14/05/1990'] + $valid, 'birthDate', 'invalid_format'];
        yield 'february 30th' => [['birthDate' => '1990-02-30'] + $valid, 'birthDate', 'not_a_real_date'];
        yield 'future date' => [['birthDate' => '2999-01-01'] + $valid, 'birthDate', 'not_in_the_past'];
        yield 'aged under 18' => [['birthDate' => (new \DateTimeImmutable('-10 years'))->format('Y-m-d')] + $valid, 'birthDate', 'age_out_of_range'];
        yield 'aged over 99' => [['birthDate' => '1850-01-01'] + $valid, 'birthDate', 'age_out_of_range'];
        yield 'vehicleType outside the catalog' => [['vehicleType' => 'TRUCK'] + $valid, 'vehicleType', 'not_in_catalog'];
        yield 'usageType outside the catalog' => [['usageType' => 'TAXI'] + $valid, 'usageType', 'not_in_catalog'];
    }

    public function testTheErrorBodyNeverEchoesTheReceivedValue(): void
    {
        $this->postComparison(['birthDate' => 'secret-value-123'] + self::VALID_BODY);

        self::assertStringNotContainsString('secret-value-123', (string) $this->client->getResponse()->getContent());
    }

    public function testOnlyPostIsAllowed(): void
    {
        $this->client->request('GET', '/comparison');

        self::assertResponseStatusCodeSame(405);
    }

    public function testTheSimulatedEndpointAnswersOverHttpForAKnownProvider(): void
    {
        $this->client->request('POST', '/_internal/simulated-providers/provider-a/quote', content: json_encode([
            'applicant' => ['birthDate' => '1990-05-14'],
            'vehicle' => ['type' => 'SEDAN', 'usage' => 'PRIVATE'],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('quoteReference', $this->json());
    }

    public function testTheSimulatedEndpointRejectsUnknownProvidersAndBadPayloads(): void
    {
        $this->client->request('POST', '/_internal/simulated-providers/provider-z/quote', content: '{}');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/_internal/simulated-providers/provider-a/quote', content: 'nope');
        self::assertResponseStatusCodeSame(400);
    }
}
