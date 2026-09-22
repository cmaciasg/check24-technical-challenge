<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Domain\ValueObjects;

use App\Comparator\CarInsurance\Domain\Exception\InvalidQuoteRequest;

final readonly class DateOfBirth
{
    private const FIELD = 'birthDate';
    private const MIN_AGE = 18;
    private const MAX_AGE = 99;

    private function __construct(
        public \DateTimeImmutable $value,
    ) {
    }

    public static function fromIsoString(string $isoDate, \DateTimeImmutable $today): self
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $isoDate, $parts)) {
            throw InvalidQuoteRequest::forField(self::FIELD, 'invalid_format');
        }

        if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw InvalidQuoteRequest::forField(self::FIELD, 'not_a_real_date');
        }

        $utc = new \DateTimeZone('UTC');
        $date = new \DateTimeImmutable($isoDate.' 00:00:00', $utc);
        $startOfToday = new \DateTimeImmutable($today->setTimezone($utc)->format('Y-m-d').' 00:00:00', $utc);

        if ($date >= $startOfToday) {
            throw InvalidQuoteRequest::forField(self::FIELD, 'not_in_the_past');
        }

        $age = $date->diff($startOfToday)->y;
        if ($age < self::MIN_AGE || $age > self::MAX_AGE) {
            throw InvalidQuoteRequest::forField(self::FIELD, 'age_out_of_range');
        }

        return new self($date);
    }

    public function toIsoString(): string
    {
        return $this->value->format('Y-m-d');
    }
}
