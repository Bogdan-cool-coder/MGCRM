<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Справочник тегов. Универсальные (scope=null) применяются ко всем сущностям.
 * Scope-специфичные — только к указанной (deal/contact/company).
 */
class Tag extends Model
{
    protected $table = 'crm_tags';

    protected $fillable = [
        'name',
        'color',
        'scope',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
