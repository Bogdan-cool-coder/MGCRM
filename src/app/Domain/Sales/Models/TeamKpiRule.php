<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use Database\Factories\Sales\TeamKpiRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TeamKpiRule — parametric team-bonus config, keyed on pipeline x period
 * (contract §1.3 — Global / Global AI are pipelines, not departments).
 *
 * Editable by admin/director (motivation.manage). Model: fillable, casts,
 * relations only — the 60/40 split + gate math lives in MotivationCardService.
 */
class TeamKpiRule extends Model
{
    /** @use HasFactory<TeamKpiRuleFactory> */
    use HasFactory;

    protected static function newFactory(): TeamKpiRuleFactory
    {
        return TeamKpiRuleFactory::new();
    }

    protected $table = 'team_kpi_rules';

    protected $fillable = [
        'pipeline_id',
        'period_year',
        'period_month',
        'team_income_target_kopecks',
        'target_currency',
        'base_pool_kopecks',
        'per_extra_member_kopecks',
        'min_members',
        'pool_currency',
        'split_contribution_pct',
        'split_equal_pct',
        'min_threshold_pct',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'team_income_target_kopecks' => 'integer',
            'base_pool_kopecks' => 'integer',
            'per_extra_member_kopecks' => 'integer',
            'min_members' => 'integer',
            'split_contribution_pct' => 'integer',
            'split_equal_pct' => 'integer',
            'min_threshold_pct' => 'integer',
        ];
    }

    // ---- Relations ----

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }
}
