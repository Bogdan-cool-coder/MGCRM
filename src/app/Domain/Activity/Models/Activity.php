<?php

declare(strict_types=1);

namespace App\Domain\Activity\Models;

use App\Domain\Activity\Enums\ActivityPriority;
use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use Database\Factories\Activity\ActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Activity — one polymorphic entity for call/meeting/task/note. All business
 * logic lives in ActivityService / MeetingReportService. Model: fillable,
 * casts, actor relations and pure computed helpers (isOverdue) only.
 *
 * Polymorphic target is WITHOUT FK (target_type string + target_id int), like
 * CrmFile — so there is intentionally NO belongsTo Deal/Company relation. The
 * target is resolved in the service via ActivityTargetType, keeping the Activity
 * context free of relation-level coupling to Sales/Crm (DDD §2).
 */
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    protected static function newFactory(): ActivityFactory
    {
        return ActivityFactory::new();
    }

    protected $table = 'activities';

    protected $fillable = [
        'kind',
        'target_type',
        'target_id',
        'title',
        'body',
        'due_at',
        'completed_at',
        'completed_by_id',
        'responsible_id',
        'created_by_id',
        'priority',
        'status',
        'is_closed',
        'progress_pct',
        'result_text',
        'is_pinned',
        'is_first_time_meeting',
        'ftm_decision_maker_attended',
        'ftm_presentation_shown',
        'ftm_report_url',
        'meeting_report_json',
        'department_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ActivityType::class,
            'status' => ActivityStatus::class,
            'priority' => ActivityPriority::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'is_closed' => 'boolean',
            'progress_pct' => 'integer',
            'is_pinned' => 'boolean',
            'is_first_time_meeting' => 'boolean',
            'ftm_decision_maker_attended' => 'boolean',
            'ftm_presentation_shown' => 'boolean',
            'meeting_report_json' => 'array',
        ];
    }

    /**
     * Computed overdue predicate (no column). Overdue ⇔ due_at in the past AND
     * not closed AND status != done. Mirrors the query predicate in
     * ActivityService so badge counts and lists never drift (E4).
     */
    public function isOverdue(): bool
    {
        if ($this->due_at === null || $this->is_closed) {
            return false;
        }

        if ($this->status === ActivityStatus::Done) {
            return false;
        }

        return $this->due_at->isPast();
    }

    // ---- Relations (actors only; target is NOT a relation) ----

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
