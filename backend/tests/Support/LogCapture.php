<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Observability\Logging\JsonLineFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/** Handler de Monolog que conserva cada línea formateada tal y como saldría a stderr. */
final class LogCapture extends AbstractProcessingHandler
{
    /** @var list<string> */
    public array $lines = [];

    public function __construct()
    {
        parent::__construct(Level::Debug);
        $this->setFormatter(new JsonLineFormatter());
    }

    protected function write(LogRecord $record): void
    {
        $this->lines[] = $record->formatted;
    }

    public function allText(): string
    {
        return implode('', $this->lines);
    }
}
