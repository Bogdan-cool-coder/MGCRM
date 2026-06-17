<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Crm\Enums\CustomFieldScope;
use App\Domain\Crm\Enums\CustomFieldType;
use Illuminate\Database\Eloquent\Model;

/**
 * Definition of a custom field for a given entity scope.
 * Values are stored in Entity.extra_fields[code] (JSONB).
 * No business logic — fillable/casts only.
 */
class CustomFieldDef extends Model
{
    protected $table = 'custom_field_defs';

    protected $fillable = [
        'entity_scope',
        'code',
        'label',
        'help_text',
        'field_type',
        'options',
        'default_value',
        'required',
        'group',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'entity_scope' => CustomFieldScope::class,
            'field_type' => CustomFieldType::class,
            'options' => 'array',
            'default_value' => 'array',
            'required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
