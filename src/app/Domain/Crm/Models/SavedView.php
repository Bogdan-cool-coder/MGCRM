<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Crm\Enums\SavedViewEntity;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SavedView — server-persisted list view configuration.
 * All business logic lives in SavedViewService. Model: fillable, casts, relations only.
 */
class SavedView extends Model
{
    protected $table = 'crm_saved_views';

    protected $fillable = [
        'user_id',
        'name',
        'entity_type',
        'is_shared',
        'is_default',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => SavedViewEntity::class,
            'is_shared' => 'boolean',
            'is_default' => 'boolean',
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
