<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Iam\Models\User;
use Database\Factories\Sales\SalaryPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SalaryPlan — monthly personal salary / KPI plan for a manager.
 *
 * Admin CRUD lives in M10. In S1.8 this model is read-only via ManagerKpiService.
 * personal_income_plan_kopecks: integer kopecks — never float.
 * personal_ftm_plan: nullable (null = no FTM plan set → HD3 graceful zeros).
 */
class SalaryPlan extends Model
{
    /** @use HasFactory<SalaryPlanFactory> */
    use HasFactory;

    protected $table = 'salary_plans';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'period_year',
        'period_month',
        'personal_income_plan_kopecks',
        'personal_income_plan_currency',
        'personal_ftm_plan',
        'team_target_id',
        'commission_rule_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'personal_income_plan_kopecks' => 'integer',
            'personal_ftm_plan' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<TeamTarget, $this>
     */
    public function teamTarget(): BelongsTo
    {
        return $this->belongsTo(TeamTarget::class);
    }

    /**
     * @return BelongsTo<CommissionRule, $this>
     */
    public function commissionRule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class);
    }

    protected static function newFactory(): SalaryPlanFactory
    {
        return SalaryPlanFactory::new();
    }
}
