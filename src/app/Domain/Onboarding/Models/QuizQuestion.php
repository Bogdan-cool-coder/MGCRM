<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Models;

use App\Domain\Onboarding\Enums\QuestionKind;
use Database\Factories\Onboarding\QuizQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizQuestion extends Model
{
    /** @use HasFactory<QuizQuestionFactory> */
    use HasFactory;

    protected static function newFactory(): QuizQuestionFactory
    {
        return QuizQuestionFactory::new();
    }

    protected $fillable = [
        'quiz_id',
        'text',
        'kind',
        'sort_order',
        'explanation',
        'points',
        'is_draft',
    ];

    protected function casts(): array
    {
        return [
            'quiz_id' => 'integer',
            'kind' => QuestionKind::class,
            'sort_order' => 'integer',
            'points' => 'integer',
            'is_draft' => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class, 'quiz_id');
    }

    /** @return HasMany<QuizOption> */
    public function options(): HasMany
    {
        return $this->hasMany(QuizOption::class, 'question_id')->orderBy('sort_order');
    }
}
