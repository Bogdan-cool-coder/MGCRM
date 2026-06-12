<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use Database\Factories\Sales\PipelineStageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PipelineStage (Kanban column). All business logic lives in services.
 * Model: fillable, casts, relations only.
 */
class PipelineStage extends Model
{
    /** @use HasFactory<PipelineStageFactory> */
    use HasFactory;

    protected static function newFactory(): PipelineStageFactory
    {
        return PipelineStageFactory::new();
    }

    protected $table = 'pipeline_stages';

    protected $fillable = [
        'pipeline_id',
        'name',
        'code',
        'sort_order',
        'color',
        'is_won',
        'is_lost',
        'hidden_by_default',
        'parent_stage_id',
        'stage_features',
        'won_gate',
        'sla_hours',
        'visible_department_ids',
        'visible_user_ids',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
            'hidden_by_default' => 'boolean',
            'won_gate' => 'boolean',
            'sla_hours' => 'integer',
            'stage_features' => 'array',
            'visible_department_ids' => 'array',
            'visible_user_ids' => 'array',
        ];
    }

    // ---- Relations ----

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_stage_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_stage_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'stage_id');
    }
}
