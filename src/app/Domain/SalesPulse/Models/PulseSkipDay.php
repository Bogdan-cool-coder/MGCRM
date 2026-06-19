<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Models;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Enums\SkipKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PulseSkipDay — a vacation / day-off marker the scheduler honours (spec §3:
 * jobs skip a team if is_team_skipped, and skip managers that are
 * is_manager_skipped).
 *
 * manager_id NULL = skip the whole team identified by team_chat_id; manager_id
 * set = a personal skip for one manager. `kind` separates a one-day /skipday from
 * a multi-day /vacation; a vacation writes one row per covered day, all sharing
 * the same vacation_until (spec §3 — returning-from-vacation detection).
 */
class PulseSkipDay extends Model
{
    protected $table = 'pulse_skip_days';

    protected $fillable = [
        'on_date',
        'kind',
        'vacation_until',
        'team_chat_id',
        'manager_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'on_date' => 'date',
            'kind' => SkipKind::class,
            'vacation_until' => 'date',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
