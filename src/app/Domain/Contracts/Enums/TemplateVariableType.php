<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Enums;

/**
 * TemplateVariableType — the six UI control types for custom template variables.
 * Controls how the manager fills {{ custom.<key> }} when creating a contract.
 */
enum TemplateVariableType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Date = 'date';
    case Select = 'select';
    case Checkbox = 'checkbox';
}
