<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Models;

use Database\Factories\Onboarding\QuizAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAttempt extends Model
{
    /** @use HasFactory<QuizAttemptFactory> */
    use HasFactory;

    protected static function newFactory(): QuizAttemptFactory
    {
        return QuizAttemptFactory::new();
    }

    protected $fillable = [
        'quiz_id',
        'user_id',
        'assignment_id',
        'attempt_number',
        'score_pct',
        'passed',
        'answers',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'quiz_id' => 'integer',
            'user_id' => 'integer',
            'assignment_id' => 'integer',
            'attempt_number' => 'integer',
            'score_pct' => 'integer',
            'passed' => 'boolean',
            'answers' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class, 'quiz_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CourseAssignment::class, 'assignment_id');
    }
}
