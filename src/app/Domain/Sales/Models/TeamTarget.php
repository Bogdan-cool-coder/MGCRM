<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Org\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TeamTarget — department-level revenue goal for a calendar month.
 *
 * Admin CRUD lives in M10 (Analytics domain). Read-only in S1.8.
 * target_amount_kopecks: integer kopecks, never float.
 */
class TeamTarget extends Model
{
    protected $table = 'team_targets';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'period_year',
        'period_month',
        'target_amount_kopecks',
        'target_currency',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'target_amount_kopecks' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return HasMany<SalaryPlan, $this>
     */
    public function salaryPlans(): HasMany
    {
        return $this->hasMany(SalaryPlan::class);
    }
}
