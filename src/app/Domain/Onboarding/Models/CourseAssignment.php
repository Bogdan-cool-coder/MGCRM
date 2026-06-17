<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Models;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Enums\AssignmentStatus;
use Database\Factories\Onboarding\CourseAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CourseAssignment extends Model
{
    /** @use HasFactory<CourseAssignmentFactory> */
    use HasFactory;

    protected static function newFactory(): CourseAssignmentFactory
    {
        return CourseAssignmentFactory::new();
    }

    protected $fillable = [
        'course_id',
        'user_id',
        'assigned_by_user_id',
        'due_date',
        'status',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'due_date' => 'datetime',
            'completed_at' => 'datetime',
            'course_id' => 'integer',
            'user_id' => 'integer',
            'assigned_by_user_id' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function lessonProgress(): HasMany
    {
        return $this->hasMany(LessonProgress::class, 'assignment_id');
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class, 'assignment_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', AssignmentStatus::Pending);
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', AssignmentStatus::InProgress);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [AssignmentStatus::Pending, AssignmentStatus::InProgress]);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', AssignmentStatus::Overdue);
    }
}
