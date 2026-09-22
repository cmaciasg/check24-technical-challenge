<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Domain\ValueObjects;

final readonly class Price
{
    private const SUPPORTED_CURRENCY = 'EUR';

    private function __construct(
        public int $amountCents,
        public string $currencyCode,
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
