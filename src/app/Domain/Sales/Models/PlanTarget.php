<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Enums\PlanLayer;
use App\Domain\Sales\Enums\PlanMetric;
use App\Domain\Sales\Enums\PlanScopeType;
use Database\Factories\Sales\PlanTargetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PlanTarget — one plannable cell = (metric × scope × period × layer)
 * (contract §2.1). Thin model — all logic lives in PlanTargetService.
 */
class PlanTarget extends Model
{
    /** @use HasFactory<PlanTargetFactory> */
    use HasFactory;

    protected static function newFactory(): PlanTargetFactory
    {
        return PlanTargetFactory::new();
    }

    protected $table = 'plan_targets';

    protected $fillable = [
        'metric',
        'scope_type',
        'scope_user_id',
        'scope_pipeline_id',
        'scope_product_group_id',
        'period_year',
        'period_month',
        'layer',
        'value_kopecks',
        'value_count',
        'currency',
        'config',
        'scope_key',
        'period_month_key',
        'created_by_id',
        'updated_by_id',
    ];

    protected function casts(): array
    {
        return [
            'metric' => PlanMetric::class,
            'scope_type' => PlanScopeType::class,
            'layer' => PlanLayer::class,
            'period_year' => 'integer',
            'period_month' => 'integer',
            'period_month_key' => 'integer',
            'value_kopecks' => 'integer',
            'value_count' => 'integer',
            'config' => 'array',
        ];
    }

    // ---- Relations ----
    //
    // NB: NOT named scopeUser()/scopePipeline()/scopeProductGroup() as the
    // contract's prose suggested — Eloquent reserves the `scope*` method
    // prefix exclusively for local query scopes (first param `Builder $query`,
    // codebase-wide convention — see AutomationRun::scopeForTarget() etc.).
    // Naming a relation that way would collide with Eloquent's scope dispatch
    // and break `PlanTarget::query()->user(...)`. Flagged for reviewer.

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scope_user_id');
    }

    public function targetPipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'scope_pipeline_id');
    }

    public function targetProductGroup(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'scope_product_group_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    /**
     * @return HasMany<PlanTargetAudit, $this>
     */
    public function audits(): HasMany
    {
        return $this->hasMany(PlanTargetAudit::class);
    }
}
