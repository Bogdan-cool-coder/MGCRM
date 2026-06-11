<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

enum PricingType: string
{
    case Fixed = 'fixed';
    case Tiered = 'tiered';
    case PerMinute = 'per_minute';
    case Package = 'package';
    case Custom = 'custom';
}
