<?php

declare(strict_types=1);

namespace App\Domain\Activity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MeetingReportOption — a choice for a kind='select' MeetingReportQuestion.
 */
class MeetingReportOption extends Model
{
    protected $table = 'meeting_report_options';

    protected $fillable = [
        'question_id',
        'text',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(MeetingReportQuestion::class, 'question_id');
    }
}
