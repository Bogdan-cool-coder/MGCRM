<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-(user, company) personal ordering of the report list, driven by
 * drag-n-drop on the frontend. The `order` array holds report ids in the
 * user's chosen sequence; it overrides the global default
 * (reports.sort_order + created_at) in ReportController::index.
 *
 * Unique on (user_id, company_id) — one ordering row per pair. Cascade on
 * delete from either side.
 */
class UserReportOrder extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
