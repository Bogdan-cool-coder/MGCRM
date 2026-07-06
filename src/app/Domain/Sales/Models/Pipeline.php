<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Sales\Enums\PipelineKind;
use Database\Factories\Sales\PipelineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pipeline (sales funnel). All business logic lives in PipelineService.
 * Model: fillable, casts, relations, scopes only.
 */
class Pipeline extends Model
{
    /** @use HasFactory<PipelineFactory> */
    use HasFactory;

    protected static function newFactory(): PipelineFactory
    {
        return PipelineFactory::new();
    }

    protected $table = 'pipelines';

    protected $fillable = [
        'name',
        'kind',
        'settings',
        'graph_layout',
        'visible_role',
        'visible_user_ids',
        'is_active',
        'sort_order',
        // "Стадия для новых сделок" (Deal Create 2.0 §2.2/§3) — nullable; a null
        // value falls back to the existing "first non-won/lost/hidden stage" rule
        // (DealService::create). Validated (belongs to THIS pipeline) in
        // UpdatePipelineRequest; a won/lost/hidden target is ignored by the
        // service rather than rejected here (one place of truth for stage choice).
        'default_stage_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PipelineKind::class,
            'settings' => 'array',
            // Cosmetic node-canvas layout. cast `array` keeps null as null in the
            // DB (not []), so the front can tell "never laid out" from "empty".
            'graph_layout' => 'array',
            'visible_user_ids' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    // ---- Relations ----

    /**
     * Stages ordered for display: system stages (won/lost) always sort to the
     * bottom via a single system-rank (0 = funnel stage, 1 = won/lost), then by
     * sort_order. Keeps the proper funnel reading order on the Kanban board and
     * the stage editor even when a system stage carries a low sort_order. The
     * CASE is portable across PG and SQLite.
     */
    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class)
            ->orderByRaw('CASE WHEN is_won THEN 1 WHEN is_lost THEN 1 ELSE 0 END')
            ->orderBy('sort_order');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function automations(): HasMany
    {
        return $this->hasMany(PipelineAutomation::class);
    }

    /** "Стадия для новых сделок" (Deal Create 2.0 §2.2/§3) — nullable. */
    public function defaultStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'default_stage_id');
    }

    // ---- Scopes ----

    /**
     * @param  Builder<Pipeline>  $query
     */
    public function scopeSales(Builder $query): void
    {
        $query->where('kind', PipelineKind::Sales->value);
    }
}
