<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * CommissionRule — admin-configurable commission rule applied to a SalaryPlan.
 *
 * Admin CRUD lives in M10 (Analytics domain). This model is read-only in S1.8.
 * rate_pct_times_100: 1000 = 10.00% — integer, never float.
 */
class CommissionRule extends Model
{
    protected $table = 'commission_rules';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'rate_pct_times_100',
        'base_currency',
        'scope',
        'applies_to_first_payment_only',
        'requires_signed_contract',
        'payment_trigger',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate_pct_times_100' => 'integer',
            'applies_to_first_payment_only' => 'boolean',
            'requires_signed_contract' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<CommissionRule>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
