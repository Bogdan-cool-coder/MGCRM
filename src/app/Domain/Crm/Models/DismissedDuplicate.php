<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dismissed duplicate pair — pair marked by a user as "not a duplicate".
 * DedupService normalizes entity_a_id < entity_b_id before insert.
 * No business logic — fillable/casts/relations only.
 */
class DismissedDuplicate extends Model
{
    public $timestamps = false;

    protected $table = 'dismissed_duplicates';

    protected $fillable = [
        'entity_type',
        'entity_a_id',
        'entity_b_id',
        'dismissed_by_user_id',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
        ];
    }

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by_user_id');
    }
}
