<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Справочник каналов привлечения (Настройки → «Каналы привлечения»).
 * Editable admin directory. No business logic — fillable/casts/relations only.
 */
class AcquisitionChannel extends Model
{
    protected $table = 'acquisition_channels';

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

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'acquisition_channel_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'acquisition_channel_id');
    }
}
