<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Models;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Enums\SnapSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PulseDailyStatus — per manager-day flags for whether the morning plan and
 * evening fact were fixed, by whom (manual/auto), and reminder counters (spec
 * §2 — counters declared for parity, driven by the scheduler in a later slice).
 * One row per (manager, on_date).
 */
class PulseDailyStatus extends Model
{
    protected $table = 'pulse_daily_status';

    protected $fillable = [
        'manager_id',
        'on_date',
        'plan_at',
        'fact_at',
        'plan_source',
        'fact_source',
        'plan_reminded_count',
        'fact_reminded_count',
    ];

    protected function casts(): array
    {
        return [
            'on_date' => 'date',
            'plan_at' => 'datetime',
            'fact_at' => 'datetime',
            'plan_source' => SnapSource::class,
            'fact_source' => SnapSource::class,
            'plan_reminded_count' => 'integer',
            'fact_reminded_count' => 'integer',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
