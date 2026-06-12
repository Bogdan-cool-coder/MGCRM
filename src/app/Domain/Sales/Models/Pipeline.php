<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Sales\Enums\PipelineKind;
use Database\Factories\Sales\PipelineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'visible_role',
        'visible_user_ids',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PipelineKind::class,
            'settings' => 'array',
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

    // ---- Scopes ----

    /**
     * @param  Builder<Pipeline>  $query
     */
    public function scopeSales(Builder $query): void
    {
        $query->where('kind', PipelineKind::Sales->value);
    }
}
