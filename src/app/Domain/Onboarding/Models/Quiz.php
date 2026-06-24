<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Models;

use Database\Factories\Onboarding\QuizFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    /** @use HasFactory<QuizFactory> */
    use HasFactory;

    protected static function newFactory(): QuizFactory
    {
        return QuizFactory::new();
    }

    protected $fillable = [
        'lesson_id',
        'title',
        'description',
        'pass_score_pct',
        'time_limit_minutes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'lesson_id' => 'integer',
            'pass_score_pct' => 'integer',
            'time_limit_minutes' => 'integer',
            'created_by_user_id' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }

    /**
     * Published (non-draft) questions — used on student paths and in scoring.
     * AI-generated drafts (is_draft=true) are excluded until HR approves them.
     *
     * @return HasMany<QuizQuestion>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class, 'quiz_id')
            ->where('is_draft', false)
            ->orderBy('sort_order');
    }

    /**
     * All questions including AI drafts — used on HR/admin paths.
     *
     * @return HasMany<QuizQuestion>
     */
    public function allQuestions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class, 'quiz_id')->orderBy('sort_order');
    }
}
