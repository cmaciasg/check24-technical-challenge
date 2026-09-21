<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Domain\ValueObjects;

final class CampaignDiscount
{
    private function __construct(
        private readonly DiscountType $type,
        private readonly int|float $value,
    ) {
    }

    public static function percentage(float $pct): self
    {
        if ($pct < 0 || $pct > 100) {
            throw new \InvalidArgumentException('A percentage discount must be between 0 and 100.');
        }

        return new self(DiscountType::PERCENTAGE, $pct);
    }

    public static function fixedAmount(int $cents): self
    {
        if ($cents < 0) {
            throw new \InvalidArgumentException('A fixed discount must not be negative.');
        }

        return new self(DiscountType::FIXED_AMOUNT, $cents);
    }

    public static function fromType(DiscountType $type, int|float $value): self
    {
        return match ($type) {
            DiscountType::PERCENTAGE => self::percentage((float) $value),
            DiscountType::FIXED_AMOUNT => self::fixedAmount((int) $value),
        };
    }

    /** Redondeo al céntimo más cercano, mitad hacia arriba, con suelo en 0. */
    public function applyTo(int $priceAmountCents): int
    {
        $discounted = match ($this->type) {
            DiscountType::PERCENTAGE => (int) round($priceAmountCents * (100 - $this->value) / 100, 0, PHP_ROUND_HALF_UP),
            DiscountType::FIXED_AMOUNT => $priceAmountCents - (int) $this->value,
        };

        return max(0, $discounted);
    }
}
