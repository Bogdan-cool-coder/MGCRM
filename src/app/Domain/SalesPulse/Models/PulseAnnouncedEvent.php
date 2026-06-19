<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Models;

use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealStageHistory;
use App\Domain\SalesPulse\Enums\AnnouncedEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PulseAnnouncedEvent — the de-dup ledger for the announcer (spec §4). One row
 * per source event records that a meeting_done / success announcement was already
 * posted, so the every-5-minute announcer never double-posts (and survives a cron
 * restart).
 *
 * The two sources have DISTINCT dedup keys (Slice 4 migration):
 *   - MeetingDone → a completed FTM Activity, deduped by `activity_id`.
 *   - Success     → a DealStageHistory transition into a won stage (NOT a task),
 *                   deduped by `deal_stage_history_id`. Such a row has a NULL
 *                   activity_id.
 * Each key is its own UNIQUE column; NULLs are distinct in a UNIQUE index so the
 * two source kinds never collide.
 *
 * The deal() / activity() / dealStageHistory() relations read Sales / Activity
 * models for the announcer's rendering.
 */
class PulseAnnouncedEvent extends Model
{
    protected $table = 'pulse_announced_events';

    protected $fillable = [
        'activity_id',
        'deal_stage_history_id',
        'event_type',
        'manager_id',
        'deal_id',
        'chat_id',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => AnnouncedEventType::class,
            'posted_at' => 'datetime',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'activity_id');
    }

    public function dealStageHistory(): BelongsTo
    {
        return $this->belongsTo(DealStageHistory::class, 'deal_stage_history_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }
}
