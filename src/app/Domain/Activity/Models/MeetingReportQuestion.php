<?php

declare(strict_types=1);

namespace App\Domain\Activity\Models;

use App\Domain\Sales\Models\Pipeline;
use Database\Factories\Activity\MeetingReportQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MeetingReportQuestion — registry entry for the meeting-report constructor.
 * pipeline_id NULL = global (all pipelines). kind 'text' (free answer) or
 * 'select' (choose from options). Answers are NOT normalised — they live as a
 * snapshot in Activity.meeting_report_json (a question may change later).
 */
class MeetingReportQuestion extends Model
{
    /** @use HasFactory<MeetingReportQuestionFactory> */
    use HasFactory;

    protected static function newFactory(): MeetingReportQuestionFactory
    {
        return MeetingReportQuestionFactory::new();
    }

    protected $table = 'meeting_report_questions';

    protected $fillable = [
        'pipeline_id',
        'text',
        'kind',
        'is_required',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_required' => 'boolean',
        ];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(MeetingReportOption::class, 'question_id')->orderBy('sort_order');
    }
}
