<?php

declare(strict_types=1);

namespace App\Domain\Activity\Models;

use App\Domain\Activity\Enums\ActivityPriority;
use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use Database\Factories\Activity\ActivityFactory;
use Illuminate\Database\Eloquent\Builder;
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
     * The three boolean attributes that must ALL be true for a counted FTM —
     * factored out of the query scope AND the object predicate so the two forms
     * (SQL and PHP) of the rule can never silently drift apart (risk Н from plan).
     * The remaining two conditions are kind=meeting and a non-null report URL,
     * which differ in form between the scope (SQL) and predicate (PHP) and are
     * applied explicitly in each.
     *
     * @var list<string>
     */
    private const FTM_BOOLEAN_FLAGS = [
        'is_first_time_meeting',
        'ftm_decision_maker_attended',
        'ftm_presentation_shown',
    ];

    /**
     * The five FTM (first-time meeting) conditions (plan §Б2) — the SINGLE
     * SOURCE for the FTM predicate. The KPI count, the feed's ftm_only filter,
     * the per-item ftm_counted flag and ManagerKpiService all delegate here so a
     * rule change can never silently desync the surfaces (risk Н from plan).
     *
     * Query form: scope the builder to counted FTM meetings. The field list is
     * shared with qualifiesAsFtm() via FTM_BOOLEAN_FLAGS — the scope and the
     * object predicate cannot diverge on which flags are required.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeFtmCounted(Builder $query): Builder
    {
        $query->where('kind', ActivityType::Meeting->value);

        foreach (self::FTM_BOOLEAN_FLAGS as $flag) {
            $query->where($flag, true);
        }

        return $query->whereNotNull('ftm_report_url');
    }

    /**
     * Object form of the five FTM conditions — true ⇔ this row is a counted FTM.
     * Accepts any object carrying the FTM attributes (Activity model, stdClass
     * row, feed item) so the KPI service and the feed resource share one rule.
     * The required boolean flags are read from FTM_BOOLEAN_FLAGS — the same list
     * the query scope iterates — so the two forms stay in lock-step.
     */
    public static function qualifiesAsFtm(object $row): bool
    {
        $kind = $row->kind ?? null;
        $kindValue = $kind instanceof \BackedEnum ? $kind->value : $kind;

        if ($kindValue !== ActivityType::Meeting->value) {
            return false;
        }

        foreach (self::FTM_BOOLEAN_FLAGS as $flag) {
            if (! (bool) ($row->{$flag} ?? false)) {
                return false;
            }
        }

        return ! empty($row->ftm_report_url);
    }

    /**
     * Computed overdue predicate (no column). Overdue ⇔ due_at in the past AND
     * not closed AND status is not final. Mirrors the query predicate in
     * ActivityService so badge counts and lists never drift (E4).
     *
     * A FINAL status (done OR rejected) is never overdue — keying on Done alone
     * left a rejected task that somehow lost its is_closed flag reported as
     * overdue, disagreeing with the open/overdue surfaces (D11/D13). is_closed
     * stays the primary partition, but the status check is robust to an
     * is_closed/status disagreement.
     */
    public function isOverdue(): bool
    {
        if ($this->due_at === null || $this->is_closed) {
            return false;
        }

        if ($this->status?->isFinal()) {
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
