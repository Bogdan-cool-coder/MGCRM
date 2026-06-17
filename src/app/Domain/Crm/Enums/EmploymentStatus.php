<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

enum EmploymentStatus: string
{
    case Works = 'works';
    case Left = 'left';
}
