<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Domain\ValueObjects;

final class Price
{
    private const SUPPORTED_CURRENCY = 'EUR';

    private function __construct(
        public readonly int $amountCents,
        public readonly string $currencyCode,
    ) {
        if ($amountCents < 0) {
            throw new \InvalidArgumentException('A price must not be negative.');
        }

        if (self::SUPPORTED_CURRENCY !== $currencyCode) {
            throw new \InvalidArgumentException(sprintf('Unsupported currency "%s".', $currencyCode));
        }
    }

    public static function ofCents(int $amountCents, string $currencyCode = self::SUPPORTED_CURRENCY): self
    {
        return new self($amountCents, $currencyCode);
    }

    public function withDiscount(CampaignDiscount $discount): self
    {
        return self::ofCents($discount->applyTo($this->amountCents), $this->currencyCode);
    }
}
