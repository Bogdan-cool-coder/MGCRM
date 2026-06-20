<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Crm\Enums\ClientStatus;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable log of client_status transitions for a company.
 * Rows are written by CompanyService; never updated after creation.
 */
class CompanyClientStatusLog extends Model
{
    protected $table = 'company_client_status_log';

    /** No updated_at — log rows are immutable. */
    const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'old_status',
        'new_status',
        'changed_by',
        'changed_at',
        'reason_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'old_status' => ClientStatus::class,
            'new_status' => ClientStatus::class,
            'changed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(DisconnectReason::class, 'reason_id');
    }
}
