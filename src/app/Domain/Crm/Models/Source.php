<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Справочник источников лида/компании (own_contact/cold_call/partner/internet/lead).
 * Editable admin directory. No business logic — fillable/casts only.
 */
class Source extends Model
{
    protected $table = 'crm_sources';

    protected $fillable = [
        'code',
        'name',
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
