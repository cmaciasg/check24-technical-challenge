<?php

declare(strict_types=1);

namespace App\Observability\Logging;

use App\Observability\RequestIdHolder;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class RequestIdProcessor implements ProcessorInterface
{
    public function __construct(private readonly RequestIdHolder $holder)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['request_id'] = $this->holder->current();

        return $record;
    }
}
