<?php

declare(strict_types=1);

namespace App\Observability\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Una línea JSON por evento, con el contexto al primer nivel (filtrable con jq) y los campos
 * comunes: timestamp (ISO-8601 UTC con milisegundos), level, event y request_id (observability.md §4.1).
 */
final class JsonLineFormatter implements FormatterInterface
{
    public function format(LogRecord $record): string
    {
        $line = [
            'timestamp' => $record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'level' => $record->level->getName(),
            'event' => $record->message,
            'request_id' => $record->extra['request_id'] ?? null,
        ] + $record->context;

        return json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n";
    }

    public function formatBatch(array $records): string
    {
        return implode('', array_map($this->format(...), $records));
    }
}
