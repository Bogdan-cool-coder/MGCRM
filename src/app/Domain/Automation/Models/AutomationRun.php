<?php

declare(strict_types=1);

namespace App\Domain\Automation\Models;

use App\Domain\Automation\Enums\AutomationTargetType;
use App\Domain\Automation\Enums\RunStatus;
use Database\Factories\Automation\AutomationRunFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AutomationRun — audit row for one automation execution.
 *
 * Append-mostly: only created_at is tracked at insert (no updated_at); the engine
 * mutates status/result/finished_at in place via finalize(). The partial-unique
 * index on (automation_id, target_type, target_id, trigger_event_ts) is the
 * idempotency guard (see migration).
 *
 * Model: fillable, casts, relation, thin query scopes only. Queries that compose
 * these scopes live in the service layer (ARCHITECTURE §1).
 */
class AutomationRun extends Model
{
    /** @use HasFactory<AutomationRunFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function newFactory(): AutomationRunFactory
    {
        return AutomationRunFactory::new();
    }

    protected $fillable = [
        'automation_id',
        'target_type',
        'target_id',
        'status',
        'trigger_event_ts',
        'payload',
        'result',
        'error_message',
        'started_at',
        'finished_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'target_type' => AutomationTargetType::class,
            'status' => RunStatus::class,
            'trigger_event_ts' => 'datetime',
            'payload' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    // ---- Relations ----

    public function automation(): BelongsTo
    {
        return $this->belongsTo(PipelineAutomation::class, 'automation_id');
    }

    // ---- Scopes (thin filters; composing queries belong to the service) ----

    public function scopeForAutomation(Builder $query, int $automationId): Builder
    {
        return $query->where('automation_id', $automationId);
    }

    public function scopeForTarget(Builder $query, AutomationTargetType $type, int $targetId): Builder
    {
        return $query->where('target_type', $type->value)->where('target_id', $targetId);
    }

    public function scopeStatus(Builder $query, RunStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * Filter the journal to runs created on/after $from and/or on/before $to.
     * Either bound may be null (open-ended period). created_at is the journal
     * timeline (this model has no updated_at).
     */
    public function scopeCreatedBetween(Builder $query, ?DateTimeInterface $from, ?DateTimeInterface $to): Builder
    {
        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        return $query;
    }

    /**
     * Newest-first journal order (ties broken by id for a stable sort).
     */
    public function scopeRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
