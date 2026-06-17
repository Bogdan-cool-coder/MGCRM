<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DealStageHistory — append-only log of stage transitions.
 * Stable event contract for automation-specialist and analytics-specialist.
 *
 * Only created_at is tracked (no updated_at): rows are never mutated.
 */
class DealStageHistory extends Model
{
    protected $table = 'deal_stage_history';

    public const UPDATED_AT = null;

    protected $fillable = [
        'deal_id',
        'from_stage_id',
        'to_stage_id',
        'user_id',
        'created_at',
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

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'to_stage_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
