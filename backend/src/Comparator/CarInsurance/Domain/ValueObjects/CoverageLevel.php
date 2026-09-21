<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Domain\ValueObjects;

enum CoverageLevel: string
{
    case BASIC = 'BASIC';
    case STANDARD = 'STANDARD';
    case PREMIUM = 'PREMIUM';
}
