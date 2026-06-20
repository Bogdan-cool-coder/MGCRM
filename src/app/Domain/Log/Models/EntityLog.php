<?php

declare(strict_types=1);

namespace App\Domain\Log\Models;

use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use Database\Factories\Log\EntityLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EntityLog — one polymorphic, append-only action-log row on a deal / company /
 * contact. All write/read logic lives in EntityLogService (ARCHITECTURE.md §1);
 * the model carries only fillable, casts and the actor relation.
 *
 * The polymorphic subject is FK-less (subject_type string + subject_id int),
 * like Activity's target — so there is intentionally NO belongsTo Deal/Company/
 * Contact relation here (DDD §2, no cross-domain relation coupling).
 *
 * Only created_at is tracked (no updated_at): rows are never mutated.
 */
class EntityLog extends Model
{
    /** @use HasFactory<EntityLogFactory> */
    use HasFactory;

    protected static function newFactory(): EntityLogFactory
    {
        return EntityLogFactory::new();
    }

    protected $table = 'entity_logs';

    public const UPDATED_AT = null;

    protected $fillable = [
        'subject_type',
        'subject_id',
        'actor_id',
        'action',
        'meta',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'subject_type' => LogSubjectType::class,
            'action' => LogAction::class,
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    // ---- Relations ----

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
