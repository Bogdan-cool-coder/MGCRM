<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Models;

use App\Domain\Iam\Models\User;
use Database\Factories\Onboarding\OnboardingAiSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OnboardingAiSession — AI-тьютор история диалога.
 *
 * One record per (user_id, lesson_id). Messages are appended on each ask()
 * call and truncated to the last 10 pairs (20 items) to control token usage.
 *
 * messages format: [{role: 'user'|'assistant', content: string, created_at: ISO-string}]
 */
class OnboardingAiSession extends Model
{
    /** @use HasFactory<OnboardingAiSessionFactory> */
    use HasFactory;

    protected static function newFactory(): OnboardingAiSessionFactory
    {
        return OnboardingAiSessionFactory::new();
    }

    protected $table = 'onboarding_ai_sessions';

    protected $fillable = [
        'user_id',
        'lesson_id',
        'messages',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'lesson_id' => 'integer',
            'messages' => 'array',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }
}
