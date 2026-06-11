<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Справочник стран (ISO 3166-1 alpha-2 lowercase).
 * Editable admin directory. No business logic — fillable/casts/relations only.
 */
class Country extends Model
{
    protected $table = 'crm_countries';

    protected $fillable = [
        'code',
        'name',
        'name_en',
        'phone_prefix',
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

    public function cities(): HasMany
    {
        return $this->hasMany(City::class, 'country_code', 'code');
    }
}
