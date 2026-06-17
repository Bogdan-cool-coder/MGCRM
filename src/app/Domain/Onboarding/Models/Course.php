<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Models;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Enums\CompletionPolicy;
use Database\Factories\Onboarding\CourseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    protected static function newFactory(): CourseFactory
    {
        return CourseFactory::new();
    }

    protected $fillable = [
        'title',
        'description',
        'cover_image_path',
        'is_published',
        'passing_score_pct',
        'completion_policy',
        'deadline_days',
        'sort_order',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'passing_score_pct' => 'integer',
            'deadline_days' => 'integer',
            'sort_order' => 'integer',
            'completion_policy' => CompletionPolicy::class,
            'created_by_user_id' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class, 'course_id')->orderBy('sort_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CourseAssignment::class, 'course_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}
