<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

enum ContactStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
