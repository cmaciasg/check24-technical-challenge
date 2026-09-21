<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Application\Compare;

enum ProviderFailureReason: string
{
    case TIMEOUT = 'timeout';
    case HTTP_ERROR = 'http_error';
    case MALFORMED_RESPONSE = 'malformed_response';
    case UNKNOWN_ERROR = 'unknown_error';
}
