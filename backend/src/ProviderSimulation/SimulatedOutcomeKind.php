<?php

declare(strict_types=1);

namespace App\ProviderSimulation;

enum SimulatedOutcomeKind: string
{
    case NORMAL = 'normal';
    case SPIKE = 'spike';
    case HTTP_ERROR = 'http_error';
    case MALFORMED = 'malformed';
}
