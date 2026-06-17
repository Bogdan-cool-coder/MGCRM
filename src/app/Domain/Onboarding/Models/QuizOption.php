<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Models;

use Database\Factories\Onboarding\QuizOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizOption extends Model
{
    /** @use HasFactory<QuizOptionFactory> */
    use HasFactory;

    protected static function newFactory(): QuizOptionFactory
    {
        return QuizOptionFactory::new();
    }

    protected $fillable = [
        'question_id',
        'text',
        'is_correct',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'question_id' => 'integer',
            'is_correct' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class, 'question_id');
    }
}
