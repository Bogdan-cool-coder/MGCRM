<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

enum CustomFieldScope: string
{
    case Contact = 'contact';
    case Company = 'company';
    case Deal = 'deal';
    case Contract = 'contract';
}
