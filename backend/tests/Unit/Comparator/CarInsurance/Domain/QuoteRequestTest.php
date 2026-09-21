<?php

declare(strict_types=1);

namespace App\Tests\Unit\Comparator\CarInsurance\Domain;

use App\Comparator\CarInsurance\Domain\Exception\InvalidQuoteRequest;
use App\Comparator\CarInsurance\Domain\Model\QuoteRequest;
use App\Comparator\CarInsurance\Domain\ValueObjects\UsageType;
use App\Comparator\CarInsurance\Domain\ValueObjects\VehicleType;
use App\Tests\Support\QuoteRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('TC-P1')]
final class QuoteRequestTest extends TestCase
{
    public function testBuildsFromAValidPayload(): void
    {
        $request = QuoteRequest::fromPayload(
            ['birthDate' => '1990-05-14', 'vehicleType' => 'HATCHBACK', 'usageType' => 'COMMERCIAL'],
            QuoteRequests::today(),
        );

        self::assertSame(VehicleType::HATCHBACK, $request->vehicleType);
        self::assertSame(UsageType::COMMERCIAL, $request->usageType);
        self::assertSame('1990-05-14', $request->birthDate->toIsoString());
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidPayloads')]
    public function testRejectsWithFieldAndRuleCode(array $payload, string $field, string $ruleCode): void
    {
        try {
            QuoteRequest::fromPayload($payload, QuoteRequests::today());
            self::fail('Expected InvalidQuoteRequest.');
        } catch (InvalidQuoteRequest $e) {
            self::assertSame($field, $e->field);
            self::assertSame($ruleCode, $e->ruleCode);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function invalidPayloads(): iterable
    {
        $valid = ['birthDate' => '1990-05-14', 'vehicleType' => 'SEDAN', 'usageType' => 'PRIVATE'];

        yield 'missing birthDate' => [array_diff_key($valid, ['birthDate' => 1]), 'birthDate', 'missing'];
        yield 'missing vehicleType' => [array_diff_key($valid, ['vehicleType' => 1]), 'vehicleType', 'missing'];
        yield 'missing usageType' => [array_diff_key($valid, ['usageType' => 1]), 'usageType', 'missing'];
        yield 'birthDate is not text' => [['birthDate' => 19900514] + $valid, 'birthDate', 'invalid_type'];
        yield 'vehicleType is not text' => [['vehicleType' => ['SEDAN']] + $valid, 'vehicleType', 'invalid_type'];
        yield 'usageType is null' => [['usageType' => null] + $valid, 'usageType', 'invalid_type'];
        yield 'vehicleType outside the catalog' => [['vehicleType' => 'TRUCK'] + $valid, 'vehicleType', 'not_in_catalog'];
        yield 'vehicleType in lower case' => [['vehicleType' => 'sedan'] + $valid, 'vehicleType', 'not_in_catalog'];
        yield 'usageType outside the catalog' => [['usageType' => 'TAXI'] + $valid, 'usageType', 'not_in_catalog'];
        yield 'under age' => [['birthDate' => '2015-01-01'] + $valid, 'birthDate', 'age_out_of_range'];
        yield 'reports birthDate first when several fail' => [['birthDate' => 'nope', 'vehicleType' => 'X', 'usageType' => 'Y'], 'birthDate', 'invalid_format'];
        yield 'reports vehicleType before usageType' => [['vehicleType' => 'X', 'usageType' => 'Y'] + $valid, 'vehicleType', 'not_in_catalog'];
    }
}
