<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Domain\Exception;

/**
 * Nunca incluye el valor recibido: `field` y `ruleCode` bastan para diagnosticar
 * y así el 400 se puede registrar sin datos personales (product.md §1.5).
 */
final class InvalidQuoteRequest extends \DomainException
{
    private function __construct(
        public readonly string $field,
        public readonly string $ruleCode,
    ) {
        parent::__construct(sprintf('Invalid quote request: %s (%s)', $field, $ruleCode));
    }

    public static function forField(string $field, string $ruleCode): self
    {
        return new self($field, $ruleCode);
    }

    public static function invalidJson(): self
    {
        return new self('body', 'invalid_json');
    }
}
