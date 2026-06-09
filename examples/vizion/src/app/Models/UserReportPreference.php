<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-(user, report) UI preferences mirrored from frontend localStorage so they
 * sync across devices. A report is now a dry table, so the only synced
 * preference is column order / hidden columns (column_order).
 *
 * Unique on (user_id, report_id) — one preferences row per pair. Cascade on
 * delete from either side.
 */
class UserReportPreference extends Model
{
    protected $fillable = [
        'user_id',
        'report_id',
        'column_order',
    ];

    protected function casts(): array
    {
        return [
            'column_order' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
