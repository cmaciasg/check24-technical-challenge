<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Domain\ValueObjects;

enum VehicleType: string
{
    case SEDAN = 'SEDAN';
    case HATCHBACK = 'HATCHBACK';
    case SUV = 'SUV';
    case VAN = 'VAN';
}
