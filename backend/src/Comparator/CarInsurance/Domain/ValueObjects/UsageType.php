<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Domain\ValueObjects;

enum UsageType: string
{
    case PRIVATE = 'PRIVATE';
    case COMMERCIAL = 'COMMERCIAL';
}
