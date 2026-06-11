<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * File folder attached to a CRM entity (contact | company).
 * Polymorphic via (owner_entity_type, owner_entity_id).
 * S1.1 scope: schema + model only; upload UI deferred.
 */
class CrmFolder extends Model
{
    protected $table = 'crm_folders';

    protected $fillable = [
        'owner_entity_type',
        'owner_entity_id',
        'name',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function files(): HasMany
    {
        return $this->hasMany(CrmFile::class, 'folder_id');
    }
}
