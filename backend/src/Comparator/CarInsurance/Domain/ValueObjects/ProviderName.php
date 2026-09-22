<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Domain\ValueObjects;

final readonly class ProviderName
{
    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException('Provider name must not be empty.');
        }

        return new self($value);
    }
}
