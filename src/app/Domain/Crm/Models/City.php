<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Справочник городов, привязанных к стране (FK → crm_countries.code).
 * Editable admin directory. No business logic — fillable/casts/relations only.
 */
class City extends Model
{
    protected $table = 'crm_cities';

    protected $fillable = [
        'country_code',
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

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }
}
