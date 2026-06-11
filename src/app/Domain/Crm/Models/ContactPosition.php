<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Справочник должностей контактных лиц.
 * Editable admin directory. No business logic — fillable/casts only.
 */
class ContactPosition extends Model
{
    use SoftDeletes;

    protected $table = 'crm_contact_positions';

    protected $fillable = [
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
