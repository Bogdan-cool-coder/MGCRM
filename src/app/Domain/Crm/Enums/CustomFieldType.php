<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

enum CustomFieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Date = 'date';
    case Select = 'select';
    case Multiselect = 'multiselect';
    case Boolean = 'boolean';
    case Url = 'url';
    case UserRef = 'user_ref';
}
