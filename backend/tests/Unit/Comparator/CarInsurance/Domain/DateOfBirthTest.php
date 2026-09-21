<?php

declare(strict_types=1);

namespace App\Tests\Unit\Comparator\CarInsurance\Domain;

use App\Comparator\CarInsurance\Domain\Exception\InvalidQuoteRequest;
use App\Comparator\CarInsurance\Domain\ValueObjects\DateOfBirth;
use App\Tests\Support\QuoteRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('TC-P1')]
final class DateOfBirthTest extends TestCase
{
    /** Hoy es 2026-09-22. */
    #[DataProvider('validDates')]
    public function testAcceptsAgesWithinRange(string $isoDate): void
    {
        $dateOfBirth = DateOfBirth::fromIsoString($isoDate, QuoteRequests::today());

        self::assertSame($isoDate, $dateOfBirth->toIsoString());
    }

    /** @return iterable<string, array{string}> */
    public static function validDates(): iterable
    {
        yield 'exactly 18 today (birthday is today)' => ['2008-09-22'];
        yield 'typical adult' => ['1990-05-14'];
        yield 'exactly 99 today' => ['1927-09-22'];
        yield '99 years and 364 days' => ['1926-09-23'];
    }

    #[DataProvider('invalidDates')]
    public function testRejectsWithTheExpectedRuleCode(string $isoDate, string $ruleCode): void
    {
        try {
            DateOfBirth::fromIsoString($isoDate, QuoteRequests::today());
            self::fail('Expected InvalidQuoteRequest.');
        } catch (InvalidQuoteRequest $e) {
            self::assertSame('birthDate', $e->field);
            self::assertSame($ruleCode, $e->ruleCode);
            if ('' !== $isoDate) {
                self::assertStringNotContainsString($isoDate, $e->getMessage(), 'The value must never appear in the exception.');
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidDates(): iterable
    {
        yield 'aged 17 (17 years and 364 days)' => ['2008-09-23', 'age_out_of_range'];
        yield 'aged 17 (17th birthday today)' => ['2009-09-22', 'age_out_of_range'];
        yield 'aged 100 today' => ['1926-09-22', 'age_out_of_range'];
        yield 'aged 101' => ['1925-01-01', 'age_out_of_range'];
        yield 'born today' => ['2026-09-22', 'not_in_the_past'];
        yield 'future date' => ['2027-01-01', 'not_in_the_past'];
        yield 'regional format' => ['14/05/1990', 'invalid_format'];
        yield 'not zero padded' => ['1990-5-14', 'invalid_format'];
        yield 'with time' => ['1990-05-14T10:00:00', 'invalid_format'];
        yield 'empty' => ['', 'invalid_format'];
        yield 'text' => ['yesterday', 'invalid_format'];
        yield 'february 30th' => ['1990-02-30', 'not_a_real_date'];
        yield 'month 13' => ['1990-13-01', 'not_a_real_date'];
        yield 'february 29th in a non leap year' => ['1990-02-29', 'not_a_real_date'];
    }
}
