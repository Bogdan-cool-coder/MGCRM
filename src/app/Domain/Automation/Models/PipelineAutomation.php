<?php

declare(strict_types=1);

namespace App\Domain\Automation\Models;

use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\TriggerKind;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Database\Factories\Automation\PipelineAutomationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PipelineAutomation — one automation rule (trigger -> action) on a pipeline.
 *
 * Model: fillable, casts, relations only. Resolve/execution logic lives in
 * AutomationEngine (ARCHITECTURE §1). stage_id NULL = applies on every stage.
 */
class PipelineAutomation extends Model
{
    /** @use HasFactory<PipelineAutomationFactory> */
    use HasFactory;

    protected static function newFactory(): PipelineAutomationFactory
    {
        return PipelineAutomationFactory::new();
    }

    protected $fillable = [
        'pipeline_id',
        'stage_id',
        'name',
        'description',
        'trigger_kind',
        'trigger_config',
        'action_kind',
        'action_config',
        'round_robin_cursor',
        'is_active',
        'created_by_user_id',
        'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger_kind' => TriggerKind::class,
            'trigger_config' => 'array',
            'action_kind' => ActionKind::class,
            'action_config' => 'array',
            'round_robin_cursor' => 'integer',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    // ---- Relations ----

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'automation_id');
    }
}
