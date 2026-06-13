<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Models;

use App\Domain\Onboarding\Enums\LessonKind;
use Database\Factories\Onboarding\LessonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory;

    protected static function newFactory(): LessonFactory
    {
        return LessonFactory::new();
    }

    protected $fillable = [
        'module_id',
        'title',
        'kind',
        'content',
        'duration_minutes',
        'sort_order',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'kind' => LessonKind::class,
            'content' => 'array',
            'is_published' => 'boolean',
            'duration_minutes' => 'integer',
            'sort_order' => 'integer',
            'module_id' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'module_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /**
     * Convenience accessor for S3.2 Quiz binding.
     * Returns the quiz_id stored in content jsonb, or null.
     */
    public function getQuizIdAttribute(): ?int
    {
        $id = data_get($this->content, 'quiz_id');

        return $id !== null ? (int) $id : null;
    }
}
