<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Domain\ValueObjects;

enum DiscountType: string
{
    case PERCENTAGE = 'PERCENTAGE';
    case FIXED_AMOUNT = 'FIXED_AMOUNT';
}
