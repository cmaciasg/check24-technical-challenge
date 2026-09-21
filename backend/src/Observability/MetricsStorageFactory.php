<?php

declare(strict_types=1);

namespace App\Observability;

use Prometheus\Storage\Adapter;
use Prometheus\Storage\APCng;
use Prometheus\Storage\InMemory;

/**
 * PHP-FPM no comparte memoria entre peticiones: en ejecución real los contadores viven en APCu
 * (memoria compartida del pool). `memory` es para tests y para desarrollo sin la extensión.
 */
final class MetricsStorageFactory
{
    public static function create(string $storage): Adapter
    {
        return match ($storage) {
            'apcu' => new APCng(),
            'memory' => new InMemory(),
            default => throw new \InvalidArgumentException(sprintf('Unknown METRICS_STORAGE "%s".', $storage)),
        };
    }
}
