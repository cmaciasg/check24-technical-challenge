<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Infra\ThirdParty;

/** Lectura validada de la respuesta cruda de un partner: nunca se asume que un json_decode sin errores es válido. */
final class ProviderPayload
{
    /** @param array<mixed> $data */
    public static function string(array $data, string ...$path): string
    {
        $value = self::at($data, $path);
        if (!is_string($value) || '' === $value) {
            throw new MalformedProviderResponse(sprintf('Expected a non-empty string at "%s".', implode('.', $path)));
        }

        return $value;
    }

    /** @param array<mixed> $data */
    public static function nonNegativeInt(array $data, string ...$path): int
    {
        $value = self::at($data, $path);
        if (!is_int($value) || $value < 0) {
            throw new MalformedProviderResponse(sprintf('Expected a non-negative integer at "%s".', implode('.', $path)));
        }

        return $value;
    }

    /**
     * Importe decimal (número o texto) convertido a céntimos redondeando al más cercano:
     * con un truncado, (int) (19.99 * 100) daría 1998.
     *
     * @param array<mixed> $data
     */
    public static function decimalAsCents(array $data, string ...$path): int
    {
        $value = self::at($data, $path);
        if (is_string($value) && 1 !== preg_match('/^\d+(\.\d+)?$/', $value)) {
            throw new MalformedProviderResponse(sprintf('Expected a decimal amount at "%s".', implode('.', $path)));
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new MalformedProviderResponse(sprintf('Expected a decimal amount at "%s".', implode('.', $path)));
        }
        if ($value < 0) {
            throw new MalformedProviderResponse(sprintf('Expected a non-negative amount at "%s".', implode('.', $path)));
        }

        return (int) round((float) $value * 100);
    }

    /**
     * @param array<mixed> $data
     * @param array<string> $path
     */
    private static function at(array $data, array $path): mixed
    {
        $current = $data;
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                throw new MalformedProviderResponse(sprintf('Missing field "%s".', implode('.', $path)));
            }
            $current = $current[$key];
        }

        return $current;
    }
}
