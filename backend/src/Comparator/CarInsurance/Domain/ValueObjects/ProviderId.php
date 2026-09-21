<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Domain\ValueObjects;

final class ProviderId
{
    private function __construct(public readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^[a-z][a-z0-9-]*$/', $value)) {
            throw new \InvalidArgumentException(sprintf('Invalid provider id "%s".', $value));
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
