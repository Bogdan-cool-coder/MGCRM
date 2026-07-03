<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Iam\Models\User;
use Database\Factories\Sales\PlanTargetAuditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PlanTargetAudit — append-only change log for a plan_targets cell
 * (contract §2.2). Pattern = DealAudit verbatim: one row per changed field,
 * UPDATED_AT disabled, rows are never mutated.
 */
class PlanTargetAudit extends Model
{
    /** @use HasFactory<PlanTargetAuditFactory> */
    use HasFactory;

    protected static function newFactory(): PlanTargetAuditFactory
    {
        return PlanTargetAuditFactory::new();
    }

    protected $table = 'plan_target_audits';

    public const UPDATED_AT = null;

    protected $fillable = [
        'plan_target_id',
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

    public function planTarget(): BelongsTo
    {
        return $this->belongsTo(PlanTarget::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
