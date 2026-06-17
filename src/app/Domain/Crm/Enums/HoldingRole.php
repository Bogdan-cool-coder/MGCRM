<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

enum HoldingRole: string
{
    case Parent = 'parent';
    case Subsidiary = 'subsidiary';
}
