<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DealAudit — append-only log of deal field changes.
 *
 * One row per changed field; extra_fields are recorded PER KEY
 * (field = "extra_fields.{code}"). Old/new values are JSON-encoded scalars.
 *
 * Only created_at is tracked (no updated_at): rows are never mutated.
 */
class DealAudit extends Model
{
    protected $table = 'deal_audits';

    public const UPDATED_AT = null;

    protected $fillable = [
        'deal_id',
        'user_id',
        'field',
        'old_value',
        'new_value',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    // ---- Relations ----

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
