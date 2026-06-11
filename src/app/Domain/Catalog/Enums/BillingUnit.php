<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

enum BillingUnit: string
{
    case Year = 'year';
    case OneTime = 'one_time';
    case Minute = 'minute';
    case Package = 'package';
}
